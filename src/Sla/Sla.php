<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Sla;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\SlaState;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * SLA targets of tickets: due times from the first matching policy, the resolution clock
 * paused while a ticket waits for the requester, and the state of each target.
 */
final class Sla
{
    public static function plugin(): HelpdeskPlugin
    {
        return AlfakitHelpdesk::plugin();
    }

    public static function policyFor(Ticket $ticket): ?SlaPolicy
    {
        foreach (self::plugin()->getSlaPolicies() as $policy) {
            if ($policy->appliesTo($ticket)) {
                return $policy;
            }
        }

        return null;
    }

    public static function clockFor(Ticket $ticket): SlaClock
    {
        $policy = self::policyFor($ticket);

        return $policy?->clock(self::plugin()->getBusinessHours()) ?? SlaClock::calendar();
    }

    /**
     * Set the policy and due times from the ticket's creation (targets already met keep
     * their times). Call after the priority or type changed too.
     */
    public static function apply(Ticket $ticket): void
    {
        $policy = self::policyFor($ticket);
        $start = CarbonImmutable::instance($ticket->created_at ?? now());

        $ticket->sla_policy = $policy?->getName();

        if ($policy === null) {
            if ($ticket->first_responded_at === null) {
                $ticket->first_response_due_at = null;
            }

            if ($ticket->solved_at === null) {
                $ticket->resolution_due_at = null;
            }

            return;
        }

        $clock = $policy->clock(self::plugin()->getBusinessHours());

        if ($ticket->first_responded_at === null) {
            $minutes = $policy->getFirstResponseMinutes();
            $ticket->first_response_due_at = $minutes === null ? null : $clock->addMinutes($start, $minutes);
        }

        // A paused clock is resumed from its remaining time instead.
        if ($ticket->solved_at === null && $ticket->sla_paused_at === null) {
            $minutes = $policy->getResolutionMinutes();
            $ticket->resolution_due_at = $minutes === null ? null : $clock->addMinutes($start, $minutes);
        }
    }

    /** The ticket waits for the requester: stop the resolution clock. */
    public static function pause(Ticket $ticket, ?DateTimeInterface $at = null): void
    {
        if ($ticket->sla_paused_at === null && $ticket->resolution_due_at !== null && $ticket->solved_at === null) {
            $ticket->sla_paused_at = CarbonImmutable::instance($at ?? now());
        }
    }

    /** Work goes on: the resolution target moves by the time the clock was paused. */
    public static function resume(Ticket $ticket, ?DateTimeInterface $at = null): void
    {
        if ($ticket->sla_paused_at === null) {
            return;
        }

        $now = CarbonImmutable::instance($at ?? now());

        if ($ticket->resolution_due_at !== null) {
            $clock = self::clockFor($ticket);
            $remaining = $clock->minutesBetween($ticket->sla_paused_at, $ticket->resolution_due_at);
            $ticket->resolution_due_at = $clock->addMinutes($now, $remaining);
        }

        $ticket->sla_paused_at = null;
    }

    public static function dueSoonMinutes(): int
    {
        return self::plugin()->getDueSoonMinutes();
    }

    public static function firstResponseState(Ticket $ticket, ?DateTimeInterface $now = null): SlaState
    {
        $due = $ticket->first_response_due_at;

        if ($due === null) {
            return SlaState::None;
        }

        if ($ticket->first_responded_at !== null) {
            return $ticket->first_responded_at <= $due ? SlaState::Met : SlaState::Missed;
        }

        if (! $ticket->getStatus()->isActive()) {
            return SlaState::None;
        }

        return self::pendingState($due, $now);
    }

    public static function resolutionState(Ticket $ticket, ?DateTimeInterface $now = null): SlaState
    {
        $due = $ticket->resolution_due_at;

        if ($due === null) {
            return SlaState::None;
        }

        if ($ticket->solved_at !== null || ! $ticket->getStatus()->isActive()) {
            $solved = $ticket->solved_at ?? $ticket->closed_at;

            return $solved === null ? SlaState::None : ($solved <= $due ? SlaState::Met : SlaState::Missed);
        }

        if ($ticket->sla_paused_at !== null || $ticket->getStatus() === TicketStatus::Pending) {
            return SlaState::Paused;
        }

        return self::pendingState($due, $now);
    }

    /**
     * The ticket's most urgent target, with its due time.
     *
     * @return array{state: SlaState, target: 'first_response'|'resolution'|null, due: CarbonImmutable|null}
     */
    public static function summary(Ticket $ticket, ?DateTimeInterface $now = null): array
    {
        $first = self::firstResponseState($ticket, $now);
        $resolution = self::resolutionState($ticket, $now);

        if ($first->urgency() === 0 && $resolution->urgency() === 0) {
            $met = $first === SlaState::Missed || $resolution === SlaState::Missed ? SlaState::Missed : ($first === SlaState::Met || $resolution === SlaState::Met ? SlaState::Met : SlaState::None);

            return ['state' => $met, 'target' => null, 'due' => null];
        }

        if ($first->urgency() >= $resolution->urgency()) {
            return ['state' => $first, 'target' => 'first_response', 'due' => self::immutable($ticket->first_response_due_at)];
        }

        return ['state' => $resolution, 'target' => 'resolution', 'due' => self::immutable($ticket->resolution_due_at)];
    }

    public static function state(Ticket $ticket, ?DateTimeInterface $now = null): SlaState
    {
        return self::summary($ticket, $now)['state'];
    }

    private static function pendingState(DateTimeInterface $due, ?DateTimeInterface $now): SlaState
    {
        $now = CarbonImmutable::instance($now ?? now());
        $due = CarbonImmutable::instance($due);

        if ($now >= $due) {
            return SlaState::Breached;
        }

        return $now->diffInMinutes($due, true) <= self::dueSoonMinutes() ? SlaState::DueSoon : SlaState::OnTrack;
    }

    private static function immutable(?DateTimeInterface $date): ?CarbonImmutable
    {
        return $date === null ? null : CarbonImmutable::instance($date);
    }
}
