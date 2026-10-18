<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Events;

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The requester rated a solved ticket (`satisfaction_rating` 1 to 5).
 */
final class TicketRated
{
    use Dispatchable;

    public function __construct(public readonly Ticket $ticket) {}
}
