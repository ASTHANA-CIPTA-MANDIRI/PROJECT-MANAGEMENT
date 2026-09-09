# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Stack

Laravel 9.52 (EOL), Filament 2 admin panel (Livewire 2 / Alpine / Tailwind), PHP 8.2, Spatie Permission (RBAC), Sanctum, Laravel Scout, Spatie Media Library + Settings. Tests run against SQLite in-memory (see `phpunit.xml`), not the dev MySQL database — no DB service needed to run the suite.

**Local toolchain note:** `php`, `composer`, and `npm` are not on PATH in some shells on this machine. Use explicit paths when a bare command fails: `/Applications/MAMP/bin/php/php8.2.26/bin/php`, and route composer through it (`... /bin/php /usr/local/bin/composer <cmd>`). For npm, prepend nvm to PATH first.

## Commands

```bash
# Full test suite (SQLite in-memory, no DB service needed)
vendor/bin/phpunit

# A single test file or method
vendor/bin/phpunit --filter=ClassName
vendor/bin/phpunit --filter=test_method_name
# Passing multiple explicit paths only runs the first suite - use --filter or run the full suite.

# Code style (must pass with zero diffs before commit; CI runs --test on app, database, tests)
vendor/bin/pint app database tests
vendor/bin/pint <specific-file-or-dir>   # format just what you touched

# Static analysis (must be 0 errors; baseline is a ratchet, not a muffler -
# regenerating it to hide a new error defeats the point)
vendor/bin/phpstan analyse

# Frontend
npm run dev      # vite dev server
npm run build    # required before running feature tests that render @vite views

# First-time setup
composer install-project   # npm install, composer install, npm run build, .env, key:generate, migrate, seed
```

CI (`.github/workflows/tests.yml`) runs on every branch push, not just main: Pint + PHPStan (`lint` job), full suite with a **70% line coverage gate** (`phpunit` job), and a composer/npm advisory gate that fails only on advisories not already listed in `docs/accepted-security-advisories.json` (`security-audit` job).

## Working conventions (established by git history, not just preference)

- One logical change = one commit, each independently self-contained and revertable. Don't bundle unrelated fixes.
- Before committing: run the specific test(s) for the change, then the full suite, then Pint on the touched files. All three green before committing.
- Commit messages are written in Indonesian in this repository's actual history — follow that convention, explaining the *why*, not just the *what*.
- Prefer minimal, behavior-preserving changes. When a change intentionally reverses previously-tested behavior (not fixing a bug, but changing a product decision), say so explicitly in the commit message and update the affected tests to assert the new behavior rather than deleting them.
- Never assume a "bug" without checking whether an existing test already asserts that exact behavior as intentional — this codebase has repeatedly had cases where surprising-looking Policy logic (e.g. "deny with no organization context, no exceptions") was a deliberate, tested product decision, not an oversight.

## Architecture

### Authorization is three independent, layered axes — never merge their logic

1. **Organization** (`organization_users.role`: owner/admin/member) — the SaaS tenant/subscription boundary. `App\Support\OrganizationContext::current($user)` resolves "which Organization is this user acting as" and **always re-verifies real membership**, never trusting a cached/session value alone. A user is limited to exactly one Organization at a time (`OrganizationPolicy::create()` requires zero existing memberships).
2. **Project** (`project_users.role`, `projects.owner_id`) — unchanged by the Organization layer. `Project::isAccessibleBy()` / `isManageableBy()` are the single source of truth for project-level access, called consistently across Filament, Livewire, the API, and jobs. A project with `organization_id = null` is legacy/pre-tenant data and is deliberately left ungated by the Organization checks (never an escape hatch — see `Project::isWithinOrganizationContext()`).
3. **Policy** (`app/Policies/*`) — the final gate. Nearly every policy ability pairs a flat Spatie permission with an object-level check (e.g. `TicketPolicy::update()` = permission **AND** `isInvolved()` — owner/responsible/project member). Holding the permission alone is never sufficient.

`Project::isManageableThroughOrganizationBy()` (Owner+Admin) and the narrower `isOwnerManageableThroughOrganizationBy()` (Owner only, used by `ProjectPolicy::update()`/`delete()`) are the two bridges between the Organization and Project axes — both re-verify the project's `organization_id` matches the user's *current* Organization context on every call; a version that skipped that re-check was a real cross-organization hole.

`AuthServiceProvider::boot()` also forces Filament's `Resource::authorizeWithGate()` / `RelationManager::authorizeWithGate()` — Filament 2 defaults to *allowing* an ability with no matching Policy method, which this closes.

### Trial gating (SaaS) sits as a fourth layer, orthogonal to the three above

`organizations.trial_ends_at` (nullable — `null` means grandfathered/unlimited, e.g. pre-existing installations backfilled by `organizations:backfill`). `App\Support\TrialGate::active($organization)` is the single yes/no. Enforced by one `Gate::before()` callback in `AuthServiceProvider` (`denyIfOrganizationLocked()`) that matches purely by **target model class** (a fixed list: Project/Ticket/Sprint/Epic/TicketComment/TicketHour — deliberately excluding `Organization` itself, so a locked-out Owner can still reach billing/settings to unlock). A self-registered or social-login user gets a personal Organization auto-provisioned on a 7-day trial — this hooks into the `Illuminate\Auth\Events\Registered` / `DutchCodingCompany\FilamentSocialite\Events\Registered` listeners (`App\Listeners\ProvisionOrganizationOn*Registration`), **not** `UserObserver`, because that observer fires for every `User` creation including every test factory call.

### Side effects live in Observers, not controllers

Every model lifecycle side effect (ticket code/order generation, activity logging, broadcast events, cache invalidation, cascading soft-deletes) is an Eloquent Observer in `app/Observers/`, registered in `EventServiceProvider::boot()`. This means side effects fire consistently regardless of the write path (Filament, Livewire, API, Tinker, seeders) — when adding a new mutation path, check whether an existing Observer already handles the side effect before writing it again.

### Client-supplied IDs are never trusted past the UI layer

A recurring pattern across every Livewire form (`IssueForm`, `EpicForm`, `RoadMap`, board filters, the Organization switcher): a `<select>`'s options being scoped to what the user may see is UX only. Every submit handler re-resolves the posted ID through an access-scoped query (`Project::accessibleBy($user)->whereKey($postedId)`, etc.) immediately before use, rather than trusting the rendered options list.

### Filament panel, not a Panel Provider

This is Filament **2**, configured via `config/filament.php` + `AppServiceProvider::boot()` — there is no `Panel` class/provider like Filament 3. Pages live in `app/Filament/Pages`, Resources in `app/Filament/Resources`, both auto-discovered by path per `config/filament.php`.

## Documentation

`docs/` has ~14 files including `docs/adr/0001-hybrid-multi-tenant-authorization.md` (the multi-tenant/SaaS architecture decision record — read this before touching anything Organization-related), `docs/security-advisories.md` + `docs/accepted-security-advisories.json` (dependency advisories formally accepted as risk, read by the CI gate), and `docs/soft-deletes.md`.
