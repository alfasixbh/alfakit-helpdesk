<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Tests\Fixtures;

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Spatie\Tags\HasTags;

/** A ticket model with spatie/laravel-tags, as `config('alfakit-helpdesk.models.ticket')`. */
class TaggedTicket extends Ticket
{
    use HasTags;
}
