<?php

namespace App\Filament\Pages;

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationSupportAccessGrant;
use App\Models\OrganizationSupportSession;
use App\Models\Project;
use App\Models\Role;
use App\Models\Subscription;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\OrganizationInvitationCreated;
use App\Support\OrganizationDefaults;
use App\Support\SupportSessionContext;
use App\Support\TrialGate;
use Carbon\Carbon;
use Closure;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Platform Super Admin only — a cross-organization view so the operator of
 * the SaaS product can monitor every Organization's trial status, and help
 * customers directly (rename a mistyped name, extend a trial, provision an
 * organization by hand, remove an abandoned one) without joining any of
 * them as a member.
 *
 * Per ADR 0001 ("Platform" section): "A platform-level Filament panel that
 * lets the Platform Super Admin browse across organizations is explicitly
 * Level-1 territory ... must not rely on a single-organization
 * `setPermissionsTeamId()` context at all." This page therefore never touches
 * `App\Support\OrganizationContext` or `App\Policies\OrganizationPolicy` —
 * both are deliberately scoped to "the organization(s) this user belongs to,"
 * which is the wrong question here. Authorization below is overridden to
 * check `auth()->user()->isSuperAdmin()` directly instead — the same
 * non-team-scoped mechanism `RolePolicy`, `UserPolicy`, and
 * `RequireTwoFactorForSuperAdmins` already use for this exact class of check
 * (Scenario D of the Teams spike found a `team_id = NULL` role assignment
 * unsafe to rely on for a cross-organization actor), reused here rather than
 * inventing a new authorization path. `AuthorizesPageAccess` re-runs
 * `userCanAccessPage()` on every Livewire request (not just the first
 * render), so every action below is covered by the same check without
 * needing its own redundant Gate call.
 *
 * Create/Edit/Delete live here rather than a Resource for the same reason
 * OrganizationSettings does: this is a hand-built table with actions that
 * call domain logic directly, not a RelationManager whose abilities would
 * be checked against the wrong Policy. Deleting an Organization only ever
 * succeeds when it has zero Projects (including trashed ones) — every
 * organization-scoped lookup table (ticket_types, ticket_priorities,
 * project_statuses, labels, activities) cascade-deletes with it, and a
 * Project's `status_id`/`priority_id`/`type_id` foreign keys are NOT
 * cascading, so a Project would otherwise be left pointing at a row that no
 * longer exists — the deleteOrganization action refuses up front instead of
 * letting that happen.
 */
class PlatformOrganizations extends AuthorizedPage implements HasForms, Tables\Contracts\HasTable
{
    use InteractsWithForms;
    use Tables\Concerns\InteractsWithTable;

    /**
     * Unused: {@see userCanAccessPage()} is overridden below instead of the
     * permission-string mechanism {@see \App\Filament\Pages\Concerns\AuthorizesPageAccess}
     * normally reads from this property. See the class docblock for why a
     * flat Spatie permission is the wrong tool for a Platform-level page.
     */
    protected static ?string $permission = null;

    protected static ?string $navigationIcon = 'heroicon-o-globe-alt';

    protected static ?string $slug = 'platform/organizations';

    protected static string $view = 'filament.pages.platform-organizations';

    /**
     * The organization whose detail panel is open (beside the activity feed).
     * Set by clicking a table row; the id is only ever used to look the row up
     * again, and this whole page is Super Admin only.
     */
    public ?int $selectedOrganizationId = null;

