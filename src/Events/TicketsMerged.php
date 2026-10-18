<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Events;

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * `$source` was merged into `$target` (its messages moved there, the source closed).
 */
final class TicketsMerged
{
    use Dispatchable;

    public function __construct(
        public readonly Ticket $source,
        public readonly Ticket $target,
    ) {}
}
