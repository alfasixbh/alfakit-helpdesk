<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Portal;

use AlfaSix\Alfakit\Forms\Components\FileUpload;
use AlfaSix\Alfakit\Forms\Components\RichEditor;
use AlfaSix\Alfakit\Notifications\Notification;
use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Form;
use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Portal\Concerns\InteractsWithPortal;
use AlfaSix\AlfakitHelpdesk\Rules\HasMessageContent;
use AlfaSix\AlfakitHelpdesk\Support\Attachments;
use AlfaSix\AlfakitHelpdesk\Support\SpamGuard;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;

/**
 * The requester answers their ticket (reopening it when it was solved).
 */
class ReplyForm extends Form
{
    use InteractsWithPortal;

    public ?Ticket $ticket = null;

    public bool $guest = false;

    public function authorize(): bool
    {
        return $this->mayView($this->ticket, $this->guest);
    }

    public function mount(mixed ...$parameters): void
    {
        $this->heading(__('alfakit-helpdesk::helpdesk.portal.reply_heading'));
    }

    public function form(Schema $schema): Schema
    {
        if ($this->ticket === null || ! $this->ticket->acceptsReplies()) {
            return $schema;
        }

        return $schema->components([
            RichEditor::make('body')
                ->label(__('alfakit-helpdesk::helpdesk.fields.message'))
                ->required()
                ->rule(new HasMessageContent)
                ->toolbarButtons(['bold', 'italic', 'link', 'bulletList', 'orderedList']),
            ...(self::attachmentsField() === null ? [] : [self::attachmentsField()]),
        ]);
    }

    public function content(Schema $schema): Schema
    {
        if ($this->ticket === null || $this->ticket->acceptsReplies()) {
            return $schema;
        }

        return $schema->components([
            Callout::make(__('alfakit-helpdesk::helpdesk.portal.closed_heading'))
                ->description(__('alfakit-helpdesk::helpdesk.portal.closed_description'))
                ->color('gray')
                ->icon('archive'),
        ]);
    }

    protected function getFormActions(): array
    {
        return $this->ticket !== null && $this->ticket->acceptsReplies() ? parent::getFormActions() : [];
    }

    public function getSubmitLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.actions.send');
    }

    public static function attachmentsField(): ?FileUpload
    {
        if (Attachments::maxFiles() === 0) {
            return null;
        }

        return FileUpload::make('attachments')
            ->label(__('alfakit-helpdesk::helpdesk.fields.attachments'))
            ->multiple()
            ->disk(Attachments::disk())
            ->directory(Attachments::directory().'/'.now()->format('Y/m'))
            ->visibility('private')
            ->maxFiles(Attachments::maxFiles())
            ->maxSize(Attachments::maxSize())
            ->acceptedFileTypes(Attachments::acceptedFileTypes())
            ->storeFileNamesIn('attachment_names');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function submit(array $data): mixed
    {
        $ticket = $this->ticket;

        abort_if($ticket === null, 404);

        if (! SpamGuard::attempt('portal', 'reply:'.$ticket->getKey())) {
            $this->failWith(__('alfakit-helpdesk::helpdesk.portal.rate_limited'));
        }

        $paths = $data['attachments'] ?? null;
        $names = $data['attachment_names'] ?? null;

        Tickets::reply(
            $ticket,
            (string) ($data['body'] ?? ''),
            author: $this->customer(),
            fromRequester: true,
            attachments: Attachments::describe(is_array($paths) || is_string($paths) ? $paths : null, is_array($names) ? $names : null),
            source: $this->guest ? TicketChannel::Email->value : TicketChannel::Portal->value,
        );

        $this->reset();

        Notification::make()->success()->title(__('alfakit-helpdesk::helpdesk.portal.replied'))->send();

        return back();
    }
}