    public function mount(): void
    {
        // ?organization=<id> opens that organization's panel straight away.
        $id = request()->integer('organization');

        $this->selectedOrganizationId = $id > 0 && Organization::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * Overrides {@see \App\Filament\Pages\Concerns\AuthorizesPageAccess::userCanAccessPage()}.
     * Both `boot()` (runs on every Livewire request, not just mount) and
     * `shouldRegisterNavigation()` already call this method via late static
     * binding, so overriding it alone is enough to redirect the existing
     * enforcement machinery at `isSuperAdmin()` instead of a permission.
     */
    public static function userCanAccessPage(): bool
    {
        return (bool) auth()->user()?->isSuperAdmin();
    }

    protected static function getNavigationGroup(): ?string
    {
        return __('Platform');
    }

    protected static function getNavigationLabel(): string
    {
        return __('Organizations');
    }

    protected function getHeading(): string|Htmlable
    {
        return __('Organizations');
    }

    // ------------------------------------------------- platform dashboard

    /**
     * Headline numbers for the cards above the table. Platform-wide on purpose:
     * this page is Super Admin only (userCanAccessPage(), re-checked on every
     * Livewire request by boot()) and none of these models carries a
     * per-organization global scope. "Since last month" is the growth against
     * the total at the start of the current month.
     *
     * @return array<int, array{label: string, total: int, delta: ?float, icon: string, tone: string}>
     */
    public function platformStats(): array
    {
        // Months are the ones the Super Admin sees (display timezone); the
        // database stores UTC, so the boundary is converted before comparing.
        $monthStart = now(config('app.display_timezone'))->startOfMonth()->utc();

        $row = function (string $label, string $icon, string $tone, int $total, int $before): array {
            return [
                'label' => $label,
                'total' => $total,
                'delta' => $before > 0 ? round(($total - $before) / $before * 100, 1) : null,
                'icon' => $icon,
                'tone' => $tone,
            ];
        };

        // The three big tables are counted at most once a minute; organizations
        // are few and read live, so a freshly created one shows up at once.
        $counts = Cache::remember('platform.dashboard.counts.'.$monthStart->format('Y-m'), 60, fn () => [
            User::class => [User::query()->count(), User::query()->where('created_at', '<', $monthStart)->count()],
            Project::class => [Project::query()->count(), Project::query()->where('created_at', '<', $monthStart)->count()],
            Ticket::class => [Ticket::query()->count(), Ticket::query()->where('created_at', '<', $monthStart)->count()],
        ]);

        return [
            $row(__('Total organizations'), 'office-building', 'blue', Organization::query()->count(), Organization::query()->where('created_at', '<', $monthStart)->count()),
            $row(__('Total users'), 'users', 'green', ...$counts[User::class]),
            $row(__('Total projects'), 'collection', 'purple', ...$counts[Project::class]),
            $row(__('Total tickets'), 'ticket', 'yellow', ...$counts[Ticket::class]),
        ];
    }

    /**
     * New organizations per month, oldest first, for the last 6 and 12 months
     * (the card's dropdown switches between them in the browser). Grouped in
     * PHP so it runs on any database driver.
     *
     * @return array{6: array{labels: array<int, string>, data: array<int, int>}, 12: array{labels: array<int, string>, data: array<int, int>}}
     */
    public function organizationGrowth(): array
    {
        $timezone = config('app.display_timezone');
        $first = now($timezone)->startOfMonth()->subMonths(11);

        $perMonth = Organization::query()
            ->where('created_at', '>=', $first->copy()->utc())
            ->pluck('created_at')
            ->countBy(fn (Carbon $date) => $date->copy()->setTimezone($timezone)->format('Y-n'));

        $months = collect(range(0, 11))->map(fn (int $i) => $first->copy()->addMonths($i));

        $labels = $months->map(fn (Carbon $month) => $month->translatedFormat('M'))->all();
        $data = $months->map(fn (Carbon $month) => (int) ($perMonth[$month->format('Y-n')] ?? 0))->all();

        return [
            '6' => ['labels' => array_slice($labels, -6), 'data' => array_slice($data, -6)],
            '12' => ['labels' => $labels, 'data' => $data],
        ];
    }

    /**
     * How the installation's organizations split by trial/subscription state,
     * using the same four meanings as trialStatusLabel(): no time limit
     * (trial_ends_at null), trial running, subscribed after the trial, and
     * trial ended.
     *
     * @return array{total: int, rows: array<int, array{label: string, count: int, percent: int, color: string}>}
     */
    public function organizationStatus(): array
    {
        $total = Organization::query()->count();
        $unlimited = Organization::query()->whereNull('trial_ends_at')->count();
        $running = Organization::query()->where('trial_ends_at', '>', now())->count();

        $past = Organization::query()->whereNotNull('trial_ends_at')->where('trial_ends_at', '<=', now());
        $subscribed = (clone $past)->whereIn('id', Subscription::query()->active()->select('organization_id'))->count();
        $ended = (clone $past)->count() - $subscribed;

        $rows = [
            [__('Subscribed'), $subscribed, '#72cc90'],
            [__('Trial active'), $running, '#3b82d6'],
            [__('No time limit'), $unlimited, '#c4c9d0'],
            [__('Trial ended'), $ended, '#ee9a82'],
        ];

        // Largest-remainder rounding so the legend always adds up to 100%.
        $percents = array_map(fn (array $row) => $total > 0 ? (int) floor($row[1] / $total * 100) : 0, $rows);

        if ($total > 0) {
            $remainders = [];
            foreach ($rows as $i => $row) {
                $remainders[$i] = ($row[1] / $total * 100) - $percents[$i];
            }
            arsort($remainders);

            foreach (array_slice(array_keys($remainders), 0, 100 - array_sum($percents)) as $i) {
                $percents[$i]++;
            }
        }

        return [
            'total' => $total,
            'rows' => array_map(fn (array $row, int $i) => [
                'label' => $row[0],
                'count' => $row[1],
                'percent' => $percents[$i],
                'color' => $row[2],
            ], $rows, array_keys($rows)),
        ];
    }

    /**
     * A merged feed of what happened across the platform: the newest event of every kind first (so one busy kind - say a seeded batch of tickets - cannot push the others out), then whatever is newest, shown newest first. Deliberately only
     * the kind of event and the organization it belongs to - never ticket
     * titles or project names: tenant content is reached through an audited
     * support session (OrganizationSupportView), not from this overview.
     *
     * @return SupportCollection<int, array{title: string, text: string, at: Carbon, icon: string, tone: string, kind: string}>
     */
    public function recentPlatformActivity(): SupportCollection
    {
        $events = collect();

        Organization::query()->latest()->limit(3)->get()->each(fn (Organization $organization) => $events->push([
            'title' => $organization->name,
            'text' => __('Organization created'),
            'at' => $organization->created_at,
            'icon' => 'office-building',
            'tone' => 'blue',
            'kind' => 'organization',
        ]));

        User::query()->latest()->limit(3)->get()->each(fn (User $user) => $events->push([
            'title' => __('New user'),
            'text' => __(':name joined', ['name' => $user->name]),
            'at' => $user->created_at,
            'icon' => 'user-add',
            'tone' => 'purple',
            'kind' => 'user',
        ]));

        OrganizationSupportAccessGrant::query()->with('organization')->latest('requested_at')->limit(3)->get()
            ->each(fn (OrganizationSupportAccessGrant $grant) => $events->push([
                'title' => __('Full Access request'),
                'text' => __('For :organization', ['organization' => $grant->organization?->name ?? '-']),
                'at' => $grant->requested_at,
                'icon' => 'shield-check',
                'tone' => 'green',
                'kind' => 'grant',
            ]));

        Project::query()->with('organization')->latest()->limit(3)->get()->each(fn (Project $project) => $events->push([
            'title' => __('New project'),
            'text' => __('In :organization', ['organization' => $project->organization?->name ?? __('No organization')]),
            'at' => $project->created_at,
            'icon' => 'collection',
            'tone' => 'blue',
            'kind' => 'project',
        ]));

        Ticket::query()->with(['project' => fn ($query) => $query->withTrashed()->with('organization')])->latest()->limit(3)->get()->each(fn (Ticket $ticket) => $events->push([
            'title' => __('New ticket'),
            'text' => __('In :organization', ['organization' => $ticket->project?->organization?->name ?? __('No organization')]),
            'at' => $ticket->created_at,
            'icon' => 'ticket',
            'tone' => 'yellow',
            'kind' => 'ticket',
        ]));

        OrganizationInvitation::query()->with('organization')->latest()->limit(3)->get()
            ->each(fn (OrganizationInvitation $invitation) => $events->push([
                'title' => __('Invitation sent'),
                'text' => __('For :organization', ['organization' => $invitation->organization?->name ?? '-']),
                'at' => $invitation->created_at,
                'icon' => 'mail',
                'tone' => 'gray',
                'kind' => 'invitation',
            ]));

        $events = $events->filter(fn (array $event) => $event['at'] !== null)->sortByDesc('at')->values();

        // The newest event of every kind always makes the list; the remaining
        // slots go to whatever is newest overall.
        $firstOfEachKind = $events->unique('kind')->keys();
        $rest = $events->keys()->diff($firstOfEachKind);

        return $firstOfEachKind->merge($rest)->take(6)->sort()->map(fn (int $key) => $events[$key])->values();
    }

    /**
     * Shortcuts for the side card. "Create organization" opens this page's own
     * header action; the others are plain links to pages Super Admins already use.
     *
     * @return array<int, array{label: string, hint: string, icon: string, tone: string, href: ?string}>
     */
    public function quickActions(): array
    {
        return [
            ['label' => __('Create organization'), 'hint' => __('Add a new organization to the platform'), 'icon' => 'office-building', 'tone' => 'blue', 'href' => null],
            ['label' => __('Manage users'), 'hint' => __('Control users and their roles'), 'icon' => 'users', 'tone' => 'green', 'href' => \App\Filament\Resources\UserResource::getUrl('index')],
            ['label' => __('Roles & permissions'), 'hint' => __('Decide who can do what'), 'icon' => 'shield-check', 'tone' => 'purple', 'href' => \App\Filament\Resources\RoleResource::getUrl('index')],
            ['label' => __('General settings'), 'hint' => __('Site name, logo and language'), 'icon' => 'cog', 'tone' => 'yellow', 'href' => ManageGeneralSettings::getUrl()],
        ];
    }

    public function selectOrganization(int $organizationId): void
    {
        $this->selectedOrganizationId = Organization::query()->whereKey($organizationId)->exists() ? $organizationId : null;
    }

    public function clearOrganizationSelection(): void
    {
        $this->selectedOrganizationId = null;
    }

    /**
     * Everything the detail panel shows for the selected organization: the
     * basics, plan state, a short member list and a platform-level history.
     * Like the activity feed it carries no tenant content (no project or
     * ticket names): that stays behind an audited support session.
     *
     * @return array<string, mixed>|null
     */
    public function organizationDetail(): ?array
    {
        if ($this->selectedOrganizationId === null) {
            return null;
        }

        $organization = Organization::query()
            ->withCount('users')
            ->with(['users' => fn ($query) => $query->wherePivot('role', 'owner')])
            ->with(['invitations' => fn ($query) => $query->pending()->where('role', 'owner')])
            ->find($this->selectedOrganizationId);

        if ($organization === null) {
            return null;
        }

        $timezone = config('app.display_timezone');
        $owner = $organization->users->first();
        $pendingOwnerInvite = $organization->invitations->first();
        $endsAt = $organization->trial_ends_at?->copy()->timezone($timezone);

        $state = match (true) {
            $organization->trial_ends_at === null => 'legacy',
            $organization->trial_ends_at->isFuture() => 'trial',
            $organization->isSubscribed() => 'subscribed',
            default => 'ended',
        };

        $daysLeft = $state === 'trial' ? max(0, (int) now($timezone)->startOfDay()->diffInDays($endsAt->copy()->startOfDay())) : null;

        [$chipLabel, $chipTone] = match ($state) {
            'legacy' => [__('Legacy'), 'blue'],
            'trial' => [__('Trial active (:days days left)', ['days' => $daysLeft]), 'green'],
            'subscribed' => [__('Subscribed'), 'green'],
            default => [__('Trial ended'), 'red'],
        };

        $plan = match ($state) {
            'legacy' => ['tone' => 'blue', 'title' => __('No time limit'), 'text' => __('This organization was grandfathered in and has no trial deadline.'), 'cta' => __('Set a trial period')],
            'trial' => ['tone' => 'yellow', 'title' => __('Trial in progress'), 'text' => __('The trial ends on :date.', ['date' => $endsAt->translatedFormat('d M Y')]), 'cta' => __('Extend trial')],
            'subscribed' => ['tone' => 'green', 'title' => __('Active subscription'), 'text' => __('This organization has an active subscription.'), 'cta' => null],
            default => ['tone' => 'red', 'title' => __('The trial has ended'), 'text' => __('This organization no longer has access. Extend the trial to let them back in, or wait for a subscription.'), 'cta' => __('Extend trial')],
        };

        $members = $organization->users()->withPivot('role')->orderBy('name')->limit(8)->get()
            ->map(fn (User $member) => ['name' => $member->name, 'email' => $member->email, 'role' => __(ucfirst((string) $member->getRelation('pivot')->role))])
            ->all();

        $fullAccess = $this->latestFullAccessGrant($organization);

        return [
            'id' => $organization->id,
            'name' => $organization->name,
            'initial' => mb_strtoupper(mb_substr($organization->name, 0, 1)),
            'tint' => ['blue', 'green', 'yellow', 'red'][$organization->id % 4],
            'owner_name' => $owner?->name ?? ($pendingOwnerInvite !== null ? __('Invitation pending') : __('No owner')),
            'owner_email' => $owner?->email ?? $pendingOwnerInvite?->email,
            'members_count' => $organization->users_count,
            'members' => $members,
            'members_more' => max(0, $organization->users_count - count($members)),
            'state' => $state,
            'chip_label' => $chipLabel,
            'chip_tone' => $chipTone,
            'ends_text' => $endsAt !== null ? __('Ends on :date', ['date' => $endsAt->translatedFormat('d M Y')]) : __('No time limit'),
            'full_access_label' => $this->fullAccessStatusLabel($organization),
            'full_access_note' => $this->fullAccessStatusDescription($organization),
            'full_access_tone' => $fullAccess === null ? 'gray' : match ($fullAccess->status()) {
                'requested' => 'yellow',
                'approved' => 'blue',
                'revoked', 'expired' => 'gray',
                default => 'green',
            },
            'created_at' => $organization->created_at?->copy()->timezone($timezone)->translatedFormat('d M Y H:i:s'),
            'plan' => $plan,
            'can_request_full_access' => $this->canRequestFullAccess($organization),
            'can_start_full_access' => $this->canStartFullAccess($organization),
            'activity' => $this->organizationHistory($organization),
        ];
    }

    /**
     * Platform-level history of one organization, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    private function organizationHistory(Organization $organization): array
    {
        /** @var SupportCollection<int, array<string, mixed>> $events */
        $events = collect([[
            'title' => __('Organization created'),
            'text' => $organization->name,
            'at' => $organization->created_at,
            'icon' => 'office-building',
            'tone' => 'blue',
        ]]);

        OrganizationInvitation::query()->where('organization_id', $organization->id)->latest()->limit(5)->get()
            ->each(function (OrganizationInvitation $invitation) use ($events) {
                $events->push(['title' => __('Invitation sent'), 'text' => __(ucfirst($invitation->role)), 'at' => $invitation->created_at, 'icon' => 'mail', 'tone' => 'gray']);

                if ($invitation->accepted_at !== null) {
                    $events->push(['title' => __('Invitation accepted'), 'text' => __(ucfirst($invitation->role)), 'at' => $invitation->accepted_at, 'icon' => 'user-add', 'tone' => 'green']);
                }
            });

        OrganizationSupportAccessGrant::query()->with(['requester', 'approver'])->where('organization_id', $organization->id)->latest('requested_at')->limit(3)->get()
            ->each(function (OrganizationSupportAccessGrant $grant) use ($events) {
                $events->push(['title' => __('Full Access request'), 'text' => __('By :name', ['name' => $grant->requester?->name ?? '-']), 'at' => $grant->requested_at, 'icon' => 'shield-check', 'tone' => 'yellow']);

                if ($grant->approved_at !== null) {
                    $events->push(['title' => __('Full Access approved'), 'text' => __('By :name', ['name' => $grant->approver?->name ?? '-']), 'at' => $grant->approved_at, 'icon' => 'shield-check', 'tone' => 'green']);
                }
            });

        OrganizationSupportSession::query()->with('superAdmin')->where('organization_id', $organization->id)->latest('started_at')->limit(3)->get()
            ->each(fn (OrganizationSupportSession $session) => $events->push([
                'title' => __('Support session started'),
                'text' => __('By :name', ['name' => $session->superAdmin?->name ?? '-']),
                'at' => $session->started_at,
                'icon' => 'eye',
                'tone' => 'purple',
            ]));

        return $events->filter(fn (array $event) => $event['at'] !== null)->sortByDesc('at')->take(8)->values()->all();
    }

