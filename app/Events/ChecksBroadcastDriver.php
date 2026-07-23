<?php

namespace App\Events;

/**
 * Only broadcast when a broadcaster is actually configured. This keeps the
 * app safe when BROADCAST_DRIVER=pusher but no credentials are set (the
 * default in development), where a synchronous broadcast would otherwise throw.
 */
trait ChecksBroadcastDriver
{
    public function broadcastWhen(): bool
    {
        $driver = config('broadcasting.default');

        if ($driver === null || $driver === 'null') {
            return false;
        }

        // Pusher needs an app key; without it a broadcast attempt would fail.
        if ($driver === 'pusher') {
            return filled(config('broadcasting.connections.pusher.key'));
        }

        // log / redis / other drivers are safe to broadcast to.
        return true;
    }
}
