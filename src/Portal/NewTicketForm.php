<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Portal;

use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Forms\Components\Select;
use AlfaSix\Alfakit\Forms\Components\TextInput;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\Enums\TicketType;
use AlfaSix\AlfakitHelpdesk\Portal\Concerns\InteractsWithPortal;
use AlfaSix\AlfakitHelpdesk\Rules\HasMessageContent;
use AlfaSix\AlfakitHelpdesk\Support\Attachments;
use AlfaSix\AlfakitHelpdesk\Support\SpamGuard;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use Illuminate\Http\RedirectResponse;

/**
 * A signed-in customer opens a ticket from the portal.
 */
class NewTicketForm extends Form
{
    use InteractsWithPortal;

    public static function requiresAuthentication(): bool
    {
        return true;
    }

    public function authorize(): bool
    {
        return $this->customer() !== null;
    }

    public function mount(mixed ...$parameters): void
    {
        $this->heading(__('alfakit-helpdesk::helpdesk.portal.new_heading'));
        $this->subheading(__('alfakit-helpdesk::helpdesk.portal.new_description'));
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('subject')
                ->label(__('alfakit-helpdesk::helpdesk.fields.subject'))
                ->required()
                ->maxLength(255),
            Select::make('type')
                ->label(__('alfakit-helpdesk::helpdesk.fields.type'))
                ->options(TicketType::options())
                ->default(TicketType::Question->value)
                ->selectablePlaceholder(false),
            RichEditor::make('body')
                ->label(__('alfakit-helpdesk::helpdesk.fields.message'))
                ->required()
                ->rule(new HasMessageContent)
                ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList']),
            ...(ReplyForm::attachmentsField() === null ? [] : [ReplyForm::attachmentsField()]),
        ]);
    }

    public function getSubmitLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.portal.submit_ticket');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): mixed
    {
        if (! SpamGuard::attempt('portal', 'new')) {
            $this->failWith(__('alfakit-helpdesk::helpdesk.portal.rate_limited'));
        }

        $paths = $data['attachments'] ?? null;
        $names = $data['attachment_names'] ?? null;

        $ticket = Tickets::open([
            'subject' => $data['subject'] ?? '',
            'type' => $data['type'] ?? null,
            'channel' => TicketChannel::Portal,
            'requester' => $this->customer(),
            'tenant' => AlfakitHelpdesk::plugin()->getPublicTenant(request()),
        ], (string) ($data['body'] ?? ''), [
            'attachments' => Attachments::describe(is_array($paths) || is_string($paths) ? $paths : null, is_array($names) ? $names : null),
            'source' => TicketChannel::Portal->value,
        ]);

        $this->reset();

        Notification::make()->success()->title(__('alfakit-helpdesk::helpdesk.portal.created', ['reference' => $ticket->getReference()]))->send();

        $url = AlfakitHelpdesk::route('portal.show', ['ticket' => $ticket->getKey()]);

        return $url === null ? back() : new RedirectResponse($url);
    }
}
