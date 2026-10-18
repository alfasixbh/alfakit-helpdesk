<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Mail;

use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use AlfaSix\AlfakitHelpdesk\Support\Links;
use AlfaSix\AlfakitHelpdesk\Support\MessageBody;

/**
 * To the requester: an agent answered (public replies only, never notes).
 */
class AgentReplied extends HelpdeskMail
{
    public function __construct(Ticket $ticket, public TicketMessage $message)
    {
        parent::__construct($ticket);
    }

    protected function markdownView(): string
    {
        return 'agent-replied';
    }

    protected function data(): array
    {
        return [
            'author' => $this->message->getAuthorName(),
            'body' => MessageBody::toPlainText($this->message->body),
            'url' => Links::forRequester($this->ticket),
        ];
    }

    protected function templateExtraData(): array
    {
        $url = Links::forRequester($this->ticket);

        return [
            'author' => $this->message->getAuthorName(),
            'message' => MessageBody::toPlainText($this->message->body),
            'url' => (string) $url,
            'button' => $url === null ? '' : EditableTemplates::button($url, (string) __('alfakit-helpdesk::helpdesk.mail.view_ticket')),
        ];
    }
}
