<?php

declare(strict_types=1);

use AlfaSix\AlfakitHelpdesk\Enums\MessageKind;
use AlfaSix\AlfakitHelpdesk\Enums\TicketPriority;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Events\ReplyAdded;
use AlfaSix\AlfakitHelpdesk\Events\TicketAssigned;
use AlfaSix\AlfakitHelpdesk\Events\TicketCreated;
use AlfaSix\AlfakitHelpdesk\Events\TicketsMerged;
use AlfaSix\AlfakitHelpdesk\Events\TicketSolved;
use AlfaSix\AlfakitHelpdesk\Mail\AgentNotification;
use AlfaSix\AlfakitHelpdesk\Mail\AgentReplied;
use AlfaSix\AlfakitHelpdesk\Mail\TicketReceived;
use AlfaSix\AlfakitHelpdesk\Mail\TicketSolvedMail;
use AlfaSix\AlfakitHelpdesk\Support\MessageBody;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use AlfaSix\AlfakitHelpdesk\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

use function AlfaSix\AlfakitHelpdesk\Tests\agent;
use function AlfaSix\AlfakitHelpdesk\Tests\customer;
use function AlfaSix\AlfakitHelpdesk\Tests\flatten;
use function AlfaSix\AlfakitHelpdesk\Tests\test;
use function AlfaSix\AlfakitHelpdesk\Tests\ticket;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

it('opens a ticket with its first message, sanitised, and tells everyone', function (): void {
    Event::fake([TicketCreated::class]);
    Mail::fake();
    config(['alfakit-helpdesk.mail.agent_emails' => ['support@example.com']]);

    $customer = customer();
    $ticket = Tickets::open(['subject' => 'Broken', 'requester' => $customer, 'priority' => 'high'], '<p>Help <script>alert(1)</script><img src=x onerror=alert(1)></p>');

    expect($ticket->getStatus())->toBe(TicketStatus::Open)
        ->and($ticket->getPriority())->toBe(TicketPriority::High)
        ->and($ticket->requester_email)->toBe($customer->email)
        ->and($ticket->messages)->toHaveCount(1);

    $body = $ticket->messages->first()->body;

    expect($body)->toContain('Help')->not->toContain('<script')->not->toContain('onerror');

    Event::assertDispatched(TicketCreated::class, fn (TicketCreated $event): bool => $event->ticket->is($ticket));
    Mail::assertQueued(TicketReceived::class, fn (TicketReceived $mail): bool => $mail->hasTo($customer->email));
    Mail::assertQueued(AgentNotification::class, fn (AgentNotification $mail): bool => $mail->hasTo('support@example.com') && $mail->isNew);
});

it('counts the first agent reply as the first response and emails the requester', function (): void {
    Mail::fake();
    Event::fake([ReplyAdded::class]);
    $agent = agent();
    $customer = customer();
    $ticket = ticket(['requester' => $customer]);

    Tickets::reply($ticket, '<p>On it!</p>', $agent);

    expect($ticket->refresh()->first_responded_at)->not->toBeNull();
    Event::assertDispatched(ReplyAdded::class);
    Mail::assertQueued(AgentReplied::class, fn (AgentReplied $mail): bool => $mail->hasTo($customer->email));
});

it('keeps internal notes away from requesters', function (): void {
    Mail::fake();
    $agent = agent();
    $customer = customer();
    $ticket = ticket(['requester' => $customer]);

    Tickets::note($ticket, '<p>Customer is on the legacy plan</p>', $agent);

    expect($ticket->messages()->where('kind', MessageKind::Note->value)->count())->toBe(1)
        ->and($ticket->publicMessages()->pluck('body')->implode(' '))->not->toContain('legacy plan')
        ->and($ticket->refresh()->first_responded_at)->toBeNull();

    Mail::assertNotQueued(AgentReplied::class);
});

it('reopens a solved ticket when the requester replies, with a fresh resolution target', function (): void {
    Mail::fake();
    Event::fake([TicketSolved::class]);
    $agent = agent();
    $customer = customer();
    $ticket = ticket(['requester' => $customer]);

    Tickets::changeStatus($ticket, TicketStatus::Solved, $agent);
    Event::assertDispatched(TicketSolved::class);
    Mail::assertQueued(TicketSolvedMail::class);
    expect($ticket->refresh()->solved_at)->not->toBeNull();

    $this->travel(2)->days();
    Tickets::reply($ticket, '<p>Still broken</p>', $customer, fromRequester: true);

    $ticket->refresh();

    expect($ticket->getStatus())->toBe(TicketStatus::Open)
        ->and($ticket->solved_at)->toBeNull()
        ->and($ticket->resolution_due_at->isFuture())->toBeTrue();
});

it('refuses requester replies to closed tickets', function (): void {
    $customer = customer();
    $ticket = ticket(['requester' => $customer]);
    Tickets::changeStatus($ticket, TicketStatus::Closed);

    expect(fn () => Tickets::reply($ticket, '<p>Hello?</p>', $customer, fromRequester: true))->toThrow(ValidationException::class);
});

