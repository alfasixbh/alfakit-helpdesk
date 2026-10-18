<?php

declare(strict_types=1);

use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Inbound\InboundMailHandler;
use AlfaSix\AlfakitHelpdesk\Mail\AgentReplied;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Support\ReplyAddress;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use AlfaSix\AlfakitHelpdesk\Tests\InboundTestCase;
use Illuminate\Support\Facades\Mail;

use function AlfaSix\AlfakitHelpdesk\Tests\agent;
use function AlfaSix\AlfakitHelpdesk\Tests\test;
use function AlfaSix\AlfakitHelpdesk\Tests\ticket;

uses(InboundTestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * @param  array<string, mixed>  $payload
 */
function inbound(array $payload, ?string $secret = 'inbound-secret'): Illuminate\Testing\TestResponse
{
    $body = (string) json_encode($payload);
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];

    if ($secret !== null) {
        $headers['HTTP_X_HELPDESK_SIGNATURE'] = 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    return test()->call('POST', '/support/inbound/mail', [], [], [], $headers, $body);
}

beforeEach(function (): void {
    Mail::fake();
});

it('refuses unsigned and wrongly signed webhooks', function (): void {
    inbound(['from' => 'a@example.com', 'subject' => 'Hi', 'text' => 'x'], secret: null)->assertForbidden();
    inbound(['from' => 'a@example.com', 'subject' => 'Hi', 'text' => 'x'], secret: 'wrong')->assertForbidden();

    expect(Ticket::query()->count())->toBe(0);
});

it('files replies sent to the signed Reply-To address by the requester', function (): void {
    $ticket = ticket(['requester_email' => 'dana@example.com']);
    Tickets::changeStatus($ticket, TicketStatus::Pending, agent());
    $address = (string) ReplyAddress::for($ticket);

    expect($address)->toStartWith('support+'.$ticket->getKey().'.')->toEndWith('@example.com');

    inbound([
        'from' => ['email' => 'DANA@example.com', 'name' => 'Dana'],
        'to' => [$address],
        'subject' => 'Re: ['.$ticket->getReference().'] Printer on fire',
        'text' => "It works now!\n\nOn Mon, Oct 19, 2026 Support wrote:\n> Did you restart it?",
        'message_id' => '<abc@mail.example.com>',
    ])->assertStatus(202)->assertJson(['ticket' => $ticket->getKey(), 'created' => false]);

    $reply = $ticket->publicMessages()->reorder()->latest('id')->first();

    expect($reply?->body)->toBe('<p>It works now!</p>')
        ->and($reply?->source)->toBe('email')
        ->and($ticket->refresh()->getStatus())->toBe(TicketStatus::Open);

    // The provider retries: nothing is added twice.
    inbound([
        'from' => 'dana@example.com',
        'to' => [$address],
        'subject' => 'Re: Printer on fire',
        'text' => 'It works now!',
        'message_id' => '<abc@mail.example.com>',
    ])->assertJson(['duplicate' => true]);

    expect($ticket->publicMessages()->count())->toBe(2);
});

it('opens a new ticket for other senders and forged addresses', function (): void {
    $ticket = ticket(['requester_email' => 'dana@example.com']);
    $address = (string) ReplyAddress::for($ticket);

    inbound(['from' => 'mallory@example.com', 'to' => [$address], 'subject' => 'Re: [#1] Printer', 'text' => 'Let me in'])->assertJson(['created' => true]);
    inbound(['from' => 'dana@example.com', 'to' => ['support+'.$ticket->getKey().'.0000000000000000@example.com'], 'subject' => 'Forged', 'text' => 'Hi'])->assertJson(['created' => true]);

    expect($ticket->publicMessages()->count())->toBe(1)
        ->and(Ticket::query()->where('channel', TicketChannel::Email->value)->pluck('subject')->all())->toBe(['Printer', 'Forged']);
});

it('rejects payloads that are not emails', function (): void {
    inbound(['subject' => 'No sender'])->assertStatus(422);
});

it('sets the Reply-To address on emails to requesters', function (): void {
    $ticket = ticket(['requester_email' => 'dana@example.com']);
    Tickets::reply($ticket, '<p>Answer</p>', agent());

    Mail::assertQueued(AgentReplied::class, fn (AgentReplied $mail): bool => $mail->hasReplyTo((string) ReplyAddress::for($ticket)));
});

it('strips quoted text from replies', function (): void {
    expect(InboundMailHandler::stripQuotedText("Thanks\n> old\nOn Tue, Bob wrote:\n> older"))->toBe('Thanks')
        ->and(InboundMailHandler::stripQuotedText('> only quoted'))->toBe('> only quoted');
});
