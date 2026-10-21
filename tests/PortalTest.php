<?php

declare(strict_types=1);

use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Portal\ContactForm;
use AlfaSix\AlfakitHelpdesk\Support\Links;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use AlfaSix\AlfakitHelpdesk\Tests\TestCase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

use function AlfaSix\AlfakitHelpdesk\Tests\agent;
use function AlfaSix\AlfakitHelpdesk\Tests\customer;
use function AlfaSix\AlfakitHelpdesk\Tests\inertiaHeaders;
use function AlfaSix\AlfakitHelpdesk\Tests\test;
use function AlfaSix\AlfakitHelpdesk\Tests\ticket;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

/**
 * @return array<int, array<string, mixed>>
 */
function portalComponents(string $url): array
{
    return test()->inertiaProps($url)['components'];
}

/**
 * @param  array<string, mixed>  $data
 */
function submitStandalone(string $token, array $data): Illuminate\Testing\TestResponse
{
    return test()->post('/_alfakit/actions', ['token' => $token, 'action' => 'submit', 'data' => $data], [...inertiaHeaders(), 'Accept' => 'application/json']);
}

beforeEach(function (): void {
    Mail::fake();
});

it('sends guests to the login page', function (): void {
    config(['alfakit-helpdesk.portal.login_url' => '/login']);

    test()->get('/support')->assertRedirect('/login');
    test()->get('/support/tickets/new')->assertRedirect('/login');
});

it('passes the opt-in "Powered by" credit to the portal page', function (): void {
    test()->actingAs(customer('Casey'));

    expect(test()->inertiaProps('/support')['meta']['poweredBy'])->toBeNull();

    config(['alfakit.credit.public_pages' => true]);

    expect(test()->inertiaProps('/support')['meta']['poweredBy'])->toContain('data-slot="powered-by"');
});

it('shows customers their own tickets only', function (): void {
    $casey = customer('Casey');
    $other = customer('Other');
    ticket(['requester' => $casey, 'subject' => 'Casey problem']);
    ticket(['requester' => $other, 'subject' => 'Other problem']);
    test()->actingAs($casey);

    $json = json_encode(portalComponents('/support'));

    expect($json)->toContain('Casey problem')->not->toContain('Other problem');
});

it('finds tickets sent from a verified email address, not from an unverified one', function (): void {
    $verified = customer('Verified', verified: true, email: 'verified@example.com');
    $unverified = customer('Unverified', verified: false, email: 'unverified@example.com');
    $mine = ticket(['requester_email' => 'VERIFIED@example.com', 'subject' => 'Sent before signing up']);
    $notMine = ticket(['requester_email' => 'unverified@example.com', 'subject' => 'Unverified address']);

    test()->actingAs($verified);
    test()->get('/support/tickets/'.$mine->getKey(), inertiaHeaders())->assertOk();

    test()->actingAs($unverified);
    test()->get('/support/tickets/'.$notMine->getKey(), inertiaHeaders())->assertNotFound();
});

it('never shows internal notes or events to the requester', function (): void {
    $casey = customer();
    $agent = agent();
    $ticket = ticket(['requester' => $casey]);
    Tickets::note($ticket, '<p>VIP, refund approved internally</p>', $agent);
    Tickets::assign($ticket, $agent, $agent);
    Tickets::reply($ticket, '<p>Public answer</p>', $agent);
    test()->actingAs($casey);

    $json = json_encode(portalComponents('/support/tickets/'.$ticket->getKey()));

    expect($json)->toContain('Public answer')
        ->not->toContain('refund approved')
        ->not->toContain('assigned the ticket');
});

it('hides other requesters\' tickets as not found', function (): void {
    $ticket = ticket(['requester' => customer('Owner')]);
    test()->actingAs(customer('Intruder'));

    test()->get('/support/tickets/'.$ticket->getKey(), inertiaHeaders())->assertNotFound();
});

it('lets the requester reply, which reopens a solved ticket', function (): void {
    $casey = customer();
    $ticket = ticket(['requester' => $casey]);
    Tickets::changeStatus($ticket, TicketStatus::Solved);
    test()->actingAs($casey);

    $components = portalComponents('/support/tickets/'.$ticket->getKey());
    $reply = collect($components)->firstWhere('class', AlfaSix\AlfakitHelpdesk\Portal\ReplyForm::class);

    expect(collect($components)->pluck('class')->all())->toContain(AlfaSix\AlfakitHelpdesk\Portal\SatisfactionForm::class);

    submitStandalone($reply['token'], ['body' => '<p>It broke again</p>'])->assertSessionHasNoErrors();

    $ticket->refresh();

    expect($ticket->getStatus())->toBe(TicketStatus::Open)
        ->and($ticket->publicMessages()->reorder()->latest('id')->first()?->from_requester)->toBeTrue();
});

it('refuses a reply token used by another customer', function (): void {
    $casey = customer();
    $ticket = ticket(['requester' => $casey]);
    test()->actingAs($casey);
    $reply = collect(portalComponents('/support/tickets/'.$ticket->getKey()))->firstWhere('class', AlfaSix\AlfakitHelpdesk\Portal\ReplyForm::class);

    test()->actingAs(customer('Mallory'));

    expect(submitStandalone($reply['token'], ['body' => '<p>Injected</p>'])->status())->toBeIn([403, 419]);
    expect($ticket->publicMessages()->count())->toBe(1);
});

