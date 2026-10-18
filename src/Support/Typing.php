<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Illuminate\Support\Facades\Cache;

/**
 * Who is typing on a ticket, for a few seconds after each keystroke ping: agents (shown to
 * the visitor's chat widget, which polls) and the requester (broadcast to the agents).
 */
final class Typing
{
    public const SECONDS = 6;

    public static function agent(Ticket $ticket, string $name): void
    {
        Cache::put(self::key($ticket, 'agent'), mb_substr($name, 0, 120), self::SECONDS);
    }

    public static function requester(Ticket $ticket): void
    {
        Cache::put(self::key($ticket, 'requester'), true, self::SECONDS);
    }

    /** The name of the agent typing, if one is. */
    public static function agentTyping(Ticket $ticket): ?string
    {
        $name = Cache::get(self::key($ticket, 'agent'));

        return is_string($name) && $name !== '' ? $name : null;
    }

    public static function requesterTyping(Ticket $ticket): bool
    {
        return Cache::get(self::key($ticket, 'requester')) === true;
    }

    public static function stopAgent(Ticket $ticket): void
    {
        Cache::forget(self::key($ticket, 'agent'));
    }

    public static function stopRequester(Ticket $ticket): void
    {
        Cache::forget(self::key($ticket, 'requester'));
    }

    private static function key(Ticket $ticket, string $who): string
    {
        return 'alfakit-helpdesk:typing:'.$ticket->getKey().':'.$who;
    }
}
