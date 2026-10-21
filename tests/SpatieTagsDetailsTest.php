<?php

declare(strict_types=1);

use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Tests\Fixtures\TaggedTicket;
use AlfaSix\AlfakitHelpdesk\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use function AlfaSix\AlfakitHelpdesk\Tests\agent;
use function AlfaSix\AlfakitHelpdesk\Tests\customer;
use function AlfaSix\AlfakitHelpdesk\Tests\test;
use function AlfaSix\AlfakitHelpdesk\Tests\ticket;

uses(TestCase::class);

require_once __DIR__.'/helpers.php';

beforeEach(function (): void {
    // The core fixtures have their own `tags` table: replace it with spatie/laravel-tags' tables.
    Schema::dropIfExists('taggables');
    Schema::dropIfExists('tags');
    Schema::create('tags', function (Blueprint $table): void {
        $table->id();
        $table->json('name');
        $table->json('slug');
        $table->string('type')->nullable();
        $table->integer('order_column')->nullable();
        $table->timestamps();
    });
    Schema::create('taggables', function (Blueprint $table): void {
        $table->foreignId('tag_id')->constrained()->cascadeOnDelete();
        $table->morphs('taggable');
        $table->unique(['tag_id', 'taggable_id', 'taggable_type']);
    });
    config()->set('alfakit-helpdesk.models.ticket', TaggedTicket::class);
});

it('edits spatie tags of type helpdesk in Edit details when the ticket model uses HasTags', function (): void {
    $agent = agent();
    $ticket = TaggedTicket::query()->findOrFail(ticket(['requester' => customer()])->getKey());
    $ticket->attachTag('billing', 'helpdesk');
    test()->actingAs($agent);

    $props = test()->inertiaProps('/helpdesk/helpdesk/tickets/'.$ticket->getKey());
    $token = $props['page']['token'];

    $mounted = test()->postJson('/helpdesk/_alfakit/actions/modal', ['token' => $token, 'action' => 'details'])->assertSuccessful()->json();
    expect(json_encode($mounted))->toContain('"tags"')->toContain('billing');

    test()->postJson('/helpdesk/_alfakit/actions', [
        'token' => $token,
        'action' => 'details',
        'data' => ['subject' => 'Printer on fire', 'priority' => 'normal', 'type' => 'question', 'tags' => ['billing', 'vip']],
    ])->assertSuccessful();

    expect($ticket->refresh()->tagsWithType('helpdesk')->pluck('name')->sort()->values()->all())->toBe(['billing', 'vip'])
        ->and(Ticket::query()->findOrFail($ticket->getKey())->labels)->toBeNull();
});
