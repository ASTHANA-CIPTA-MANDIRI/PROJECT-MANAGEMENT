<?php

namespace App\Filament\Pages;

use App\Models\Epic;
use App\Models\Label;
use App\Models\Organization;
use App\Models\OrganizationSupportAction;
use App\Models\OrganizationSupportSession;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\Sprint;
use App\Models\Ticket;
use App\Models\TicketActivity;
use App\Models\TicketPriority;
use App\Models\TicketStatus;
use App\Support\SupportSessionContext;
use App\Support\TrialGate;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;

/**
 * The Super Admin's window into an Organization's data while a support
 * session (App\Support\SupportSessionContext) is active — the companion page
 * PlatformOrganizations::startSupportSession redirects into. Read-only for a
 * LEVEL_READ_ONLY session; a LEVEL_SUPPORT_ACTION session additionally gets
 * the small, explicit set of write actions defined below
 * (changeTicketStatus() from Phase 4, changeTicketPriority() from Phase 6A,
 * changeTicketResponsible() from Phase 6B, changeTicketDueDate() from
 * Phase 6C, changeTicketTitle() from Phase 6D, changeTicketLabels() from
 * Phase 6E, changeProjectStatus()/sprintStart()/sprintStop()/
 * changeSprintDates() from Phase 6G — nothing else yet. Phase 6F (Ticket
 * content) was evaluated and deliberately deferred, not implemented:
 * organization_support_actions.old_value/new_value are VARCHAR(255)
 * columns, and Ticket::$content is unbounded rich-text HTML — a full-
 * content audit trail needs a schema decision (widen the columns, or
 * record a weaker summary instead of the full value) this class does not
 * make unilaterally.
 *
 * deleteTicket()/restoreTicket() (Phase 11) are a different shape from
 * every write method above: the first Destructive Category A capability,
 * and deliberately Full-Access-only rather than Support-Action-reachable —
 * see their own docblocks for why they bypass
 * SupportSessionContext::authorizeCapability() entirely and call
 * authorizeFullAccess() directly instead. deleteSprint()/restoreSprint()
 * (Phase 12, Destructive Category B) follow that exact same shape for
 * Sprint — see their own docblocks for the Epic-mirror side effect
 * restoreSprint() has to account for that deleteTicket()/restoreTicket()
 * never had to. deleteProject()/restoreProject() (Phase 13, the highest-
 * blast-radius pair yet) follow the identical Full-Access-only shape once
 * more, but never reimplement App\Observers\ProjectObserver's own Ticket/
 * Sprint/Epic cascade — they stay thin authorize → resolve → mutate → audit
 * wrappers around plain $project->delete()/restore(), and audit the cascade
 * as one bounded summary row rather than one row per cascaded child — see
 * deleteProject()'s own docblock for the full reasoning.
 *
 * bulkDeleteTicket()/bulkRestoreTicket()/bulkDeleteSprint()/bulkRestoreSprint()
 * (Phase 14, the last destructive category) apply deleteTicket()'s/
 * restoreTicket()'s/deleteSprint()'s/restoreSprint()'s exact single-target
 * mutation to a whole client-selected batch at once, under an explicit
 * ALL-OR-NOTHING contract: one target anywhere in the batch that fails to
 * resolve (not found, wrong organization, or the wrong precondition state
 * for the action) rejects the entire batch rather than silently applying to
 * a valid subset — the same discipline changeTicketLabels() already applies
 * to a single ticket's label set, extended here across an entire selection.
 * Every batch is resolved and validated twice: once before the transaction
 * opens (so an invalid batch fails fast without ever writing anything), and
 * again from scratch immediately inside DB::transaction(), right before any
 * mutation runs — the second pass is what the mutation actually trusts, not
 * the first; see resolveBulkTickets()'s own docblock for why. Bulk project
 * delete/restore is deliberately out of scope for this phase.
 *
 * Every read query filters by this organization's id at the database level
 * (never Model::all() + a PHP-side filter). It reads $organization->projects,
 * ->tickets(), ->sprints() and ->users() directly rather than
 * Project::accessibleBy()/Ticket::scopeVisibleTo()/etc., which is
 * intentional and safe specifically *because* the only write path is the one
 * defined here, which re-verifies organization/level itself rather than
 * leaning on those scopes — see
 * docs/adr/0001-hybrid-multi-tenant-authorization.md's addendum after
 * Scenario D for why this is a separate side-channel from
 * App\Support\OrganizationContext rather than a bypass added to it.
 *
 * Every public method that takes an identifier derived from client input
 * (sprintStatusBreakdown()'s Sprint parameter, changeTicketStatus()'s
 * $ticketId/$newStatusId) re-verifies it against the active session's
 * organization itself — Livewire can be asked to call any public method
 * directly with a client-supplied value (Livewire\ImplicitlyBoundMethod
 * resolves a typed Model parameter the same way route-model-binding does,
 * and a plain int/string parameter is trusted no further than any other
 * client input), so a method cannot rely on only ever being reached through
 * an already-scoped list rendered in Blade.
 *
 * Access requires both an active support session *and* isSuperAdmin() —
 * userCanAccessPage() is re-run on every Livewire request by
 * AuthorizesPageAccess (see AuthorizedPage), so ending the session (or
 * letting it expire) immediately locks this page — and everything below —
 * out again. Level (Read Only vs Support Action) is a second, narrower gate
 * checked inside changeTicketStatus() itself via
 * SupportSessionContext::authorizeAction() — a Read Only session still opens
 * this page (unchanged from Phase 1), it simply cannot reach that method
 * successfully.
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

    /**
     * Phase 14 — the maximum number of ids a single bulk call
     * (bulkDeleteTicket()/bulkRestoreTicket()/bulkDeleteSprint()/
     * bulkRestoreSprint()) will accept. This page's own UI can never submit
     * more than a small handful in practice — tickets() itself is capped at
     * RECENT_TICKETS_LIMIT (25) and sprints() is uncapped but naturally
     * small (see that method's own docblock) — but a public Livewire method
     * is reachable with an arbitrary client-supplied array regardless of
     * what the rendered UI offers (the same "never trust the caller already
     * filtered" discipline this whole class already applies to every other
     * client-suppliable value). Without a cap, nothing stops a direct
     * `$wire.call('bulkDeleteTicket', [1..10000])` from resolving a very
     * large Eloquent collection and running thousands of per-item
     * delete()+OrganizationSupportAction::create() pairs inside one
     * DB::transaction() — a real timeout/resource-exhaustion risk, not a
     * correctness one (every id would still be organization-scoped and
     * individually audited correctly). 100 is a generous multiple of what
     * this page's own UI could ever realistically select at once, chosen
     * the same way PROJECT_CASCADE_WINDOW_SECONDS below documents a
     * concrete, named product number rather than an arbitrary magic value
     * inlined at each call site.
     */
    private const BULK_ACTION_MAX_ITEMS = 100;

    private ?Organization $cachedOrganization = null;

    /**
     * @var array<int, Collection>
     */
    private array $ticketStatusOptionsCache = [];

    private ?Collection $priorityOptionsCache = null;

    /**
     * @var array<int, SupportCollection>
     */
    private array $responsibleOptionsCache = [];

    private ?Collection $labelOptionsCache = null;

    private ?Collection $projectStatusOptionsCache = null;

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
     *
     * Phase 13: a Full Access session also sees soft-deleted projects in
     * this same list — restoreProject() needs a trashed project to actually
     * be visible somewhere for its confirm()-gated control to reach it at
     * all, exactly mirroring tickets()'s/sprints()'s own Phase 11/12 change.
     * Read Only and Support Action sessions keep today's behavior unchanged,
     * since neither may ever call restoreProject()/deleteProject() — both
     * are Full-Access-only. 'sprints_count'/'epics_count' are added to the
     * same withCount() call already running here — the live (not-yet-
     * cascaded) counts a Full Access delete would actually cascade, read in
     * the Projects table's existing single query rather than a new one.
     */
    public function projects(): Collection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new Collection;
        }

        return $organization->projects()
            ->when(
                $this->session()?->isFullAccess() === true,
                fn ($query) => $query->withTrashed()
            )
            ->withCount([
                'tickets as tickets_count',
                'tickets as open_tickets_count' => fn ($query) => $query
                    ->whereHas('status', fn ($query) => $query->where('is_final', false)),
                'tickets as overdue_tickets_count' => fn ($query) => $query
                    ->whereNotNull('due_date')
                    ->where('due_date', '<', now()->startOfDay()),
                'sprints as sprints_count',
                'epics as epics_count',
            ])
            ->with([
                'sprints' => fn ($query) => $query->whereNotNull('started_at')->whereNull('ended_at'),
                'status:id,name,color',
            ])
            ->latest()
            ->get();
    }

    /**
     * The ProjectStatuses a Support Action may move a Project into —
     * organization-scoped exactly like priorityOptions()/labelOptions()
     * above (ProjectStatus uses the same
     * App\Models\Concerns\BelongsToOrganization trait), so this includes
     * this organization's own statuses plus organization_id-less legacy
     * ones — the identical scope ProjectForm's own status_id Select already
     * filters through via ->visibleTo(auth()->user()). Support Action never
     * offers a wider set than an ordinary edit through ProjectForm already
     * would.
     */
    public function projectStatusOptions(): Collection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new Collection;
        }

        return $this->projectStatusOptionsCache ??= ProjectStatus::query()
            ->visibleToOrganization($organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);
    }

    /**
     * Support Action's seventh write action — Phase 6G, and the first one
     * whose target is a Project rather than a Ticket. Same shape as
     * changeTicketPriority() above (scalar ids, organization-scoped
     * resolution, authorizeAction(), validate the new value against its own
     * organization-scoped options, no-op guard, single transaction) — the
     * only structural difference is the target's own organization_id is
     * checked directly ($project->organization_id), not through a
     * whereHas('project', ...) hop like every Ticket-targeted action above,
     * since a Project already carries its own Organization boundary
     * (App\Observers\ProjectObserver::updating() even throws if anything
     * ever tries to change it after creation, so re-checking it here is
     * exactly as safe as it is for every Ticket action re-checking
     * ticket->project->organization_id).
     *
     * No existing Observer/Event/Activity fires when a Project's status_id
     * changes (confirmed by reading App\Observers\ProjectObserver in full —
     * its only hooks are creating()/updating()/deleting()/restoring(), none
     * of which reference status_id; App\Observers\ProjectStatusObserver
     * only reacts to changes on the ProjectStatus row itself, e.g.
     * is_default uniqueness, mirroring the identical
     * TicketPriorityObserver finding changeTicketPriority() already
     * documented). OrganizationSupportAction remains the only audit trail
     * this action produces.
     */
    public function changeProjectStatus(int $projectId, int $newStatusId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        $session = SupportSessionContext::authorizeAction(auth()->user(), $organization->id);

        // Project carries its own organization_id directly — no
        // whereHas('project', ...) hop needed, unlike every Ticket-targeted
        // action above.
        $project = Project::query()
            ->where('organization_id', $organization->id)
            ->find($projectId);

        abort_unless($project !== null, 403);

        // $newStatusId is client input just as much as $projectId is —
        // valid for *this organization's* scope, not merely "a
        // ProjectStatus row that exists somewhere".
        $newStatusIsValid = $this->projectStatusOptions()->contains('id', $newStatusId);

        abort_unless($newStatusIsValid, 403);

        $oldStatusId = (int) $project->status_id;

        // No-op: see changeTicketStatus()'s identical guard.
        if ($oldStatusId === $newStatusId) {
            return;
        }

        DB::transaction(function () use ($project, $newStatusId, $oldStatusId, $session, $organization) {
            // Plain save(), consistent with every action above — sets
            // exactly one column, nothing else on $project is touched.
            $project->status_id = $newStatusId;
            $project->save();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'project.change_status',
                'target_type' => Project::class,
                'target_id' => $project->id,
                'field' => 'status_id',
                'old_value' => (string) $oldStatusId,
                'new_value' => (string) $newStatusId,
            ]);
        });

        Notification::make()
            ->title(__('Project updated'))
            ->success()
            ->send();
    }

    /**
     * The organization's most recent tickets across every project — see
     * RECENT_TICKETS_LIMIT's docblock for why this is a capped list rather
     * than a paginated one. project's status_type and organization_id are
     * included (not just id/name) — ticketStatusOptions()/changeTicketStatus()
     * need status_type to decide whether a project uses the shared global
     * status set or its own custom one (the same rule
     * KanbanScrumHelper::statusesQuery() already applies), and
     * ticketStatusOptions() re-checks organization_id on every call (see
     * its own docblock) — leaving it unselected would make that check
     * always see null and reject even this method's own, already-scoped
     * tickets, the exact regression sprints() had to fix for
     * sprintStatusBreakdown().
     */
    public function tickets(): Collection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new Collection;
        }

        return Ticket::query()
            // Phase 11: a Full Access session also sees soft-deleted tickets
            // in this same list — restoreTicket() needs a trashed ticket to
            // actually be visible somewhere for its confirm()-gated control
            // to reach it at all. Read Only and Support Action sessions keep
            // today's behavior unchanged (no trashed rows), since neither
            // may ever call restoreTicket()/deleteTicket() — both are
            // Full-Access-only (see those methods' own docblocks).
            ->when(
                $this->session()?->isFullAccess() === true,
                fn ($query) => $query->withTrashed()
            )
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->with(['project:id,name,status_type,organization_id', 'status:id,name,color', 'priority:id,name,color', 'responsible:id,name', 'labels:id,name,color'])
            ->latest()
            ->limit(self::RECENT_TICKETS_LIMIT)
            ->get();
    }

    /**
     * The statuses a Support Action may move $ticket into — the same rule
     * KanbanScrumHelper::statusesQuery() already uses for board columns:
     * the project's own custom set when it opts into one
     * (status_type === 'custom'), the shared global set (project_id IS
     * NULL) otherwise. Memoized per project id for the lifetime of this
     * request — the Tickets list can show several tickets from the same
     * project, and this would otherwise run once per ticket instead of
     * once per distinct project.
     *
     * Security audit finding (MEDIUM): this is public and takes an
     * Eloquent-model parameter — the exact same shape as
     * sprintStatusBreakdown()'s own fixed finding. Livewire\ImplicitlyBoundMethod
     * resolves $ticket from whatever id a direct `$wire.call('ticketStatusOptions', id)`
     * sends, regardless of organization, so this cannot rely on only ever
     * being reached with a $ticket that tickets()/changeTicketStatus()
     * already resolved through an organization-scoped query — it must
     * re-verify that itself, on every call.
     */
    public function ticketStatusOptions(Ticket $ticket): Collection
    {
        abort_unless(
            $this->organization() !== null && $ticket->project?->organization_id === $this->organization()->id,
            403
        );

        // Plain -> (not ?->): the abort_unless() above already establishes
        // $ticket->project is non-null for the rest of this method — its
        // condition can only be true if $ticket->project?->organization_id
        // resolved to a real value, which requires $ticket->project itself
        // to exist. PHPStan proves this statically; a redundant ?-> here
        // would just be flagged as dead-code-safety noise.
        return $this->ticketStatusOptionsCache[$ticket->project_id] ??= TicketStatus::query()
            ->when(
                $ticket->project->status_type === 'custom',
                fn ($query) => $query->where('project_id', $ticket->project_id),
                fn ($query) => $query->whereNull('project_id')
            )
            ->orderBy('order')
            ->get(['id', 'name']);
    }

    /**
     * Support Action V1's first (and, as of Phase 4, only) write action.
     * Deliberately takes plain int identifiers, never Ticket/TicketStatus
     * model parameters — a typed Eloquent parameter on a public Livewire
     * method is implicitly resolved by Livewire from whatever the client
     * sends (see this class's own docblock, and the security audit finding
     * that made sprintStatusBreakdown() re-verify its Sprint parameter for
     * exactly this reason). Resolution order matters and mirrors
     * KanbanScrumHelper::authorizedBoardTicket()/recordUpdated(): resolve
     * through an organization-scoped query, authorize, validate the target
     * value against its own scope, only then write.
     */
    public function changeTicketStatus(int $ticketId, int $newStatusId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        $session = SupportSessionContext::authorizeCapability(auth()->user(), $organization->id, 'change_ticket_status');

        // Resolved through the same organization filter every read method
        // on this page already uses — never Ticket::find($ticketId) first
        // and checked after. A ticket belonging to a different
        // organization is not found here at all, so there is nothing to
        // leak a 404-vs-403 distinction about either.
        // organization_id is included in the eager-loaded columns so the
        // ticketStatusOptions() call below (which independently re-checks
        // it) sees the real value rather than null — see that method's own
        // docblock for why it re-checks at all.
        $ticket = Ticket::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->with('project:id,status_type,organization_id')
            ->find($ticketId);

        abort_unless($ticket !== null, 403);

        // $newStatusId is client input just as much as $ticketId is — valid
        // for *this ticket's* project/organization scope, not merely
        // "a TicketStatus row that exists somewhere".
        $newStatusIsValid = $this->ticketStatusOptions($ticket)->contains('id', $newStatusId);

        abort_unless($newStatusIsValid, 403);

        $oldStatusId = (int) $ticket->status_id;

        // No-op: the status picked is the one the ticket already has.
        // Neither the ticket nor the audit trail should record a change
        // that did not happen — an OrganizationSupportAction row must mean
        // "this actually changed", the same discipline
        // TicketObserver::updating() already applies to TicketActivity
        // (it only fires when old_status_id/new_status_id actually differ).
        if ($oldStatusId === $newStatusId) {
            return;
        }

        DB::transaction(function () use ($ticket, $newStatusId, $oldStatusId, $session, $organization) {
            // Plain save(), not a query-builder update(): TicketObserver::updating()
            // must run to keep its existing side effects (TicketActivity,
            // watcher notifications, the live board broadcast) exactly as
            // they already work for every other status-changing path in
            // this app (e.g. KanbanScrumHelper::recordUpdated()). auth()->id()
            // inside that observer resolves to this Super Admin's own id —
            // correct and intentional: this action is genuinely performed
            // by them, not impersonation of anyone else.
            $ticket->status_id = $newStatusId;
            $ticket->save();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'ticket.change_status',
                'target_type' => Ticket::class,
                'target_id' => $ticket->id,
                'field' => 'status_id',
                'old_value' => (string) $oldStatusId,
                'new_value' => (string) $newStatusId,
            ]);
        });

        Notification::make()
            ->title(__('Ticket updated'))
            ->success()
            ->send();
    }

    /**
     * Phase 11 — the first Destructive Category A capability ever opened to
     * Support Center, and deliberately Full-Access-only: unlike
     * changeTicketStatus() above, this does NOT call
     * SupportSessionContext::authorizeCapability(). That method's Support
     * Action branch returns as soon as a Support Action session matches the
     * target organization, before 'delete_ticket' is ever checked against
     * FULL_ACCESS_CAPABILITIES (see authorizeCapability()'s own docblock and
     * test_authorize_capability_allows_a_support_action_session_for_any_capability)
     * — correct for a capability Support Action already legitimately has,
     * but wrong here: deleting a ticket must never be reachable through a
     * Support Action session, only through an Owner-approved Full Access
     * grant. So this calls the allowlist check and authorizeFullAccess()
     * directly, which only ever accepts a session with isFullAccess() true —
     * a Support Action (or Read Only) session is refused outright, with no
     * path through this method at all.
     */
    public function deleteTicket(int $ticketId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        abort_unless(SupportSessionContext::isFullAccessCapabilityAllowed('delete_ticket'), 403);
        $session = SupportSessionContext::authorizeFullAccess(auth()->user(), $organization->id);

        // Resolved through the same organization-scoped query every write
        // method on this page uses — never Ticket::find($ticketId) first and
        // checked after (see changeTicketStatus()'s own comment on this).
        $ticket = Ticket::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->find($ticketId);

        // No separate "already trashed" precondition check is needed (unlike
        // restoreTicket()'s below): this query never used withTrashed(), so
        // Eloquent's default SoftDeletingScope already excludes a trashed
        // ticket from the result entirely — it simply isn't found, and the
        // abort_unless() above already turns that into the same 403 an
        // explicit trashed() check would have produced. Adding one anyway
        // would be dead code that can never actually evaluate true.
        abort_unless($ticket !== null, 403);

        DB::transaction(function () use ($ticket, $session, $organization) {
            // Eloquent delete(), not a query-builder update(): this must run
            // through App\Observers\TicketObserver::deleting()/deleted() the
            // same way every other delete path (TicketResource,
            // ProjectObserver's cascade) does. Read in full during Phase 11
            // inspection: deleted() only calls
            // $ticket->project?->forgetStatistics() and
            // WidgetDataCache::invalidate() — cache invalidation, nothing
            // that itself needs to be proven to roll back.
            $ticket->delete();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'ticket.delete',
                'target_type' => Ticket::class,
                'target_id' => $ticket->id,
                'field' => 'deleted_at',
                'old_value' => null,
                'new_value' => (string) $ticket->deleted_at,
            ]);
        });

        Notification::make()
            ->title(__('Ticket deleted'))
            ->success()
            ->send();
    }

    /**
     * The symmetric undo of deleteTicket() above — same reasoning for why
     * this bypasses authorizeCapability() and calls
     * isFullAccessCapabilityAllowed()/authorizeFullAccess() directly:
     * restoring a ticket must be just as Full-Access-only as deleting one,
     * never reachable through a Support Action session.
     */
    public function restoreTicket(int $ticketId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        abort_unless(SupportSessionContext::isFullAccessCapabilityAllowed('restore_ticket'), 403);
        $session = SupportSessionContext::authorizeFullAccess(auth()->user(), $organization->id);

        // withTrashed() is required here — the whole point of this method is
        // to find a ticket that is currently soft-deleted, which the default
        // Eloquent scope excludes.
        $ticket = Ticket::withTrashed()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->find($ticketId);

        abort_unless($ticket !== null, 403);

        // Symmetric precondition to deleteTicket(): a ticket that is not
        // currently trashed is an invalid target for restore.
        abort_unless($ticket->trashed(), 403);

        $oldDeletedAt = (string) $ticket->deleted_at;

        DB::transaction(function () use ($ticket, $session, $organization, $oldDeletedAt) {
            // Eloquent restore(), not a query-builder update(): runs through
            // TicketObserver::restored(), which only calls
            // WidgetDataCache::invalidate() (confirmed in full during Phase
            // 11 inspection) — same reasoning as delete() above.
            $ticket->restore();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'ticket.restore',
                'target_type' => Ticket::class,
                'target_id' => $ticket->id,
                'field' => 'deleted_at',
                'old_value' => $oldDeletedAt,
                'new_value' => null,
            ]);
        });

        Notification::make()
            ->title(__('Ticket restored'))
            ->success()
            ->send();
    }

    /**
     * The priorities a Support Action may move any of this organization's
     * tickets into. Unlike ticketStatusOptions() (which varies per ticket,
     * since TicketStatus is project-scoped — status_type === 'custom' vs
     * the shared global set), TicketPriority is organization-scoped only
     * (App\Models\Concerns\BelongsToOrganization — confirmed from the
     * actual schema/model, not assumed: ticket_priorities.organization_id,
     * nullable for legacy/pre-Fase-3B rows, added by Fase 3B tenant
     * isolation). One list computed once for the whole organization is
     * therefore both correct and simpler — there is no per-project
     * variation to account for, unlike statuses.
     *
     * Takes no parameters at all — organization comes from the active
     * session, the same as members()/recentActivity() above, so there is
     * no client-suppliable identifier here for Livewire to bind to and
     * nothing to re-verify per call.
     *
     * scopeVisibleToOrganization() (not scopeVisibleTo($user)) is reused
     * deliberately: it is the exact primitive this codebase already built
     * for "I have a concrete organization id, not a User to resolve one
     * from via OrganizationContext" — App\Jobs\ImportJiraTicketsJob uses
     * it for the identical reason (a queue worker has no session either).
     * scopeVisibleTo($user) would be the wrong axis here for the same
     * reason OrganizationContext itself is never used on this page.
     */
    public function priorityOptions(): Collection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new Collection;
        }

        return $this->priorityOptionsCache ??= TicketPriority::query()
            ->visibleToOrganization($organization->id)
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    /**
     * Support Action V1's second write action. Same shape as
     * changeTicketStatus() immediately above (scalar ids, organization-
     * scoped resolution, authorizeAction(), validate the target value
     * against its own scope, no-op guard, single transaction) — see that
     * method's own docblock for why each step is ordered the way it is.
     *
     * No eager-load of $ticket->project is needed here (unlike
     * changeTicketStatus()): priorityOptions() takes no ticket at all, so
     * there is nothing on the relation this method itself needs to read.
     *
     * No existing Observer/Event/Activity fires when a Ticket's
     * priority_id changes (confirmed by reading TicketObserver in full —
     * its updating() hook only ever inspects status_id; TicketPriorityObserver
     * only reacts to changes on the TicketPriority row itself, e.g.
     * is_default uniqueness, never to a Ticket's priority_id). So unlike
     * status changes, OrganizationSupportAction is the only audit trail
     * this action produces — nothing existing to preserve or risk
     * bypassing, and no new Observer/Event is added just for this.
     */
    public function changeTicketPriority(int $ticketId, int $newPriorityId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        $session = SupportSessionContext::authorizeAction(auth()->user(), $organization->id);

        // Same organization-scoped resolution as changeTicketStatus() —
        // never Ticket::find($ticketId) trusted before the fact.
        $ticket = Ticket::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->find($ticketId);

        abort_unless($ticket !== null, 403);

        // $newPriorityId is client input just as much as $ticketId is —
        // valid for *this organization's* scope, not merely "a
        // TicketPriority row that exists somewhere".
        $newPriorityIsValid = $this->priorityOptions()->contains('id', $newPriorityId);

        abort_unless($newPriorityIsValid, 403);

        $oldPriorityId = (int) $ticket->priority_id;

        // No-op: see changeTicketStatus()'s identical guard — neither the
        // ticket nor the audit trail should record a change that did not
        // happen.
        if ($oldPriorityId === $newPriorityId) {
            return;
        }

        DB::transaction(function () use ($ticket, $newPriorityId, $oldPriorityId, $session, $organization) {
            // Plain save(), not a query-builder update() — consistent with
            // changeTicketStatus(), even though no Observer currently
            // reacts to this specific column; a future one (or a model
            // event listener) would still fire correctly this way.
            $ticket->priority_id = $newPriorityId;
            $ticket->save();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'ticket.change_priority',
                'target_type' => Ticket::class,
                'target_id' => $ticket->id,
                'field' => 'priority_id',
                'old_value' => (string) $oldPriorityId,
                'new_value' => (string) $newPriorityId,
            ]);
        });

        Notification::make()
            ->title(__('Ticket updated'))
            ->success()
            ->send();
    }

    /**
     * Who may become $ticket's responsible — audited from the actual
     * codebase, not assumed: App\Support\UserOptions::forProjectId()
     * (used by TicketResource/Forms/TicketForm.php's own responsible_id
     * select) shows the *real* rule the rest of the app already holds
     * every user to is $project->contributors (project owner + its
     * project_users members) — project-scoped, like TicketStatus, not
     * organization-scoped like TicketPriority. Support Action deliberately
     * intersects that with organization membership on top
     * ($organization->users()) as an extra safety boundary specific to
     * this elevated, audited write path — project_users and
     * organization_users are independent axes in this app (ADR 0001), so a
     * project contributor is not guaranteed to be an organization member
     * (e.g. a legacy/pre-Organization contributor). This never *loosens*
     * what a normal user could already do through TicketForm — only ever
     * narrows it further — and Support Action must help an organization,
     * not redefine who its members are.
     *
     * Returns plain id/name pairs, never the underlying User model —
     * $project->contributors is a Collection of full User models
     * (Project::contributors()), which would otherwise carry every column,
     * including creation_token, into whatever this method returns — the
     * exact class of oversight members() was fixed for.
     *
     * Takes an already-resolved Project (private, so this carries none of
     * the implicit-model-binding risk a *public* Livewire method's
     * parameter would — see this class's own docblock) so both
     * responsibleOptions() and changeTicketResponsible() share one
     * resolution instead of duplicating it.
     */
    private function resolveResponsibleOptions(Project $project, Organization $organization): SupportCollection
    {
        if (isset($this->responsibleOptionsCache[$project->id])) {
            return $this->responsibleOptionsCache[$project->id];
        }

        $organizationMemberIds = $organization->users()->pluck('users.id');

        return $this->responsibleOptionsCache[$project->id] = $project->contributors
            ->whereIn('id', $organizationMemberIds)
            ->sortBy('name')
            ->values()
            ->map(fn ($user) => (object) ['id' => $user->id, 'name' => $user->name]);
    }

    /**
     * Public entry point for responsibleOptions — takes the ticket's
     * scalar id, never a Ticket model (see this class's own docblock for
     * why), and resolves it through the same organization-scoped query
     * every write method here uses. A ticket from another organization is
     * never found at all, so there is nothing to leak its project's
     * contributors either.
     */
    public function responsibleOptions(int $ticketId): SupportCollection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new SupportCollection;
        }

        $ticket = Ticket::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->with('project')
            ->find($ticketId);

        abort_unless($ticket !== null, 403);

        return $this->resolveResponsibleOptions($ticket->project, $organization);
    }

    /**
     * Support Action V1's third write action. Same shape as
     * changeTicketStatus()/changeTicketPriority() above, with one
     * difference: $newResponsibleId is nullable — responsible_id is a
     * nullable column (tickets.responsible_id) and TicketForm's own
     * responsible_id select has no ->required(), so clearing the
     * assignment is already a normal, supported state in this app, not
     * something Support Action invents.
     *
     * No existing Observer/Event/Activity fires when a Ticket's
     * responsible_id changes (confirmed by reading TicketObserver in
     * full — the only reference to "responsible" anywhere in
     * app/Observers or app/Notifications is TicketCreated's own
     * creation-time email line, not a reassignment hook). Same situation
     * as changeTicketPriority(): OrganizationSupportAction is the only
     * audit trail this action produces, and none is added for it.
     */
    public function changeTicketResponsible(int $ticketId, ?int $newResponsibleId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        $session = SupportSessionContext::authorizeAction(auth()->user(), $organization->id);

        // Same organization-scoped resolution as changeTicketStatus()/
        // changeTicketPriority() — never Ticket::find($ticketId) trusted
        // before the fact.
        $ticket = Ticket::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->with('project')
            ->find($ticketId);

        abort_unless($ticket !== null, 403);

        // null (clear assignment) is always valid and skips this check
        // entirely — there is no "scope" to validate an absence against.
        // A non-null value is client input just as much as $ticketId is —
        // valid for *this ticket's project's contributors, intersected
        // with this organization's membership*, not merely "a User row
        // that exists somewhere".
        if ($newResponsibleId !== null) {
            $newResponsibleIsValid = $this->resolveResponsibleOptions($ticket->project, $organization)
                ->contains('id', $newResponsibleId);

            abort_unless($newResponsibleIsValid, 403);
        }

        $oldResponsibleId = $ticket->responsible_id;

        // No-op: see changeTicketStatus()'s identical guard. null === null
        // (already unassigned, asked to clear again) is correctly treated
        // as a no-op here too.
        if ($oldResponsibleId === $newResponsibleId) {
            return;
        }

        DB::transaction(function () use ($ticket, $newResponsibleId, $oldResponsibleId, $session, $organization) {
            // Plain save(), consistent with changeTicketStatus()/
            // changeTicketPriority() — no Observer currently reacts to
            // this column either, but a future one would still fire
            // correctly this way.
            $ticket->responsible_id = $newResponsibleId;
            $ticket->save();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'ticket.change_responsible',
                'target_type' => Ticket::class,
                'target_id' => $ticket->id,
                'field' => 'responsible_id',
                'old_value' => $oldResponsibleId !== null ? (string) $oldResponsibleId : null,
                'new_value' => $newResponsibleId !== null ? (string) $newResponsibleId : null,
            ]);
        });

        Notification::make()
            ->title(__('Ticket updated'))
            ->success()
            ->send();
    }

    /**
     * Validates a client-supplied due date string against the same
     * business rule TicketResource/Forms/TicketForm.php's own due_date
     * DatePicker already has (audited from the actual form definition,
     * not assumed): no ->required(), no min/max date constraint, no
     * "must be in the future" rule — a syntactically valid date, or null
     * to clear it, is all this app has ever asked for. This does not
     * invent a stricter rule; it only guards against a malformed string
     * reaching the database, since a <input type="date"> can still be
     * made to submit garbage via a direct Livewire call.
     *
     * Carbon::createFromFormat('Y-m-d', ...) alone is not enough:
     * PHP/Carbon's underlying parser silently rolls an out-of-range value
     * like "2024-13-45" over into a different, valid-looking date instead
     * of failing — re-formatting the parsed result and comparing it back
     * to the original string catches that rollover.
     *
     * Returns a Carbon instance (or null), not the raw string: Ticket::$due_date
     * is cast to Carbon|null (`protected $casts = ['due_date' => 'date']`),
     * so assigning a plain string to it is a real type mismatch a static
     * analyzer can (and does) catch, even though Eloquent's date-cast
     * mutator happens to accept a string at runtime — the fix is to hand
     * back the type the property actually expects, not to suppress the
     * check.
     */
    private function parseDueDate(?string $rawDueDate): ?\Carbon\Carbon
    {
        if ($rawDueDate === null) {
            return null;
        }

        try {
            $parsed = \Carbon\Carbon::createFromFormat('Y-m-d', $rawDueDate);
        } catch (\Throwable $exception) {
            $parsed = false;
        }

        abort_unless($parsed !== false && $parsed->format('Y-m-d') === $rawDueDate, 403);

        return $parsed->startOfDay();
    }

    /**
     * Support Action V1's fourth write action. Same shape as
     * changeTicketStatus()/changeTicketPriority()/changeTicketResponsible()
     * above — scalar ids, organization-scoped resolution, authorizeAction(),
     * validate the target value, no-op guard, single transaction. Simpler
     * than the other three in one respect: due_date has no cross-referenced
     * "valid options" scope to check (unlike status/priority/responsible,
     * which all had to be verified against some other organization/project-
     * scoped table) — the only thing to validate is that the client-supplied
     * string is actually a well-formed date, via parseDueDate() above.
     *
     * No existing Observer/Event/Activity fires when a Ticket's due_date
     * changes (confirmed by reading TicketObserver in full, same as the
     * priority_id/responsible_id findings before it — nothing in
     * app/Observers or app/Notifications reacts to this column at all).
     * OrganizationSupportAction remains the only audit trail this action
     * produces, and none is added for it.
     */
    public function changeTicketDueDate(int $ticketId, ?string $newDueDate): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        $session = SupportSessionContext::authorizeAction(auth()->user(), $organization->id);

        // Same organization-scoped resolution as every other write method
        // on this page — never Ticket::find($ticketId) trusted before the
        // fact.
        $ticket = Ticket::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->find($ticketId);

        abort_unless($ticket !== null, 403);

        // Validated before comparison — a malformed string must never be
        // silently treated as null nor written as-is. Kept as a real
        // Carbon instance (matching Ticket::$due_date's own cast type) for
        // the assignment below; $newDueDateString is the plain 'Y-m-d'
        // representation used for the no-op comparison and the audit row,
        // the same string shape $oldDueDate already is.
        $newDueDate = $this->parseDueDate($newDueDate);
        $newDueDateString = $newDueDate?->toDateString();

        $oldDueDate = $ticket->due_date?->toDateString();

        // No-op: see changeTicketStatus()'s identical guard.
        if ($oldDueDate === $newDueDateString) {
            return;
        }

        DB::transaction(function () use ($ticket, $newDueDate, $newDueDateString, $oldDueDate, $session, $organization) {
            // Plain save(), consistent with the other three actions — no
            // Observer reacts to this column either, but a future one
            // would still fire correctly this way.
            $ticket->due_date = $newDueDate;
            $ticket->save();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'ticket.change_due_date',
                'target_type' => Ticket::class,
                'target_id' => $ticket->id,
                'field' => 'due_date',
                'old_value' => $oldDueDate,
                'new_value' => $newDueDateString,
            ]);
        });

        Notification::make()
            ->title(__('Ticket updated'))
            ->success()
            ->send();
    }

    /**
     * Validates a client-supplied ticket title against the same rule
     * TicketResource/Forms/TicketForm.php's own `name` TextInput already
     * has (audited from the actual form definition, not assumed):
     * ->required() and ->maxLength(255) — matching the column itself
     * (`$table->string('name')`, a 255-char VARCHAR). This app has no
     * "Ticket title" column at all: the field the rest of this codebase
     * calls "Ticket name" (TicketForm's own label, this page's own Tickets
     * table header) is `tickets.name` — the same field this method
     * targets, "Title" being purely the business-facing name Phase 6D
     * asked for it under.
     *
     * Not trimmed before being stored: TicketForm's TextInput has no
     * dehydrateStateUsing() or similar normalization step either, so
     * Support Action does not invent stricter/different behavior than
     * what a normal edit already does — only trim() is used to *detect*
     * a whitespace-only string as effectively blank for the required
     * check, the original untrimmed string is what gets validated for
     * length and ultimately returned.
     */
    private function validatedTitle(string $rawTitle): string
    {
        abort_unless(trim($rawTitle) !== '' && mb_strlen($rawTitle) <= 255, 403);

        return $rawTitle;
    }

    /**
     * Support Action V1's fifth write action. Same shape as
     * changeTicketStatus()/changeTicketPriority()/changeTicketResponsible()/
     * changeTicketDueDate() above — scalar ids, organization-scoped
     * resolution, authorizeAction(), validate the target value, no-op
     * guard, single transaction. Like due_date, title has no cross-
     * referenced "valid options" scope to check — only the required/
     * max-length rule TicketForm already enforces, via validatedTitle()
     * above.
     *
     * `action` is the business-facing 'ticket.change_title' (matching how
     * this capability was asked for and how it reads in an audit list),
     * but `field` is the real column name 'name' — the same discipline
     * every other action here already follows: field always names the
     * actual database column that changed, never a UI-facing synonym for
     * it, so anyone reading OrganizationSupportAction rows later can
     * correlate them with the schema directly.
     *
     * No existing Observer/Event/Activity fires when a Ticket's name
     * changes (confirmed by reading TicketObserver in full, same as
     * priority_id/responsible_id/due_date before it — nothing in
     * app/Observers or app/Notifications reacts to this column).
     * OrganizationSupportAction remains the only audit trail this action
     * produces, and none is added for it.
     */
    public function changeTicketTitle(int $ticketId, string $newTitle): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        $session = SupportSessionContext::authorizeAction(auth()->user(), $organization->id);

        // Same organization-scoped resolution as every other write method
        // on this page — never Ticket::find($ticketId) trusted before the
        // fact.
        $ticket = Ticket::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->find($ticketId);

        abort_unless($ticket !== null, 403);

        // Validated before comparison — a blank or oversized string must
        // never be silently accepted nor written as-is.
        $newTitle = $this->validatedTitle($newTitle);

        $oldTitle = $ticket->name;

        // No-op: see changeTicketStatus()'s identical guard.
        if ($oldTitle === $newTitle) {
            return;
        }

        DB::transaction(function () use ($ticket, $newTitle, $oldTitle, $session, $organization) {
            // Plain save(), consistent with the other four actions — sets
            // exactly one column, nothing else on $ticket is touched.
            $ticket->name = $newTitle;
            $ticket->save();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'ticket.change_title',
                'target_type' => Ticket::class,
                'target_id' => $ticket->id,
                'field' => 'name',
                'old_value' => $oldTitle,
                'new_value' => $newTitle,
            ]);
        });

        Notification::make()
            ->title(__('Ticket updated'))
            ->success()
            ->send();
    }

    /**
     * The Labels a Support Action may attach to a ticket in this
     * organization — organization-scoped exactly like priorityOptions()
     * above (Label uses the same App\Models\Concerns\BelongsToOrganization
     * trait as TicketPriority), so this includes this organization's own
     * Labels plus organization_id-less legacy rows
     * (BelongsToOrganization::scopeVisibleToOrganization()) — the identical
     * scope TicketResource/Forms/TicketForm.php's own labelsSelect() already
     * filters through via ->visibleTo(auth()->user()). Support Action never
     * offers a wider set than an ordinary edit through TicketForm already
     * would.
     *
     * Unlike responsibleOptions(), this needs no ticket/project at all —
     * Label is organization-scoped, not project-scoped — so it is memoized
     * once per request rather than per ticket/project id.
     */
    public function labelOptions(): Collection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new Collection;
        }

        return $this->labelOptionsCache ??= Label::query()
            ->visibleToOrganization($organization->id)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);
    }

    /**
     * Support Action's sixth write action — Phase 6E. Unlike every action
     * before it, the mutation target is not a column on `tickets` but the
     * `label_ticket` pivot table behind Ticket::labels() (a belongsToMany)
     * — the exact relationship TicketForm's own Labels multi-select already
     * syncs through Filament's ->relationship('labels', ...) machinery.
     * Reusing $ticket->labels()->sync() here means this action can never
     * behave differently from what an ordinary edit through TicketForm
     * already does to this same pivot table.
     *
     * $newLabelIds is client input exactly like every other write method's
     * parameters, so it is never trusted as "already an array of clean
     * ints" — each element is validated scalar-and-integer via
     * filter_var(..., FILTER_VALIDATE_INT) and non-integer entries are
     * dropped, then deduplicated (a repeated id in the array must not
     * attach the same label twice, nor manufacture a spurious audit entry
     * that looks like a real change). What remains is then validated
     * *wholesale* against labelOptions() (this organization's own Labels
     * plus organization_id-less legacy ones) — a single id outside that
     * set aborts the entire call rather than silently attaching only the
     * valid subset, the same all-or-nothing discipline
     * changeTicketPriority()/changeTicketResponsible() already apply to
     * their own single-id input. An empty array is valid input (clears
     * every label), matching labelsSelect() having no ->required().
     *
     * `field` is 'labels' (Ticket::labels(), the relationship name) rather
     * than a column name: unlike every action before this one there is no
     * single `tickets` column being changed — label_ticket has no
     * organization_id of its own, its boundary is inherited entirely from
     * Ticket (via the organization-scoped $ticket resolution below) and
     * Label (via labelOptions() above). old_value/new_value store the
     * sorted, comma-joined label ids before/after the change — the same
     * plain-string shape organization_support_actions.old_value/new_value
     * already use for every other action (see changeTicketPriority()'s
     * (string) cast), not a fabricated new structure.
     *
     * No existing Observer/Event/Activity fires on label_ticket
     * attach/detach — confirmed by reading App\Observers\LabelObserver in
     * full (its only hook is creating(), which stamps a *new* Label's own
     * organization_id, never touching the pivot) and grepping app/Observers
     * and app/Notifications for any other reference to labels, which finds
     * none. OrganizationSupportAction remains the only audit trail this
     * action produces.
     */
    public function changeTicketLabels(int $ticketId, array $newLabelIds): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        $session = SupportSessionContext::authorizeAction(auth()->user(), $organization->id);

        // Same organization-scoped resolution as every other write method
        // on this page — never Ticket::find($ticketId) trusted before the
        // fact.
        $ticket = Ticket::query()
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->find($ticketId);

        abort_unless($ticket !== null, 403);

        // Untrusted client array: keep only clean, unique integers before
        // anything else touches it.
        $newLabelIds = collect($newLabelIds)
            ->filter(fn ($id) => is_scalar($id))
            ->map(fn ($id) => filter_var($id, FILTER_VALIDATE_INT))
            ->filter(fn ($id) => $id !== false)
            ->unique()
            ->sort()
            ->values();

        // All-or-nothing against this organization's own visible Labels —
        // see this method's own docblock for why a partial attach is never
        // acceptable here.
        $validLabelIds = $this->labelOptions()->pluck('id');

        abort_unless($newLabelIds->diff($validLabelIds)->isEmpty(), 403);

        $oldLabelIds = $ticket->labels->pluck('id')->sort()->values();

        // No-op: see changeTicketStatus()'s identical guard — comparing the
        // two sorted id lists, not array identity, so [2, 1] vs [1, 2]
        // (same set, different client-supplied order) is still a no-op.
        if ($oldLabelIds->all() === $newLabelIds->all()) {
            return;
        }

        DB::transaction(function () use ($ticket, $newLabelIds, $oldLabelIds, $session, $organization) {
            // sync() touches only the label_ticket pivot rows for this
            // ticket — no other column on $ticket, or on Label itself, is
            // written.
            $ticket->labels()->sync($newLabelIds->all());

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'ticket.change_labels',
                'target_type' => Ticket::class,
                'target_id' => $ticket->id,
                'field' => 'labels',
                'old_value' => $oldLabelIds->implode(','),
                'new_value' => $newLabelIds->implode(','),
            ]);
        });

        Notification::make()
            ->title(__('Ticket updated'))
            ->success()
            ->send();
    }

    /**
     * Every sprint across the organization's projects — each one's tickets
     * (with just their status) are eager loaded here so
     * sprintStatusBreakdown() below never runs a query per sprint.
     * project's organization_id is deliberately included in the column
     * list (not just id/name) — sprintStatusBreakdown() re-checks it on
     * every call, so leaving it unselected would make that check always
     * see null and reject even this method's own, already-scoped sprints.
     *
     * Phase 12: a Full Access session also sees soft-deleted sprints in this
     * same list — restoreSprint() needs a trashed sprint to actually be
     * visible somewhere for its confirm()-gated control to reach it at all,
     * exactly mirroring tickets()'s own Phase 11 change. Read Only and
     * Support Action sessions keep today's behavior unchanged, since neither
     * may ever call restoreSprint()/deleteSprint() — both are Full-Access-
     * only (see those methods' own docblocks). 'epic' is eager loaded with
     * withTrashed() so the Blade view can tell whether restoring this sprint
     * would also cascade-restore its mirrored epic (App\Observers\SprintObserver
     * ::restoring()) — without withTrashed() here, an already-trashed epic
     * would simply resolve to null and that real impact would be silently
     * hidden from the confirm() dialog.
     */
    public function sprints(): Collection
    {
        $organization = $this->organization();

        if ($organization === null) {
            return new Collection;
        }

        return Sprint::query()
            ->when(
                $this->session()?->isFullAccess() === true,
                fn ($query) => $query->withTrashed()
            )
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->with([
                'project:id,name,organization_id',
                'tickets.status:id,name,is_final,is_default',
                'epic' => fn ($query) => $query->withTrashed(),
            ])
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
     * Resolves $sprintId the same organization-scoped way every write
     * method on this page resolves its target — never Sprint::find($id)
     * trusted before the fact. Shared by every Sprint-targeted action below
     * instead of each repeating the same query.
     *
     * $withTrashed defaults to false so sprintStart()/sprintStop()/
     * changeSprintDates() (Phase 6G, unchanged by Phase 12) keep resolving
     * only a live sprint exactly as before — none of the three has any valid
     * target state for an already-trashed sprint. restoreSprint() (Phase 12)
     * is the only caller that ever passes true, the same way
     * restoreTicket() needs Ticket::withTrashed() where deleteTicket()
     * doesn't.
     */
    private function resolveSprint(int $sprintId, Organization $organization, bool $withTrashed = false): Sprint
    {
        $sprint = Sprint::query()
            ->when($withTrashed, fn ($query) => $query->withTrashed())
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->find($sprintId);

        abort_unless($sprint !== null, 403);

        return $sprint;
    }

    /**
     * Support Action's eighth write action — Phase 6G. Unlike every action
     * above, "start a sprint" is not a plain field-setter: it is the exact
     * state transition
     * App\Filament\Resources\ProjectResource\RelationManagers\SprintsRelationManager's
     * own "start" row action already performs, audited from that action's
     * real code, not assumed. That existing action enforces a real business
     * invariant — "a project is never left with zero or two active
     * sprints" — by atomically closing any other sprint of the same project
     * that is still running (started_at set, ended_at null) in the same
     * write as starting this one. Support Action reuses that exact
     * invariant rather than a simplified "just set started_at" version,
     * which would otherwise let a Support session put a project into a
     * state the real UI deliberately prevents.
     *
     * abort_unless(...) below mirrors that same action's own ->visible()
     * rule (only offered when neither started_at nor ended_at is set) — an
     * already-started or already-ended sprint has no valid "start" target
     * value to no-op against (unlike e.g. changeTicketPriority(), there is
     * no client-supplied value being compared to the current one here), so
     * attempting it from the wrong state is rejected outright rather than
     * silently doing nothing.
     *
     * The other sprint(s) this closes are updated via the query builder,
     * exactly like SprintsRelationManager's own start action does for the
     * same rows (not $model->save() per row) — App\Observers\SprintObserver
     * ::updated() only reacts to name/starts_at/ends_at changes (confirmed
     * by reading it in full), never started_at/ended_at, so no Observer
     * behavior is skipped by matching the existing code's own choice here.
     * Each closed sprint still gets its own audit row — one row per single
     * field changed on one resource, the same granularity the
     * organization_support_actions migration's own docblock specifies —
     * rather than folding a side effect on a different resource into the
     * primary action's row.
     */
    public function sprintStart(int $sprintId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        $session = SupportSessionContext::authorizeAction(auth()->user(), $organization->id);

        $sprint = $this->resolveSprint($sprintId, $organization);

        abort_unless($sprint->started_at === null && $sprint->ended_at === null, 403);

        $now = now();

        DB::transaction(function () use ($sprint, $now, $session, $organization) {
            $otherActiveSprintIds = Sprint::where('project_id', $sprint->project_id)
                ->where('id', '<>', $sprint->id)
                ->whereNotNull('started_at')
                ->whereNull('ended_at')
                ->pluck('id');

            if ($otherActiveSprintIds->isNotEmpty()) {
                Sprint::whereIn('id', $otherActiveSprintIds)->update(['ended_at' => $now]);
            }

            $sprint->started_at = $now;
            $sprint->save();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'sprint.start',
                'target_type' => Sprint::class,
                'target_id' => $sprint->id,
                'field' => 'started_at',
                'old_value' => null,
                'new_value' => (string) $now,
            ]);

            foreach ($otherActiveSprintIds as $closedSprintId) {
                OrganizationSupportAction::create([
                    'support_session_id' => $session->id,
                    'actor_user_id' => auth()->id(),
                    'organization_id' => $organization->id,
                    'action' => 'sprint.auto_stop',
                    'target_type' => Sprint::class,
                    'target_id' => $closedSprintId,
                    'field' => 'ended_at',
                    'old_value' => null,
                    'new_value' => (string) $now,
                ]);
            }
        });

        Notification::make()
            ->title(__('Sprint updated'))
            ->success()
            ->send();
    }

    /**
     * Support Action's ninth write action — Phase 6G. The symmetric "stop"
     * to sprintStart() above, mirroring
     * SprintsRelationManager's own "stop" row action exactly: a plain
     * ended_at = now() write, no invariant to enforce (closing a sprint
     * never risks leaving the project with zero or two active sprints the
     * way starting one does). abort_unless(...) mirrors that action's own
     * ->visible() rule (only offered when started_at is set and ended_at is
     * not) for the same reason sprintStart() rejects the wrong state
     * outright instead of treating it as a no-op.
     */
    public function sprintStop(int $sprintId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        $session = SupportSessionContext::authorizeAction(auth()->user(), $organization->id);

        $sprint = $this->resolveSprint($sprintId, $organization);

        abort_unless($sprint->started_at !== null && $sprint->ended_at === null, 403);

        $now = now();

        DB::transaction(function () use ($sprint, $now, $session, $organization) {
            $sprint->ended_at = $now;
            $sprint->save();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'sprint.stop',
                'target_type' => Sprint::class,
                'target_id' => $sprint->id,
                'field' => 'ended_at',
                'old_value' => null,
                'new_value' => (string) $now,
            ]);
        });

        Notification::make()
            ->title(__('Sprint updated'))
            ->success()
            ->send();
    }

    /**
     * Validates a client-supplied sprint date string the same rollover-safe
     * way parseDueDate() above does (Carbon::createFromFormat() re-verified
     * by round-tripping back to 'Y-m-d' and comparing against the original
     * string) — but non-nullable: sprints.starts_at/ends_at are NOT NULL
     * columns (database/migrations/2023_01_15_202225_create_sprints_table.php)
     * and SprintForm's own DatePicker pair are both ->required(), so unlike
     * due_date there is no legitimate "clear the date" state to support
     * here.
     */
    private function parseSprintDate(string $rawDate): \Carbon\Carbon
    {
        try {
            $parsed = \Carbon\Carbon::createFromFormat('Y-m-d', $rawDate);
        } catch (\Throwable $exception) {
            $parsed = false;
        }

        abort_unless($parsed !== false && $parsed->format('Y-m-d') === $rawDate, 403);

        return $parsed->startOfDay();
    }

    /**
     * Support Action's tenth write action — Phase 6G. Both dates are
     * validated and written together, atomically: SprintForm's own pair of
     * DatePicker fields enforce a cross-field rule on each other
     * (starts_at ->beforeOrEqual(ends_at), ends_at ->afterOrEqual(starts_at)),
     * so validating one in isolation would let Support Action create a
     * sprint with an end date before its start date, a state the real form
     * never allows. `field` is the compound 'starts_at,ends_at' rather than
     * two separate rows — the two columns are one unit here precisely
     * because they can only ever be valid or invalid as a pair.
     *
     * Plain save(), not a query-builder update() — unlike sprintStart()'s
     * closing of *other* sprints, this write is to $sprint itself, and
     * App\Observers\SprintObserver::updated() must fire so the mirrored
     * Epic (created by that same Observer's created() hook) stays in sync
     * with the new dates, exactly like an ordinary edit through SprintForm
     * already does.
     */
    public function changeSprintDates(int $sprintId, string $newStartsAt, string $newEndsAt): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        $session = SupportSessionContext::authorizeAction(auth()->user(), $organization->id);

        $sprint = $this->resolveSprint($sprintId, $organization);

        $newStartsAt = $this->parseSprintDate($newStartsAt);
        $newEndsAt = $this->parseSprintDate($newEndsAt);

        // Same cross-field rule SprintForm's own DatePicker pair enforces.
        abort_unless($newStartsAt->lte($newEndsAt), 403);

        $oldStartsAtString = $sprint->starts_at->toDateString();
        $oldEndsAtString = $sprint->ends_at->toDateString();
        $newStartsAtString = $newStartsAt->toDateString();
        $newEndsAtString = $newEndsAt->toDateString();

        // No-op: see changeTicketStatus()'s identical guard.
        if ($oldStartsAtString === $newStartsAtString && $oldEndsAtString === $newEndsAtString) {
            return;
        }

        DB::transaction(function () use ($sprint, $newStartsAt, $newEndsAt, $oldStartsAtString, $oldEndsAtString, $newStartsAtString, $newEndsAtString, $session, $organization) {
            $sprint->starts_at = $newStartsAt;
            $sprint->ends_at = $newEndsAt;
            $sprint->save();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'sprint.change_dates',
                'target_type' => Sprint::class,
                'target_id' => $sprint->id,
                'field' => 'starts_at,ends_at',
                'old_value' => $oldStartsAtString.','.$oldEndsAtString,
                'new_value' => $newStartsAtString.','.$newEndsAtString,
            ]);
        });

        Notification::make()
            ->title(__('Sprint updated'))
            ->success()
            ->send();
    }

    /**
     * Phase 12 — Destructive Category B, following deleteTicket()'s exact
     * shape (Phase 11): deliberately Full-Access-only, bypassing
     * SupportSessionContext::authorizeCapability() entirely and calling the
     * allowlist check + authorizeFullAccess() directly, for the identical
     * reason — a Support Action session must never reach this, even though
     * it already legitimately has sprintStart()/sprintStop()/
     * changeSprintDates() as its own, separate capabilities via
     * authorizeAction().
     *
     * Epic side effect, verified by reading App\Observers\SprintObserver in
     * full during Phase 12 inspection: it has no deleting()/deleted() hook
     * at all — only created()/updated()/restoring(). So unlike
     * deleteTicket() there is nothing to even check here: deleting a sprint
     * never touches its mirrored epic (App\Models\Epic, linked via
     * Sprint::epic_id) in any way, it stays exactly as it was. The epic can
     * only ever be taken down together with the sprint by
     * App\Observers\ProjectObserver::deleting()'s own project-level cascade,
     * a completely different write path this method does not go through
     * (resolveSprint() only ever finds a sprint whose project is not itself
     * trashed, via whereHas('project', ...)'s implicit SoftDeletingScope on
     * Project).
     *
     * Uses the default (non-trashed) resolveSprint() call, exactly like
     * deleteTicket()'s equivalent comment explains: the default
     * SoftDeletingScope already excludes an already-trashed sprint from the
     * result, so a separate "not already trashed" precondition would be
     * dead code.
     */
    public function deleteSprint(int $sprintId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        abort_unless(SupportSessionContext::isFullAccessCapabilityAllowed('delete_sprint'), 403);
        $session = SupportSessionContext::authorizeFullAccess(auth()->user(), $organization->id);

        $sprint = $this->resolveSprint($sprintId, $organization);

        DB::transaction(function () use ($sprint, $session, $organization) {
            // Eloquent delete(), not a query-builder update(): must run
            // through App\Observers\SprintObserver's hooks the same way
            // every other Sprint delete path does — confirmed in full during
            // Phase 12 inspection that deleting()/deleted() do not exist on
            // that observer, so nothing beyond sprints.deleted_at itself
            // changes here.
            $sprint->delete();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'sprint.delete',
                'target_type' => Sprint::class,
                'target_id' => $sprint->id,
                'field' => 'deleted_at',
                'old_value' => null,
                'new_value' => (string) $sprint->deleted_at,
            ]);
        });

        Notification::make()
            ->title(__('Sprint deleted'))
            ->success()
            ->send();
    }

    /**
     * The symmetric undo of deleteSprint() above — same Full-Access-only
     * reasoning, and the same withTrashed()/trashed() precondition shape as
     * restoreTicket().
     *
     * Epic side effect, the one genuine asymmetry Phase 12 inspection found:
     * App\Observers\SprintObserver::restoring() unconditionally looks up
     * `$sprint->epic()->onlyTrashed()->first()` and restores it if found.
     * deleteSprint() above never trashes the mirrored epic itself, but nothing
     * stops the epic from having been independently trashed by an unrelated
     * path in the meantime — App\Http\Livewire\RoadMap\EpicForm::delete()
     * lets any user with access to the epic's project soft-delete *any*
     * epic directly, including one still backing an active-but-now-trashed
     * sprint (confirmed by reading that component and EpicPolicy in full;
     * neither special-cases a sprint-mirrored epic). So $sprint->restore()
     * below can legitimately cascade-restore an epic this method never
     * touched directly — a real side effect, not a hypothetical one, and it
     * gets its own separate OrganizationSupportAction row ('epic.auto_restore'),
     * mirroring how sprintStart() already audits the *other* sprint it
     * auto-stops as a distinct row rather than folding it into the primary
     * action's row. The trashed epic (if any) is captured *before*
     * $sprint->restore() runs, since that call is what changes its
     * deleted_at.
     */
    public function restoreSprint(int $sprintId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        abort_unless(SupportSessionContext::isFullAccessCapabilityAllowed('restore_sprint'), 403);
        $session = SupportSessionContext::authorizeFullAccess(auth()->user(), $organization->id);

        $sprint = $this->resolveSprint($sprintId, $organization, withTrashed: true);

        // Symmetric precondition to deleteSprint(): a sprint that is not
        // currently trashed is an invalid target for restore.
        abort_unless($sprint->trashed(), 403);

        $oldDeletedAt = (string) $sprint->deleted_at;

        DB::transaction(function () use ($sprint, $session, $organization, $oldDeletedAt) {
            // Captured before restore() runs — SprintObserver::restoring()
            // is what would change (or clear) this epic's own deleted_at,
            // so it has to be read first.
            $trashedEpic = $sprint->epic()->onlyTrashed()->first();
            $epicOldDeletedAt = $trashedEpic !== null ? (string) $trashedEpic->deleted_at : null;

            // Eloquent restore(), not a query-builder update(): runs through
            // SprintObserver::restoring(), which is exactly the cascade this
            // method's own docblock documents and audits separately below.
            $sprint->restore();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'sprint.restore',
                'target_type' => Sprint::class,
                'target_id' => $sprint->id,
                'field' => 'deleted_at',
                'old_value' => $oldDeletedAt,
                'new_value' => null,
            ]);

            if ($trashedEpic !== null) {
                OrganizationSupportAction::create([
                    'support_session_id' => $session->id,
                    'actor_user_id' => auth()->id(),
                    'organization_id' => $organization->id,
                    'action' => 'epic.auto_restore',
                    'target_type' => Epic::class,
                    'target_id' => $trashedEpic->id,
                    'field' => 'deleted_at',
                    'old_value' => $epicOldDeletedAt,
                    'new_value' => null,
                ]);
            }
        });

        Notification::make()
            ->title(__('Sprint restored'))
            ->success()
            ->send();
    }

    // -----------------------------------------------------------------
    // Phase 14 — Bulk Full Access Gate. bulkDeleteTicket()/
    // bulkRestoreTicket()/bulkDeleteSprint()/bulkRestoreSprint() apply
    // deleteTicket()'s/restoreTicket()'s/deleteSprint()'s/restoreSprint()'s
    // exact single-target mutation to a whole client-selected batch,
    // Full-Access-only exactly like those four (never authorizeCapability(),
    // a Support Action session must never reach any of these), under an
    // explicit ALL-OR-NOTHING contract — see this class's own docblock.
    // -----------------------------------------------------------------

    /**
     * Untrusted-client-array-to-clean-int-collection, shared by every bulk
     * method below — the exact same sanitization changeTicketLabels()
     * already applies to its own $newLabelIds array (scalar check,
     * FILTER_VALIDATE_INT, drop anything that fails, dedupe), extracted
     * here once rather than repeated four times.
     */
    private function sanitizeBulkIds(array $rawIds): SupportCollection
    {
        return collect($rawIds)
            ->filter(fn ($id) => is_scalar($id))
            ->map(fn ($id) => filter_var($id, FILTER_VALIDATE_INT))
            ->filter(fn ($id) => $id !== false)
            ->unique()
            ->values();
    }

    /**
     * Resolves+validates a whole batch of Ticket ids under the ALL-OR-
     * NOTHING contract Phase 14 requires: every id in $ticketIds must
     * resolve to a real Ticket belonging to $organization AND already be in
     * the correct precondition state for the action ($forDelete: not
     * trashed, matching deleteTicket()'s own default-SoftDeletingScope
     * reasoning; !$forDelete: trashed, matching restoreTicket()'s own
     * trashed() precondition) — a single id anywhere in the batch that
     * fails either check rejects the entire batch, never a partial apply.
     * This is the exact same "validate the whole set, not the valid
     * subset" discipline changeTicketLabels() already applies to a single
     * ticket's label ids, extended here across an entire ticket selection.
     *
     * Mixed-organization input needs no separate branch: whereHas('project',
     * ...) already scopes the query to $organization, so an id belonging to
     * a different organization is simply never found by it — its absence
     * from the resolved collection is caught by the exact same "every
     * requested id must have resolved" count check that also catches a
     * garbage/nonexistent id, per this class's own docblock on why mixed-
     * organization input is not a distinct code path.
     *
     * Deliberately called twice by every bulk method below: once before
     * DB::transaction() opens (fail fast on an invalid batch without ever
     * writing anything), and again from scratch immediately inside the
     * transaction, right before any mutation runs. The second call is the
     * one the mutation actually trusts — the first call's result is never
     * reused for the mutation itself, so a batch that was valid when this
     * method first ran but had its state changed by a *different* request
     * in the window before the transaction actually started (the same
     * TOCTOU window every REVALIDATION step in this phase exists to close)
     * is caught by the second call rather than silently trusted from the
     * first. See bulkDeleteTicket()'s own docblock for why this re-SELECT,
     * without lockForUpdate(), is judged sufficient for V1 rather than a
     * row-locking read.
     *
     * @return Collection<int, Ticket>
     */
    private function resolveBulkTickets(SupportCollection $ticketIds, Organization $organization, bool $forDelete): Collection
    {
        abort_unless($ticketIds->isNotEmpty(), 403);
        abort_unless($ticketIds->count() <= self::BULK_ACTION_MAX_ITEMS, 403);

        $tickets = Ticket::query()
            ->when($forDelete === false, fn ($query) => $query->withTrashed())
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->whereIn('id', $ticketIds)
            ->get();

        // ALL-OR-NOTHING: every requested id must have resolved to a real,
        // in-scope Ticket. For $forDelete === true this query never used
        // withTrashed(), so an already-trashed ticket is silently excluded
        // by the default SoftDeletingScope — exactly like a nonexistent or
        // wrong-organization id, it simply fails this same count check
        // rather than needing its own separate precondition branch.
        abort_unless($tickets->count() === $ticketIds->count(), 403);

        if ($forDelete === false) {
            // Symmetric to restoreTicket()'s own trashed() precondition,
            // applied to every resolved ticket in the batch — withTrashed()
            // above means a live ticket would otherwise slip through the
            // count check above undetected.
            abort_unless($tickets->every(fn (Ticket $ticket) => $ticket->trashed()), 403);
        }

        return $tickets;
    }

    /**
     * Sprint's equivalent of resolveBulkTickets() immediately above — same
     * ALL-OR-NOTHING contract, same reasoning for why mixed-organization
     * input needs no separate branch, same two-pass (pre-transaction +
     * in-transaction REVALIDATION) calling convention. resolveSprint()
     * above is not reused here: it resolves and aborts for exactly one id
     * at a time, and reimplementing its per-id abort_unless() in a loop
     * here would abort on the *first* invalid id instead of resolving the
     * whole batch to determine ALL-OR-NOTHING membership the same way
     * resolveBulkTickets() does — this stays a single set-based query
     * instead.
     *
     * @return Collection<int, Sprint>
     */
    private function resolveBulkSprints(SupportCollection $sprintIds, Organization $organization, bool $forDelete): Collection
    {
        abort_unless($sprintIds->isNotEmpty(), 403);
        abort_unless($sprintIds->count() <= self::BULK_ACTION_MAX_ITEMS, 403);

        $sprints = Sprint::query()
            ->when($forDelete === false, fn ($query) => $query->withTrashed())
            ->whereHas('project', fn ($query) => $query->where('organization_id', $organization->id))
            ->whereIn('id', $sprintIds)
            ->get();

        abort_unless($sprints->count() === $sprintIds->count(), 403);

        if ($forDelete === false) {
            abort_unless($sprints->every(fn (Sprint $sprint) => $sprint->trashed()), 403);
        }

        return $sprints;
    }

    /**
     * Phase 14's first bulk capability — soft-deletes every Ticket in
     * $ticketIds in one call, exactly reusing deleteTicket()'s own
     * per-ticket mutation ($ticket->delete(), running through
     * TicketObserver the same way) rather than reimplementing it, just
     * looped across the whole resolved batch. Full-Access-only via the same
     * isFullAccessCapabilityAllowed()+authorizeFullAccess() direct-call
     * shape deleteTicket() itself uses — never authorizeCapability(), so a
     * Support Action session cannot reach this regardless of how many
     * capabilities it already legitimately has.
     *
     * CONCURRENCY EVALUATION (Phase 14, mirroring Phase 10's own written
     * evaluation for change_ticket_status rather than forcing a MySQL-only
     * test where one is not needed): two concurrent bulk-delete requests
     * with an overlapping ticket selection are not judged to need
     * lockForUpdate() here. The worst case if both requests' REVALIDATION
     * SELECT (inside resolveBulkTickets(), see its own docblock) both read
     * "not trashed" before either commits is that both transactions call
     * $ticket->delete() and both create an OrganizationSupportAction row
     * for the same ticket — a duplicate audit row for an action that was
     * independently authorized both times (both callers hold a genuinely
     * valid Full Access grant for this same organization), not an
     * unauthorized mutation, not a cross-organization leak, and not data
     * loss: the ticket ends up soft-deleted either way, which is the
     * correct end state regardless of execution order. This is the exact
     * same category of outcome sprintStart()'s own un-locked "close other
     * active sprints" query already accepts elsewhere on this page — an
     * idempotent-outcome mutation where ordering affects only cosmetic
     * duplication, not correctness or isolation. A stronger guarantee
     * (lockForUpdate() per resolved row, following the pattern
     * SupportSessionContext's own grant methods already establish) would
     * only remove that cosmetic duplicate-audit-row possibility, not close
     * any actual security gap, so it is not added for V1.
     */
    public function bulkDeleteTicket(array $ticketIds): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        abort_unless(SupportSessionContext::isFullAccessCapabilityAllowed('bulk_delete_ticket'), 403);
        $session = SupportSessionContext::authorizeFullAccess(auth()->user(), $organization->id);

        $ticketIds = $this->sanitizeBulkIds($ticketIds);

        // First pass — resolve+validate the whole batch before ever opening
        // a transaction, so an invalid batch fails fast without writing
        // anything. Its result is deliberately discarded: the mutation
        // below never reuses it, see resolveBulkTickets()'s own docblock.
        $this->resolveBulkTickets($ticketIds, $organization, forDelete: true);

        DB::transaction(function () use ($ticketIds, $organization, $session) {
            // REVALIDATION — re-resolved from scratch, inside the
            // transaction, immediately before any mutation runs. Never
            // trusts the pass above as still-valid proof.
            $tickets = $this->resolveBulkTickets($ticketIds, $organization, forDelete: true);

            foreach ($tickets as $ticket) {
                // Eloquent delete(), not a query-builder update(): same
                // reasoning as deleteTicket() — must run through
                // TicketObserver the same way every other delete path does.
                $ticket->delete();

                // Per-item audit: one OrganizationSupportAction row per
                // ticket, not a single batch-summary row — Phase 14's own
                // instruction is granular per-item auditing for bulk
                // (unlike deleteProject()'s bounded cascade-summary row,
                // which exists because a project's cascade can reach
                // thousands of rows; a bulk selection here is capped at
                // BULK_ACTION_MAX_ITEMS and operator-selected, not
                // cascaded).
                OrganizationSupportAction::create([
                    'support_session_id' => $session->id,
                    'actor_user_id' => auth()->id(),
                    'organization_id' => $organization->id,
                    'action' => 'ticket.bulk_delete',
                    'target_type' => Ticket::class,
                    'target_id' => $ticket->id,
                    'field' => 'deleted_at',
                    'old_value' => null,
                    'new_value' => (string) $ticket->deleted_at,
                ]);
            }
        });

        Notification::make()
            ->title(__(':count ticket(s) deleted', ['count' => $ticketIds->count()]))
            ->success()
            ->send();
    }

    /**
     * Symmetric undo of bulkDeleteTicket() above — same Full-Access-only
     * shape, same ALL-OR-NOTHING/REVALIDATION contract via
     * resolveBulkTickets(forDelete: false), same per-item audit granularity,
     * same concurrency reasoning (see bulkDeleteTicket()'s own docblock —
     * two overlapping concurrent bulk-restores produce, at worst, a
     * duplicate 'ticket.bulk_restore' audit row for an already-correct end
     * state, not an authorization or isolation gap).
     */
    public function bulkRestoreTicket(array $ticketIds): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        abort_unless(SupportSessionContext::isFullAccessCapabilityAllowed('bulk_restore_ticket'), 403);
        $session = SupportSessionContext::authorizeFullAccess(auth()->user(), $organization->id);

        $ticketIds = $this->sanitizeBulkIds($ticketIds);

        $this->resolveBulkTickets($ticketIds, $organization, forDelete: false);

        DB::transaction(function () use ($ticketIds, $organization, $session) {
            $tickets = $this->resolveBulkTickets($ticketIds, $organization, forDelete: false);

            foreach ($tickets as $ticket) {
                $oldDeletedAt = (string) $ticket->deleted_at;

                // Eloquent restore(), not a query-builder update(): same
                // reasoning as restoreTicket() — must run through
                // TicketObserver the same way every other restore path does.
                $ticket->restore();

                OrganizationSupportAction::create([
                    'support_session_id' => $session->id,
                    'actor_user_id' => auth()->id(),
                    'organization_id' => $organization->id,
                    'action' => 'ticket.bulk_restore',
                    'target_type' => Ticket::class,
                    'target_id' => $ticket->id,
                    'field' => 'deleted_at',
                    'old_value' => $oldDeletedAt,
                    'new_value' => null,
                ]);
            }
        });

        Notification::make()
            ->title(__(':count ticket(s) restored', ['count' => $ticketIds->count()]))
            ->success()
            ->send();
    }

    /**
     * Sprint's equivalent of bulkDeleteTicket() above — same shape, reusing
     * deleteSprint()'s own per-sprint mutation. Same Epic finding
     * deleteSprint()'s own docblock documents applies unchanged here:
     * App\Observers\SprintObserver has no deleting()/deleted() hook at all,
     * so this never touches a sprint's mirrored epic — no cascade to audit
     * for delete, unlike bulkRestoreSprint() below.
     */
    public function bulkDeleteSprint(array $sprintIds): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        abort_unless(SupportSessionContext::isFullAccessCapabilityAllowed('bulk_delete_sprint'), 403);
        $session = SupportSessionContext::authorizeFullAccess(auth()->user(), $organization->id);

        $sprintIds = $this->sanitizeBulkIds($sprintIds);

        $this->resolveBulkSprints($sprintIds, $organization, forDelete: true);

        DB::transaction(function () use ($sprintIds, $organization, $session) {
            $sprints = $this->resolveBulkSprints($sprintIds, $organization, forDelete: true);

            foreach ($sprints as $sprint) {
                $sprint->delete();

                OrganizationSupportAction::create([
                    'support_session_id' => $session->id,
                    'actor_user_id' => auth()->id(),
                    'organization_id' => $organization->id,
                    'action' => 'sprint.bulk_delete',
                    'target_type' => Sprint::class,
                    'target_id' => $sprint->id,
                    'field' => 'deleted_at',
                    'old_value' => null,
                    'new_value' => (string) $sprint->deleted_at,
                ]);
            }
        });

        Notification::make()
            ->title(__(':count sprint(s) deleted', ['count' => $sprintIds->count()]))
            ->success()
            ->send();
    }

    /**
     * Symmetric undo of bulkDeleteSprint() above. Unlike that method, this
     * carries forward the one genuine asymmetry restoreSprint() itself
     * documents: App\Observers\SprintObserver::restoring() unconditionally
     * cascade-restores an already-trashed mirrored epic for *each* sprint
     * restored — a real per-item side effect, not folded into a single
     * batch-level summary. So this batch can legitimately produce between N
     * and 2N OrganizationSupportAction rows (one 'sprint.bulk_restore' row
     * per sprint, plus one 'epic.auto_restore' row for each sprint whose own
     * mirrored epic was independently trashed and gets cascade-restored
     * with it) — this is not folded into a single "N sprints, M epics"
     * summary row the way deleteProject()'s cascade is, because Phase 14's
     * own instruction is granular per-item auditing, and this is a
     * mechanical, per-item application of restoreSprint()'s own
     * already-approved single-target behavior (Phase 12) to each member of
     * the batch — not a new decision being made unilaterally here. The
     * trashed epic (if any) is captured *before* $sprint->restore() runs,
     * for the same reason restoreSprint() itself captures it first: that
     * call is what changes the epic's own deleted_at.
     */
    public function bulkRestoreSprint(array $sprintIds): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        abort_unless(SupportSessionContext::isFullAccessCapabilityAllowed('bulk_restore_sprint'), 403);
        $session = SupportSessionContext::authorizeFullAccess(auth()->user(), $organization->id);

        $sprintIds = $this->sanitizeBulkIds($sprintIds);

        $this->resolveBulkSprints($sprintIds, $organization, forDelete: false);

        DB::transaction(function () use ($sprintIds, $organization, $session) {
            $sprints = $this->resolveBulkSprints($sprintIds, $organization, forDelete: false);

            foreach ($sprints as $sprint) {
                $oldDeletedAt = (string) $sprint->deleted_at;

                // Captured before restore() runs — SprintObserver::restoring()
                // is what would change (or clear) this epic's own deleted_at,
                // exactly mirroring restoreSprint()'s own single-target logic.
                $trashedEpic = $sprint->epic()->onlyTrashed()->first();
                $epicOldDeletedAt = $trashedEpic !== null ? (string) $trashedEpic->deleted_at : null;

                $sprint->restore();

                OrganizationSupportAction::create([
                    'support_session_id' => $session->id,
                    'actor_user_id' => auth()->id(),
                    'organization_id' => $organization->id,
                    'action' => 'sprint.bulk_restore',
                    'target_type' => Sprint::class,
                    'target_id' => $sprint->id,
                    'field' => 'deleted_at',
                    'old_value' => $oldDeletedAt,
                    'new_value' => null,
                ]);

                if ($trashedEpic !== null) {
                    OrganizationSupportAction::create([
                        'support_session_id' => $session->id,
                        'actor_user_id' => auth()->id(),
                        'organization_id' => $organization->id,
                        'action' => 'epic.auto_restore',
                        'target_type' => Epic::class,
                        'target_id' => $trashedEpic->id,
                        'field' => 'deleted_at',
                        'old_value' => $epicOldDeletedAt,
                        'new_value' => null,
                    ]);
                }
            }
        });

        Notification::make()
            ->title(__(':count sprint(s) restored', ['count' => $sprintIds->count()]))
            ->success()
            ->send();
    }

    /**
     * A trashed child's deleted_at must fall within this many seconds of the
     * project's own to count as "taken down by the project's own cascade"
     * rather than independently deleted at some unrelated earlier time.
     * Mirrors App\Observers\ProjectObserver::CASCADE_WINDOW_SECONDS exactly
     * (30 seconds) — that constant is private on the Observer, so this
     * duplicates its literal value rather than introduce a new coupling
     * between the two classes. This copy is read-only/display-only: it is
     * only ever used to compute the numbers shown in projectRestoreImpact()/
     * restoreProject()'s confirm() dialog and audit row, never to decide
     * what $project->restore() actually restores — that decision is made
     * entirely by ProjectObserver itself, which this method's callers never
     * override.
     */
    private const PROJECT_CASCADE_WINDOW_SECONDS = 30;

    /**
     * The Ticket/Sprint/Epic counts that restoring $project would actually
     * cascade-restore, per the same within-window rule
     * App\Observers\ProjectObserver::restoring() itself applies. Shared by
     * restoreProject() and projectRestoreImpact() so the confirm() dialog's
     * numbers and the numbers the audit row records can never drift apart
     * from each other — both read this same method.
     *
     * Three bounded count() queries against the already-resolved,
     * organization-scoped $project — not a naive per-row loop, and not
     * Model::all() filtered in PHP.
     *
     * @return array{tickets: int, sprints: int, epics: int}
     */
    private function projectCascadeRestoreCounts(Project $project): array
    {
        $cutoff = $project->deleted_at->copy()->subSeconds(self::PROJECT_CASCADE_WINDOW_SECONDS);

        return [
            'tickets' => $project->tickets()->onlyTrashed()->where('deleted_at', '>=', $cutoff)->count(),
            'sprints' => $project->sprints()->onlyTrashed()->where('deleted_at', '>=', $cutoff)->count(),
            'epics' => $project->epics()->onlyTrashed()->where('deleted_at', '>=', $cutoff)->count(),
        ];
    }

    /**
     * Public entry point for the Blade confirm() dialog on a trashed
     * project's Restore button — takes the project's scalar id, never a
     * Project model (same Livewire-implicit-binding reasoning as every
     * other public per-row method on this page, e.g. sprintStatusBreakdown()),
     * and re-resolves it through the organization-scoped, withTrashed()
     * query itself rather than trusting the caller already filtered.
     *
     * @return array{tickets: int, sprints: int, epics: int}
     */
    public function projectRestoreImpact(int $projectId): array
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        $project = Project::withTrashed()
            ->where('organization_id', $organization->id)
            ->find($projectId);

        abort_unless($project !== null && $project->trashed(), 403);

        return $this->projectCascadeRestoreCounts($project);
    }

    /**
     * Phase 13 — the highest-blast-radius Full Access capability yet: unlike
     * deleteTicket()/deleteSprint() (Phase 11/12), a Project cascades to
     * every Ticket, Sprint and Epic beneath it. Same Full-Access-only shape
     * as those two — bypasses SupportSessionContext::authorizeCapability()
     * entirely and calls the allowlist check + authorizeFullAccess()
     * directly, for the identical reason: a Support Action session must
     * never reach this, even though changeProjectStatus() already
     * legitimately belongs to it via authorizeAction().
     *
     * Cascade behavior, verified by reading App\Observers\ProjectObserver::
     * deleting() in full during Phase 13 inspection: it chunks through
     * $project->tickets()/sprints()/epics() (200 at a time via chunkById —
     * safe to delete while chunking) and calls Eloquent delete() on each, so
     * TicketObserver::deleted() still fires per cascaded ticket exactly like
     * every other delete path (TicketResource, deleteTicket() above) —
     * SprintObserver has no deleting()/deleted() hook at all (confirmed
     * during Phase 12 inspection, unchanged since), and Epic has no
     * Observer whatsoever, so nothing beyond their own deleted_at changes
     * for those two. This method does not reimplement any of that:
     * $project->delete() alone triggers the entire cascade, so this stays a
     * thin authorize → resolve → mutate → audit wrapper, per this phase's
     * own core principle — reuse, don't reimplement.
     *
     * Confirmed NOT cascaded by ProjectObserver or any other Observer, by
     * reading every Observer in app/Observers during Phase 13 inspection:
     * TicketComment, TicketHour, TicketActivity, and Spatie Media
     * attachments. None has a deleting()/deleted() hook wired to a Ticket
     * or Project delete — TicketComment's only lifecycle mechanism is its
     * own, entirely independent 90-day prune of already-soft-deleted rows
     * (see docs/soft-deletes.md). They are left exactly as
     * $project->delete() (via its Ticket cascade) already leaves them; this
     * phase does not add new cascade logic for them.
     *
     * Audit granularity: a project can have thousands of tickets, so one
     * OrganizationSupportAction row per cascaded child (the literal reading
     * of "audit every affected entity") risks thousands of INSERTs inside a
     * single transaction — a real timeout risk at scale, not a hypothetical
     * one. This writes exactly two rows instead: the required
     * 'project.delete' row (field='deleted_at', matching the exact old/new
     * shape deleteTicket()/deleteSprint() already use) plus one O(1)
     * 'project.delete_cascade_summary' row recording the ticket/sprint/epic
     * counts that were cascaded — field left null, per
     * organization_support_actions' own migration docblock ("field
     * nullable: a future action kind that isn't a single-field change is
     * not forced to fabricate one"). Counts are read via withCount() on the
     * same organization-scoped $project this method already resolved, not a
     * second, separately-scoped query that could diverge from it.
     */
    public function deleteProject(int $projectId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        abort_unless(SupportSessionContext::isFullAccessCapabilityAllowed('delete_project'), 403);
        $session = SupportSessionContext::authorizeFullAccess(auth()->user(), $organization->id);

        // Same organization-scoped resolution as every other write method
        // on this page — never Project::find($projectId) trusted before the
        // fact. withCount(), not three separate count() queries and not a
        // second, independently-scoped query — the counts describe exactly
        // this resolved $project.
        $project = Project::query()
            ->where('organization_id', $organization->id)
            ->withCount(['tickets', 'sprints', 'epics'])
            ->find($projectId);

        // No separate "already trashed" precondition check is needed (same
        // reasoning as deleteTicket()/deleteSprint()): this query never used
        // withTrashed(), so the default SoftDeletingScope already excludes a
        // trashed project from the result entirely.
        abort_unless($project !== null, 403);

        $ticketsCount = (int) $project->tickets_count;
        $sprintsCount = (int) $project->sprints_count;
        $epicsCount = (int) $project->epics_count;

        DB::transaction(function () use ($project, $session, $organization, $ticketsCount, $sprintsCount, $epicsCount) {
            // Eloquent delete(), not a query-builder update(): this is what
            // runs the entire cascade through App\Observers\ProjectObserver::
            // deleting() — see this method's own docblock for what was
            // verified about it during Phase 13 inspection. That cascade is
            // already wrapped in its own DB::transaction() (a nested
            // savepoint inside this one), so a failure anywhere in it —
            // including this audit write below — rolls back everything
            // together.
            $project->delete();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'project.delete',
                'target_type' => Project::class,
                'target_id' => $project->id,
                'field' => 'deleted_at',
                'old_value' => null,
                'new_value' => (string) $project->deleted_at,
            ]);

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'project.delete_cascade_summary',
                'target_type' => Project::class,
                'target_id' => $project->id,
                'field' => null,
                'old_value' => null,
                'new_value' => "tickets:{$ticketsCount},sprints:{$sprintsCount},epics:{$epicsCount}",
            ]);
        });

        Notification::make()
            ->title(__('Project deleted'))
            ->success()
            ->send();
    }

    /**
     * The symmetric undo of deleteProject() above — same Full-Access-only
     * reasoning, and the same withTrashed()/trashed() precondition shape as
     * restoreTicket()/restoreSprint().
     *
     * Cascade behavior, verified by reading App\Observers\ProjectObserver::
     * restoring() in full during Phase 13 inspection: it only restores the
     * tickets/sprints/epics that are *still trashed* and whose own
     * deleted_at falls within PROJECT_CASCADE_WINDOW_SECONDS of the
     * project's own deleted_at — a child independently deleted before the
     * project ever was is correctly left alone (see that constant's own
     * docblock for why this class duplicates the literal window value
     * rather than sharing it with the Observer). restoreSprint()'s own
     * epic-mirror side effect (SprintObserver::restoring()) is a genuine
     * second-order consequence of this same cascade whenever a cascaded
     * sprint is restored — this method does not audit that separately from
     * the cascade summary row below, unlike restoreSprint() itself, because
     * Phase 13 treats the whole cascade as one bounded summary rather than
     * per-entity rows (see deleteProject()'s own docblock for the audit-
     * granularity reasoning, which applies identically here).
     *
     * Counts are computed *before* $project->restore() runs (which is what
     * changes deleted_at on each cascaded row) via
     * projectCascadeRestoreCounts() — the exact same method
     * projectRestoreImpact() uses for the confirm() dialog, so what the
     * dialog promises and what the audit row records can never drift apart.
     */
    public function restoreProject(int $projectId): void
    {
        $organization = $this->organization();

        abort_unless($organization !== null, 403);

        abort_unless(SupportSessionContext::isFullAccessCapabilityAllowed('restore_project'), 403);
        $session = SupportSessionContext::authorizeFullAccess(auth()->user(), $organization->id);

        // withTrashed() is required here — the whole point of this method is
        // to find a project that is currently soft-deleted, which the
        // default Eloquent scope excludes.
        $project = Project::withTrashed()
            ->where('organization_id', $organization->id)
            ->find($projectId);

        abort_unless($project !== null, 403);

        // Symmetric precondition to deleteProject(): a project that is not
        // currently trashed is an invalid target for restore.
        abort_unless($project->trashed(), 403);

        $oldDeletedAt = (string) $project->deleted_at;
        $cascadeCounts = $this->projectCascadeRestoreCounts($project);

        DB::transaction(function () use ($project, $session, $organization, $oldDeletedAt, $cascadeCounts) {
            // Eloquent restore(), not a query-builder update(): runs through
            // ProjectObserver::restoring(), the exact cascade this method's
            // own docblock documents.
            $project->restore();

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'project.restore',
                'target_type' => Project::class,
                'target_id' => $project->id,
                'field' => 'deleted_at',
                'old_value' => $oldDeletedAt,
                'new_value' => null,
            ]);

            OrganizationSupportAction::create([
                'support_session_id' => $session->id,
                'actor_user_id' => auth()->id(),
                'organization_id' => $organization->id,
                'action' => 'project.restore_cascade_summary',
                'target_type' => Project::class,
                'target_id' => $project->id,
                'field' => null,
                'old_value' => "tickets:{$cascadeCounts['tickets']},sprints:{$cascadeCounts['sprints']},epics:{$cascadeCounts['epics']}",
                'new_value' => null,
            ]);
        });

        Notification::make()
            ->title(__('Project restored'))
            ->success()
            ->send();
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
