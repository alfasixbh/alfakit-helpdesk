<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Events;

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The assignee or group of a ticket changed.
 */
final class TicketAssigned
{
    use Dispatchable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?Model $assignee,
        public readonly ?Model $previousAssignee = null,
    ) {}
}
