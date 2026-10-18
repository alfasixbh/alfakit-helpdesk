<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Sla;

use AlfaSix\Alfakit\Support\Concerns\EvaluatesClosures;
use AlfaSix\AlfakitHelpdesk\Enums\TicketPriority;
use AlfaSix\AlfakitHelpdesk\Enums\TicketType;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Closure;

/**
 * Response and resolution targets for the tickets it applies to. The first matching
 * policy of `HelpdeskPlugin::slaPolicies()` applies to a ticket.
 *
 * ```php
 * SlaPolicy::make('urgent')
 *     ->priorities([TicketPriority::Urgent])
 *     ->firstResponseWithin(30)   // minutes
 *     ->resolveWithin(4 * 60);
 *
 * SlaPolicy::make('vip')
 *     ->when(fn (Ticket $ticket): bool => $ticket->requester?->is_vip === true)
 *     ->firstResponseWithin(15)
 *     ->calendarHours();          // count every minute, not only business hours
 * ```
 */
class SlaPolicy
{
    use EvaluatesClosures;

    /** @var array<int, string> */
    protected array $priorities = [];

    /** @var array<int, string> */
    protected array $types = [];

    protected ?Closure $condition = null;

    protected ?int $firstResponseMinutes = null;

    protected ?int $resolutionMinutes = null;

    protected bool $usesBusinessHours = true;

    protected ?BusinessHours $businessHours = null;

    final public function __construct(protected string $name) {}

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param  array<int, TicketPriority|string>  $priorities
     */
    public function priorities(array $priorities): static
    {
        $this->priorities = array_values(array_map(static fn (TicketPriority|string $priority): string => $priority instanceof TicketPriority ? $priority->value : $priority, $priorities));

        return $this;
    }

    /**
     * @param  array<int, TicketType|string>  $types
     */
    public function types(array $types): static
    {
        $this->types = array_values(array_map(static fn (TicketType|string $type): string => $type instanceof TicketType ? $type->value : $type, $types));

        return $this;
    }

    /**
     * Apply only when the closure returns true: `fn (Ticket $ticket): bool`.
     */
    public function when(?Closure $condition): static
    {
        $this->condition = $condition;

        return $this;
    }

    public function firstResponseWithin(?int $minutes): static
    {
        $this->firstResponseMinutes = $minutes === null ? null : max(0, $minutes);

        return $this;
    }

    public function resolveWithin(?int $minutes): static
    {
        $this->resolutionMinutes = $minutes === null ? null : max(0, $minutes);

        return $this;
    }

    /** Count every minute (nights, weekends and holidays included). */
    public function calendarHours(bool $condition = true): static
    {
        $this->usesBusinessHours = ! $condition;

        return $this;
    }

    /** Business hours of this policy (the plugin's by default). */
    public function businessHours(?BusinessHours $hours): static
    {
        $this->businessHours = $hours;
        $this->usesBusinessHours = true;

        return $this;
    }

    public function getFirstResponseMinutes(): ?int
    {
        return $this->firstResponseMinutes;
    }

    public function getResolutionMinutes(): ?int
    {
        return $this->resolutionMinutes;
    }

    public function appliesTo(Ticket $ticket): bool
    {
        if ($this->priorities !== [] && ! in_array($ticket->getPriority()->value, $this->priorities, true)) {
            return false;
        }

        if ($this->types !== [] && ! in_array($ticket->getType()->value, $this->types, true)) {
            return false;
        }

        if ($this->condition !== null) {
            return (bool) $this->evaluate($this->condition, ['ticket' => $ticket, 'record' => $ticket], [Ticket::class => $ticket, $ticket::class => $ticket]);
        }

        return true;
    }

    public function clock(?BusinessHours $default): SlaClock
    {
        if (! $this->usesBusinessHours) {
            return SlaClock::calendar();
        }

        $hours = $this->businessHours ?? $default;

        return $hours === null ? SlaClock::calendar() : SlaClock::business($hours);
    }
}
