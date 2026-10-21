<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\Alfakit\Forms\Components\TextInput;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Abuse protection of the public side: rate limits per IP address (and key), a honeypot
 * input and a minimum time between showing a form and sending it.
 */
final class SpamGuard
{
    public static function honeypotField(): string
    {
        $name = config('alfakit-helpdesk.spam.honeypot_field', '_website');

        return is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,40}$/', $name) === 1 ? $name : '_website';
    }

    /**
     * A text input people never see (off screen, hidden from assistive technology and out
     * of the tab order); bots that fill every input fill it too.
     */
    public static function honeypot(): TextInput
    {
        return TextInput::make(self::honeypotField())
            ->label(__('alfakit-helpdesk::helpdesk.portal.honeypot'))
            ->autocomplete(false)
            ->dehydrated()
            ->columnSpanFull()
            ->extraFieldWrapperAttributes([
                'aria-hidden' => 'true',
                'data-helpdesk-honeypot' => 'true',
                // Clipped in place: pushed 10000px towards the start it widened right-to-left
                // pages by 10000px (a horizontal scrollbar on every Arabic form).
                'style' => 'position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0',
            ])
            ->extraInputAttributes(['tabindex' => '-1']);
    }

    /**
     * A filled honeypot, or a form sent faster than a person could fill it.
     */
    public static function isSpam(mixed $honeypot, ?int $issuedAt): bool
    {
        if (is_string($honeypot) && trim($honeypot) !== '') {
            return true;
        }

        $min = max(0, (int) config('alfakit-helpdesk.spam.min_seconds', 2));

        return $min > 0 && $issuedAt !== null && time() - $issuedAt < $min;
    }

    /**
     * Count an attempt; false when the limit (`rate_limits.{$limit}`) is reached.
     */
    public static function attempt(string $limit, string $key = ''): bool
    {
        $config = config('alfakit-helpdesk.rate_limits.'.$limit);
        $max = is_array($config) ? max(1, (int) ($config['max_attempts'] ?? 5)) : 5;
        $decay = is_array($config) ? max(1, (int) ($config['decay_seconds'] ?? 60)) : 60;
        $id = 'alfakit-helpdesk:'.$limit.':'.sha1((string) request()->ip().'|'.$key);

        if (RateLimiter::tooManyAttempts($id, $max)) {
            return false;
        }

        RateLimiter::hit($id, $decay);

        return true;
    }

    public static function availableIn(string $limit, string $key = ''): int
    {
        return RateLimiter::availableIn('alfakit-helpdesk:'.$limit.':'.sha1((string) request()->ip().'|'.$key));
    }
}
