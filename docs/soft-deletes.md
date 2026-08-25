# Soft Delete Policy

11 models use `SoftDeletes`. Before this pass none of them had a restore path
in the UI and none had a prune schedule — deleted records were either stuck
(no way back for a genuine mistake) or accumulated forever. This page records
the audit and the policy each model landed on, reviewed 2026-08-25.

## Restorable (Filament UI: Trashed filter + Restore action)

| Model | Where | Notes |
|---|---|---|
| `User` | UserResource | Accidental account deletion must be recoverable. |
| `Project` | ProjectResource | Restoring cascades to its trashed tickets/sprints/epics — see below. |
| `Ticket` | TicketResource | `code` is never freed on delete (allocated from the project's counter), so a restored ticket can't collide with a new one. |
| `Sprint` | ProjectResource → SprintsRelationManager | Restoring also restores its mirrored Epic — see below. No standalone SprintResource; it only exists as a project relation. |
| `Epic` | *(no direct UI — see below)* | Restored automatically when its sprint is restored. |
| `Activity` | ActivityResource | Small admin-managed lookup table (time-log categories). |
| `ProjectStatus` | ProjectStatusResource | Small admin-managed lookup table. |
| `TicketType` | TicketTypeResource | Small admin-managed lookup table. |
| `TicketPriority` | TicketPriorityResource | Small admin-managed lookup table. |
| `TicketStatus` | TicketStatusResource | Small admin-managed lookup table. |

Each policy's `restore()` mirrors its existing `delete()` check (whoever can
delete a record can undo that delete). `ForceDeleteAction` was deliberately
**not** added anywhere: permanent purging of these models is a rare, high-risk
operation better done deliberately (`php artisan tinker` / a future dedicated
command) than exposed as a one-click button next to Restore.

### Parent-child restore

- **Project → tickets/sprints/epics.** `ProjectObserver::deleting()` cascades
  a project delete to its tickets, sprints and epics (see the M-6 fix).
  `ProjectObserver::restoring()` is the symmetric undo: it restores the
  tickets/sprints/epics that are still trashed *and* whose `deleted_at` falls
  within 30 seconds of the project's own — i.e. rows the cascade took down,
  not something a user independently deleted at some unrelated earlier time.
  A plain `onlyTrashed()` restore isn't enough here: a ticket deleted on its
  own days before the project would also be "still trashed" and would
  wrongly come back. The 30-second window is generous (every row in one
  cascade run is written within the same request) while erring toward
  restoring too little rather than ever resurrecting an unrelated delete.
- **Sprint → Epic.** Every sprint's epic is created by (and only by)
  `SprintObserver::created()` — there is no such thing as an
  independently-created "sprint's epic". `SprintObserver::restoring()`
  therefore restores the mirrored epic whenever it is found trashed, since
  the only way it could be trashed while its sprint is being restored is that
  the sprint (or its project) took it down too.
- **Epic without a sprint.** The Road Map also allows creating a "manual"
  epic with no sprint behind it (`EpicForm`, a bespoke Livewire component —
  not a Filament Resource table). Deleting one of those is independent of any
  sprint, and there is currently no restore UI for that specific case: adding
  one means extending the hand-built Road Map/Gantt UI, which was out of
  scope for this pass. Recovery for that narrow case is a manual
  `Epic::withTrashed()->find($id)->restore()`.

## Prune only (no restore UI)

| Model | Retention | Mechanism |
|---|---|---|
| `TicketComment` | 90 days after soft-delete | `TicketComment::prunable()` (Laravel's `Prunable` trait) + the scheduled `model:prune` command (`app/Console/Kernel.php`, daily at 03:00). |

Comments are high-volume, user-authored content with no existing "undo
delete" expectation anywhere in the product (unlike a whole ticket or
project). Rather than add restore UI for every comment, a soft-deleted
comment is kept for 90 days — long enough to recover from an accidental
delete via direct DB access if truly needed — then permanently removed by
`model:prune` so the table doesn't grow without bound. Laravel Scout removes
the comment from the search index automatically when it is finally deleted
(the same `deleted` hook that normally fires).

## Why not everything

`php artisan model:prune` only ever discovers models that opt in via the
`Prunable`/`MassPrunable` trait, so adding it to `TicketComment` does not
affect any of the restorable models above — none of them use that trait, and
none are on any prune schedule. That is deliberate: `User`, `Project`,
`Ticket`, `Sprint`, `Epic` and the reference-lookup tables are all things an
admin would plausibly want back after an accidental delete, so they get a
restore path and no automatic purge, while `TicketComment` gets the reverse.
