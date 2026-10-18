<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Portal\Concerns;

use AlfaSix\Alfakit\Actions\Exceptions\Halt;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Illuminate\Database\Eloquent\Model;

/**
 * Shared by the portal's standalone components: the signed-in customer, and whether they
 * (or a guest holding a signed link) may see a ticket.
 *
 * @mixin \AlfaSix\Alfakit\Standalone\Component
 */
trait InteractsWithPortal
{
    public function customer(): ?Model
    {
        $user = $this->getPanel()->auth()->user();

        return $user instanceof Model ? $user : null;
    }

    /**
     * The requester's own ticket, or one opened from a signed link (`$guest`, which only
     * travels inside the encrypted context token). Merged tickets show their target.
     */
    protected function mayView(?Ticket $ticket, bool $guest): bool
    {
        if ($ticket === null) {
            return false;
        }

        return $guest || $ticket->isRequestedBy($this->customer());
    }

    protected function failWith(string $title, ?string $body = null): never
    {
        Notification::make()->danger()->title($title)->body($body)->send();

        throw new Halt;
    }
}
