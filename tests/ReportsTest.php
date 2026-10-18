<?php

declare(strict_types=1);

use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Support\Reports;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use AlfaSix\AlfakitHelpdesk\Tests\TestCase;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

use function AlfaSix\AlfakitHelpdesk\Tests\agent;
use function AlfaSix\AlfakitHelpdesk\Tests\customer;
use function AlfaSix\AlfakitHelpdesk\Tests\team;
use function AlfaSix\AlfakitHelpdesk\Tests\test;
use function AlfaSix\AlfakitHelpdesk\Tests\ticket;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

it('reports volume, median first response, SLA compliance and CSAT', function (): void {
    Mail::fake();
    $agent = agent();
    test()->travelTo(CarbonImmutable::parse('2026-10-19 09:00', 'UTC'));

    $fast = ticket(['requester' => customer()]);
    $slow = ticket(['requester' => customer()]);
    $open = ticket(['requester' => customer()]);

    test()->travelTo(CarbonImmutable::parse('2026-10-19 09:10', 'UTC'));
    Tickets::reply($fast, '<p>Fast</p>', $agent);
    test()->travelTo(CarbonImmutable::parse('2026-10-19 11:00', 'UTC'));
    Tickets::reply($slow, '<p>Slow</p>', $agent);
    Tickets::changeStatus($fast, TicketStatus::Solved, $agent);
    Tickets::changeStatus($slow, TicketStatus::Solved, $agent);
    Tickets::rate($fast->refresh(), 5);
    Tickets::rate($slow->refresh(), 2);

    $report = Reports::summary(Ticket::query(), CarbonImmutable::parse('2026-10-18', 'UTC'), CarbonImmutable::parse('2026-10-19 12:00', 'UTC'));

    expect($report['created'])->toBe(3)
        ->and($report['solved'])->toBe(2)
        // 10 and 120 minutes.
        ->and($report['median_first_response'])->toBe(65)
        // fast: first response met, resolution met; slow: first response missed, resolution met; open: first response breached.
        ->and($report['sla_compliance'])->toBe(60.0)
        ->and($report['csat'])->toBe(50.0)
        ->and($report['ratings'])->toBe(2)
        ->and(array_sum($report['daily_created']))->toBe(3)
        ->and($open->exists)->toBeTrue();
});

it('computes medians and durations', function (): void {
    expect(Reports::median([]))->toBeNull()
        ->and(Reports::median([5, 1, 3]))->toBe(3)
        ->and(Reports::median([1, 2, 3, 10]))->toBe(3)
        ->and(Reports::duration(null))->toBe('–')
        ->and(Reports::duration(135))->toContain('2')->toContain('15');
});

it('renders the reports widget for the current tenant only', function (): void {
    $alpha = team('Alpha');
    $beta = team('Beta');
    $dana = agent();
    $dana->teams()->attach($alpha);
    ticket(['tenant' => $alpha]);
    ticket(['tenant' => $beta]);
    ticket(['tenant' => $beta]);
    test()->actingAs($dana);

    $props = test()->get('/helpdesk-team/alpha/helpdesk/tickets', [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => '',
        'X-Inertia-Partial-Component' => 'Alfakit/Page',
        'X-Inertia-Partial-Data' => 'alfakitLazy',
    ])->assertOk()->json('props.alfakitLazy');

    $widget = collect($props)->first();

    expect(json_encode($widget))->toContain('Tickets created');
    expect(collect($widget['stats'] ?? [])->firstWhere('label', 'Tickets created')['value'] ?? null)->toBe('1');
});
