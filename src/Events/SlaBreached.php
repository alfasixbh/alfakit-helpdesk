<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Events;

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A ticket missed an SLA target (`first_response` or `resolution`). Dispatched once per
 * ticket by `php artisan alfakit-helpdesk:sla`.
 */
final class SlaBreached
{
    use Dispatchable;

    /**
     * @param  'first_response'|'resolution'  $target
     */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $target,
    ) {}
}
