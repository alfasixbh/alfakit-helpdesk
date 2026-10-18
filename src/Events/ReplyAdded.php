<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Events;

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A message was added to a ticket: a public reply (`$message->from_requester` tells who
 * wrote it) or an internal note (`$message->isInternal()`). Not dispatched for the first
 * message of a new ticket (see `TicketCreated`).
 */
final class ReplyAdded
{
    use Dispatchable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketMessage $message,
    ) {}
}
