# Architecture

Rencanakan is a Laravel 9 project‑management application. The admin experience
is built with the [Filament](https://filamentphp.com) TALL‑stack panel
(Livewire + Alpine + Tailwind), and a versioned REST API exposes the same domain
to external clients.

This document describes the architecture using the
[C4 model](https://c4model.com): **Context → Container → Component**.

## Level 1 — System Context

Who uses the system and what it talks to.

```mermaid
C4Context
    title System Context — Rencanakan

    Person(member, "Team member", "Creates projects, tickets, sprints; logs time")
    Person(admin, "Administrator", "Manages users, roles and settings")

    System(app, "Rencanakan", "Project management: Kanban/Scrum boards, sprints, time tracking, REST API")

    System_Ext(smtp, "SMTP / Mail", "Delivers verification & notification emails")
    System_Ext(oauth, "OAuth providers", "Google, GitHub social login")
    System_Ext(sentry, "Sentry", "Exception & performance monitoring")
    System_Ext(pusher, "Pusher", "Real-time broadcast (optional)")
    System_Ext(jira, "Jira", "One-off ticket import")

    Rel(member, app, "Uses", "HTTPS")
    Rel(admin, app, "Administers", "HTTPS")
    Rel(app, smtp, "Sends email", "SMTP")
    Rel(app, oauth, "Authenticates via", "OAuth 2.0")
    Rel(app, sentry, "Reports errors & traces", "HTTPS")
    Rel(app, pusher, "Publishes events", "WebSocket")
    Rel(app, jira, "Imports tickets from", "REST")
```

## Level 2 — Containers

The deployable/runtime pieces.

```mermaid
C4Container
    title Container Diagram — Rencanakan

    Person(member, "Team member")

    System_Boundary(app, "Rencanakan") {
        Container(web, "Web application", "PHP 8.1+ / Laravel 9 / Filament 2", "Serves the admin panel, Livewire components and the REST API")
        Container(worker, "Queue worker", "php artisan queue:work", "Sends queued notifications, runs Jira import job")
        Container(scheduler, "Scheduler", "cron → php artisan schedule:run", "Runs reports:daily and cleanup:old-activities")
        ContainerDb(db, "Database", "MySQL 8", "Projects, tickets, sprints, users, activity log")
        Container(assets, "Frontend assets", "Vite build", "Compiled CSS/JS served from /public/build")
    }

    System_Ext(smtp, "SMTP / Mail")
    System_Ext(sentry, "Sentry")

    Rel(member, web, "Uses", "HTTPS")
    Rel(web, db, "Reads / writes", "Eloquent / PDO")
    Rel(worker, db, "Reads jobs / writes", "PDO")
    Rel(scheduler, web, "Invokes artisan commands")
    Rel(worker, smtp, "Delivers email")
    Rel(web, sentry, "Reports exceptions")
    Rel(web, assets, "References built manifest")
```

> **Note on defaults.** Out of the box `CACHE_DRIVER`, `SESSION_DRIVER` and
> `FILESYSTEM_DISK` are file/local, and `QUEUE_CONNECTION` is `database`.
> Redis and Pusher are supported but optional. In development the queue can run
> synchronously (`QUEUE_CONNECTION=sync`).

## Level 3 — Components (inside the Web application)

How the Laravel application is organised.

```mermaid
C4Component
    title Component Diagram — Web application

    Container_Boundary(web, "Web application") {
        Component(panel, "Filament Admin Panel", "Resources, Pages, Widgets", "Kanban/Scrum boards, roadmap, timesheets, CRUD")
        Component(api, "REST API v1", "Controllers + API Resources", "Token-authenticated JSON endpoints")
        Component(requests, "Form Requests", "Validation", "Shared validation for panel & API")
        Component(policies, "Policies", "Authorization", "Spatie permission checks per resource")
        Component(models, "Domain Models", "Eloquent", "Project, Ticket, Sprint, Epic, ...")
        Component(notifications, "Notifications", "Mail / queued", "Ticket events, daily summary")
        Component(commands, "Console Commands", "Artisan", "reports:daily, cleanup:old-activities")
        Component(monitoring, "Error tracking", "Sentry integration", "Exceptions + user context + tracing")
    }

    ContainerDb(db, "Database", "MySQL 8")

    Rel(panel, models, "Uses")
    Rel(api, requests, "Validates with")
    Rel(api, models, "Uses")
    Rel(panel, policies, "Authorizes via")
    Rel(api, policies, "Authorizes via")
    Rel(models, db, "Persists to")
    Rel(models, notifications, "Triggers")
    Rel(commands, models, "Reads/aggregates")
    Rel(panel, monitoring, "Errors reported to")
    Rel(api, monitoring, "Errors reported to")
```

## Key cross-cutting concerns

| Concern | Where | Notes |
|---------|-------|-------|
| **Authentication** | Filament Breezy (panel), Sanctum tokens (API), Socialite (Google/GitHub) | Panel access gated by `User::canAccessFilament()` (must hold a role) |
| **Authorization** | Spatie Permission + Policies | Every resource has a policy; API also checks project membership |
| **Rate limiting** | `RouteServiceProvider` + middleware | API 100/min per token, public 60/min per IP, login 5/min, `internal` IP‑whitelist |
| **Atomicity** | `DB::transaction` | Ticket creation, sprint start, batch ops, Jira import |
| **Performance** | Eager loading + widget caching (1h TTL) | See `Project::statistics()` and `WithCachedData` |
| **Observability** | Sentry + query log channel | Enabled via env; no-op without a DSN |

See also: [Database schema](database-schema.md) · [API](api.md) · [Deployment](deployment.md)
