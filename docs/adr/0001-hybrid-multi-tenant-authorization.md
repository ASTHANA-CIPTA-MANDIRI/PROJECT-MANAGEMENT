# ADR 0001: Hybrid Multi-Tenant Authorization Architecture

- **Status:** Accepted (documentation only — no implementation yet)
- **Date:** 2026-09-01
- **Deciders:** Project owner, informed by Phase 1 technical spike
- **Supersedes:** —
- **Related:** [Audit SaaS Rencanakan](https://claude.ai/code/artifact/d635dee8-96fb-4189-9058-275d3ce62007) (round 10 audit), [Roadmap SaaS Rencanakan](https://claude.ai/code/artifact/fb86c9d2-85d8-4fe4-92c8-db953d23b2bb), [Spike: Spatie Permission Teams](https://claude.ai/code/artifact/4f996a0a-608b-4693-86b2-c9745dfc4e80)

## Context

Rencanakan is being evolved from a single-instance project-management app into a
multi-tenant SaaS product. The round-10 audit proved the current schema has
**zero** tenant/organization boundary — `Role`, `Permission`, `Settings`, and
several lookup tables (`ticket_types`, `ticket_priorities`, `labels`,
`activities`, `project_statuses`) are instance-global. A Phase 1 spike then
asked one narrow, falsifiable question: *can `spatie/laravel-permission`'s
built-in Teams feature (already installed, currently disabled) become the
foundation for Organization-level RBAC without breaking the app's existing
authorization?*

The spike ran six proof-of-concept scenarios against this app's **real**
models and policies (`User`, `Role`, `Permission`, `Project`, `Ticket`,
`ProjectPolicy`, `TicketPolicy`) on `spatie/laravel-permission` **5.11.1**,
the version actually installed (confirmed via `composer.lock`, not assumed
from generic docs). Results:

| Scenario | Question | Result |
|---|---|---|
| A | Does a permission granted in Organization 1 leak into Organization 2? | **No leak** — passed |
| B | Does an Organization-level permission bypass Project-level membership? | **No bypass** — `Project::isAccessibleBy()` stayed fully independent — passed |
| C | Does the same user with different roles in two organizations get correct, non-leaking results? | **Correct, isolated** — passed |
| D | Does a role assigned with no team context (i.e. every existing role assignment today) survive once team context is live? | **Fails** — a `team_id = NULL` pivot row is invisible under any concrete team context; empirically reproduced, not assumed |
| E | Does `$user->can()` reflect a team-context switch mid-process on the same `User` instance? | **Fails** — stale result from the previous context; root-caused to `HasRoles::hasRole()`/`getPermissionsViaRoles()` both calling `$this->loadMissing('roles', ...)`, which skips reloading an already-hydrated relation |
| F | Does a real app Policy (`TicketPolicy::update`, permission **and** object-level `isInvolved()`) still work correctly under team context? | **Works correctly** — passed |

Also established empirically: of this app's 112 `->can()` call sites and 26
`authorize()`-related call sites, **none** need to be edited individually for
Teams to work — a single request-boundary hook is sufficient, because every
one of those call sites resolves down to the same team-scoped
`hasPermissionTo()`/`hasRole()` machinery. Zero permission checks exist today
in `app/Jobs`, `app/Console/Commands`, `app/Notifications`,
`app/Listeners`, or `app/Observers`.

## Decision

**HYBRID.** No single mechanism covers every authorization layer this
product needs. Each layer is assigned to whichever mechanism the spike
actually proved handles it correctly:

```text
Spatie Teams          → Organization-level RBAC (Owner / Admin / Member)
Existing project_users → Project-level RBAC/access (unchanged)
Policies               → Final authorization gate (unchanged)
Platform Super Admin   → Separate mechanism, explicitly NOT team-scoped
Subscription/Plan      → Feature entitlement and limits (future phase, not RBAC)
```

This is not a compromise made to avoid a harder decision — it is the only
option the POC evidence supports. Full-Teams-everywhere fails Scenario D
(Platform Super Admin). Full-manual-scoping (rejecting Teams entirely) would
work but throws away a working, tested package and forces editing 138
existing call sites for no proven benefit, since Scenarios A/B/C/F show
Teams handles the Organization layer correctly as-is.

## Architecture

### Platform

```text
Platform
└── Platform Super Admin
```

The Platform Super Admin — the operator of the SaaS product itself, who
manages every organization — **does not** use Spatie Teams as its
authorization source. Scenario D is the reason: a role assignment that is
conceptually cross-organization has no safe representation in Teams' pivot
model. `wherePivot('team_id', getPermissionsTeamId())` requires an exact
match; a `NULL` team_id on the pivot never matches any concrete team context,
concrete or absent.

Two implementation options are viable (a decision for Phase 4, not this
ADR):

1. **Dedicated column**, e.g. `users.is_platform_super_admin` — fully
   decoupled from the Spatie RBAC tables. Simplest to reason about; the
   check never touches `roles()`/`hasRole()` at all.
2. **Temporary team-scope bypass** around the check only, e.g.
   `PermissionRegistrar::$teams = false; ...check...; PermissionRegistrar::$teams = $previous;`
   — this mirrors a pattern the package itself already uses internally
   (`HasRoles::bootHasRoles()`'s `deleting` hook does exactly this to detach
   roles safely regardless of team context — see
   `vendor/spatie/laravel-permission/src/Traits/HasRoles.php:20-32`). Not
   invented for this app; a precedent from the package's own source.

Whichever is chosen, `team_id = NULL` **must never** be used as a stand-in
for "Platform Super Admin." Scenario D proved that representation does not
survive contact with a live team context.

#### Addendum: Super Admin support access is not a Scenario-D bypass

A separate, narrower feature was added after this ADR: a Platform Super
Admin can start a temporary "view as Organization" support session
(`App\Support\SupportSessionContext`, `organization_support_sessions` table,
`PlatformOrganizations::startSupportSession` action,
`App\Filament\Pages\OrganizationSupportView` page). This is deliberately
**not** an instance of the Scenario-D problem above, and not a general
"Super Admin can see everything" context bypass:

- **Time-boxed** — a session expires after 1 hour (`expires_at`), enforced
  by re-deriving active/expired state on every read, not a one-time check.
- **Logged** — every session requires a non-empty `reason` and is a
  permanent audit row (`organization_support_sessions`), never silently
  granted.
- **Read-only by construction** — `OrganizationSupportView` defines no write
  actions at all, so there is nothing on that page for a Policy or
  `Gate::before` to guard. It reads `$organization->projects` directly
  instead of `Project::accessibleBy()`, which is safe specifically because
  no write path exists on that page to accidentally inherit the bypass.
- **Never touches the three axes above** — `SupportSessionContext` does not
  read or write `App\Support\OrganizationContext`, does not call
  `Project::accessibleBy()`/`isAccessibleBy()`/`isManageableBy()`, and no
  `App\Policies\*` class knows this feature exists. It is a separate
  side-channel, not a modification to how Organization/Project/Policy
  authorization already works.

This preserves the invariant this ADR establishes throughout: object-level
authorization never assumes "Super Admin can see everything" — even the one
place Super Admin *does* get cross-organization read access is additive,
audited, temporary, and outside the three axes rather than a bypass inside
them.

### Organization

```text
Organization
├── Owner
├── Admin
└── Member
```

Spatie Teams is the RBAC mechanism for this layer only.

```text
Spatie team_id = organization.id
```

Role names are no longer globally unique — the migration philosophy below
covers the index change this requires. `RoleResource`'s uniqueness
validation will need to scope to the active `team_id`, not just
`guard_name`, when this is implemented (not now).

### Project

```text
Project
├── project_users
├── Project Manager
├── Worker
└── Guest
```

Unchanged. Scenario B proved `Project::scopeAccessibleBy()` /
`isAccessibleBy()` / `isManageableBy()` are already fully independent of
Spatie's team context — an Organization-level permission does not and must
not substitute for project membership. This layer is not touched by the
Teams rollout at all; it continues exactly as audited in the round-10
report.

### Subscription

Role and Plan are different concepts and must not be conflated, even though
an earlier product direction ("Role = package") predates this ADR's
Organization layer:

```text
Organization
    ↓
Subscription
    ↓
Plan
    ↓
Features / Limits
```

Example of the distinction:

```text
Owner              = Role               (Organization RBAC, Spatie Teams)
Pro                = Plan                (Subscription)
50 Projects         = Limit              (entitlement, read alongside can())
Advanced Reports    = Feature            (entitlement, read alongside can())
```

A user's **Role** answers "what may this person do inside their
organization." A **Plan** answers "what is this organization allowed to
have/use at all," independent of who inside it is asking. Both are checked
together at the point of action (e.g. "can create a project" = has the
`Create project` permission **and** the organization's project count is
under its Plan's limit), but they are never the same table or the same
check.

## Security Invariant

```text
User of Organization A
MUST NOT access Organization B
```

This applies to every organization-scoped resource, without exception,
unless the acting user is authenticated as the Platform Super Admin through
its own separate, explicit authorization path (never as a side effect of
an Organization-level role):

- Projects
- Tickets
- Timesheets
- Attachments
- Labels
- Statuses
- Types
- Priorities
- Activities
- Organization Roles
- Organization Permissions

The round-10 audit already proved most of these tables have **no** existing
tenant boundary at all (Bagian 15/16 of that report) — this invariant is the
contract those future migrations must satisfy, not a description of
anything that exists today.

## Organization Context

The current organization is a piece of request state that determines both
`setPermissionsTeamId()` (for Spatie Teams) and every data query's
`organization_id` scope. It is **never** trusted from an inbound identifier
alone.

**Core rule:** an `organization_id` arriving via a URL segment, request
parameter, session value, or any other client-influenced or
previously-cached channel is a *claim*, not a *fact*. It must be verified
against a real membership relationship — the same discipline this app
already applies to `project_id` today (`Project::isAccessibleBy($user)`,
called explicitly at every access point rather than trusted from route
binding alone; see the round-10 audit's Bagian 8). The Organization
equivalent (`$organization->hasMember($user)` or equivalent) must run
**before** `setPermissionsTeamId()` is called, every time, in every context
below. If the check fails, the request is rejected — it does not fall
through to a default organization.

| Context | How organization context must be resolved and verified |
|---|---|
| **HTTP** | A dedicated middleware, placed immediately after authentication and before any route/controller logic, resolves the intended organization (from subdomain, session "active organization," or a validated route parameter — the exact signal is a Phase 2/4 decision, not this ADR) and verifies membership before calling `setPermissionsTeamId()`. No downstream code re-derives context on its own. |
| **Filament** | Runs inside the same HTTP request lifecycle, so the middleware above covers it. A platform-level Filament panel that lets the Platform Super Admin browse *across* organizations is explicitly Level-1 territory (see Platform section) and must not rely on a single-organization `setPermissionsTeamId()` context at all. |
| **Livewire** | Livewire components re-execute on every subsequent AJAX request, not just initial page load — the same reason this app's `AuthorizesPageAccess` trait and the `Ticket/Attachments.php` fix (round-10 audit, Bagian 10) place authorization in `boot()` rather than `mount()`. Organization context must be re-verified on every Livewire request the same way, not resolved once and trusted for the component's lifetime. |
| **API** | Same middleware as HTTP, applied to the `api` middleware group. A token acting on behalf of an organization must have that organization independently verified against the token owner's membership on every request — never trusted from a request parameter alone, mirroring the "wrong provider/wrong project" risk class the round-10 API audit already checked for `project_id`. |
| **Jobs** | Queue workers are long-running and process jobs for many different organizations in sequence — there is no safe "ambient" context to inherit from whatever dispatched the job. Every organization-scoped job must carry its target `organization_id` explicitly in its constructor (serialized with the job payload) and call `setPermissionsTeamId()` as the first line of `handle()`. Today's `ImportJiraTicketsJob` already carries its acting user explicitly rather than trusting ambient state (round-10 audit, Bagian J) — this is the same discipline extended to organization context. |
| **Commands** | Same principle as Jobs. A command that touches multiple organizations (e.g. a per-organization report) must loop and call `setPermissionsTeamId()` fresh for each one — and, per the Permission State rules below, must not reuse a single hydrated `User` instance across that loop without refreshing it. |
| **Notifications** | A synchronously-sent notification inherits the context of the request that triggered it and needs nothing extra. A **queued** notification runs in a worker process and follows the Jobs rule above: resolve and set its own organization context at the start of its handler, never assume the dispatching request's context survived. |
| **Scheduler** | Laravel's scheduler only invokes Commands — the Commands rule applies directly. `reports:daily`/`tickets:due-date-reminders`-style commands that will eventually become organization-partitioned (round-10 audit, Bagian 16, already flagged this as a scalability concern independent of Teams) must resolve context per organization inside their own loop. |
| **Webhooks** | Highest-risk context: an inbound webhook payload is unauthenticated until its signature is verified, so an `organization_id` present in the payload itself must never be used directly. Verify the signature first; then resolve the organization from the app's own stored record tied to that webhook's subscription/customer identifier (a server-side lookup, not a client-supplied value); then set context. This mirrors the existing FormRequest pattern of resolving foreign keys through an access-scoped query rather than trusting the request body (round-10 audit, Bagian G) — applied to a channel with an even lower trust floor than an authenticated HTTP request. |

## Permission State

Scenario E's finding is an operational rule, not a code change to make now:

- Do not reuse a single `User` model instance across an organization-context
  switch without ensuring its permission-related state is fresh. In
  practice: either re-fetch the user (`User::find($id)` /
  `$user->fresh()`) or explicitly `$user->unsetRelation('roles')` and
  `$user->unsetRelation('permissions')` immediately after every
  `setPermissionsTeamId()` call that changes context mid-process.
- Organization context must be determined **once, consistently, at the
  start of each unit of work** (a request, a job execution, one iteration
  of a command's per-organization loop) — never inferred midway through
  from whatever the previous state happened to be.
- **Do not** "fix" this by editing the 112 existing `->can()` call sites (or
  the 26 `authorize()` sites) individually. The spike proved the problem is
  never in those call sites themselves — it is entirely about *when* and
  *how many times* `setPermissionsTeamId()` is called relative to *when* the
  `User` instance's relations were first hydrated. Fix the context-setting
  discipline at the boundaries listed above, not the 138 call sites that
  consume its result.
- Do not carry request state (session values, previously-resolved
  organization ids, previously-loaded model instances) across unit-of-work
  boundaries — each HTTP request, each job execution, and each command
  invocation starts its organization context resolution fresh, per the
  Organization Context table above.

## Migration Philosophy

No destructive migration, ever, without a rollback plan. Every schema
change in this program follows the same eight-step sequence, in order:

1. **Additive migration first** — new tables/columns only; nothing existing
   is renamed, retyped, or dropped in the same migration that adds
   something new.
2. **Nullable column** — every new foreign key (`organization_id`, `team_id`,
   and equivalents on the currently-global lookup tables identified in the
   round-10 audit) starts nullable. Nothing existing breaks the moment the
   migration runs.
3. **Dry-run** — any backfill command runs in `--dry-run` mode first,
   reporting what it *would* change, mirroring the existing
   `security:remediate-default-role --dry-run` pattern already established
   in this codebase.
4. **Backfill** — populate the new column for existing rows (e.g. every
   pre-SaaS installation's projects move under one default Organization,
   per the roadmap's Phase 2).
5. **Validation** — confirm 100% of rows that need a value have one, and
   that no unexpected row was missed or mis-assigned, before touching
   constraints.
6. **Staging test** — the full sequence (migrate → backfill → validate) is
   rehearsed against a staging copy of real (or realistic) data, not only
   against the SQLite in-memory test database.
7. **Production migration** — the same, now-rehearsed sequence runs against
   production, during a defined maintenance/low-traffic window if the
   backfill is non-trivial in size.
8. **Enforce `NOT NULL`** only after step 5 confirms every row that needs a
   value has one — never as part of the same migration that adds the
   column.

Scenario A's own POC directly demonstrates why step-skipping is dangerous:
adding the `team_id` column alone, without also correcting the `roles`
table's unique index, produced an immediate, reproducible failure
(`UNIQUE constraint failed: roles.name, roles.guard_name`) the first time a
second organization tried to create a role with a name already used
elsewhere. A schema change that looks additive can still break behavior if
a dependent constraint isn't updated in the same reasoned step.

## Backward Compatibility

`users`, `projects`, `tickets`, `project_users`, `roles`, and `permissions`
must remain fully usable throughout the entire transition — existing
installations do not stop working the day Organization support is merged,
and do not require an all-at-once cutover.

No behavior change to any of these tables ships without an explicit
migration strategy covering it (per the Migration Philosophy above). This
specifically includes: `RoleResource`'s uniqueness validation, the
`RestoreBulkAction`/`DeleteBulkAction` authorization fixes already shipped
on `jarne`, and every one of the 15 existing Policies — none of these are
touched by this ADR, and none should be touched until the corresponding
implementation phase (per the roadmap) actually requires it.

## Final Architecture

```text
Platform
   ↓
Organizations
   ↓
Organization RBAC          (Spatie Teams, team_id = organization.id)
   ↓
Projects
   ↓
Project Membership         (existing project_users, unchanged)
   ↓
Tickets
```

```text
Organization
   ↓
Subscription
   ↓
Plan
   ↓
Feature Entitlement
```

These two paths are independent and cross only at the point of action: an
ability check reads both "does this role permit it" (top diagram) and "does
this plan allow it" (bottom diagram) — never merging the two into a single
table or a single check.

## Final Verdict

**HYBRID**

```text
Spatie Teams            → Organization RBAC
Existing project_users  → Project RBAC/access
Policies                → Final authorization
Platform Super Admin    → Platform-level authorization, kept separate
Subscription/Plan       → Feature entitlement and limits
```

This decision is documentation only. No migration, Policy, Resource, or
config change has been made as part of this ADR — see the companion spike
branch (`spike/spatie-permission-teams`) for the proof-of-concept evidence
this decision is based on, kept separate and unmerged.
