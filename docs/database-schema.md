# Database Schema

MySQL 8, managed entirely through Laravel migrations (`database/migrations`).
The schema has **32 tables**: the project‑management domain, reference data, and
supporting framework tables.

## Entity–Relationship diagram (core domain)

```mermaid
erDiagram
    USERS ||--o{ PROJECTS : "owns"
    PROJECT_STATUSES ||--o{ PROJECTS : "status"
    PROJECTS ||--o{ PROJECT_USERS : "members"
    USERS ||--o{ PROJECT_USERS : "membership"
    PROJECTS ||--o{ PROJECT_FAVORITES : "favorited"
    USERS ||--o{ PROJECT_FAVORITES : "favorites"

    PROJECTS ||--o{ TICKETS : "contains"
    USERS ||--o{ TICKETS : "owns"
    USERS ||--o{ TICKETS : "responsible for"
    TICKET_STATUSES ||--o{ TICKETS : "status"
    TICKET_TYPES ||--o{ TICKETS : "type"
    TICKET_PRIORITIES ||--o{ TICKETS : "priority"
    EPICS ||--o{ TICKETS : "epic"
    SPRINTS ||--o{ TICKETS : "sprint"

    PROJECTS ||--o{ EPICS : "has"
    EPICS ||--o{ EPICS : "parent of"
    PROJECTS ||--o{ SPRINTS : "has"
    EPICS ||--o{ SPRINTS : "linked epic"
    PROJECTS ||--o{ TICKET_STATUSES : "custom statuses"

    TICKETS ||--o{ TICKET_COMMENTS : "comments"
    USERS ||--o{ TICKET_COMMENTS : "author"
    TICKETS ||--o{ TICKET_ACTIVITIES : "status log"
    USERS ||--o{ TICKET_ACTIVITIES : "actor"
    TICKETS ||--o{ TICKET_HOURS : "time logged"
    USERS ||--o{ TICKET_HOURS : "logged by"
    ACTIVITIES ||--o{ TICKET_HOURS : "categorised as"
    TICKETS ||--o{ TICKET_RELATIONS : "relates"
    TICKETS ||--o{ TICKET_SUBSCRIBERS : "watchers"
    USERS ||--o{ TICKET_SUBSCRIBERS : "subscribes"

    USERS {
        bigint id PK
        string name
        string email UK
        string type "db | social | oidc"
        string password "nullable (social)"
        timestamp deleted_at "soft delete"
    }
    PROJECTS {
        bigint id PK
        string name
        string ticket_prefix "<= 3 chars, unique"
        string type "kanban | scrum"
        string status_type "default | custom"
        bigint owner_id FK
        bigint status_id FK
        timestamp deleted_at
    }
    TICKETS {
        bigint id PK
        string code "e.g. ABC-1"
        string name
        longtext content
        int order
        double estimation "hours, nullable"
        bigint project_id FK
        bigint owner_id FK
        bigint responsible_id FK "nullable"
        bigint status_id FK
        bigint type_id FK
        bigint priority_id FK
        bigint epic_id FK "nullable"
        bigint sprint_id FK "nullable"
        timestamp deleted_at
    }
    SPRINTS {
        bigint id PK
        string name
        date starts_at
        date ends_at
        datetime started_at "nullable"
        datetime ended_at "nullable"
        bigint project_id FK
        bigint epic_id FK "auto-created"
    }
    EPICS {
        bigint id PK
        string name
        date starts_at
        date ends_at
        bigint project_id FK
        bigint parent_id FK "nullable, self"
    }
    TICKET_HOURS {
        bigint id PK
        double value "hours"
        bigint ticket_id FK
        bigint user_id FK
        bigint activity_id FK "nullable"
    }
    TICKET_ACTIVITIES {
        bigint id PK
        bigint ticket_id FK
        bigint old_status_id FK
        bigint new_status_id FK
        bigint user_id FK
    }
    PROJECT_USERS {
        bigint id PK
        bigint project_id FK
        bigint user_id FK
        string role "employee | customer | administrator"
    }
```

## Table reference

### Core domain

| Table | Purpose | Key columns |
|-------|---------|-------------|
| `projects` | A project (Kanban or Scrum) | `owner_id`, `status_id`, `ticket_prefix`, `type`, `status_type` |
| `tickets` | Work item / issue | `code`, `project_id`, `owner_id`, `responsible_id`, `status_id`, `type_id`, `priority_id`, `epic_id`, `sprint_id`, `estimation`, `order` |
| `sprints` | Time‑boxed iteration | `project_id`, `epic_id`, `starts_at`, `ends_at`, `started_at`, `ended_at` |
| `epics` | Large body of work (roadmap) | `project_id`, `parent_id`, `starts_at`, `ends_at` |

### Ticket collaboration

| Table | Purpose |
|-------|---------|
| `ticket_comments` | Discussion on a ticket (`ticket_id`, `user_id`, `content`) |
| `ticket_activities` | Status‑change audit log (`old_status_id` → `new_status_id`, `user_id`) |
| `ticket_hours` | Logged time (`value` in hours, optional `activity_id`) |
| `ticket_relations` | Links between tickets (`relation_id`, `type`) |
| `ticket_subscribers` | Watchers (`ticket_id`, `user_id`) |

### Reference data

| Table | Purpose |
|-------|---------|
| `project_statuses` | Project status options (`is_default`) |
| `ticket_statuses` | Ticket status; `project_id` null = global, set = per‑project custom |
| `ticket_types` | Ticket type (Bug, Feature, …) with `icon`/`color` |
| `ticket_priorities` | Priority levels |
| `activities` | Time‑tracking categories (Development, Meeting, …) |

### Membership & access

| Table | Purpose |
|-------|---------|
| `project_users` | Project membership with pivot `role` |
| `project_favorites` | Per‑user favorited projects |
| `users` | Accounts (`type` = db/social/oidc, nullable password for social) |
| `roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions` | Spatie RBAC |

### Framework & support

`migrations`, `password_resets`, `personal_access_tokens` (Sanctum),
`sessions`/`cache` (if configured), `jobs`, `failed_jobs`, `notifications`,
`media` (Spatie Media Library), `settings` (Spatie Settings),
`socialite_users`, `pending_user_emails`.

## Conventions

- **Soft deletes** on domain tables (`projects`, `tickets`, `sprints`, `epics`,
  statuses/types/priorities, `users`, `activities`) via a `deleted_at` column —
  records are hidden, not destroyed. Relations use `withTrashed()` so history
  stays readable.
- **Ticket `code`** (`PREFIX-N`) and **`order`** are generated automatically in
  the `Ticket` model's `creating` hook.
- **Every sprint auto‑creates a linked epic** (see `Sprint::boot`), so a sprint
  always appears on the roadmap.
- `ticket_activities` has **no soft delete**; it is pruned by
  `php artisan cleanup:old-activities` (archived to file, then removed).

> ⚠️ The `settings` table has no unique index on `(group, name)` — MySQL
> tolerates this but SQLite (used in tests) cannot upsert, so tests fake the
> settings instead of persisting them.
