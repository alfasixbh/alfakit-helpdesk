<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Policies;

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Support\Agents;
use Illuminate\Database\Eloquent\Model;

/**
 * The default ticket policy: agents (`HelpdeskPlugin::authorize()`, everyone in the panel
 * by default) may do everything in the panel. Register your own with
 * `Gate::policy(Ticket::class, …)`. Customers never go through this policy: the portal
 * only shows them their own tickets.
 */
class TicketPolicy
{
    public function viewAny(Model $user): bool
    {
        return Agents::isAgent($user);
    }

    public function view(Model $user, Ticket $ticket): bool
    {
        return Agents::isAgent($user);
    }

    public function create(Model $user): bool
    {
        return Agents::isAgent($user);
    }

    public function update(Model $user, Ticket $ticket): bool
    {
        return Agents::isAgent($user) && ! $ticket->isMerged();
    }

    public function delete(Model $user, Ticket $ticket): bool
    {
        return Agents::isAgent($user);
    }

    public function deleteAny(Model $user): bool
    {
        return Agents::isAgent($user);
    }
}
