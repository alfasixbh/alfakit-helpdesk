<?php

declare(strict_types=1);

use AlfaSix\AlfakitHelpdesk\Enums\MessageKind;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use AlfaSix\AlfakitHelpdesk\Support\Typing;
use AlfaSix\AlfakitHelpdesk\Tests\TestCase;
use Illuminate\Support\Facades\Mail;

use function AlfaSix\AlfakitHelpdesk\Tests\agent;
use function AlfaSix\AlfakitHelpdesk\Tests\customer;
use function AlfaSix\AlfakitHelpdesk\Tests\inbox;
use function AlfaSix\AlfakitHelpdesk\Tests\test;
use function AlfaSix\AlfakitHelpdesk\Tests\ticket;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    Mail::fake();
});

it('renders the inbox with the views and the first page of my tickets', function (): void {
    $agent = agent();
    ticket(['subject' => 'Mine', 'assignee' => $agent]);
    ticket(['subject' => 'Nobody\'s']);
    test()->actingAs($agent);

    ['inbox' => $inbox] = inbox();

    expect($inbox['type'])->toBe('helpdesk:inbox')
        ->and($inbox['actionComponent'])->toBe('helpdesk')
        ->and($inbox['view'])->toBe('mine')
        ->and(collect($inbox['list']['tickets'])->pluck('subject')->all())->toBe(['Mine'])
        ->and(collect($inbox['list']['views'])->firstWhere('key', 'unassigned')['count'])->toBe(1)
        ->and($inbox['live']['channels'])->toBe([])
        ->and($inbox['pollingInterval'])->toBe('20s');
});

it('lists, searches and pages views through the list action', function (): void {
    $agent = agent();
    foreach (range(1, 32) as $i) {
        ticket(['subject' => "Ticket {$i}"]);
    }
    ticket(['subject' => 'Refund request']);
    test()->actingAs($agent);
    ['token' => $token] = inbox();

    $first = test()->inboxAction('helpdesk', $token, 'helpdeskList', payload: ['arguments' => ['helpdesk' => ['view' => 'unassigned']]])->assertOk()->json('result.list');

    expect($first['tickets'])->toHaveCount(30)->and($first['hasMore'])->toBeTrue();

    $second = test()->inboxAction('helpdesk', $token, 'helpdeskList', payload: ['arguments' => ['helpdesk' => ['view' => 'unassigned', 'page' => 2]]])->json('result.list');
    $search = test()->inboxAction('helpdesk', $token, 'helpdeskList', payload: ['arguments' => ['helpdesk' => ['view' => 'all', 'search' => 'refund']]])->json('result.list');

    expect($second['tickets'])->toHaveCount(3)
        ->and(collect($search['tickets'])->pluck('subject')->all())->toBe(['Refund request']);
});

it('loads a ticket with notes, its actions and presence for agents', function (): void {
    $agent = agent();
    $ticket = ticket(['requester' => customer('Casey')]);
    Tickets::note($ticket, '<p>Internal: check billing</p>', $agent);
    Typing::requester($ticket);
    test()->actingAs($agent);
    ['token' => $token] = inbox();

    $payload = test()->inboxAction('helpdesk', $token, 'helpdeskTicket', $ticket->getKey())->assertOk()->json('result.ticket');

    expect(collect($payload['messages'])->pluck('kind')->all())->toBe(['reply', 'note'])
        ->and($payload['requester']['name'])->toBe('Casey')
        ->and($payload['typing'])->toBeTrue()
        ->and($payload['canReply'])->toBeTrue()
        ->and(collect($payload['actions'])->pluck('name')->all())->toContain('reply', 'note', 'take', 'assign', 'merge')
        ->and($payload)->not->toHaveKey('presence');
});

it('sends quick replies and notes from the inbox', function (): void {
    $agent = agent();
    $ticket = ticket(['requester' => customer()]);
    test()->actingAs($agent);
    ['token' => $token] = inbox();

    $reply = test()->inboxAction('helpdesk', $token, 'helpdeskQuickReply', $ticket->getKey(), ['arguments' => ['helpdesk' => ['text' => "Hi <there>\nTry again", 'status' => 'pending']]])->assertOk();
    test()->inboxAction('helpdesk', $token, 'helpdeskQuickReply', $ticket->getKey(), ['arguments' => ['helpdesk' => ['text' => 'Note to self', 'internal' => true]]])->assertOk();
    test()->inboxAction('helpdesk', $token, 'helpdeskQuickReply', $ticket->getKey(), ['arguments' => ['helpdesk' => ['text' => '   ']]])->assertStatus(422);

    expect($reply->json('result.ticket.status.value'))->toBe('pending')
        ->and($ticket->messages()->where('kind', MessageKind::Reply->value)->reorder()->latest('id')->first()?->body)->toContain('Hi &lt;there&gt;')->toContain('Try again')->not->toContain('<there>')
        ->and($ticket->messages()->where('kind', MessageKind::Note->value)->count())->toBe(1)
        ->and($ticket->refresh()->getStatus())->toBe(TicketStatus::Pending);
});

it('runs ticket actions from the inbox for the posted ticket', function (): void {
    $agent = agent();
    $ticket = ticket();
    test()->actingAs($agent);
    ['token' => $token] = inbox();

    test()->inboxAction('helpdesk', $token, 'take', $ticket->getKey())->assertSuccessful();
    expect((string) $ticket->refresh()->assignee_id)->toBe((string) $agent->id);

    test()->inboxAction('helpdesk', $token, 'solve', $ticket->getKey())->assertSuccessful();
    expect($ticket->refresh()->getStatus())->toBe(TicketStatus::Solved);

    test()->inboxAction('helpdesk', $token, 'helpdeskTicket', 999999)->assertNotFound();
    test()->inboxAction('helpdesk', $token, 'nope', $ticket->getKey())->assertNotFound();
});

it('refuses to assign tickets to users who are not agents', function (): void {
    $agent = agent();
    $customer = customer();
    $ticket = ticket();
    test()->actingAs($agent);
    ['token' => $token] = inbox();

    test()->inboxAction('helpdesk', $token, 'assign', $ticket->getKey(), ['data' => ['assignee_id' => (string) $customer->id]])->assertStatus(422);

    expect($ticket->refresh()->assignee_id)->toBeNull();
});

it('records agent typing for the visitor', function (): void {
    $agent = agent('Dana');
    $ticket = ticket();
    test()->actingAs($agent);
    ['token' => $token] = inbox();

    test()->inboxAction('helpdesk', $token, 'helpdeskTyping', $ticket->getKey())->assertOk();

    expect(Typing::agentTyping($ticket))->toBe('Dana');
});

it('keeps customers out of the inbox and its actions', function (): void {
    $agent = agent();
    $ticket = ticket();
    test()->actingAs($agent);
    ['token' => $token] = inbox();

    test()->actingAs(customer());

    test()->get('/helpdesk/helpdesk/inbox', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertForbidden();
    expect(test()->inboxAction('helpdesk', $token, 'helpdeskTicket', $ticket->getKey())->status())->toBeIn([403, 419]);
});
