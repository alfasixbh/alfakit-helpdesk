<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;

/**
 * The Reply-To address of a ticket's emails, `support+{ticket}.{signature}@example.com`
 * (from `inbound.reply_to`), so replies find their ticket without trusting the subject.
 */
final class ReplyAddress
{
    public static function for(Ticket $ticket): ?string
    {
        $base = config('alfakit-helpdesk.inbound.reply_to');

        if (! config('alfakit-helpdesk.inbound.enabled', false) || ! is_string($base) || ! str_contains($base, '@')) {
            return null;
        }

        [$local, $domain] = explode('@', $base, 2);

        return $local.'+'.$ticket->getKey().'.'.self::signature((string) $ticket->getKey()).'@'.$domain;
    }

    /**
     * The ticket a recipient address points to (its signature must match).
     */
    public static function resolve(string $address): ?Ticket
    {
        if (preg_match('/\+(\d{1,20})\.([a-f0-9]{16})@/i', $address, $match) !== 1) {
            return null;
        }

        if (! hash_equals(self::signature($match[1]), strtolower($match[2]))) {
            return null;
        }

        $model = AlfakitHelpdesk::ticketModel();
        $ticket = $model::query()->whereKey((int) $match[1])->first();

        return $ticket instanceof Ticket ? $ticket : null;
    }

    private static function signature(string $ticket): string
    {
        return substr(hash_hmac('sha256', 'alfakit-helpdesk-reply|'.$ticket, (string) config('app.key')), 0, 16);
    }
}
