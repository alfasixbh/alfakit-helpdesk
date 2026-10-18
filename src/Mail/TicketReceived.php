<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Mail;

use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\AlfakitHelpdesk\Support\Links;

/**
 * To the requester: we received your request.
 */
class TicketReceived extends HelpdeskMail
{
    protected function markdownView(): string
    {
        return 'ticket-received';
    }

    protected function data(): array
    {
        return ['url' => Links::forRequester($this->ticket)];
    }

    protected function templateExtraData(): array
    {
        $url = Links::forRequester($this->ticket);

        return [
            'url' => (string) $url,
            'button' => $url === null ? '' : EditableTemplates::button($url, (string) __('alfakit-helpdesk::helpdesk.mail.view_ticket')),
        ];
    }
}
