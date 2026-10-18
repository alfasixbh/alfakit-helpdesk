<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Placeholders of canned replies: `{{ requester.name }}`, `{{ ticket.reference }}`…
 *
 * Rendering is a plain lookup in a list of allowed variables: nothing in a reply is
 * evaluated (no Blade, no PHP, no expressions, no method calls), values are HTML-escaped,
 * unknown placeholders render as nothing and the result is sanitised again.
 */
final class Placeholders
{
    public const PATTERN = '/\{\{\s*([a-z][a-z0-9_]*(?:\.[a-z][a-z0-9_]*)?)\s*\}\}/i';

    /** @var array<string, Closure(Ticket, ?Model): (string|int|float|null)> */
    private static array $custom = [];

    /**
     * Add a variable: `Placeholders::define('company.name', fn (Ticket $ticket, ?Model $agent) => 'Acme')`.
     * Names are `word` or `group.word` (letters, digits, underscores).
     *
     * @param  Closure(Ticket, ?Model): (string|int|float|null)  $resolver
     */
    public static function define(string $name, Closure $resolver): void
    {
        self::$custom[strtolower($name)] = $resolver;
    }

    /** Forget custom variables (tests). */
    public static function flush(): void
    {
        self::$custom = [];
    }

    /**
     * The names available in canned replies (shown as help in the editor).
     *
     * @return array<int, string>
     */
    public static function names(): array
    {
        return [...array_keys(self::builtIn()), ...array_keys(self::$custom)];
    }

    /**
     * The variables' values for a ticket and agent.
     *
     * @return array<string, string>
     */
    public static function values(Ticket $ticket, ?Model $agent = null): array
    {
        $values = [];

        foreach ([...self::builtIn(), ...self::$custom] as $name => $resolver) {
            $value = $resolver($ticket, $agent);
            $values[strtolower($name)] = is_scalar($value) ? (string) $value : '';
        }

        return $values;
    }

    /**
     * Replace the placeholders of a reply (HTML) with escaped values and sanitise it.
     */
    public static function render(string $html, Ticket $ticket, ?Model $agent = null): string
    {
        $values = self::values($ticket, $agent);

        $rendered = (string) preg_replace_callback(self::PATTERN, static fn (array $match): string => e($values[strtolower($match[1])] ?? ''), $html);

        return MessageBody::sanitize($rendered);
    }

    /**
     * @return array<string, Closure(Ticket, ?Model): (string|int|float|null)>
     */
    private static function builtIn(): array
    {
        $name = static fn (?Model $user): ?string => is_scalar($value = $user?->getAttribute('name')) ? (string) $value : null;
        $first = static fn (string $name): string => explode(' ', trim($name))[0];

        return [
            'requester.name' => static fn (Ticket $ticket): string => $ticket->getRequesterName(),
            'requester.first_name' => static fn (Ticket $ticket): string => $first($ticket->getRequesterName()),
            'requester.email' => static fn (Ticket $ticket): ?string => $ticket->getRequesterEmail(),
            'agent.name' => static fn (Ticket $ticket, ?Model $agent): ?string => $name($agent),
            'agent.first_name' => static fn (Ticket $ticket, ?Model $agent): ?string => ($agentName = $name($agent)) === null ? null : $first($agentName),
            'ticket.reference' => static fn (Ticket $ticket): string => $ticket->getReference(),
            'ticket.id' => static fn (Ticket $ticket): int|string|null => $ticket->getKey(),
            'ticket.subject' => static fn (Ticket $ticket): string => $ticket->subject,
            'ticket.status' => static fn (Ticket $ticket): string => $ticket->getStatus()->getLabel(),
            'ticket.priority' => static fn (Ticket $ticket): string => $ticket->getPriority()->getLabel(),
            'ticket.url' => static fn (Ticket $ticket): ?string => Links::forRequester($ticket),
            'app.name' => static fn (): string => (string) config('app.name'),
        ];
    }
}
