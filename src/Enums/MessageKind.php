<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Enums;

/**
 * A message of a ticket's conversation. Only `reply` messages are ever shown to the
 * requester: `note` is internal to agents and `event` records changes (status, merges).
 */
enum MessageKind: string
{
    case Reply = 'reply';
    case Note = 'note';
    case Event = 'event';

    public function isPublic(): bool
    {
        return $this === self::Reply;
    }
}