    protected function getTableRecordActionUsing(): ?Closure
    {
        return fn (): string => 'selectOrganization';
    }

    protected function getTableRecordClassesUsing(): ?Closure
    {
        return fn (Organization $record): ?string => $record->id === $this->selectedOrganizationId ? 'rk-pl__row-selected' : null;
    }

    protected function getTableDescription(): ?string
    {
        return __('Click a row to see the organization\'s details.');
    }

    /**
     * Every Organization in the installation, regardless of the acting
     * user's own membership — the entire point of this page. `withCount`
     * avoids an N+1 for the member-count column; the `users` eager load is
     * pre-filtered to the owner pivot row only, so the owner column below
     * reads it without a second query per row.
     */
    protected function getTableQuery(): Builder
    {
        return Organization::query()
            ->withCount('users')
            ->with(['users' => fn ($query) => $query->wherePivot('role', 'owner')])
            // A pending Owner invitation only ever exists while the
            // organization has no Owner yet (createOrganizationWithInvitedOwner()
            // never attaches one until acceptance) — eager loaded here so
            // the Owner column and the revoke action below read it without
            // a second query per row.
            ->with(['invitations' => fn ($query) => $query->pending()->where('role', 'owner')])
            // Trials closest to running out (or already expired) float to the
            // top; grandfathered organizations (trial_ends_at is null) have
            // no urgency at all, so they sort last regardless of direction.
            ->orderByRaw('trial_ends_at is null')
            ->orderBy('trial_ends_at');
    }

