<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Portal;

use AlfaSix\Alfakit\Forms\Components\Textarea;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\Portal\Concerns\InteractsWithPortal;
use AlfaSix\AlfakitHelpdesk\Support\MessageBody;
use AlfaSix\AlfakitHelpdesk\Support\SpamGuard;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;

/**
 * "Contact us" for everyone: a guest's message becomes a ticket (with a signed link in the
 * confirmation email). Rate limited per IP address; spam (the honeypot, or a form sent
 * too fast) gets the same confirmation and stores nothing.
 */
class ContactForm extends Form
{
    use InteractsWithPortal;

    /** Session key of the confirmation shown after a submit. */
    public const SUBMITTED_FLASH_KEY = 'alfakit-helpdesk.contact.submitted';

    /** When the form was shown (Unix time), carried in the token for the spam time check. */
    public ?int $issuedAt = null;

    public static function show(): static
    {
        return static::make(['issuedAt' => time()]);
    }

    public function mount(mixed ...$parameters): void
    {
        $this->heading(__('alfakit-helpdesk::helpdesk.contact.heading'));
        $this->subheading(__('alfakit-helpdesk::helpdesk.contact.description'));
    }

    public function isSubmitted(): bool
    {
        if (request()->routeIs($this->getPanel()->generateRouteName('internal.*'))) {
            return false;
        }

        return request()->hasSession() && request()->session()->has(self::SUBMITTED_FLASH_KEY);
    }

    public function form(Schema $schema): Schema
    {
        if ($this->isSubmitted()) {
            return $schema;
        }

        $customer = $this->customer();

        return $schema->columns(['default' => 1, 'sm' => 2])->components([
            TextInput::make('name')
                ->label(__('alfakit-helpdesk::helpdesk.fields.requester_name'))
                ->required()
                ->maxLength(255)
                ->default(is_string($name = $customer?->getAttribute('name')) ? $name : null)
                ->autocomplete('name'),
            TextInput::make('email')
                ->label(__('alfakit-helpdesk::helpdesk.fields.requester_email'))
                ->email()
                ->required()
                ->maxLength(255)
                ->default(is_string($email = $customer?->getAttribute('email')) ? $email : null)
                ->autocomplete('email'),
            TextInput::make('subject')
                ->label(__('alfakit-helpdesk::helpdesk.fields.subject'))
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Textarea::make('message')
                ->label(__('alfakit-helpdesk::helpdesk.fields.message'))
                ->required()
                ->rows(6)
                ->maxLength(10000)
                ->columnSpanFull(),
            SpamGuard::honeypot(),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        if (! $this->isSubmitted()) {
            return $schema;
        }

        return $schema->components([
            Callout::make(__('alfakit-helpdesk::helpdesk.contact.sent_heading'))
                ->description(__('alfakit-helpdesk::helpdesk.contact.sent_description'))
                ->success()
                ->icon('circle-check'),
        ]);
    }

    protected function getFormActions(): array
    {
        return $this->isSubmitted() ? [] : parent::getFormActions();
    }

    public function getSubmitLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.contact.submit');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): mixed
    {
        if (! SpamGuard::attempt('contact')) {
            $this->failWith(
                __('alfakit-helpdesk::helpdesk.portal.rate_limited'),
                __('alfakit-helpdesk::helpdesk.portal.retry_in', ['seconds' => SpamGuard::availableIn('contact')]),
            );
        }

        $honeypot = $data[SpamGuard::honeypotField()] ?? request()->input('data.'.SpamGuard::honeypotField());

        if (! SpamGuard::isSpam($honeypot, $this->issuedAt)) {
            $customer = $this->customer();
            $email = (string) ($data['email'] ?? '');
            // A signed-in customer writing from their own address files the ticket as theirs.
            $mine = $customer !== null && is_string($own = $customer->getAttribute('email')) && mb_strtolower($own) === mb_strtolower($email);

            Tickets::open([
                'subject' => $data['subject'] ?? '',
                'channel' => TicketChannel::Contact,
                'requester' => $mine ? $customer : null,
                'requester_email' => $email,
                'requester_name' => $data['name'] ?? null,
                'tenant' => AlfakitHelpdesk::plugin()->getPublicTenant(request()),
            ], MessageBody::fromText((string) ($data['message'] ?? '')), ['source' => TicketChannel::Contact->value]);
        }

        $this->reset();

        if (request()->hasSession()) {
            request()->session()->flash(self::SUBMITTED_FLASH_KEY, true);
        }

        return back();
    }
}
