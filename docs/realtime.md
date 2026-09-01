# Real-time Notifications

Rencanakan broadcasts live updates over WebSockets (Pusher) so boards and ticket
views update without a refresh. It uses Laravel's event broadcasting on the
server and [Laravel Echo](https://laravel.com/docs/broadcasting#client-side-installation)
on the client.

Real-time is **optional and off by default** — the app works fully without it.

## Broadcast events

| Event | Broadcast name | Channels | Fires when |
|-------|----------------|----------|------------|
| `App\Events\TicketStatusChanged` | `ticket.status.changed` | `private-project.{id}` | A ticket moves to a new status |
| `App\Events\TicketCommentPosted` | `ticket.comment.posted` | `private-ticket.{id}`, `private-project.{id}` | A comment is posted |

Both are **private channels**, authorized in `routes/channels.php`:

- `project.{id}` — the user must own or be a member of the project.
- `ticket.{id}` — the user must be the ticket owner/responsible, or a
  member/owner of its project.

Events implement `broadcastWhen()` (via `ChecksBroadcastDriver`): they only
broadcast when a broadcaster is actually configured, so a missing Pusher key is
a safe no-op rather than an error.

## Enabling real-time

1. **Server env** (`.env`):

   ```dotenv
   BROADCAST_DRIVER=pusher
   PUSHER_APP_ID=your-app-id
   PUSHER_APP_KEY=your-key
   PUSHER_APP_SECRET=your-secret
   PUSHER_APP_CLUSTER=mt1
   ```

   The `VITE_PUSHER_*` variables already mirror these for the front‑end.

2. **Front‑end packages & build:**

   ```bash
   npm install            # installs laravel-echo + pusher-js
   npm run build          # or: npm run dev
   ```

   `resources/js/bootstrap.js` initialises `window.Echo` only when
   `VITE_PUSHER_APP_KEY` is set.

3. **Queue.** Broadcasts are dispatched as events; if you use
   `QUEUE_CONNECTION=database`, keep a `queue:work` worker running so broadcasts
   are delivered.

## Subscribing from the front‑end

```js
// After Echo is initialised (see resources/js/bootstrap.js)
window.Echo.private(`project.${projectId}`)
    .listen('.ticket.status.changed', (e) => {
        // e = { ticket_id, code, name, project_id, old_status_id, new_status_id, actor_id }
        // move the ticket card to its new column
    })
    .listen('.ticket.comment.posted', (e) => {
        // e = { comment_id, ticket_id, content, user_id }
        // append the comment live
    });
```

> The leading dot in `.ticket.status.changed` tells Echo to use the custom
> `broadcastAs()` name instead of the fully‑qualified class name.

## Testing

Broadcasting is covered by `tests/Feature/BroadcastingTest.php`, which asserts
events are dispatched on the right channels with the right payload, and that the
`broadcastWhen()` guard and channel authorization behave correctly — without
sending anything over the network (`BROADCAST_DRIVER=null` in `phpunit.xml`).