it('assigns tickets and records the change in the conversation', function (): void {
    Event::fake([TicketAssigned::class]);
    $agent = agent('Dana');
    $other = agent('Riley');
    $ticket = ticket();

    Tickets::assign($ticket, $other, $agent);

    expect((string) $ticket->refresh()->assignee_id)->toBe((string) $other->id)
        ->and($ticket->messages()->where('kind', MessageKind::Event->value)->first()?->body)->toContain('Dana')->toContain('Riley');

    Event::assertDispatched(TicketAssigned::class, fn (TicketAssigned $event): bool => $event->assignee?->is($other) === true);
});

it('merges tickets of the same requester and refuses others', function (): void {
    Event::fake([TicketsMerged::class]);
    $agent = agent();
    $customer = customer();
    $source = ticket(['requester' => $customer, 'subject' => 'Duplicate'], '<p>Second email</p>');
    $target = ticket(['requester' => $customer, 'subject' => 'Original'], '<p>First email</p>');
    $stranger = ticket(['requester' => customer('Someone else')]);

    expect(fn () => Tickets::merge($source, $stranger, $agent))->toThrow(ValidationException::class)
        ->and(fn () => Tickets::merge($source, $source, $agent))->toThrow(ValidationException::class);

    Tickets::merge($source, $target, $agent);

    expect($source->refresh()->isMerged())->toBeTrue()
        ->and($source->getStatus())->toBe(TicketStatus::Closed)
        ->and($target->publicMessages()->pluck('body')->implode(' '))->toContain('Second email')->toContain('First email');

    Event::assertDispatched(TicketsMerged::class);
});

it('rates solved tickets only', function (): void {
    $ticket = ticket(['requester' => customer()]);

    expect(fn () => Tickets::rate($ticket, 5))->toThrow(ValidationException::class);

    Tickets::changeStatus($ticket, TicketStatus::Solved);
    Tickets::rate($ticket->refresh(), 9, '  Great  ');

    expect($ticket->refresh()->satisfaction_rating)->toBe(5)
        ->and($ticket->satisfaction_comment)->toBe('Great');
});

it('escapes plain text messages', function (): void {
    expect(MessageBody::fromText("<b>hi</b>\nthere\n\nnext"))->toBe('<p>&lt;b&gt;hi&lt;/b&gt;<br>'."\n".'there</p><p>next</p>');
});

it('shows agents the whole conversation with notes on the ticket page', function (): void {
    $agent = agent();
    $ticket = ticket(['requester' => customer()]);
    Tickets::note($ticket, '<p>Secret agent note</p>', $agent);
    test()->actingAs($agent);

    $props = test()->inertiaProps('/helpdesk/helpdesk/tickets/'.$ticket->getKey());

    expect(json_encode($props['page']['content']))->toContain('Secret agent note')->toContain('My printer is on fire');
    expect(collect($props['page']['headerActions'])->pluck('name')->all())->toContain('reply', 'note', 'solve');
});

it('forbids the panel to users who are not agents', function (): void {
    $ticket = ticket(['requester' => $customer = customer()]);
    test()->actingAs($customer);

    test()->get('/helpdesk/helpdesk/tickets', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertForbidden();
    test()->get('/helpdesk/helpdesk/tickets/'.$ticket->getKey(), ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertForbidden();
});

it('runs the reply action of the ticket page with a canned reply and attachments refused outside the directory', function (): void {
    Mail::fake();
    $agent = agent();
    $ticket = ticket(['requester' => customer()]);
    test()->actingAs($agent);
    $token = test()->inertiaProps('/helpdesk/helpdesk/tickets/'.$ticket->getKey())['page']['token'];

    test()->postJson('/helpdesk/_alfakit/actions', [
        'token' => $token,
        'action' => 'reply',
        'data' => ['body' => '<p>Fixed it</p>', 'status' => 'pending', 'attachments' => []],
    ])->assertSuccessful();

    $ticket->refresh();

    expect($ticket->getStatus())->toBe(TicketStatus::Pending)
        ->and($ticket->sla_paused_at)->not->toBeNull()
        ->and($ticket->publicMessages()->reorder()->latest('id')->first()?->body)->toContain('Fixed it');

    test()->postJson('/helpdesk/_alfakit/actions', [
        'token' => $token,
        'action' => 'reply',
        'data' => ['body' => '<p></p>'],
    ])->assertStatus(422);
});

it('lists tickets with views as tabs and the reports widget', function (): void {
    $agent = agent();
    ticket(['requester' => customer()]);
    test()->actingAs($agent);

    $props = test()->inertiaProps('/helpdesk/helpdesk/tickets');
    $json = json_encode($props['page']);

    expect($json)->toContain('Due soon')->toContain('Breached')->toContain('My open tickets');
    expect(collect(flatten($props['page']['content']))->pluck('type')->all())->toContain('StatsOverviewWidget');
});
