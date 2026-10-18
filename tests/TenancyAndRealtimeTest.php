<?php

declare(strict_types=1);

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\Realtime\Realtime;
use AlfaSix\Alfakit\Realtime\RecordChanged;
use AlfaSix\Alfakit\Tests\Fixtures\AuthTenancy\Panels;
use AlfaSix\AlfakitHelpdesk\Models\HelpdeskGroup;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Realtime\TicketActivity;
use AlfaSix\AlfakitHelpdesk\Support\Agents;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use AlfaSix\AlfakitHelpdesk\Tests\Fixtures\Panels\HelpdeskPanelProvider;
use AlfaSix\AlfakitHelpdesk\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

use function AlfaSix\AlfakitHelpdesk\Tests\agent;
use function AlfaSix\AlfakitHelpdesk\Tests\customer;
use function AlfaSix\AlfakitHelpdesk\Tests\inbox;
use function AlfaSix\AlfakitHelpdesk\Tests\team;
use function AlfaSix\AlfakitHelpdesk\Tests\test;
use function AlfaSix\AlfakitHelpdesk\Tests\ticket;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    Mail::fake();
});

/**
 * @return array{alpha: AlfaSix\Alfakit\Tests\Fixtures\Models\Team, beta: AlfaSix\Alfakit\Tests\Fixtures\Models\Team, dana: AlfaSix\AlfakitHelpdesk\Tests\Fixtures\HelpdeskUser, bob: AlfaSix\AlfakitHelpdesk\Tests\Fixtures\HelpdeskUser}
 */
function helpdeskTeams(): array
{
    $alpha = team('Alpha');
    $beta = team('Beta');
    $dana = agent('Dana');
    $bob = agent('Bob');
    $dana->teams()->attach($alpha);
    $bob->teams()->attach($beta);

    return compact('alpha', 'beta', 'dana', 'bob');
}

it('scopes tickets to the current tenant', function (): void {
    ['alpha' => $alpha, 'beta' => $beta, 'dana' => $dana] = helpdeskTeams();
    $mine = ticket(['subject' => 'Alpha ticket', 'tenant' => $alpha]);
    $theirs = ticket(['subject' => 'Beta ticket', 'tenant' => $beta]);
    test()->actingAs($dana);

    $list = json_encode(test()->inertiaProps('/helpdesk-team/alpha/helpdesk/tickets')['page']['content']);

    expect($list)->toContain('Alpha ticket')->not->toContain('Beta ticket');

    test()->get('/helpdesk-team/alpha/helpdesk/tickets/'.$theirs->getKey(), ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertNotFound();
    test()->get('/helpdesk-team/beta/helpdesk/tickets', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertNotFound();

    ['token' => $token, 'inbox' => $inbox] = inbox('/helpdesk-team/alpha/helpdesk/inbox');
    $all = test()->inboxAction('helpdesk-team/alpha', $token, 'helpdeskList', payload: ['arguments' => ['helpdesk' => ['view' => 'all']]])->json('result.list.tickets');

    expect(collect($all)->pluck('subject')->all())->toBe(['Alpha ticket']);

    test()->inboxAction('helpdesk-team/alpha', $token, 'helpdeskTicket', $theirs->getKey())->assertNotFound();
    test()->inboxAction('helpdesk-team/alpha', $token, 'helpdeskQuickReply', $theirs->getKey(), ['arguments' => ['helpdesk' => ['text' => 'Leak?']]])->assertNotFound();

    expect($theirs->messages()->count())->toBe(1)->and($mine->exists)->toBeTrue();
});

it('creates tickets in the current tenant', function (): void {
    ['alpha' => $alpha, 'dana' => $dana] = helpdeskTeams();
    test()->actingAs($dana);
    $token = test()->inertiaProps('/helpdesk-team/alpha/helpdesk/tickets/create')['page']['token'];

    test()->postJson('/helpdesk-team/alpha/_alfakit/actions', [
        'token' => $token,
        'action' => 'create',
        'data' => ['subject' => 'Phone call', 'requester_email' => 'caller@example.com', 'body' => '<p>Called about invoices</p>', 'priority' => 'normal', 'type' => 'question'],
    ])->assertRedirect();

    $ticket = Ticket::query()->where('subject', 'Phone call')->sole();

    expect($ticket->tenant_type)->toBe($alpha->getMorphClass())
        ->and((string) $ticket->tenant_id)->toBe((string) $alpha->id)
        ->and($ticket->first_responded_at)->not->toBeNull()
        ->and($ticket->messages()->first()?->from_requester)->toBeFalse();
});

it('offers only the tenant\'s agents and groups', function (): void {
    ['alpha' => $alpha, 'beta' => $beta, 'dana' => $dana, 'bob' => $bob] = helpdeskTeams();
    $betaGroup = HelpdeskGroup::query()->create(['name' => 'Beta billing', 'tenant_type' => $beta->getMorphClass(), 'tenant_id' => $beta->id]);
    $ticket = ticket(['tenant' => $alpha]);
    test()->actingAs($dana);
    ['token' => $token] = inbox('/helpdesk-team/alpha/helpdesk/inbox');

    alfakit()->setCurrentPanel(alfakit()->getPanel('helpdesk-team'));
    alfakit()->setTenant($alpha, isQuiet: true);

    expect(array_values(Agents::options()))->toBe(['Dana']);

    test()->inboxAction('helpdesk-team/alpha', $token, 'assign', $ticket->getKey(), ['data' => ['assignee_id' => (string) $bob->id]])->assertStatus(422);
    test()->inboxAction('helpdesk-team/alpha', $token, 'assign', $ticket->getKey(), ['data' => ['assignee_id' => (string) $dana->id, 'group_id' => (string) $betaGroup->id]])->assertStatus(422);

    expect($ticket->refresh()->assignee_id)->toBeNull();
});

// ---------------------------------------------------------------- Realtime

function realtimeHelpdesk(): Panel
{
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb' => [
            'driver' => 'reverb',
            'key' => 'test-key',
            'secret' => 'test-secret',
            'app_id' => '1001',
            'options' => ['host' => 'localhost', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false],
        ],
    ]);

    Realtime::flush();

    $panel = Panels::register('live-desk', fn (Panel $panel): Panel => $panel->realtime()->plugin(HelpdeskPanelProvider::plugin()));
    Realtime::bootPanel($panel);

    return $panel;
}