    protected function getTableColumns(): array
    {
        return [
            Tables\Columns\TextColumn::make('name')
                ->label(__('Organization'))
                ->html()
                ->formatStateUsing(fn (string $state, Organization $record) => new HtmlString(
                    '<span class="rk-pl__org"><i class="rk-pl__tile rk-pf__tile--'.['blue', 'green', 'yellow', 'red'][$record->id % 4].'">'
                    .e(mb_strtoupper(mb_substr($state, 0, 1))).'</i><b>'.e($state).'</b></span>'
                ))
                ->searchable()
                ->sortable(),

            Tables\Columns\TextColumn::make('owner')
                ->label(__('Owner'))
                ->getStateUsing(function (Organization $record) {
                    if ($owner = $record->users->first()) {
                        return $owner->name;
                    }

                    return $record->invitations->first() !== null
                        ? __('Invitation pending')
                        : __('No owner');
                })
                // HtmlString on purpose: Filament runs plain-string descriptions through
                // Markdown, which turns an e-mail address into a mailto: link inside a
                // row that is itself clickable (the click opened the mail app).
                ->description(function (Organization $record) {
                    $email = $record->users->first()?->email ?? $record->invitations->first()?->email;

                    return $email === null ? null : new HtmlString(e($email));
                })
                ->color(fn (Organization $record) => $record->users->isEmpty() && $record->invitations->isNotEmpty()
                    ? 'warning'
                    : null),

            Tables\Columns\TextColumn::make('users_count')
                ->label(__('Members'))
                ->sortable(),

            Tables\Columns\TextColumn::make('trial_status')
                ->label(__('Trial status'))
                ->getStateUsing(fn (Organization $record) => $this->trialStatusLabel($record))
                ->html()
                ->formatStateUsing(function (string $state, Organization $record) {
                    $legacy = $record->trial_ends_at === null;

                    return new HtmlString(
                        '<span title="'.e($state).'" class="rk-og__chip rk-og__chip--'.($legacy ? 'blue' : ['success' => 'green', 'danger' => 'red', 'gray' => 'gray'][$this->trialStatusColor($record)]).'">'
                        .e($legacy ? __('Legacy') : $state).'</span>'
                    );
                }),

            // Phase 7 (Full Access UI/UX Gate). Reads through
            // latestFullAccessGrant() rather than an eager-loaded relation:
            // this table is small in practice (one row per Organization in
            // the whole installation, same assumption the rest of this page
            // already makes — see getTableQuery()'s own eager loads for
            // comparison), and a per-row grant lookup keeps this column's
            // logic in one place instead of teaching Organization a new
            // relation only this column would ever use.
            Tables\Columns\TextColumn::make('full_access_status')
                ->label(__('Full Access'))
                ->getStateUsing(fn (Organization $record) => $this->fullAccessStatusLabel($record))
                ->description(function (Organization $record) {
                    $note = $this->fullAccessStatusDescription($record);

                    return $note === null ? null : new HtmlString(e($note));
                }),

            Tables\Columns\TextColumn::make('created_at')
                ->label(__('Created at'))
                ->html()
                ->formatStateUsing(function ($state) {
                    $at = Carbon::parse($state)->timezone(config('app.display_timezone'));

                    return new HtmlString('<span class="rk-pl__date"><b>'.e($at->translatedFormat('d M Y')).'</b><small>'.e($at->format('H:i:s')).'</small></span>');
                })
                ->sortable(),
        ];
    }

    protected function getTableHeading(): ?string
    {
        return __('Organizations');
    }

    protected function getTableEmptyStateHeading(): ?string
    {
        return __('No organizations yet');
    }

