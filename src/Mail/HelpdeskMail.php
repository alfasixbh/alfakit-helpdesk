<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Mail;

use AlfaSix\Alfakit\Mail\Concerns\UsesAlfakitMailTheme;
use AlfaSix\Alfakit\Mail\Concerns\UsesEditableTemplate;
use AlfaSix\Alfakit\Mail\MailTheme;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Support\ReplyAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

/**
 * The helpdesk's queued emails: `[#42] Subject`, with the ticket's signed Reply-To
 * address when reply by email is on, in the AlfaKit mail theme. Admins can edit their
 * subject (after the `[#42]` reference) and body (`helpdesk.*` templates).
 */
abstract class HelpdeskMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;
    use UsesAlfakitMailTheme;
    use UsesEditableTemplate;

    public function __construct(public Ticket $ticket)
    {
        $this->onConnection(is_string($connection = config('alfakit-helpdesk.mail.connection')) ? $connection : null);
        $this->onQueue(is_string($queue = config('alfakit-helpdesk.mail.queue')) ? $queue : null);
        $this->afterCommit();
    }

    abstract protected function markdownView(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function data(): array;

    public function envelope(): Envelope
    {
        $replyTo = ReplyAddress::for($this->ticket);

        return new Envelope(
            subject: '['.$this->ticket->getReference().'] '.$this->templateSubject((string) $this->ticket->subject),
            replyTo: $replyTo === null ? [] : [new Address($replyTo)],
        );
    }

    public function content(): Content
    {
        $poweredBy = AlfakitHelpdesk::plugin()->getPoweredByEmailLine();

        return $this->templateContent(new Content(markdown: 'alfakit-helpdesk::mail.'.$this->markdownView(), with: [
            'ticket' => $this->ticket,
            'poweredBy' => $poweredBy,
            ...$this->data(),
        ]), $poweredBy);
    }

    public function templateKey(): string
    {
        return 'helpdesk.'.$this->markdownView();
    }

    /**
     * Merge tags: the ticket's reference, subject and requester, plus the mail's own.
     *
     * @return array<string, mixed>
     */
    public function templateData(): array
    {
        return [
            'reference' => $this->ticket->getReference(),
            'subject' => (string) $this->ticket->subject,
            'name' => $this->ticket->getRequesterName(),
            'app' => MailTheme::brand()->name,
            ...$this->templateExtraData(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function templateExtraData(): array
    {
        return [];
    }

    public function headers(): Headers
    {
        $domain = parse_url((string) config('app.url'), PHP_URL_HOST);
        $domain = is_string($domain) && $domain !== '' ? $domain : 'localhost';

        return new Headers(references: ['ticket-'.$this->ticket->getKey().'@'.$domain]);
    }
}
