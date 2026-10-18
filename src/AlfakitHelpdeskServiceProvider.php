<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk;

use AlfaSix\Alfakit\Mail\CoreMails;
use AlfaSix\Alfakit\Mail\MailDefinition;
use AlfaSix\Alfakit\Mail\Mails;
use AlfaSix\AlfakitHelpdesk\Commands\CheckSlaCommand;
use AlfaSix\AlfakitHelpdesk\Commands\MakePortalPageCommand;
use AlfaSix\AlfakitHelpdesk\Inbound\HmacSignatureVerifier;
use AlfaSix\AlfakitHelpdesk\Inbound\InboundMailParser;
use AlfaSix\AlfakitHelpdesk\Inbound\InboundMailVerifier;
use AlfaSix\AlfakitHelpdesk\Inbound\JsonInboundMailParser;
use AlfaSix\AlfakitHelpdesk\Mail\AgentNotification;
use AlfaSix\AlfakitHelpdesk\Mail\AgentReplied;
use AlfaSix\AlfakitHelpdesk\Mail\TicketReceived;
use AlfaSix\AlfakitHelpdesk\Mail\TicketSolvedMail;
use AlfaSix\AlfakitHelpdesk\Models\CannedReply;
use AlfaSix\AlfakitHelpdesk\Models\HelpdeskGroup;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use AlfaSix\AlfakitHelpdesk\Policies\CannedReplyPolicy;
use AlfaSix\AlfakitHelpdesk\Policies\HelpdeskGroupPolicy;
use AlfaSix\AlfakitHelpdesk\Policies\TicketPolicy;
use Closure;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AlfakitHelpdeskServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/alfakit-helpdesk.php', 'alfakit-helpdesk');

        $this->app->bindIf(InboundMailVerifier::class, HmacSignatureVerifier::class);
        $this->app->bindIf(InboundMailParser::class, JsonInboundMailParser::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'alfakit-helpdesk');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'alfakit-helpdesk');

        // Your own `Gate::policy(...)` (registered later) replaces these.
        foreach ([Ticket::class => TicketPolicy::class, CannedReply::class => CannedReplyPolicy::class, HelpdeskGroup::class => HelpdeskGroupPolicy::class] as $model => $policy) {
            if (! array_key_exists($model, Gate::policies())) {
                Gate::policy($model, $policy);
            }
        }

        static::registerMails();

        if ((bool) config('alfakit-helpdesk.route.enabled', true) && ! $this->app->routesAreCached()) {
            $this->loadRoutesFrom(__DIR__.'/../routes/web.php');
        }

        if ($this->app->runningInConsole()) {
            $this->commands([CheckSlaCommand::class, MakePortalPageCommand::class]);

            $this->publishes([
                __DIR__.'/../config/alfakit-helpdesk.php' => config_path('alfakit-helpdesk.php'),
            ], 'alfakit-helpdesk-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'alfakit-helpdesk-migrations');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/alfakit-helpdesk'),
            ], 'alfakit-helpdesk-translations');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/alfakit-helpdesk'),
            ], 'alfakit-helpdesk-views');
        }
    }

    /**
     * The helpdesk mails, for the mail preview page and editable templates.
     */
    protected static function registerMails(): void
    {
        $group = static fn (): string => (string) __('alfakit-helpdesk::helpdesk.mail_templates.group');
        $label = static fn (string $key): Closure => static fn (): string => (string) __('alfakit-helpdesk::helpdesk.mail_templates.'.$key);
        $heading = static fn (string $key, array $replace = []): string => '<h1>'.e((string) __('alfakit-helpdesk::helpdesk.mail.'.$key, $replace)).'</h1>';
        $tag = static fn (string $text): string => (string) preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', static fn (array $match): string => MailDefinition::tag($match[1]), $text);

        Mails::register(
            MailDefinition::make('helpdesk.ticket-received')
                ->label($label('ticket_received'))
                ->group($group)
                ->preview(static fn (): TicketReceived => new TicketReceived(self::previewTicket()))
                ->editable(
                    mergeTags: static fn (): array => CoreMails::tags(['reference', 'subject', 'name', 'button', 'url', 'app']),
                    subject: '{{ subject }}',
                    body: static fn (): string => $heading('received.heading').MailDefinition::paragraphs(
                        (string) __('alfakit-helpdesk::helpdesk.mail.received.body', ['reference' => '{{ reference }}', 'subject' => '{{ subject }}']),
                        '{{ button }}',
                        (string) __('alfakit-helpdesk::helpdesk.mail.reply_hint'),
                    ),
                ),
            MailDefinition::make('helpdesk.agent-replied')
                ->label($label('agent_replied'))
                ->group($group)
                ->preview(static fn (): AgentReplied => new AgentReplied(self::previewTicket(), self::previewMessage()))
                ->editable(
                    mergeTags: static fn (): array => CoreMails::tags(['reference', 'subject', 'name', 'author', 'message', 'button', 'url', 'app']),
                    subject: '{{ subject }}',
                    body: static fn (): string => $tag($heading('replied.heading', ['name' => '{{ author }}'])).MailDefinition::paragraphs(
                        '{{ message }}',
                        '{{ button }}',
                        (string) __('alfakit-helpdesk::helpdesk.mail.reply_hint'),
                    ),
                ),
            MailDefinition::make('helpdesk.ticket-solved')
                ->label($label('ticket_solved'))
                ->group($group)
                ->preview(static fn (): TicketSolvedMail => new TicketSolvedMail(self::previewTicket()))
                ->editable(
                    mergeTags: static fn (): array => CoreMails::tags(['reference', 'subject', 'name', 'rate_button', 'button', 'url', 'app']),
                    subject: '{{ subject }}',
                    body: static fn (): string => $heading('solved.heading').MailDefinition::paragraphs(
                        (string) __('alfakit-helpdesk::helpdesk.mail.solved.body', ['reference' => '{{ reference }}', 'subject' => '{{ subject }}']),
                        '{{ rate_button }}',
                        (string) __('alfakit-helpdesk::helpdesk.mail.solved.reopen'),
                        '{{ button }}',
                    ),
                ),
            MailDefinition::make('helpdesk.agent-notification')
                ->label($label('agent_notification'))
                ->group($group)
                ->preview(static fn (): AgentNotification => new AgentNotification(self::previewTicket(), self::previewMessage(), isNew: true))
                ->editable(
                    mergeTags: static fn (): array => CoreMails::tags(['reference', 'subject', 'name', 'message', 'button', 'url', 'app']),
                    subject: '{{ subject }}',
                    body: static fn (): string => $tag($heading('agent.new_heading', ['reference' => '{{ reference }}'])).MailDefinition::paragraphs(
                        '{{ subject }}',
                        '{{ message }}',
                        '{{ button }}',
                    ),
                ),
        );
    }

    /**
     * An unsaved ticket for mail previews.
     */
    protected static function previewTicket(): Ticket
    {
        $ticket = new Ticket(['subject' => 'The printer is on fire', 'requester_name' => 'Ada Lovelace', 'requester_email' => 'ada@example.com', 'priority' => 'high']);
        $ticket->setAttribute($ticket->getKeyName(), 42);

        return $ticket;
    }

    protected static function previewMessage(): TicketMessage
    {
        return new TicketMessage(['body' => '<p>We sent a technician. Please keep a safe distance from the printer until they arrive.</p>', 'author_name' => 'Grace Hopper']);
    }
}
