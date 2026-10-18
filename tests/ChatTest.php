<?php

declare(strict_types=1);

use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Support\AccessToken;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use AlfaSix\AlfakitHelpdesk\Support\Typing;
use AlfaSix\AlfakitHelpdesk\Tests\TestCase;
use Illuminate\Support\Facades\Mail;

use function AlfaSix\AlfakitHelpdesk\Tests\agent;
use function AlfaSix\AlfakitHelpdesk\Tests\customer;
use function AlfaSix\AlfakitHelpdesk\Tests\test;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    Mail::fake();
    // The cookie is read as sent (the encryption middleware is the application's).
    test()->withoutMiddleware([Illuminate\Cookie\Middleware\EncryptCookies::class, Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class]);
});

function chatCookie(Illuminate\Testing\TestResponse $response): string
{
    $cookie = collect($response->headers->getCookies())->first(fn ($cookie): bool => $cookie->getName() === 'alfakit_helpdesk_chat');

    expect($cookie)->not->toBeNull()
        ->and($cookie->isHttpOnly())->toBeTrue()
        ->and($cookie->getSameSite())->toBe('lax');

    return (string) $cookie->getValue();
}

it('serves the widget settings and strings', function (): void {
    $response = test()->getJson('/support/chat')->assertOk();

    expect($response->json('conversation'))->toBeNull()
        ->and($response->json('config.strings.placeholder'))->toBe('Write a message…')
        ->and($response->json('config.greeting'))->toBe('Hi! How can we help you today?')
        ->and($response->json('config.honeypot'))->toBe('_website');
});

it('shows the "Powered by" credit in the widget only when it is turned on', function (): void {
    expect(test()->getJson('/support/chat')->json('config.poweredBy'))->toBeNull();

    config(['alfakit.credit.public_pages' => true]);

    expect(test()->getJson('/support/chat')->json('config.poweredBy'))->toContain('data-slot="powered-by"');
});

it('starts a chat that becomes a ticket and remembers the visitor', function (): void {
    $response = test()->postJson('/support/chat', ['message' => 'Is anyone there?', 'name' => 'Vic', 'email' => 'vic@example.com'])->assertCreated();
    $token = chatCookie($response);
    $ticket = Ticket::query()->sole();

    expect($ticket->getChannel())->toBe(TicketChannel::Chat)
        ->and($ticket->requester_email)->toBe('vic@example.com')
        ->and(AccessToken::resolve($token)?->is($ticket))->toBeTrue()
        ->and($response->json('conversation.messages.0.html'))->toBe('<p>Is anyone there?</p>');

    test()->withCredentials()->withUnencryptedCookie('alfakit_helpdesk_chat', $token)->postJson('/support/chat/messages', ['message' => 'Hello?'])->assertOk();

    expect($ticket->publicMessages()->count())->toBe(2);
});

it('shows the visitor agent replies and typing, never internal notes', function (): void {
    $token = chatCookie(test()->postJson('/support/chat', ['message' => 'Help'])->assertCreated());
    $ticket = Ticket::query()->sole();
    $agent = agent('Dana');

    Tickets::note($ticket, '<p>Probably a bot</p>', $agent);
    Tickets::reply($ticket, '<p>Hi, Dana here</p>', $agent);
    Typing::agent($ticket, 'Dana');

    $conversation = test()->withCredentials()->withUnencryptedCookie('alfakit_helpdesk_chat', $token)->getJson('/support/chat')->assertOk()->json('conversation');

    expect(json_encode($conversation))->toContain('Hi, Dana here')->not->toContain('Probably a bot')
        ->and($conversation['agentTyping'])->toBe('Dana')
        ->and(collect($conversation['messages'])->pluck('from')->all())->toBe(['visitor', 'agent']);
});

it('refuses forged and tampered chat tokens', function (string $token): void {
    test()->postJson('/support/chat', ['message' => 'Help'])->assertCreated();
    $ticket = Ticket::query()->sole();

    test()->withCredentials()->withUnencryptedCookie('alfakit_helpdesk_chat', str_replace('{id}', (string) $ticket->getKey(), $token))
        ->postJson('/support/chat/messages', ['message' => 'Hijack'])
        ->assertNotFound();

    expect($ticket->publicMessages()->count())->toBe(1);
})->with([
    'garbage' => 'nope',
    'unsigned' => '{id}.'.str_repeat('a', 40).'.'.str_repeat('0', 64),
]);

it('refuses a correctly signed token whose secret was revoked', function (): void {
    $token = chatCookie(test()->postJson('/support/chat', ['message' => 'Help'])->assertCreated());
    $ticket = Ticket::query()->sole();

    AccessToken::revoke($ticket);

    test()->withCredentials()->withUnencryptedCookie('alfakit_helpdesk_chat', $token)->postJson('/support/chat/messages', ['message' => 'Again'])->assertNotFound();
});

it('drops chats from bots', function (): void {
    $response = test()->postJson('/support/chat', ['message' => 'Spam', '_website' => 'x'])->assertCreated();

    expect(Ticket::query()->count())->toBe(0)
        ->and(collect($response->headers->getCookies())->map->getName()->all())->not->toContain('alfakit_helpdesk_chat');
});

it('rate limits starting chats', function (): void {
    config(['alfakit-helpdesk.rate_limits.chat_start' => ['max_attempts' => 2, 'decay_seconds' => 60]]);

    test()->postJson('/support/chat', ['message' => 'One'])->assertCreated();
    test()->postJson('/support/chat', ['message' => 'Two'])->assertCreated();
    test()->postJson('/support/chat', ['message' => 'Three'])->assertStatus(429)->assertHeader('Retry-After');
});

it('validates chat messages', function (): void {
    test()->postJson('/support/chat', ['message' => ''])->assertStatus(422);
    test()->postJson('/support/chat', ['message' => str_repeat('a', 4001)])->assertStatus(422);
    test()->postJson('/support/chat/messages', ['message' => 'No chat yet'])->assertNotFound();
});

it('uses the account of signed-in customers instead of a cookie', function (): void {
    $casey = customer('Casey');
    test()->actingAs($casey);

    $response = test()->postJson('/support/chat', ['message' => 'Hi from my account'])->assertCreated();

    expect(collect($response->headers->getCookies())->map->getName()->all())->not->toContain('alfakit_helpdesk_chat')
        ->and((string) Ticket::query()->sole()->requester_id)->toBe((string) $casey->id);

    expect(test()->getJson('/support/chat')->json('conversation.messages.0.html'))->toBe('<p>Hi from my account</p>');
});

it('records the visitor typing for agents', function (): void {
    $token = chatCookie(test()->postJson('/support/chat', ['message' => 'Help'])->assertCreated());

    test()->withCredentials()->withUnencryptedCookie('alfakit_helpdesk_chat', $token)->postJson('/support/chat/typing')->assertOk();

    expect(Typing::requesterTyping(Ticket::query()->sole()))->toBeTrue();
});
