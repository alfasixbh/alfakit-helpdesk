<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Mail;

use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use AlfaSix\AlfakitHelpdesk\Support\Links;
use AlfaSix\AlfakitHelpdesk\Support\MessageBody;

/**
 * To agents: a new ticket, or the requester replied.
 */
class AgentNotification extends HelpdeskMail
{
    public function __construct(Ticket $ticket, public ?TicketMessage $message = null, public bool $isNew = false)
    {
        parent::__construct($ticket);
    }

    protected function markdownView(): string
    {
        return 'agent-notification';
    }

    protected function data(): array
    {
        return [
            'isNew' => $this->isNew,
            'requester' => $this->ticket->getRequesterName(),
            'body' => $this->message === null ? null : MessageBody::toPlainText($this->message->body),
            'url' => Links::forAgent($this->ticket),
        ];
    }

    protected function templateExtraData(): array
    {
        $url = Links::forAgent($this->ticket);

        return [
            'message' => $this->message === null ? '' : MessageBody::toPlainText($this->message->body),
            'url' => (string) $url,
            'button' => $url === null ? '' : EditableTemplates::button($url, (string) __('alfakit-helpdesk::helpdesk.mail.open_ticket')),
        ];
    }
}