it('opens a ticket from the portal', function (): void {
    $casey = customer();
    test()->actingAs($casey);
    $form = portalComponents('/support/tickets/new')[0];

    submitStandalone($form['token'], ['subject' => 'Need an invoice', 'type' => 'question', 'body' => '<p>For October</p>']);

    $ticket = Ticket::query()->where('subject', 'Need an invoice')->first();

    expect($ticket)->not->toBeNull()
        ->and($ticket->getChannel())->toBe(TicketChannel::Portal)
        ->and((string) $ticket->requester_id)->toBe((string) $casey->id);
});

it('opens a guest\'s ticket from a signed link only', function (): void {
    $ticket = ticket(['requester_email' => 'guest@example.com']);

    test()->get('/support/t/'.$ticket->getKey(), inertiaHeaders())->assertForbidden();

    $url = Links::guest($ticket);

    expect($url)->not->toBeNull();

    $components = test()->get((string) $url, inertiaHeaders())->assertOk()->json('props.components');
    $reply = collect($components)->firstWhere('class', AlfaSix\AlfakitHelpdesk\Portal\ReplyForm::class);

    submitStandalone($reply['token'], ['body' => '<p>Thanks!</p>'])->assertSessionHasNoErrors();

    expect($ticket->publicMessages()->count())->toBe(2);
});

it('hides the contact form honeypot without widening right-to-left pages', function (): void {
    $form = portalComponents('/support/contact')[0];
    $style = (string) (collect(iterator_to_array(new RecursiveIteratorIterator(new RecursiveArrayIterator($form))))->first(fn (mixed $value, string|int $key): bool => $key === 'style' && is_string($value) && str_contains($value, 'position:absolute')) ?? '');

    // Pushed 10000px towards the start, it gave Arabic pages a 10000px horizontal scroll.
    expect($style)->toContain('clip-path:inset(50%)')->not->toContain('-10000px');
});

it('creates tickets for guests from the contact form', function (): void {
    $form = portalComponents('/support/contact')[0];

    submitStandalone($form['token'], ['name' => 'Gwen', 'email' => 'gwen@example.com', 'subject' => 'Pricing', 'message' => "Hello <b>team</b>\nHow much?"]);

    $ticket = Ticket::query()->where('subject', 'Pricing')->first();

    expect($ticket)->not->toBeNull()
        ->and($ticket->getChannel())->toBe(TicketChannel::Contact)
        ->and($ticket->requester_email)->toBe('gwen@example.com')
        ->and($ticket->messages->first()->body)->toContain('&lt;b&gt;team&lt;/b&gt;');

    Mail::assertQueued(AlfaSix\AlfakitHelpdesk\Mail\TicketReceived::class);
});

it('drops spam from the contact form silently', function (): void {
    $form = portalComponents('/support/contact')[0];

    submitStandalone($form['token'], ['name' => 'Bot', 'email' => 'bot@example.com', 'subject' => 'Cheap pills', 'message' => 'Buy now', '_website' => 'http://spam.test'])
        ->assertSessionHasNoErrors();

    expect(Ticket::query()->count())->toBe(0);

    // Sent faster than a person could.
    config(['alfakit-helpdesk.spam.min_seconds' => 30]);
    $form = ContactForm::show()->toPayload();
    submitStandalone($form['token'], ['name' => 'Bot', 'email' => 'bot@example.com', 'subject' => 'Fast', 'message' => 'Buy now']);

    expect(Ticket::query()->count())->toBe(0);
});

it('rate limits the contact form', function (): void {
    config(['alfakit-helpdesk.rate_limits.contact' => ['max_attempts' => 2, 'decay_seconds' => 60]]);
    $form = portalComponents('/support/contact')[0];

    foreach (range(1, 3) as $attempt) {
        submitStandalone($form['token'], ['name' => 'Gwen', 'email' => 'gwen@example.com', 'subject' => 'Try '.$attempt, 'message' => 'Hi']);
    }

    expect(Ticket::query()->pluck('subject')->all())->toBe(['Try 1', 'Try 2']);
});

it('validates the contact form', function (): void {
    $form = portalComponents('/support/contact')[0];

    submitStandalone($form['token'], ['name' => '', 'email' => 'not-an-email', 'subject' => '', 'message' => ''])->assertStatus(422);

    expect(Ticket::query()->count())->toBe(0);
});

it('collects satisfaction ratings from the signed survey link', function (): void {
    $ticket = ticket(['requester_email' => 'guest@example.com']);
    Tickets::changeStatus($ticket, TicketStatus::Solved);

    test()->get('/support/rate/'.$ticket->getKey(), inertiaHeaders())->assertForbidden();

    $form = test()->get((string) Links::rate($ticket), inertiaHeaders())->assertOk()->json('props.components.0');

    submitStandalone($form['token'], ['rating' => '6', 'comment' => ''])->assertStatus(422);
    submitStandalone($form['token'], ['rating' => '4', 'comment' => 'Quick and kind'])->assertSessionHasNoErrors();

    expect($ticket->refresh()->satisfaction_rating)->toBe(4)
        ->and($ticket->satisfaction_comment)->toBe('Quick and kind');
});

it('signs attachment downloads', function (): void {
    Illuminate\Support\Facades\Storage::disk('local')->put('helpdesk/2026/10/report.txt', 'hello');
    $ticket = ticket(['requester' => customer()]);
    $message = Tickets::reply($ticket, '<p>See attached</p>', agent(), attachments: [['path' => 'helpdesk/2026/10/report.txt', 'name' => 'report.txt']]);

    test()->get('/support/attachments/'.$message->id.'/0')->assertForbidden();

    $url = URL::temporarySignedRoute('alfakit-helpdesk.attachments', now()->addMinute(), ['message' => $message->id, 'index' => 0]);

    test()->get($url)->assertOk()->assertDownload('report.txt');
});