it('broadcasts ticket activity with keys only, never message bodies', function (): void {
    Event::fake([TicketActivity::class, RecordChanged::class]);
    realtimeHelpdesk();
    $agent = agent();
    $ticket = ticket(['requester' => customer()], '<p>Top secret first message</p>');

    Tickets::reply($ticket, '<p>Top secret reply</p>', $agent);
    Tickets::note($ticket, '<p>Top secret note</p>', $agent);

    Event::assertDispatched(TicketActivity::class, function (TicketActivity $event) use ($ticket): bool {
        $payload = $event->broadcastWith();
        $channels = array_map(static fn ($channel): string => $channel->name, $event->broadcastOn());

        expect(array_keys($payload))->toBe(['ticket', 'kind', 'actor', 'by', 'at'])
            ->and(json_encode($payload))->not->toContain('Top secret')->not->toContain('Printer')
            ->and($channels)->toContain('private-alfakit.live-desk._.AlfaSix-AlfakitHelpdesk-Models-Ticket')
            ->and($channels)->toContain('presence-alfakit.live-desk._.AlfaSix-AlfakitHelpdesk-Models-Ticket.'.$ticket->getKey())
            ->and($event->broadcastAs())->toBe('helpdesk.activity');

        return $payload['ticket'] === $ticket->getKey();
    });

    Event::assertDispatched(TicketActivity::class, fn (TicketActivity $event): bool => $event->broadcastWith()['kind'] === 'message');
});

it('broadcasts nothing for panels without realtime', function (): void {
    Event::fake([TicketActivity::class]);
    Realtime::flush();

    Tickets::reply(ticket(), '<p>Hi</p>', agent());

    Event::assertNotDispatched(TicketActivity::class);
});

it('gives the realtime inbox its channels and the ticket\'s presence channel', function (): void {
    Event::fake([TicketActivity::class, RecordChanged::class]);
    realtimeHelpdesk();
    $agent = agent();
    $ticket = ticket();
    test()->actingAs($agent);

    ['token' => $token, 'inbox' => $inbox] = inbox('/live-desk/helpdesk/inbox');

    expect($inbox['live']['channels'])->toBe(['alfakit.live-desk._.AlfaSix-AlfakitHelpdesk-Models-Ticket']);

    $payload = test()->inboxAction('live-desk', $token, 'helpdeskTicket', $ticket->getKey())->json('result.ticket');

    expect($payload['presence'])->toBe(['channel' => 'alfakit.live-desk._.AlfaSix-AlfakitHelpdesk-Models-Ticket.'.$ticket->getKey(), 'state' => 'viewing']);

    // Typing pings broadcast at most every few seconds.
    test()->inboxAction('live-desk', $token, 'helpdeskTyping', $ticket->getKey())->assertOk();
    test()->inboxAction('live-desk', $token, 'helpdeskTyping', $ticket->getKey())->assertOk();

    expect(Event::dispatched(TicketActivity::class, fn (TicketActivity $event): bool => $event->broadcastWith()['kind'] === 'typing'))->toHaveCount(1);
});

it('authorises the ticket channels for agents only', function (): void {
    Event::fake([TicketActivity::class, RecordChanged::class]);
    realtimeHelpdesk();
    $ticket = ticket();
    $channel = 'presence-alfakit.live-desk._.AlfaSix-AlfakitHelpdesk-Models-Ticket.'.$ticket->getKey();

    test()->actingAs(agent());
    test()->postJson('/live-desk/_alfakit/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertOk();

    test()->actingAs(customer());
    test()->postJson('/live-desk/_alfakit/broadcasting/auth', ['socket_id' => '1234.5678', 'channel_name' => $channel])->assertForbidden();
});