    /**
     * Manual provisioning — e.g. an enterprise deal onboarded by hand
     * instead of self-serve signup. Mirrors CreateOrganization's own
     * create+owner+defaults transaction (Phase 5.2) exactly, except the
     * owner is a Select instead of always auth()->id(), since here it is
     * the Super Admin acting on someone else's behalf.
     */
    protected function getTableHeaderActions(): array
    {
        return [
            Tables\Actions\Action::make('createOrganization')
                ->label(__('Create organization'))
                ->icon('heroicon-o-plus')
                ->modalHeading(__('Create organization'))
                ->form([
                    TextInput::make('name')
                        ->label(__('Organization name'))
                        ->required()
                        ->maxLength(255),

                    // Two ways to name an Owner: pick someone who already
                    // has an account (existing behaviour), or hand the
                    // Super Admin an email for someone who doesn't yet —
                    // e.g. onboarding an enterprise customer by hand before
                    // they have ever touched the product. The picked mode
                    // only decides which fields below are shown/required;
                    // the action() closure re-derives everything from
                    // $data itself, never from which fields the form
                    // happened to render (same discipline as owner_id's
                    // own re-resolution below).
                    // Deliberately not ->required(): its own ->default()
                    // only applies once Livewire actually mounts the form
                    // (real usage always has it pre-selected), but a test
                    // driving callTableAction() with an explicit $data
                    // array bypasses that mount lifecycle - the action()
                    // closure below already treats a missing value the
                    // same as 'existing' via `?? 'existing'`, so gating
                    // submission on this field being non-empty would only
                    // ever reject requests that never actually omit a
                    // real choice in the browser.
                    Radio::make('owner_mode')
                        ->label(__('Owner'))
                        ->options([
                            'existing' => __('Choose an existing user'),
                            'invite' => __('Invite someone new by email'),
                        ])
                        ->default('existing')
                        ->reactive(),

                    Select::make('owner_id')
                        ->label(__('Owner'))
                        ->visible(fn (Closure $get) => $get('owner_mode') !== 'invite')
                        ->required(fn (Closure $get) => $get('owner_mode') !== 'invite')
                        ->searchable()
                        ->getSearchResultsUsing(fn (string $search) => User::query()
                            ->where(fn (Builder $query) => $query
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%"))
                            ->orderBy('name')
                            ->limit(50)
                            ->get()
                            ->mapWithKeys(fn (User $user) => [$user->id => "{$user->name} ({$user->email})"]))
                        ->getOptionLabelUsing(fn ($value) => ($user = User::find($value))
                            ? "{$user->name} ({$user->email})"
                            : null)
                        ->helperText(__("This user becomes the organization's initial Owner.")),

                    TextInput::make('invite_name')
                        ->label(__('Full name'))
                        ->visible(fn (Closure $get) => $get('owner_mode') === 'invite')
                        ->required(fn (Closure $get) => $get('owner_mode') === 'invite')
                        ->maxLength(255),

                    TextInput::make('invite_email')
                        ->label(__('Email'))
                        ->email()
                        ->visible(fn (Closure $get) => $get('owner_mode') === 'invite')
                        ->required(fn (Closure $get) => $get('owner_mode') === 'invite')
                        ->maxLength(255)
                        ->helperText(__('An invitation link is emailed to them — they become Owner once they accept. If this email already has an account, they are made Owner immediately instead.')),

                    DatePicker::make('trial_ends_at')
                        ->label(__('Trial ends at'))
                        ->helperText(__('Leave blank for an unlimited (grandfathered) organization.')),
                ])
                ->action(function (array $data): void {
                    $name = trim((string) $data['name']);

                    if ($name === '') {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('The organization name must not be blank.'),
                        ]);
                    }

                    if (Organization::where('name', $name)->exists()) {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('This organization name is already taken.'),
                        ]);
                    }

                    try {
                        if (($data['owner_mode'] ?? 'existing') === 'invite') {
                            $this->createOrganizationWithInvitedOwner($name, $data);
                        } else {
                            $this->createOrganizationWithExistingOwner($name, $data);
                        }
                    } catch (QueryException $exception) {
                        // organizations.name is unique at the database level
                        // too — the final backstop against a race with the
                        // check above, same discipline as CreateOrganization.
                        if ($exception->getCode() !== '23000') {
                            throw $exception;
                        }

                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('This organization name is already taken.'),
                        ]);
                    }
                }),
        ];
    }

    /**
     * The "pick someone who already has an account" branch — unchanged
     * from before the invite-by-email option existed, just extracted so
     * the action() closure above only has to decide which branch to run.
     */
    private function createOrganizationWithExistingOwner(string $name, array $data): void
    {
        $owner = User::find($data['owner_id'] ?? null);

        if ($owner === null) {
            throw ValidationException::withMessages([
                'mountedTableActionData.owner_id' => __('Please choose an owner.'),
            ]);
        }

        DB::transaction(function () use ($name, $owner, $data) {
            $organization = Organization::create([
                'name' => $name,
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
            ]);
            $organization->users()->attach($owner->id, ['role' => 'owner']);
            OrganizationDefaults::seed($organization);
            $this->assignOwnerAccessRoleIfNone($owner);
        });

        Notification::make()
            ->title(__('Organization created'))
            ->success()
            ->send();
    }

    /**
     * The "invite someone new by email" branch. If the email already
     * belongs to an account, this short-circuits to the exact same
     * immediate-attach behaviour as the existing-owner branch — an
     * invitation would only make them wait on an email they don't need to
     * open, mirroring the precedent OrganizationSettings::addExistingUser()
     * already sets for "existing account short-circuits the invite".
     *
     * Otherwise, the Organization is created right away (so it is visible
     * in the table immediately, with the Owner column showing the pending
     * invitation — see getTableColumns()) but nobody is attached as Owner
     * yet; App\Http\Livewire\AcceptOrganizationInvitation attaches them at
     * acceptance, exactly like every other invitation in this app. Sending
     * role: 'owner' here is safe specifically because this page never
     * calls OrganizationPolicy::addMember() (the ability that hard-blocks
     * 'owner' — see that method's own docblock) — this is Super Admin's own,
     * separate authorization path (see this class's own docblock), and
     * AcceptOrganizationInvitation::accept() itself trusts whatever role an
     * invitation carries without re-checking that allow-list.
     */
    private function createOrganizationWithInvitedOwner(string $name, array $data): void
    {
        $email = trim((string) ($data['invite_email'] ?? ''));
        $inviteName = trim((string) ($data['invite_name'] ?? ''));

        if ($email === '') {
            throw ValidationException::withMessages([
                'mountedTableActionData.invite_email' => __('Please enter an email address.'),
            ]);
        }

        if ($inviteName === '') {
            throw ValidationException::withMessages([
                'mountedTableActionData.invite_name' => __('Please enter a full name.'),
            ]);
        }

        $existingUser = User::where('email', $email)->first();

        if ($existingUser !== null) {
            DB::transaction(function () use ($name, $existingUser, $data) {
                $organization = Organization::create([
                    'name' => $name,
                    'trial_ends_at' => $data['trial_ends_at'] ?? null,
                ]);
                $organization->users()->attach($existingUser->id, ['role' => 'owner']);
                OrganizationDefaults::seed($organization);
                $this->assignOwnerAccessRoleIfNone($existingUser);
            });

            Notification::make()
                ->title(__('Organization created'))
                ->body(__('This email already had an account, so they were made Owner immediately.'))
                ->success()
                ->send();

            return;
        }

        $accessRole = Role::where('name', 'Owner')->first();
        $plainToken = OrganizationInvitation::generateToken();

        $invitation = DB::transaction(function () use ($name, $data, $inviteName, $email, $accessRole, $plainToken) {
            $organization = Organization::create([
                'name' => $name,
                'trial_ends_at' => $data['trial_ends_at'] ?? null,
            ]);
            OrganizationDefaults::seed($organization);

            return OrganizationInvitation::create([
                'organization_id' => $organization->id,
                'name' => $inviteName,
                'email' => $email,
                'role' => 'owner',
                'access_role_id' => $accessRole?->id,
                'token_hash' => OrganizationInvitation::hashToken($plainToken),
                'expires_at' => now()->addDays(OrganizationInvitation::LIFETIME_DAYS),
                'created_by' => auth()->id(),
            ]);
        });

        // Mailed only after the transaction above has committed (the
        // notification itself is queued with afterCommit — see
        // OrganizationInvitationCreated — this call site keeps the same
        // discipline of never issuing outside I/O from inside the DB
        // transaction that could still roll back).
        NotificationFacade::route('mail', $email)
            ->notify(new OrganizationInvitationCreated($invitation, $plainToken));

        Notification::make()
            ->title(__('Organization created — invitation sent'))
            ->success()
            ->send();
    }

    protected function getTableActions(): array
    {
        return [
            // Row click (getTableRecordActionUsing) and the first icon in the row bar.
            Tables\Actions\Action::make('selectOrganization')
                ->label(__('Details'))
                ->icon('heroicon-o-information-circle')
                ->action(fn (Organization $record) => $this->selectOrganization($record->id)),

            Tables\Actions\Action::make('editOrganization')
                ->label(__('Edit'))
                ->icon('heroicon-o-pencil')
                ->modalHeading(__('Edit organization'))
                // Owner reassignment deliberately has no field here —
                // ownership transfer is a separate, not-yet-built feature
                // (same boundary OrganizationPolicy's addMember() docblock
                // already draws), not a side effect of an unrelated edit.
                // Filament 2's table actions have no fillForm() (a Filament
                // 3 API) — each field reads the record's current value via
                // its own ->default(), the same pattern changeRole() in
                // OrganizationSettings already uses.
                ->form([
                    TextInput::make('name')
                        ->label(__('Organization name'))
                        ->required()
                        ->maxLength(255)
                        ->default(fn (Organization $record) => $record->name),

                    DatePicker::make('trial_ends_at')
                        ->label(__('Trial ends at'))
                        ->helperText(__('Leave blank for an unlimited (grandfathered) organization.'))
                        ->default(fn (Organization $record) => $record->trial_ends_at),
                ])
                ->action(function (Organization $record, array $data): void {
                    $name = trim((string) $data['name']);

                    if ($name === '') {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('The organization name must not be blank.'),
                        ]);
                    }

                    if (Organization::where('name', $name)->whereKeyNot($record->id)->exists()) {
                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('This organization name is already taken.'),
                        ]);
                    }

                    try {
                        $record->update([
                            'name' => $name,
                            'trial_ends_at' => $data['trial_ends_at'] ?? null,
                        ]);
                    } catch (QueryException $exception) {
                        if ($exception->getCode() !== '23000') {
                            throw $exception;
                        }

                        throw ValidationException::withMessages([
                            'mountedTableActionData.name' => __('This organization name is already taken.'),
                        ]);
                    }

                    Notification::make()
                        ->title(__('Organization updated'))
                        ->success()
                        ->send();
                }),

            // Read-only-by-construction support access (see
            // App\Support\SupportSessionContext and
            // App\Filament\Pages\OrganizationSupportView's own docblocks):
            // no write UI in this app has a Super Admin bypass, so a page
            // that only ever reads through this session is automatically
            // safe without inventing a new Gate::before rule. $data['reason']
            // is required below, so there is always an audit trail for why
            // this Organization was accessed.
            //
            // Phase 1 of Support Action: the `level` field lets the Super
            // Admin pick Read Only vs Support Action up front, defaulting
            // to Read Only so choosing nothing changes nothing about
            // today's behavior. Support Action carries no extra capability
            // yet — OrganizationSupportView stays 100% read-only regardless
            // of which level is picked here until Phase 3 builds the
            // write-authorization layer on top of it.
            Tables\Actions\Action::make('startSupportSession')
                ->label(__('Masuk sebagai Support'))
                ->icon('heroicon-o-eye')
                ->form([
                    Textarea::make('reason')
                        ->label(__('Alasan'))
                        ->required()
                        ->maxLength(500),

                    // Deliberately not ->required(): same reasoning as
                    // owner_mode above on createOrganization's form — its
                    // ->default() only applies once Livewire actually
                    // mounts the form (real usage always has Read Only
                    // pre-selected), but a test driving
                    // callTableAction() with an explicit $data array
                    // bypasses that mount lifecycle entirely. The
                    // action() closure's own `?? LEVEL_READ_ONLY` below
                    // already treats a missing value the same as picking
                    // Read Only, so gating submission on this field being
                    // non-empty would only ever reject requests that never
                    // actually omit a real choice in the browser.
                    Radio::make('level')
                        ->label(__('Support level'))
                        ->options([
                            OrganizationSupportSession::LEVEL_READ_ONLY => __('Support level: Read Only'),
                            OrganizationSupportSession::LEVEL_SUPPORT_ACTION => __('Support level: Support Action'),
                        ])
                        ->default(OrganizationSupportSession::LEVEL_READ_ONLY),
                ])
                ->requiresConfirmation()
                ->action(function (Organization $record, array $data): void {
                    SupportSessionContext::start(
                        auth()->user(),
                        $record,
                        $data['reason'],
                        $data['level'] ?? OrganizationSupportSession::LEVEL_READ_ONLY
                    );

                    Notification::make()
                        ->title(__('Sesi support dimulai.'))
                        ->success()
                        ->send();

                    $this->redirect(OrganizationSupportView::getUrl());
                }),

            // Phase 7 (Full Access UI/UX Gate) — first step of the separate
            // Full Access flow (App\Support\SupportSessionContext's own
            // docblock section on the grant lifecycle). Deliberately its
            // own action rather than a third Radio option on
            // startSupportSession above: LEVEL_FULL_ACCESS can never be
            // reached through SupportSessionContext::start() (see that
            // method's own docblock), so offering it in that form would be
            // a UI promise the backend cannot keep — a Full Access session
            // only ever begins by consuming an Owner-approved grant, which
            // is what startFullAccess below actually does.
            Tables\Actions\Action::make('requestFullAccess')
                ->label(__('Request Full Access'))
                ->icon('heroicon-o-key')
                ->modalHeading(__('Request Full Access'))
                ->visible(fn (Organization $record) => $this->canRequestFullAccess($record))
                ->form([
                    Textarea::make('reason')
                        ->label(__('Alasan'))
                        ->required()
                        ->maxLength(500),

                    // Static, informational only — never a field the actor
                    // picks from and never written anywhere. See
                    // SupportSessionContext::isFullAccessCapabilityAllowed()'s
                    // own docblock: it is a fail-closed allowlist that is
                    // always empty today, by deliberate product decision,
                    // not a placeholder oversight, and there is no
                    // `scope`/`capability` column on organization_support_
                    // access_grants to persist a choice into even if this
                    // were made selectable.
                    Placeholder::make('scope')
                        ->label(__('Scope'))
                        ->content(__('Belum ada kapabilitas yang disetujui untuk Full Access.')),
                ])
                ->requiresConfirmation()
                ->action(function (Organization $record, array $data): void {
                    try {
                        SupportSessionContext::requestFullAccess(auth()->user(), $record, $data['reason']);
                    } catch (HttpException $exception) {
                        // requestFullAccess() aborts with 409 when this
                        // organization already has an active (REQUESTED or
                        // APPROVED-and-unconsumed) grant — translated into a
                        // friendly notification instead of Symfony's raw
                        // error page, the same way createOrganization above
                        // turns a unique-name QueryException into a
                        // validation message rather than letting it surface
                        // raw.
                        if ($exception->getStatusCode() !== 409) {
                            throw $exception;
                        }

                        Notification::make()
                            ->title(__('Organisasi ini sudah punya permintaan Full Access yang aktif.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title(__('Permintaan Full Access dikirim ke Owner organisasi.'))
                        ->success()
                        ->send();
                }),

            // Second step of the Full Access flow: only reachable once the
            // Organization's Owner has approved the request above. No grant
            // id is ever sent to the browser for this action to echo back —
            // latestFullAccessGrant() re-derives the relevant grant
            // server-side, scoped to $record (a Filament-resolved,
            // trusted Organization, not client-supplied state), both for
            // ->visible() and again inside ->action() itself, so a stale
            // rendered button (the grant expired/was revoked a moment
            // after page load) cannot be clicked into a stale action.
            Tables\Actions\Action::make('startFullAccess')
                ->label(__('Start Full Access'))
                ->icon('heroicon-o-lock-open')
                ->color('danger')
                ->requiresConfirmation()
                ->modalSubheading(__('This begins a Full Access support session for this organization.'))
                ->visible(fn (Organization $record) => $this->canStartFullAccess($record))
                ->action(function (Organization $record): void {
                    $grant = $this->latestFullAccessGrant($record);

                    // Defense in depth, not the real gate: consumeFullAccessGrant()
                    // re-verifies isSuperAdmin(), the requester match, and
                    // isApproved() itself (see its own docblock) — this
                    // abort only fails fast, before even attempting the
                    // call, for the same reasons ->visible() above hides
                    // the button in the first place.
                    abort_unless($grant !== null && $grant->requested_by === auth()->id(), 403);

                    SupportSessionContext::consumeFullAccessGrant(auth()->user(), $grant);

                    Notification::make()
                        ->title(__('Full Access session dimulai.'))
                        ->success()
                        ->send();

                    $this->redirect(OrganizationSupportView::getUrl());
                }),

            // Lets the requesting Super Admin withdraw their own request
            // before it is consumed (Architecture Design Phase 2, Open
            // Question #3 — see SupportSessionContext::revokeFullAccessGrant()'s
            // own docblock on why self-cancellation is allowed). Same
            // "never trust a client-supplied grant id" discipline as
            // startFullAccess above: the grant is re-derived from $record,
            // never read from form/session state.
            Tables\Actions\Action::make('cancelFullAccessRequest')
                ->label(__('Cancel request'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Organization $record) => $this->canCancelFullAccessRequest($record))
                ->action(function (Organization $record): void {
                    $grant = $this->latestFullAccessGrant($record);

                    if ($grant === null) {
                        return;
                    }

                    SupportSessionContext::revokeFullAccessGrant(auth()->user(), $grant);

                    Notification::make()
                        ->title(__('Permintaan Full Access dibatalkan.'))
                        ->success()
                        ->send();
                }),

            // Only ever shown while an Organization has no Owner yet and a
            // pending invitation is the reason why (getTableColumns()'s
            // Owner column renders the same condition as "Invitation
            // pending") — lets the Super Admin free up the email (e.g. it
            // was mistyped) without waiting for the 7-day expiry.
            Tables\Actions\Action::make('revokeOwnerInvitation')
                ->label(__('Revoke invitation'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (Organization $record) => $record->users->isEmpty() && $record->invitations->isNotEmpty())
                ->action(function (Organization $record): void {
                    $invitation = $record->invitations->first();

                    if ($invitation === null) {
                        return;
                    }

                    OrganizationInvitation::whereKey($invitation->id)
                        ->whereNull('accepted_at')
                        ->whereNull('revoked_at')
                        ->update(['revoked_at' => now()]);

                    Notification::make()
                        ->title(__('Invitation revoked'))
                        ->success()
                        ->send();
                }),

            Tables\Actions\Action::make('deleteOrganization')
                ->label(__('Delete'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->modalHeading(__('Delete organization?'))
                // Not record-scoped ($record isn't resolvable yet at the
                // point Filament 2 evaluates shouldOpenModal() for a table
                // action - only ->default() form fields and ->action()
                // itself receive the mounted record) - kept generic instead
                // of interpolating the organization's name.
                ->modalSubheading(__('This permanently removes its memberships, invitations, and organization-scoped reference data (ticket types, priorities, statuses, labels, activities). An organization that still has projects cannot be deleted — reassign or delete them first.'))
                ->modalButton(__('Delete'))
                ->action(function (Organization $record): void {
                    // withTrashed() because a soft-deleted Project's
                    // status_id/priority_id/type_id foreign keys still point
                    // at this organization's lookup rows, so the cascade
                    // below would still violate them otherwise. This is the
                    // real guard (not just a friendlier message) — every
                    // Ticket lives under a Project, and every Project under
                    // this Organization, so zero Projects (trashed included)
                    // means nothing else can hold a foreign key into the
                    // lookup rows this delete cascades through.
                    if ($record->projects()->withTrashed()->exists()) {
                        Notification::make()
                            ->title(__('Cannot delete an organization that still has projects'))
                            ->body(__('Reassign or delete its projects first, then try again.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    $record->delete();

                    Notification::make()
                        ->title(__('Organization deleted'))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * organization_users.role='owner' (set right above this call) is the
     * tenant/authority axis; it grants nothing in Filament by itself -
     * User::canAccessFilament() gates purely on Spatie roles()->exists(),
     * same as OrganizationSettings::addExistingUser() already has to
     * account for. Unlike that method, this one never risks overwriting an
     * *active* Role: Spatie roles are global per-user (not Organization-
     * scoped - Teams was rejected, see ADR 0001), so this only ever fires
     * for a user picked here who has no Role at all yet - otherwise a
     * Super Admin manually onboarding an enterprise customer could hand
     * that picked "Owner" a brand-new user shell that can't even log in.
     * A missing/renamed "Owner" Role (OrganizationAccessRoleSeeder) simply
     * leaves the user role-less, exactly like accessRoleFor() elsewhere -
     * never an error, since granting no permissions is always the safe
     * failure mode here.
     */
    private function assignOwnerAccessRoleIfNone(User $owner): void
    {
        if ($owner->roles()->exists()) {
            return;
        }

        $role = Role::where('name', 'Owner')->first();

        if ($role !== null && ! $role->isSuperAdminRole()) {
            $owner->assignRole($role);
        }
    }

    /**
     * Mirrors App\Support\TrialGate::active()'s own three-way read of
     * trial_ends_at/isSubscribed(), rendered as the label a human needs
     * instead of a boolean. Calendar-day (not wall-clock) difference, so the
     * "N hari lagi" count does not depend on what time of day this page
     * happens to be rendered.
     */
    private function trialStatusLabel(Organization $organization): string
    {
        if ($organization->trial_ends_at === null) {
            return __('Grandfathered');
        }

        if ($organization->trial_ends_at->isFuture()) {
            $days = now()->startOfDay()->diffInDays($organization->trial_ends_at->copy()->startOfDay());

            return __('Trial active (:days days left)', ['days' => $days]);
        }

        // Past its trial window: TrialGate::active() is what the rest of the
        // app actually gates access on, so this reads the same source of
        // truth rather than re-deciding "expired but subscribed" locally.
        // Reachable as soon as an Organization has an active Subscription
        // row (Fase 7) — Fase 8-9 (payment/billing) is what will actually
        // create one.
        return TrialGate::active($organization) ? __('Subscribed') : __('Trial ended');
    }

    private function trialStatusColor(Organization $organization): string
    {
        if ($organization->trial_ends_at === null) {
            return 'gray';
        }

        if ($organization->trial_ends_at->isFuture()) {
            return 'success';
        }

        return $organization->isSubscribed() ? 'success' : 'danger';
    }

    // -------------------------------------------------- Full Access (Phase 7)

    /**
     * The most recent Full Access grant for $organization, regardless of
     * its state — every UI element that touches Full Access below (the
     * status column, and each of the three actions' own ->visible()
     * conditions and ->action() closures) reads through this single query
     * rather than separately re-deriving "which grant is the relevant one
     * right now". A fresh, unmemoized query every call — this page holds
     * no Livewire property caching it across requests, so a grant the
     * Owner just approved in another tab is reflected the moment this
     * table next re-renders, the same "never a cached/session value"
     * discipline OrganizationContext::current() and
     * SupportSessionContext::current() already apply elsewhere.
     */
    private function latestFullAccessGrant(Organization $organization): ?OrganizationSupportAccessGrant
    {
        return OrganizationSupportAccessGrant::query()
            ->where('organization_id', $organization->id)
            ->latest('requested_at')
            ->first();
    }

    /**
     * requestFullAccess() itself is the real gate (it 409s on an active
     * grant, re-checked under lockForUpdate() — see its own docblock) —
     * this is UX only, so the action isn't offered at all for an
     * organization that visibly already has one in flight. A grant that
     * is consumed, revoked, or expired leaves the organization free to be
     * requested again, mirroring requestFullAccess()'s own "no active
     * grant" definition.
     */
    private function canRequestFullAccess(Organization $organization): bool
    {
        $grant = $this->latestFullAccessGrant($organization);

        return $grant === null || in_array($grant->status(), ['consumed', 'revoked', 'expired'], true);
    }

    /**
     * Mirrors consumeFullAccessGrant()'s own actor-integrity guarantee
     * (only the original requester may ever consume their own approved
     * grant — see that method's docblock): the button itself is hidden for
     * every other Super Admin, not just refused on click.
     */
    private function canStartFullAccess(Organization $organization): bool
    {
        $grant = $this->latestFullAccessGrant($organization);

        return $grant !== null
            && $grant->status() === 'approved'
            && $grant->requested_by === auth()->id();
    }

    /**
     * revokeFullAccessGrant() allows either the Owner or the original
     * requester to cancel, but this page only ever acts as the requester's
     * side of that relationship (the Owner's side lives on
     * OrganizationSettings) — so only requested/approved grants owned by
     * the current Super Admin show a Cancel button here.
     */
    private function canCancelFullAccessRequest(Organization $organization): bool
    {
        $grant = $this->latestFullAccessGrant($organization);

        return $grant !== null
            && in_array($grant->status(), ['requested', 'approved'], true)
            && $grant->requested_by === auth()->id();
    }

    private function fullAccessStatusLabel(Organization $organization): string
    {
        $grant = $this->latestFullAccessGrant($organization);

        if ($grant === null) {
            return __('No request');
        }

        return match ($grant->status()) {
            'requested' => __('Requested'),
            'approved' => __('Approved — ready to start'),
            'consumed' => $this->fullAccessSessionStatusLabel($grant),
            'revoked' => __('Revoked'),
            default => __('Expired'),
        };
    }

    /**
     * A consumed grant's own status() never changes again (consumed_at is
     * permanent), so once consumed the more useful thing to show is
     * whatever the session it produced is doing right now — active, ended
     * (Super Admin stopped it or started a different one), or expired
     * (its own one-hour window lapsed) — read from
     * OrganizationSupportSession::isActive()/ended_at directly rather than
     * adding a parallel status method there for a single call site.
     */
    private function fullAccessSessionStatusLabel(OrganizationSupportAccessGrant $grant): string
    {
        $session = $grant->session;

        if ($session === null) {
            return __('Consumed');
        }

        if ($session->ended_at !== null) {
            return __('Session ended');
        }

        return $session->isActive() ? __('Full Access session active') : __('Session expired');
    }

    private function fullAccessStatusDescription(Organization $organization): ?string
    {
        $grant = $this->latestFullAccessGrant($organization);

        if ($grant === null) {
            return null;
        }

        $parts = [__('Requested by :name', ['name' => $grant->requester?->name ?? '—'])];

        if ($grant->approved_at !== null) {
            $parts[] = __('Approved by :name', ['name' => $grant->approver?->name ?? '—']);
        }

        if ($grant->status() === 'approved') {
            $parts[] = __('Expires :date', ['date' => $grant->grant_expires_at->diffForHumans()]);
        } elseif ($grant->status() === 'requested') {
            $parts[] = __('Expires :date', ['date' => $grant->request_expires_at->diffForHumans()]);
        }

        return implode(' · ', $parts);
    }
}
