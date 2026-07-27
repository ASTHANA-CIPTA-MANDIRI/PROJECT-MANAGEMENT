import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Initialise Laravel Echo for real-time updates, but only when a Pusher key is
// configured and Echo hasn't already been set up. With real-time disabled
// (empty VITE_PUSHER_APP_KEY) the app runs normally without sockets.
if (!window.Echo && import.meta.env.VITE_PUSHER_APP_KEY) {
    window.Echo = new Echo({
        broadcaster: 'pusher',
        key: import.meta.env.VITE_PUSHER_APP_KEY,
        cluster: import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1',
        wsHost: import.meta.env.VITE_PUSHER_HOST
            ? import.meta.env.VITE_PUSHER_HOST
            : `ws-${import.meta.env.VITE_PUSHER_APP_CLUSTER}.pusher.com`,
        wsPort: import.meta.env.VITE_PUSHER_PORT ?? 80,
        wssPort: import.meta.env.VITE_PUSHER_PORT ?? 443,
        forceTLS: (import.meta.env.VITE_PUSHER_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    // The Kanban/Scrum boards subscribe themselves: their Livewire components
    // declare `echo-private:project.{id},.ticket.status.changed` (and
    // `.ticket.comment.posted`) listeners via getListeners(), so they refresh
    // live off this same window.Echo instance — no manual subscription here.
    // See docs/realtime.md and app/Helpers/KanbanScrumHelper.php.
}
