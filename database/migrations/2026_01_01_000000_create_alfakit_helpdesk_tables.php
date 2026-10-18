<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('helpdesk_groups', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('tenant');
            $table->string('name');
            $table->string('description', 1000)->nullable();
            $table->timestamps();
        });

        Schema::create('helpdesk_group_agent', function (Blueprint $table): void {
            $table->foreignId('group_id')->constrained('helpdesk_groups')->cascadeOnDelete();
            $table->unsignedBigInteger('agent_id')->index();
            $table->primary(['group_id', 'agent_id']);
        });

        Schema::create('helpdesk_tickets', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('tenant');
            $table->string('subject');
            $table->string('status', 20)->default('open')->index();
            $table->string('priority', 20)->default('normal')->index();
            $table->string('type', 20)->default('question');
            $table->string('channel', 20)->default('agent');
            $table->json('labels')->nullable(); // tags, unless the model uses spatie/laravel-tags

            // The requester: a signed-in user, or a guest known by email (and name).
            $table->unsignedBigInteger('requester_id')->nullable()->index();
            $table->string('requester_email')->nullable()->index();
            $table->string('requester_name')->nullable();

            $table->unsignedBigInteger('assignee_id')->nullable()->index();
            $table->foreignId('group_id')->nullable()->constrained('helpdesk_groups')->nullOnDelete();
            $table->foreignId('merged_into_id')->nullable()->constrained('helpdesk_tickets')->nullOnDelete();

            // SLA targets (computed from the plugin's policies and business hours).
            $table->string('sla_policy', 100)->nullable();
            $table->timestamp('first_response_due_at')->nullable()->index();
            $table->timestamp('resolution_due_at')->nullable()->index();
            $table->timestamp('sla_paused_at')->nullable();
            $table->timestamp('first_responded_at')->nullable();
            $table->timestamp('sla_breach_notified_at')->nullable();

            $table->timestamp('solved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->timestamp('requester_seen_at')->nullable();

            // Satisfaction (CSAT) after the ticket is solved: 1 to 5.
            $table->unsignedTinyInteger('satisfaction_rating')->nullable();
            $table->string('satisfaction_comment', 2000)->nullable();
            $table->timestamp('satisfaction_rated_at')->nullable();

            // Guests (contact form, live chat) prove they own a ticket with a token whose
            // hash is stored here.
            $table->string('access_token_hash', 64)->nullable();

            $table->timestamps();
        });

        Schema::create('helpdesk_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('helpdesk_tickets')->cascadeOnDelete();
            $table->string('kind', 10)->default('reply');
            $table->string('source', 20)->default('agent');
            $table->nullableMorphs('author');
            $table->boolean('from_requester')->default(false);
            $table->string('author_name')->nullable();
            $table->string('author_email')->nullable();
            $table->longText('body');
            $table->json('attachments')->nullable();
            $table->string('message_id')->nullable()->index();
            $table->timestamps();

            $table->index(['ticket_id', 'kind']);
        });

        Schema::create('helpdesk_canned_replies', function (Blueprint $table): void {
            $table->id();
            $table->nullableMorphs('tenant');
            $table->string('title');
            $table->longText('body');
            $table->unsignedBigInteger('owner_id')->nullable()->index();
            $table->boolean('is_shared')->default(true);
            $table->unsignedInteger('uses_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('helpdesk_canned_replies');
        Schema::dropIfExists('helpdesk_messages');
        Schema::dropIfExists('helpdesk_tickets');
        Schema::dropIfExists('helpdesk_group_agent');
        Schema::dropIfExists('helpdesk_groups');
    }
};
