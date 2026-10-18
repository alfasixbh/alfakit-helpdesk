<?php

declare(strict_types=1);

use AlfaSix\AlfakitHelpdesk\Enums\SlaState;
use AlfaSix\AlfakitHelpdesk\Enums\TicketPriority;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Events\SlaBreached;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Sla\BusinessHours;
use AlfaSix\AlfakitHelpdesk\Sla\Sla;
use AlfaSix\AlfakitHelpdesk\Sla\SlaClock;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use AlfaSix\AlfakitHelpdesk\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;

use function AlfaSix\AlfakitHelpdesk\Tests\agent;
use function AlfaSix\AlfakitHelpdesk\Tests\customer;
use function AlfaSix\AlfakitHelpdesk\Tests\test;
use function AlfaSix\AlfakitHelpdesk\Tests\ticket;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

// 2026-10-16 is a Friday.
function helpdeskAt(string $moment): CarbonImmutable
{
    return CarbonImmutable::parse($moment, 'UTC');
}

it('counts business minutes across evenings and weekends', function (): void {
    $clock = SlaClock::business(BusinessHours::make('UTC')->weekdays('09:00', '17:00'));

    expect($clock->addMinutes(helpdeskAt('2026-10-16 16:30'), 60)->format('Y-m-d H:i'))->toBe('2026-10-19 09:30')
        ->and($clock->addMinutes(helpdeskAt('2026-10-17 10:00'), 60)->format('Y-m-d H:i'))->toBe('2026-10-19 10:00')
        ->and($clock->addMinutes(helpdeskAt('2026-10-19 07:00'), 0)->format('Y-m-d H:i'))->toBe('2026-10-19 09:00')
        ->and($clock->addMinutes(helpdeskAt('2026-10-19 09:00'), 8 * 60 * 2)->format('Y-m-d H:i'))->toBe('2026-10-20 17:00')
        ->and($clock->minutesBetween(helpdeskAt('2026-10-16 16:00'), helpdeskAt('2026-10-19 10:00')))->toBe(120)
        ->and($clock->minutesBetween(helpdeskAt('2026-10-19 10:00'), helpdeskAt('2026-10-19 09:00')))->toBe(0);
});

it('skips holidays and breaks, in the business timezone', function (): void {
    $hours = BusinessHours::make('Asia/Bahrain')
        ->days(['sun', 'mon', 'tue', 'wed', 'thu'], '08:00', '12:00')
        ->days(['sun', 'mon', 'tue', 'wed', 'thu'], '13:00', '17:00')
        ->holidays(['2026-10-18']);
    $clock = SlaClock::business($hours);

    // Thursday 2026-10-15 11:30 Bahrain (08:30 UTC) + 90 minutes: 30 before the break, 60 after.
    expect($clock->addMinutes(helpdeskAt('2026-10-15 08:30'), 90)->setTimezone('Asia/Bahrain')->format('Y-m-d H:i'))->toBe('2026-10-15 14:00')
        // Thursday 16:30 + 60: Friday and Saturday off, Sunday the 18th a holiday → Monday 08:30.
        ->and($clock->addMinutes(helpdeskAt('2026-10-15 13:30'), 60)->setTimezone('Asia/Bahrain')->format('Y-m-d H:i'))->toBe('2026-10-19 08:30')
        ->and($hours->isOpenAt(helpdeskAt('2026-10-15 09:30')))->toBeFalse() // 12:30 in Bahrain: lunch
        ->and($hours->isOpenAt(helpdeskAt('2026-10-15 10:30')))->toBeTrue();
});

