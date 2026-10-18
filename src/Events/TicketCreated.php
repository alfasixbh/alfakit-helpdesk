<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Events;

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket was created (by an agent, the portal, the contact form, the chat or email).
 */
final class TicketCreated
{
    use Dispatchable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?TicketMessage $message = null,
    ) {}
}
