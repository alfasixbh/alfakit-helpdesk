<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Illuminate\Support\Str;

/**
 * Guests (live chat visitors) prove they own a ticket with a token: `{ticket}.{secret}.{signature}`.
 * The signature (HMAC with the app key) rejects forged tokens without a query, and only a
 * hash of the random secret is stored, so a database leak reveals no usable token.
 */
final class AccessToken
{
    public static function issue(Ticket $ticket): string
    {
        $secret = Str::random(40);
        $ticket->forceFill(['access_token_hash' => hash('sha256', $secret)])->save();

        $payload = $ticket->getKey().'.'.$secret;

        return $payload.'.'.self::sign($payload);
    }

    /**
     * The ticket the token gives access to, or null.
     */
    public static function resolve(?string $token): ?Ticket
    {
        if (! is_string($token) || preg_match('/^(\d{1,20})\.([A-Za-z0-9]{40})\.([a-f0-9]{64})$/', $token, $match) !== 1) {
            return null;
        }

        if (! hash_equals(self::sign($match[1].'.'.$match[2]), $match[3])) {
            return null;
        }

        $model = AlfakitHelpdesk::ticketModel();
        $ticket = $model::query()->whereKey((int) $match[1])->first();

        if (! $ticket instanceof Ticket || ! is_string($ticket->access_token_hash) || ! hash_equals($ticket->access_token_hash, hash('sha256', $match[2]))) {
            return null;
        }

        return $ticket;
    }

    /** Invalidate the ticket's token. */
    public static function revoke(Ticket $ticket): void
    {
        $ticket->forceFill(['access_token_hash' => null])->save();
    }

    private static function sign(string $payload): string
    {
        return hash_hmac('sha256', 'alfakit-helpdesk|'.$payload, (string) config('app.key'));
    }
}
