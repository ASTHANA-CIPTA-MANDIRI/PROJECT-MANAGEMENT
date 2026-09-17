<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Models\OrganizationSupportSession;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Support\SupportSessionContext;
use App\Support\TrialGate;
use Illuminate\Database\Eloquent\Collection;

/**
 * The Super Admin's read-only window into an Organization's data while a
 * support session (App\Support\SupportSessionContext) is active — the
 * companion page PlatformOrganizations::startSupportSession redirects into.
 *
 * Deliberately read-only by construction: no write action is defined
 * anywhere on this page, so there is nothing here to gate with a Policy or
 * a Gate::before rule. It reads $organization->projects, ->tickets(),
 * ->sprints() and ->users() directly rather than
 * Project::accessibleBy()/Ticket::scopeVisibleTo()/etc., which is
 * intentional and safe specifically *because* no write path is exposed —
 * see docs/adr/0001-hybrid-multi-tenant-authorization.md's addendum after
 * Scenario D for why this is a separate side-channel from
 * App\Support\OrganizationContext rather than a bypass added to it. Every
 * query below filters by this organization's id at the database level
 * (never Model::all() + a PHP-side filter). Every public method that takes
 * an Eloquent-model parameter (sprintStatusBreakdown()) re-verifies that
 * model's organization_id against the active session itself — Livewire can
 * be asked to call any public method directly with a client-supplied id
 * (Livewire\ImplicitlyBoundMethod resolves a typed Model parameter from it,
 * the same mechanism route-model-binding uses), so a method cannot rely on
 * only ever being reached through sprints()'s own already-scoped list.
 *
 * Access requires both an active support session *and* isSuperAdmin() —
 * userCanAccessPage() is re-run on every Livewire request by
 * AuthorizesPageAccess (see AuthorizedPage), so ending the session (or
 * letting it expire) immediately locks this page — and everything below —
 * out again.
 */
class OrganizationSupportView extends AuthorizedPage
{
    protected static ?string $navigationIcon = 'heroicon-o-eye';

    protected static bool $shouldRegisterNavigation = false;

    protected static ?string $slug = 'platform/support-session';

    protected static string $view = 'filament.pages.organization-support-view';

    /**
     * How many rows the tickets()/recentActivity() lists show at once.
     * Capped rather than paginated: this page is a plain Blade view (like
     * OrganizationSettings::pendingInvitations()), not a Filament HasTable
     * component, and Livewire's WithPagination trait is not used anywhere
     * else in this codebase for a page built that way — reaching for it
     * here would be a new pattern, not a reused one. A newest-first,
     * capped summary is enough for a support operator sanity-checking
     * "does this organization's data look right", which is this page's
     * whole purpose; projects()/sprints()/members() are left uncapped
     * because an organization's own project/sprint/member count is
     * naturally small (the same assumption OrganizationSettings' own
     * uncapped members table already makes).
     */
    private const RECENT_TICKETS_LIMIT = 25;

    private const RECENT_ACTIVITY_LIMIT = 20;

    private ?Organization $cachedOrganization = null;

    public static function userCanAccessPage(): bool
    {
        $user = auth()->user();

        return (bool) $user?->isSuperAdmin() && SupportSessionContext::current($user) !== null;
    }

    public function getHeading(): string
    {
        return __('Sesi Support');
    }

    public function session(): ?OrganizationSupportSession
    {
        return SupportSessionContext::current(auth()->user());
    }

    public function endSession(): void
    {
        SupportSessionContext::stop(auth()->user());

        $this->redirect(PlatformOrganizations::getUrl());
    }

    /**
     * Memoized for the lifetime of this instance only (like
     * OrganizationSettings::organization()) — re-read from session() each
     * time it is first needed, never cached across requests, so an ended/
     * expired session (session() returning null) is reflected immediately.
     */
    private function organization(): ?Organization
    {
        return $this->cachedOrganization ??= $this->session()?->organization;
    }

