<?php

namespace App\Models;

use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Project extends Model implements HasMedia
{
    use HasFactory, InteractsWithMedia, Searchable, SoftDeletes;

    /**
     * The data indexed for full-text search (Laravel Scout).
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => strip_tags((string) $this->description),
            'ticket_prefix' => $this->ticket_prefix,
        ];
    }

    protected $fillable = [
        'name', 'description', 'status_id', 'owner_id', 'ticket_prefix',
        'status_type', 'type',
    ];

    protected $appends = [
        'cover',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id', 'id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'organization_id', 'id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(ProjectStatus::class, 'status_id', 'id')->withTrashed();
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_users', 'project_id', 'user_id')->withPivot(['role']);
    }

    /**
     * Projects the given user owns or is a member of, within their current
     * Organization context — plus, Phase 5.3B, every project belonging to
     * that Organization at all when the user is its Owner/Admin, even one
     * they own no `project_users` row on (isManageableThroughOrganizationBy()'s
     * query-level twin). The single source of truth for "does this user have
     * access to this project" query logic, usable standalone
     * (Project::accessibleBy($user)) or nested inside a whereHas('project', ...)
     * closure on a related model.
     *
     * A project with no organization_id (not yet backfilled into Phase 2's
     * Organization model) is left ungated by the organization check — it was
     * never assigned to any tenant, so there is no "wrong org" to leak into;
     * visibility stays governed purely by the owner/project_users rule below,
     * exactly as it was before Organization existed (ADR 0001, "must not
     * become an escape hatch" — nothing gains new visibility this way).
     */
    public function scopeAccessibleBy(Builder $query, User $user): Builder
    {
        $currentOrganization = OrganizationContext::current($user);
        $organizationGrantsManagement = $currentOrganization?->isManageableBy($user) ?? false;

        return $query
            ->where(fn (Builder $query) => $query->where('owner_id', $user->id)
                ->orWhereHas('users', fn (Builder $query) => $query->where('users.id', $user->id))
                ->when(
                    $organizationGrantsManagement,
                    fn (Builder $query) => $query->orWhere('organization_id', $currentOrganization->id)
                ))
            ->where(fn (Builder $query) => $query->whereNull('organization_id')
                ->orWhere('organization_id', $currentOrganization?->id));
    }

    /**
     * Whether this user may reach the project's contents: its owner, or one of
     * its members, and — when the project belongs to an Organization — only
     * while that Organization is the user's current context (see
     * scopeAccessibleBy() above for why a null organization_id is exempt).
     * The single-instance twin of the accessibleBy() scope above — the
     * policies ask this question about a model they already hold, where a
     * query scope would have nothing to filter.
     *
     * exists(), not count(): the answer is a yes/no, and count() makes the
     * database tally rows nobody looks at.
     */
    public function isAccessibleBy(User $user): bool
    {
        return $this->isWithinOrganizationContext($user)
            && ($this->owner_id === $user->id
                || $this->users()->whereKey($user->id)->exists()
                || $this->isManageableThroughOrganizationBy($user));
    }

    /**
     * Whether this user may change the project itself, and everything planned
     * under it: its owner, a member holding the managing project role, or —
     * Phase 5.3B — the Owner/Admin of the Organization this project belongs
     * to — gated by the same Organization context as isAccessibleBy() above.
     */
    public function isManageableBy(User $user): bool
    {
        return $this->isWithinOrganizationContext($user)
            && ($this->owner_id === $user->id
                || $this->users()
                    ->whereKey($user->id)
                    ->wherePivot('role', config('system.projects.affectations.roles.can_manage'))
                    ->exists()
                || $this->isManageableThroughOrganizationBy($user));
    }

    /**
     * Phase 5.3B — Organization Owner/Admin authority over a Project they
     * belong to no `project_users` row for. Deliberately NOT a
     * `project_users` attach or a new "project_manager"/"owner" role: this
     * is a second, independent source of Project authority (Organization
     * membership/role), never written into `project_users.role`, which stays
     * exactly `employee`/`customer`/`administrator` as before (ADR 0001 —
     * Organization RBAC and Project RBAC stay two separate axes).
     *
     * `organization_id === null` (legacy/pre-Organization projects) never
     * reaches this branch — there is no Organization to derive authority
     * from, so those projects keep being governed purely by owner_id/
     * project_users exactly as before this phase.
     *
     * Public (Phase 5.4.3 fix) and fully self-contained: originally private
     * and safe only because isAccessibleBy()/isManageableBy() already ran
     * isWithinOrganizationContext() first, which independently confirms
     * organization_id equals the user's *current* Organization before this
     * method is ever reached from there. ProjectPolicy now calls this
     * directly too (to grant Owner/Admin update/delete/view authority
     * without also requiring the legacy flat `Update project`/`Delete
     * project`/`View project` Spatie permission that owner_id/project_users
     * holders still need) — calling it without that outer guard would have
     * been a real cross-organization hole: an Owner of Organization A whose
     * active context is A could otherwise pass this check for a Project
     * belonging to Organization B, since the old body only asked "is the
     * user's current org manageable by them", never "does it match *this*
     * project's org." This version re-verifies that match itself, so it is
     * safe to call from anywhere, not just from behind another gate.
     */
    public function isManageableThroughOrganizationBy(User $user): bool
    {
        if ($this->organization_id === null) {
            return false;
        }

        $currentOrganization = OrganizationContext::current($user);

        return $currentOrganization !== null
            && $currentOrganization->id === $this->organization_id
            && $currentOrganization->isManageableBy($user);
    }

    /**
     * Fase 6b — narrower sibling of isManageableThroughOrganizationBy()
     * above: only the Organization's Owner (not Admin) gets destructive/
     * settings authority over a project through organization membership
     * alone. ProjectPolicy::update()/delete() call this instead of the
     * broader method; ::view() and ::create() are deliberately unaffected
     * and keep using the Owner+Admin version, since an Admin still needs
     * to see every project in the organization to manage the team and may
     * still create new ones — subscription-model-direction.md's
     * Admin/Agent principle is specifically "no authority to delete a
     * project or change its settings/billing", not "no authority at all."
     * Same organization-match re-verification as the method above, for the
     * same reason: safe to call from anywhere, not just from behind
     * another guard.
     */
    public function isOwnerManageableThroughOrganizationBy(User $user): bool
    {
        if ($this->organization_id === null) {
            return false;
        }

        $currentOrganization = OrganizationContext::current($user);

        return $currentOrganization !== null
            && $currentOrganization->id === $this->organization_id
            && $currentOrganization->isOwnedBy($user);
    }

    /**
     * Whether the user's current Organization context (see
     * App\Support\OrganizationContext) matches this project's organization —
     * or the project has none, in which case there is nothing to match
     * against and the check is skipped entirely (see scopeAccessibleBy()).
     */
    private function isWithinOrganizationContext(User $user): bool
    {
        return $this->organization_id === null
            || $this->organization_id === OrganizationContext::current($user)?->id;
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class, 'project_id', 'id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(TicketStatus::class, 'project_id', 'id');
    }

    public function epics(): HasMany
    {
        return $this->hasMany(Epic::class, 'project_id', 'id');
    }

    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class, 'project_id', 'id');
    }

    /**
     * The date range the road map spans.
     *
     * Both always return a date object, and Eloquent caches object attribute
     * values by default (Attribute::$withObjectCaching), so these were never
     * re-querying. shouldCache() states that requirement outright instead of
     * leaning on a default that silently stops applying the day one of them
     * returns null - which is exactly what bit currentSprint below.
     */
    public function epicsFirstDate(): Attribute
    {
        return (new Attribute(
            get: function () {
                $firstEpic = $this->epics()->orderBy('starts_at')->first();
                if ($firstEpic) {
                    return $firstEpic->starts_at;
                }

                return now();
            }
        ))->shouldCache();
    }

    public function epicsLastDate(): Attribute
    {
        return (new Attribute(
            get: function () {
                $firstEpic = $this->epics()->orderBy('ends_at', 'desc')->first();
                if ($firstEpic) {
                    return $firstEpic->ends_at;
                }

                return now();
            }
        ))->shouldCache();
    }

    public function contributors(): Attribute
    {
        return new Attribute(
            get: function () {
                // ->users is Eloquent's cached relation collection, not a
                // copy: push()ing onto it would leave the owner looking like
                // a member to every other reader of $project->users for the
                // rest of the request.
                $users = $this->users->collect();
                $users->push($this->owner);

                return $users->unique('id');
            }
        );
    }

    public function cover(): Attribute
    {
        // The Filament SpatieMediaLibraryFileUpload stores the cover in the
        // default collection, so read it back with the proper Spatie API
        // instead of misusing the media() relation.
        return new Attribute(
            get: fn () => $this->getFirstMedia()?->getFullUrl()
                ?? 'https://ui-avatars.com/api/?background=3f84f3&color=ffffff&name='.urlencode($this->name)
        );
    }

    /**
     * The running sprint, if any.
     *
     * The scrum board reads this from the page heading, the board query, two
     * action visibility checks and the sub-heading. Eloquent caches object
     * attribute values by default, so that was already one query - but only
     * while a sprint is actually running: null is not an object, so a project
     * between sprints re-ran the query on every single read. shouldCache()
     * covers the empty case too.
     *
     * Starting or ending a sprint writes through the query builder and then
     * re-reads the project fresh, so the per-instance cache never goes stale
     * on that path.
     */
    public function currentSprint(): Attribute
    {
        return (new Attribute(
            get: fn () => $this->sprints()
                ->whereNotNull('started_at')
                ->whereNull('ended_at')
                ->first()
        ))->shouldCache();
    }

    public function nextSprint(): Attribute
    {
        return (new Attribute(
            get: function () {
                if ($this->currentSprint) {
                    return $this->sprints()
                        ->whereNull('started_at')
                        ->whereNull('ended_at')
                        ->where('starts_at', '>=', $this->currentSprint->ends_at)
                        ->orderBy('starts_at')
                        ->first();
                }

                return null;
            }
        ))->shouldCache();
    }

    /**
     * Hand out the next ticket number for this project. The counter lives on
     * the project row and is bumped under a row lock inside a transaction, so
     * two simultaneous creations can never get the same number, and a number
     * is never reused after its ticket is (soft) deleted.
     */
    public function allocateTicketNumber(): int
    {
        return DB::transaction(function () {
            $next = (int) static::withTrashed()
                ->whereKey($this->id)
                ->lockForUpdate()
                ->value('last_ticket_number') + 1;

            static::withTrashed()->whereKey($this->id)
                ->update(['last_ticket_number' => $next]);

            $this->last_ticket_number = $next;

            return $next;
        });
    }

    /**
     * Aggregate statistics for the project, cached for one hour so the view
     * that displays them does not recompute the counts on every request.
     *
     * @return array{tickets:int, contributors:int, sprints:int, epics:int, logged_hours:float}
     */
    public function statistics(): array
    {
        return Cache::remember($this->statisticsCacheKey(), 3600, function () {
            $ticketIds = $this->tickets()->pluck('id');

            return [
                'tickets' => $ticketIds->count(),
                'contributors' => $this->contributors->count(),
                'sprints' => $this->sprints()->count(),
                'epics' => $this->epics()->count(),
                'logged_hours' => (float) TicketHour::whereIn('ticket_id', $ticketIds)->sum('value'),
            ];
        });
    }

    /**
     * Cache key holding this project's statistics.
     */
    public function statisticsCacheKey(): string
    {
        return "project:{$this->id}:statistics";
    }

    /**
     * Invalidate the cached statistics (call when tickets/hours change).
     */
    public function forgetStatistics(): void
    {
        Cache::forget($this->statisticsCacheKey());
    }
}
