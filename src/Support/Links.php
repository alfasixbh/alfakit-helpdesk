<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Links to a ticket: for the requester (the portal for users, a signed link for guests)
 * and for agents (the ticket in the panel).
 */
final class Links
{
    /** How long the signed links of emails to guests work. */
    public const GUEST_LINK_DAYS = 60;

    public static function forRequester(Ticket $ticket): ?string
    {
        if ($ticket->requester_id !== null && config('alfakit-helpdesk.portal.enabled', true)) {
            return AlfakitHelpdesk::route('portal.show', ['ticket' => $ticket->getKey()]);
        }

        return self::guest($ticket);
    }

    /**
     * A signed link that shows a guest's ticket (and lets them reply) without signing in.
     */
    public static function guest(Ticket $ticket): ?string
    {
        $name = AlfakitHelpdesk::routeName('guest.show');

        if (! Route::has($name)) {
            return null;
        }

        return URL::temporarySignedRoute($name, now()->addDays(self::GUEST_LINK_DAYS), ['ticket' => $ticket->getKey()]);
    }

    /**
     * A signed link to rate the ticket (satisfaction survey).
     */
    public static function rate(Ticket $ticket): ?string
    {
        $name = AlfakitHelpdesk::routeName('rate');

        if (! Route::has($name)) {
            return null;
        }

        return URL::temporarySignedRoute($name, now()->addDays(self::GUEST_LINK_DAYS), ['ticket' => $ticket->getKey()]);
    }

    public static function forAgent(Ticket $ticket): ?string
    {
        $panel = AlfakitHelpdesk::panel();

        if ($panel === null) {
            return null;
        }

        $resource = AlfakitHelpdesk::plugin()->getTicketResource();

        try {
            $tenant = $panel->hasTenancy() ? $ticket->tenant : null;

            return $resource::getUrl('view', ['record' => $ticket], panel: $panel, tenant: $tenant);
        } catch (Throwable) {
            return null;
        }
    }
}