it('rejects invalid business hours', function (): void {
    expect(fn () => BusinessHours::make()->days(['mon'], '17:00', '09:00'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => BusinessHours::make()->days(['funday'], '09:00', '17:00'))->toThrow(InvalidArgumentException::class)
        ->and(fn () => BusinessHours::make()->days(['mon'], '9am', '17:00'))->toThrow(InvalidArgumentException::class);
});

it('sets due times from the first matching policy', function (): void {
    test()->travelTo(helpdeskAt('2026-10-16 16:30'));

    $standard = ticket();
    $urgent = ticket(['priority' => TicketPriority::Urgent]);

    expect($standard->sla_policy)->toBe('standard')
        ->and($standard->first_response_due_at?->format('Y-m-d H:i'))->toBe('2026-10-19 09:30')
        ->and($standard->resolution_due_at?->format('Y-m-d H:i'))->toBe('2026-10-19 16:30')
        // Urgent counts calendar hours.
        ->and($urgent->sla_policy)->toBe('urgent')
        ->and($urgent->first_response_due_at?->format('Y-m-d H:i'))->toBe('2026-10-16 17:00')
        ->and($urgent->resolution_due_at?->format('Y-m-d H:i'))->toBe('2026-10-16 18:30');

    // Raising the priority moves the targets to the urgent policy.
    Tickets::update($standard, ['priority' => TicketPriority::Urgent]);

    expect($standard->refresh()->sla_policy)->toBe('urgent')
        ->and($standard->first_response_due_at?->format('Y-m-d H:i'))->toBe('2026-10-16 17:00');
});

it('pauses the resolution clock while the ticket waits for the requester', function (): void {
    test()->travelTo(helpdeskAt('2026-10-19 09:00'));
    $customer = customer();
    $ticket = ticket(['requester' => $customer]);

    expect($ticket->resolution_due_at?->format('Y-m-d H:i'))->toBe('2026-10-19 17:00');

    test()->travelTo(helpdeskAt('2026-10-19 10:00'));
    Tickets::changeStatus($ticket, TicketStatus::Pending, agent());

    expect(Sla::resolutionState($ticket->refresh()))->toBe(SlaState::Paused);

    // The requester answers the next morning: 7 business hours are left.
    test()->travelTo(helpdeskAt('2026-10-20 09:00'));
    Tickets::reply($ticket, '<p>Here are the logs</p>', $customer, fromRequester: true);

    $ticket->refresh();

    expect($ticket->getStatus())->toBe(TicketStatus::Open)
        ->and($ticket->sla_paused_at)->toBeNull()
        ->and($ticket->resolution_due_at?->format('Y-m-d H:i'))->toBe('2026-10-20 16:00');
});

it('reports the state of each target', function (): void {
    test()->travelTo(helpdeskAt('2026-10-19 09:00'));
    $ticket = ticket();

    expect(Sla::firstResponseState($ticket, helpdeskAt('2026-10-19 09:10')))->toBe(SlaState::DueSoon)
        ->and(Sla::firstResponseState($ticket, helpdeskAt('2026-10-19 10:30')))->toBe(SlaState::Breached)
        ->and(Sla::summary($ticket, helpdeskAt('2026-10-19 10:30'))['target'])->toBe('first_response');

    test()->travelTo(helpdeskAt('2026-10-19 09:20'));
    Tickets::reply($ticket, '<p>Hi</p>', agent());

    expect(Sla::firstResponseState($ticket->refresh()))->toBe(SlaState::Met)
        ->and(Sla::resolutionState($ticket, helpdeskAt('2026-10-19 11:00')))->toBe(SlaState::OnTrack)
        ->and(Sla::resolutionState($ticket, helpdeskAt('2026-10-19 16:30')))->toBe(SlaState::DueSoon);

    test()->travelTo(helpdeskAt('2026-10-20 09:00'));
    Tickets::changeStatus($ticket, TicketStatus::Solved);

    expect(Sla::resolutionState($ticket->refresh()))->toBe(SlaState::Missed);
});

it('finds breached and due-soon tickets', function (): void {
    test()->travelTo(helpdeskAt('2026-10-19 09:00'));
    $late = ticket(['subject' => 'Late']);
    test()->travelTo(helpdeskAt('2026-10-19 10:30'));
    $soon = ticket(['subject' => 'Soon']);
    $answered = ticket(['subject' => 'Answered']);
    Tickets::reply($answered, '<p>Done</p>', agent());

    test()->travelTo(helpdeskAt('2026-10-19 11:10'));

    expect(Ticket::query()->breached()->pluck('subject')->all())->toBe(['Late'])
        ->and(Ticket::query()->dueSoon()->pluck('subject')->all())->toBe(['Soon']);
});

it('warns about breaches once and closes old solved tickets', function (): void {
    Event::fake([SlaBreached::class]);
    test()->travelTo(helpdeskAt('2026-10-19 09:00'));
    $agent = agent();
    $late = ticket(['assignee' => $agent]);
    $solved = ticket();
    Tickets::reply($solved, '<p>Fixed</p>', $agent);
    Tickets::changeStatus($solved, TicketStatus::Solved, $agent);

    test()->travelTo(helpdeskAt('2026-10-26 09:00'));
    test()->artisan('alfakit-helpdesk:sla')->assertSuccessful();
    test()->artisan('alfakit-helpdesk:sla')->assertSuccessful();

    Event::assertDispatchedTimes(SlaBreached::class, 1);
    Event::assertDispatched(SlaBreached::class, fn (SlaBreached $event): bool => $event->ticket->is($late) && $event->target === 'first_response');

    expect($solved->refresh()->getStatus())->toBe(TicketStatus::Closed)
        ->and($agent->notifications()->count())->toBe(1);
});
