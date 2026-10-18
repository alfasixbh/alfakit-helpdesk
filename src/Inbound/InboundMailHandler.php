<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Inbound;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use AlfaSix\AlfakitHelpdesk\Support\MessageBody;
use AlfaSix\AlfakitHelpdesk\Support\ReplyAddress;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;

/**
 * Files an inbound email: a reply to a ticket when it was sent to the ticket's signed
 * Reply-To address by its requester, else a new ticket. Only the requester can add to a
 * ticket by email; replies to closed tickets open a follow-up.
 */
class InboundMailHandler
{
    /**
     * @return array{ticket: int|string, created: bool, duplicate: bool}
     */
    public function handle(InboundMessage $message): array
    {
        if ($message->messageId !== null) {
            $model = AlfakitHelpdesk::messageModel();
            $existing = $model::query()->where('message_id', $message->messageId)->first();

            if ($existing instanceof TicketMessage) {
                return ['ticket' => $existing->ticket_id, 'created' => false, 'duplicate' => true];
            }
        }

        $body = $this->body($message);
        $ticket = $this->findTicket($message);

        if ($ticket !== null && $ticket->acceptsReplies()) {
            $reply = Tickets::reply($ticket, $body, null, fromRequester: true, source: TicketChannel::Email->value);
            $reply->forceFill(['message_id' => $message->messageId, 'author_name' => $message->fromName ?? $ticket->requester_name, 'author_email' => $message->fromEmail])->save();

            return ['ticket' => $ticket->getKey(), 'created' => false, 'duplicate' => false];
        }

        $created = Tickets::open([
            'subject' => $this->subject($message->subject, $ticket),
            'channel' => TicketChannel::Email,
            'requester' => $ticket?->requester_id !== null ? $ticket->requester : null,
            'requester_email' => $message->fromEmail,
            'requester_name' => $message->fromName,
            'tenant' => $ticket->tenant ?? AlfakitHelpdesk::plugin()->getPublicTenant(request()),
        ], $body, ['source' => TicketChannel::Email->value]);

        $created->messages()->first()?->forceFill(['message_id' => $message->messageId])->save();

        return ['ticket' => $created->getKey(), 'created' => true, 'duplicate' => false];
    }

    /**
     * The ticket of a signed recipient address, when the sender is its requester.
     */
    protected function findTicket(InboundMessage $message): ?Ticket
    {
        foreach ($message->to as $address) {
            $ticket = ReplyAddress::resolve($address);

            if ($ticket === null) {
                continue;
            }

            $email = $ticket->getRequesterEmail();

            return $email !== null && mb_strtolower($email) === mb_strtolower($message->fromEmail) ? $ticket : null;
        }

        return null;
    }

    protected function body(InboundMessage $message): string
    {
        if ($message->text !== null && trim($message->text) !== '') {
            return MessageBody::fromText(self::stripQuotedText($message->text));
        }

        $html = MessageBody::sanitize($message->html);

        return MessageBody::isBlank($html) ? MessageBody::fromText('—') : $html;
    }

    protected function subject(string $subject, ?Ticket $previous): string
    {
        $subject = trim((string) preg_replace('/^((re|fwd?|aw|sv|tr)\s*:\s*)+/i', '', $subject));
        $subject = trim((string) preg_replace('/^\[#\d+\]\s*/', '', $subject));

        return $subject !== '' ? $subject : ($previous->subject ?? (string) __('alfakit-helpdesk::helpdesk.ticket.no_subject'));
    }

    /**
     * The new part of a reply: everything before "On … wrote:" and the quoted lines.
     */
    public static function stripQuotedText(string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $lines = explode("\n", $text);
        $kept = [];

        foreach ($lines as $line) {
            if (preg_match('/^\s*(On\s.+wrote:|-{2,}\s*Original Message\s*-{2,}|From:\s.+@.+)$/i', $line) === 1) {
                break;
            }

            if (str_starts_with(ltrim($line), '>')) {
                continue;
            }

            $kept[] = $line;
        }

        $result = trim(implode("\n", $kept));

        return $result === '' ? trim($text) : $result;
    }
}
