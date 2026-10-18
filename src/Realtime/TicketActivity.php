<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Realtime;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithBroadcasting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Something happened on a ticket: a message was added (`message`), someone is typing
 * (`typing`) or the ticket changed (`updated`). Broadcast on the panel's channels of the
 * ticket model and of the ticket (the same channels as AlfaKit's `RecordChanged`, so the
 * same policies and tenant checks authorise them) with keys only:
 * `{ticket, kind, actor, by, at}`. Never subjects or message bodies: clients refetch
 * through the authorised endpoints.
 */
class TicketActivity implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use InteractsWithBroadcasting;
    use InteractsWithSockets;

    /** Echo listens with `.listen('.helpdesk.activity', …)`. */
    public const EVENT = 'helpdesk.activity';

    /**
     * @param  array<Channel>  $channels
     * @param  array{ticket: int|string, kind: string, actor: string, by: int|string|null, at: int}  $payload
     */
    public function __construct(
        public readonly array $channels,
        public readonly array $payload,
        ?string $connection = null,
    ) {
        if ($connection !== null) {
            $this->broadcastVia($connection);
        }
    }

    /**
     * @return array<Channel>
     */
    public function broadcastOn(): array
    {
        return $this->channels;
    }

    public function broadcastAs(): string
    {
        return self::EVENT;
    }

    /**
     * @return array{ticket: int|string, kind: string, actor: string, by: int|string|null, at: int}
     */
    public function broadcastWith(): array
    {
        return $this->payload;
    }
}
