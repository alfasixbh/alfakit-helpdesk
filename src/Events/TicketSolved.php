<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Events;

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

final class TicketSolved
{
    use Dispatchable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly ?Authenticatable $by = null,
    ) {}
}
