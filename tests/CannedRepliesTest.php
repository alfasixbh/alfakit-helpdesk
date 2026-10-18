<?php

declare(strict_types=1);

use AlfaSix\AlfakitHelpdesk\Models\CannedReply;
use AlfaSix\AlfakitHelpdesk\Support\CannedReplies;
use AlfaSix\AlfakitHelpdesk\Support\Placeholders;
use AlfaSix\AlfakitHelpdesk\Tests\TestCase;
use Illuminate\Support\Facades\Gate;

use function AlfaSix\AlfakitHelpdesk\Tests\agent;
use function AlfaSix\AlfakitHelpdesk\Tests\customer;
use function AlfaSix\AlfakitHelpdesk\Tests\test;
use function AlfaSix\AlfakitHelpdesk\Tests\ticket;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

it('fills in placeholders for the ticket and agent', function (): void {
    $agent = agent('Dana Scully');
    $ticket = ticket(['requester' => customer('Fox Mulder'), 'subject' => 'Lights in the sky']);

    $html = Placeholders::render('<p>Hi {{ requester.first_name }}, {{agent.name}} here about {{ ticket.reference }} "{{ ticket.subject }}".</p>', $ticket, $agent);

    expect($html)->toBe('<p>Hi Fox, Dana Scully here about #'.$ticket->getKey().' &#34;Lights in the sky&#34;.</p>');
});

it('never evaluates anything and escapes every value', function (): void {
    $agent = agent();
    $ticket = ticket(['requester' => customer('<script>alert("x")</script>'), 'subject' => '<img src=x onerror=alert(1)>']);

    $html = Placeholders::render('<p>{{ requester.name }} {{ ticket.subject }} {{ phpinfo() }} {{ $secret }} {{ config.app.key }} {{ requester.password }} @php echo 1; @endphp {!! 1 !!}</p>', $ticket, $agent);

    expect($html)->not->toContain('<script')
        ->not->toContain('<img')
        ->not->toContain('onerror=')
        ->not->toContain((string) config('app.key'))
        ->toContain('&lt;script&gt;')
        // Unknown and malformed placeholders don't run: they render as nothing or stay text.
        ->toContain('{{ phpinfo() }}')
        ->toContain('{{ $secret }}')
        ->not->toContain('password');
});

it('sanitises the reply itself', function (): void {
    $ticket = ticket(['requester' => customer()]);

    expect(Placeholders::render('<p onclick="steal()">Hi</p><script>alert(1)</script><a href="javascript:alert(1)">x</a>', $ticket))
        ->not->toContain('onclick')
        ->not->toContain('<script')
        ->not->toContain('javascript:');
});

it('supports custom variables', function (): void {
    Placeholders::define('company.name', fn (): string => 'Acme & Co');

    expect(Placeholders::render('<p>{{ company.name }}</p>', ticket()))->toBe('<p>Acme &amp; Co</p>')
        ->and(Placeholders::names())->toContain('company.name', 'requester.name');
});

it('sanitises canned replies when they are saved', function (): void {
    $reply = CannedReply::query()->create(['title' => 'Hi', 'body' => '<p>Hello {{ requester.name }}</p><script>alert(1)</script>']);

    expect($reply->body)->toBe('<p>Hello {{ requester.name }}</p>');
});

it('keeps private canned replies to their owner', function (): void {
    $dana = agent('Dana');
    $riley = agent('Riley');
    $shared = CannedReply::query()->create(['title' => 'Shared', 'body' => '<p>a</p>', 'is_shared' => true, 'owner_id' => $dana->id]);
    $private = CannedReply::query()->create(['title' => 'Mine', 'body' => '<p>b</p>', 'is_shared' => false, 'owner_id' => $dana->id]);

    test()->actingAs($riley);
    alfakit()->setCurrentPanel(alfakit()->getPanel('helpdesk'));

    expect(array_values(CannedReplies::options($riley)))->toBe(['Shared'])
        ->and(CannedReplies::find($private->id, $riley))->toBeNull()
        ->and(Gate::forUser($riley)->allows('update', $private))->toBeFalse()
        ->and(Gate::forUser($dana)->allows('update', $private))->toBeTrue()
        ->and(Gate::forUser($riley)->allows('update', $shared))->toBeTrue();

    test()->get('/helpdesk/helpdesk/canned-replies/'.$private->id.'/edit', ['X-Inertia' => 'true', 'X-Inertia-Version' => ''])->assertNotFound();
});

it('inserts a canned reply in the reply editor through the server', function (): void {
    $agent = agent('Dana');
    $ticket = ticket(['requester' => customer('Fox Mulder')]);
    $reply = CannedReply::query()->create(['title' => 'Greeting', 'body' => '<p>Hi {{ requester.first_name }}!</p>']);
    test()->actingAs($agent);
    $token = test()->inertiaProps('/helpdesk/helpdesk/tickets/'.$ticket->getKey())['page']['token'];

    $response = test()->postJson('/helpdesk/_alfakit/schema/refresh', [
        'token' => $token,
        'component' => 'action:page.reply',
        'state' => ['canned_reply' => (string) $reply->id, 'body' => null],
        'path' => 'canned_reply',
    ])->assertOk();

    expect(json_encode($response->json()))->toContain('Hi Fox!');
    expect($reply->refresh()->uses_count)->toBe(1);
});
