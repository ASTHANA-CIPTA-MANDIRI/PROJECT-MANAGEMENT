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

    // Example subscriptions (see docs/realtime.md):
    //
    //   window.Echo.private(`project.${projectId}`)
    //       .listen('.ticket.status.changed', (e) => { /* update board */ })
    //       .listen('.ticket.comment.posted', (e) => { /* append comment */ });
}
