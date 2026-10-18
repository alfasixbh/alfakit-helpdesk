<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Mail;

use AlfaSix\Alfakit\Mail\EditableTemplates;
use AlfaSix\AlfakitHelpdesk\Support\Links;

/**
 * To the requester: the ticket is solved, with the satisfaction survey link.
 */
class TicketSolvedMail extends HelpdeskMail
{
    protected function markdownView(): string
    {
        return 'ticket-solved';
    }

    protected function data(): array
    {
        return [
            'url' => Links::forRequester($this->ticket),
            'rateUrl' => $this->ticket->satisfaction_rated_at === null ? Links::rate($this->ticket) : null,
        ];
    }

    protected function templateExtraData(): array
    {
        $url = Links::forRequester($this->ticket);
        $rateUrl = $this->ticket->satisfaction_rated_at === null ? Links::rate($this->ticket) : null;

        return [
            'url' => (string) $url,
            'button' => $url === null ? '' : EditableTemplates::button($url, (string) __('alfakit-helpdesk::helpdesk.mail.view_ticket')),
            'rate_button' => $rateUrl === null ? '' : EditableTemplates::button($rateUrl, (string) __('alfakit-helpdesk::helpdesk.mail.solved.rate')),
        ];
    }
}
