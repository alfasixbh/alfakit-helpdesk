<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Realtime;

use AlfaSix\Alfakit\Realtime\ModelChanges;
use AlfaSix\Alfakit\Realtime\RealtimeChannels;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Broadcasts `TicketActivity` to every `realtime()` panel with the helpdesk plugin. Panels
 * without `realtime()` get nothing (their inbox polls).
 */
final class HelpdeskBroadcaster
{
    public const MESSAGE = 'message';

    public const TYPING = 'typing';

    public const UPDATED = 'updated';

    /**
     * @param  'message'|'typing'|'updated'  $kind
     * @param  'agent'|'requester'|'system'  $actor
     */
    public static function dispatch(Ticket $ticket, string $kind, string $actor = 'system', ?Model $by = null): void
    {
        $key = $ticket->getKey();

        if (! is_int($key) && ! is_string($key)) {
            return;
        }

        foreach (alfakit()->getPanels() as $panel) {
            if (! $panel->isRealtimeConfigured() || ! $panel->hasPlugin(HelpdeskPlugin::ID)) {
                continue;
            }

            if (RealtimeChannels::resourceFor($panel, $ticket::class) === null) {
                continue;
            }

            try {
                $channels = ModelChanges::channelsFor($panel, $ticket);
            } catch (Throwable) {
                continue;
            }

            if ($channels === []) {
                continue;
            }

            $byKey = $by?->getKey();

            broadcast(new TicketActivity($channels, [
                'ticket' => $key,
                'kind' => $kind,
                'actor' => $actor,
                'by' => is_int($byKey) || is_string($byKey) ? $byKey : null,
                'at' => (int) floor(microtime(true) * 1000),
            ], $panel->getBroadcastConnection()))->toOthers();
        }
    }
}