    /**
     * Read-only counters for the "Organization Overview" section — every
     * count is a database-level query scoped to this organization's id,
     * never Model::all() filtered in PHP. Reuses App\Support\TrialGate for
     * the trial/subscription read the rest of the app already treats as
     * the single source of truth, instead of re-deriving it.
     *
     * @return array{trial_label: string, created_at: \Illuminate\Support\Carbon|null, projects_count: int, members_count: int, tickets_count: int, active_sprints_count: int, overdue_tickets_count: int}|null
     */
    public function overview(): ?array
    {
        $organization = $this->organization();

        if ($organization === null) {
            return null;
        }

        $ticketsInOrganization = fn () => Ticket::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id));

        return [
            'trial_label' => $this->trialLabel($organization),
            'created_at' => $organization->created_at,
            'projects_count' => $organization->projects()->count(),
            'members_count' => $organization->users()->count(),
            'tickets_count' => $ticketsInOrganization()->count(),
            'active_sprints_count' => Sprint::query()
                ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
                ->whereNotNull('started_at')
                ->whereNull('ended_at')
                ->count(),
            'overdue_tickets_count' => $ticketsInOrganization()
                ->whereNotNull('due_date')
                ->where('due_date', '<', now()->startOfDay())
                ->count(),
        ];
    }

    /**
     * A miniature of PlatformOrganizations::trialStatusLabel()'s three-way
     * read of trial_ends_at/TrialGate::active() — kept as its own tiny copy
     * instead of extracting a shared helper, since this read-only page
     * deliberately shares no code with the write-capable Platform pages
     * (see this class's own docblock) and the label logic is a few lines.
     */
    private function trialLabel(Organization $organization): string
    {
        if ($organization->trial_ends_at === null) {
            return __('Grandfathered');
        }

        if ($organization->trial_ends_at->isFuture()) {
            $days = now()->startOfDay()->diffInDays($organization->trial_ends_at->copy()->startOfDay());

            return __('Trial active (:days days left)', ['days' => $days]);
        }

        return TrialGate::active($organization) ? __('Subscribed') : __('Trial ended');
    }

    /**
     * Every project in the organization, with the read-only counts the
     * template needs eager loaded — one extra query per count column
     * (withCount) plus one for active sprints (with()), not one per
     * project — instead of reaching for each Project's own
     * currentSprint/statistics() (which would run per-instance queries).
     */
    public function projects(): Collection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new Collection;
        }

        return $organization->projects()
            ->withCount([
                'tickets as tickets_count',
                'tickets as open_tickets_count' => fn ($query) => $query
                    ->whereHas('status', fn ($query) => $query->where('is_final', false)),
                'tickets as overdue_tickets_count' => fn ($query) => $query
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', now()->startOfDay()),
            ])
            ->with(['sprints' => fn ($query) => $query->whereNotNull('started_at')->whereNull('ended_at')])
            ->latest()
            ->get();
    }

    /**
     * The organization's most recent tickets across every project — see
     * RECENT_TICKETS_LIMIT's docblock for why this is a capped list rather
     * than a paginated one.
     */
    public function tickets(): Collection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new Collection;
        }

        return Ticket::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->with(['project:id,name', 'status:id,name,color', 'priority:id,name,color', 'responsible:id,name'])
            ->latest()
            ->limit(self::RECENT_TICKETS_LIMIT)
            ->get();
    }

    /**
     * Every sprint across the organization's projects — each one's tickets
     * (with just their status) are eager loaded here so
     * sprintStatusBreakdown() below never runs a query per sprint.
     * project's organization_id is deliberately included in the column
     * list (not just id/name) — sprintStatusBreakdown() re-checks it on
     * every call, so leaving it unselected would make that check always
     * see null and reject even this method's own, already-scoped sprints.
     */
    public function sprints(): Collection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new Collection;
        }

        return Sprint::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->with(['project:id,name,organization_id', 'tickets.status:id,name,is_final,is_default'])
            ->latest('starts_at')
            ->get();
    }

    /**
     * Todo/In progress/Done counts for one sprint, derived from
     * TicketStatus::is_default/is_final — the same two existing flags the
     * rest of this app already treats as "the starting state" and "a
     * closed state" — rather than introducing a new stored bucket on
     * Sprint or TicketStatus just for this summary.
     *
     * Security audit finding (HIGH): $sprint is Livewire-implicit-bound —
     * Livewire\ImplicitlyBoundMethod resolves a typed Eloquent parameter
     * from whatever scalar id the client sends on a direct method call
     * (e.g. `$wire.call('sprintStatusBreakdown', 123)`), the same mechanism
     * Laravel route-model-binding uses, completely bypassing the fact that
     * the Blade view only ever passes sprints already filtered by
     * sprints() above. Without this check, a Super Admin whose active
     * support session is for Organization A could pass an id belonging to
     * Organization B's sprint and read its ticket-status breakdown — the
     * exact "re-verify on every call, don't just trust the caller already
     * filtered" discipline Project::isManageableThroughOrganizationBy()'s
     * own docblock warns a skipped check here becomes. abort_unless(...,
     * 403) mirrors the same pattern Kanban::mount()/Scrum::mount() already
     * use for "this record isn't the caller's to see".
     */
    public function sprintStatusBreakdown(Sprint $sprint): array
    {
        $organization = $this->organization();

        abort_unless(
            $organization !== null && $sprint->project?->organization_id === $organization->id,
            403
        );

        $todo = 0;
        $done = 0;

        foreach ($sprint->tickets as $ticket) {
            if ($ticket->status?->is_final) {
                $done++;
            } elseif ($ticket->status?->is_default) {
                $todo++;
            }
        }

        return [
            'todo' => $todo,
            'done' => $done,
            'in_progress' => $sprint->tickets->count() - $todo - $done,
        ];
    }

    /**
     * Every member of the organization, with the same role/joined-at pivot
     * columns OrganizationSettings' own members table reads — minus the
     * write actions that page adds. Uncapped: see RECENT_TICKETS_LIMIT's
     * docblock for why that is safe here.
     *
     * Security audit finding (HIGH): explicitly selects only the columns
     * the Blade view actually reads (id/name/email), rather than the bare
     * `$organization->users()->get()` this used to be — that pulled every
     * User column, including `creation_token` (the account-activation
     * secret ValidateAccount::validateAccount() uses to set a pending
     * member's password — see UserObserver::creating()), into whatever
     * this method returns. $hidden on the User model already keeps
     * password/remember_token out of any array/JSON serialization, but
     * creation_token is deliberately NOT hidden (ValidateAccount reads it
     * as a plain attribute), so relying on $hidden alone would not have
     * closed this. Columns are qualified with the `users.` table prefix —
     * required the same way OrganizationSettings::getTableQuery() already
     * documents: an unqualified column collides with organization_users'
     * own same-named column once the pivot join is in the query. Selecting
     * explicit columns still leaves Eloquent's pivot hydration
     * (BelongsToMany::get()) free to append its own aliased pivot columns,
     * so $member->pivot->role/created_at (what the Blade view reads) are
     * unaffected.
     */
    public function members(): Collection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new Collection;
        }

        return $organization->users()
            ->select(['users.id', 'users.name', 'users.email'])
            ->orderBy('users.name')
            ->get();
    }

    /**
     * The organization's most recent ticket status changes — the only
     * activity/audit trail this app actually has. App\Models\Activity is a
     * lookup table for time-tracking categories (see ticket_hours.activity_id),
     * not an event feed, and there is no general-purpose activity-log
     * package in this codebase (audited via composer.json) — so this reads
     * App\Models\TicketActivity, which already records "who changed which
     * ticket's status, from what, to what, when", scoped to this
     * organization through its ticket's project. See
     * RECENT_TICKETS_LIMIT's docblock for why this is a capped list.
     */
    public function recentActivity(): Collection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new Collection;
        }

        return TicketActivity::query()
            ->whereHas('ticket.project', fn ($query) => $query->where('organization_id', $organization->id))
            ->with(['ticket:id,name,code', 'oldStatus:id,name', 'newStatus:id,name', 'user:id,name'])
            ->latest()
            ->limit(self::RECENT_ACTIVITY_LIMIT)
            ->get();
    }
}
