<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Http\Controllers;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Enums\TicketChannel;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Realtime\HelpdeskBroadcaster;
use AlfaSix\AlfakitHelpdesk\Support\AccessToken;
use AlfaSix\AlfakitHelpdesk\Support\MessageBody;
use AlfaSix\AlfakitHelpdesk\Support\SpamGuard;
use AlfaSix\AlfakitHelpdesk\Support\Tickets;
use AlfaSix\AlfakitHelpdesk\Support\Typing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Validation\ValidationException;

/**
 * The live chat widget's endpoints. A visitor's chat is a ticket on the `chat` channel;
 * guests are recognised by a signed, HTTP-only cookie (`AccessToken`), signed-in
 * customers by their account. Visitors only ever receive public replies, and agents'
 * typing state; message bodies are never broadcast.
 */
class ChatController
{
    /** GET: the widget's settings and the visitor's current chat. */
    public function show(Request $request): JsonResponse
    {
        $ticket = $this->current($request);

        return new JsonResponse([
            'config' => $this->config(),
            'conversation' => $ticket === null ? null : $this->conversation($ticket),
        ]);
    }

    /** POST: start a chat with a first message. */
    public function start(Request $request): JsonResponse
    {
        $data = $this->validate($request, [
            'message' => ['required', 'string', 'max:'.$this->maxLength()],
            'name' => ['nullable', 'string', 'max:255'],
            'email' => [config('alfakit-helpdesk.chat.require_email', false) && AlfakitHelpdesk::customer() === null ? 'required' : 'nullable', 'email', 'max:255'],
        ]);

        if (! SpamGuard::attempt('chat_start')) {
            return $this->tooManyAttempts('chat_start');
        }

        $text = trim((string) $data['message']);

        // Bots get a chat that looks started, and nothing is stored.
        if (SpamGuard::isSpam($request->input(SpamGuard::honeypotField()), null)) {
            return new JsonResponse(['conversation' => [
                'key' => null,
                'status' => TicketStatus::Open->value,
                'messages' => [['key' => '0', 'from' => 'visitor', 'name' => (string) __('alfakit-helpdesk::helpdesk.portal.you'), 'html' => MessageBody::fromText($text), 'createdAt' => now()->toAtomString()]],
                'agentTyping' => null,
            ]], 201);
        }

        // One chat at a time: a visitor with an open chat keeps writing in it.
        $existing = $this->current($request);

        if ($existing !== null && $existing->getStatus()->isActive()) {
            Tickets::reply($existing, MessageBody::fromText($text), AlfakitHelpdesk::customer(), fromRequester: true, source: TicketChannel::Chat->value);

            return new JsonResponse(['conversation' => $this->conversation($existing->refresh())]);
        }

        $customer = AlfakitHelpdesk::customer();
        $name = is_string($data['name'] ?? null) && trim($data['name']) !== '' ? trim($data['name']) : null;
        $email = is_string($data['email'] ?? null) && trim($data['email']) !== '' ? trim($data['email']) : null;

        $ticket = Tickets::open([
            'subject' => mb_substr(MessageBody::toText(MessageBody::fromText($text)), 0, 80),
            'channel' => TicketChannel::Chat,
            'requester' => $customer,
            'requester_email' => $customer === null ? $email : null,
            'requester_name' => $customer === null ? $name : null,
            'tenant' => AlfakitHelpdesk::plugin()->getPublicTenant($request),
        ], MessageBody::fromText($text), ['source' => TicketChannel::Chat->value]);

        $response = new JsonResponse(['conversation' => $this->conversation($ticket)], 201);

        if ($customer === null) {
            $response->withCookie($this->cookie(AccessToken::issue($ticket)));
        }

        return $response;
    }

    /** POST: the visitor writes in their chat. */
    public function message(Request $request): JsonResponse
    {
        $ticket = $this->current($request);

        abort_if($ticket === null, 404);

        $data = $this->validate($request, ['message' => ['required', 'string', 'max:'.$this->maxLength()]]);

        if (! SpamGuard::attempt('chat_message', (string) $ticket->getKey())) {
            return $this->tooManyAttempts('chat_message', (string) $ticket->getKey());
        }

        if (! $ticket->acceptsReplies()) {
            throw ValidationException::withMessages(['message' => __('alfakit-helpdesk::helpdesk.errors.closed')]);
        }

        Tickets::reply($ticket, MessageBody::fromText((string) $data['message']), AlfakitHelpdesk::customer(), fromRequester: true, source: TicketChannel::Chat->value);

        return new JsonResponse(['conversation' => $this->conversation($ticket->refresh())]);
    }

    /** POST: the visitor is typing (agents see it live). */
    public function typing(Request $request): JsonResponse
    {
        $ticket = $this->current($request);

        abort_if($ticket === null, 404);

        if (SpamGuard::attempt('typing', (string) $ticket->getKey())) {
            Typing::requester($ticket);
            HelpdeskBroadcaster::dispatch($ticket, HelpdeskBroadcaster::TYPING, 'requester');
        }

        return new JsonResponse(['ok' => true]);
    }

    /** DELETE: forget the chat on this device (the ticket stays). */
    public function leave(Request $request): JsonResponse
    {
        return (new JsonResponse(['ok' => true]))->withCookie(Cookie::forget($this->cookieName()));
    }

    // ---------------------------------------------------------------- Internals

    /**
     * The visitor's chat: the cookie's ticket for guests; for signed-in customers their
     * latest chat ticket.
     */
    protected function current(Request $request): ?Ticket
    {
        abort_unless((bool) config('alfakit-helpdesk.chat.enabled', true), 404);

        $customer = AlfakitHelpdesk::customer();

        if ($customer !== null) {
            $model = AlfakitHelpdesk::ticketModel();
            $ticket = $model::query()
                ->where('channel', TicketChannel::Chat->value)
                ->whereNull('merged_into_id')
                ->requestedBy($customer)
                ->latest('last_activity_at')
                ->first();

            return $ticket instanceof Ticket ? $ticket : null;
        }

        $token = $request->cookie($this->cookieName());
        $ticket = AccessToken::resolve(is_string($token) ? $token : null);

        if ($ticket?->isMerged() && $ticket->mergedInto !== null) {
            return $ticket->mergedInto;
        }

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    protected function conversation(Ticket $ticket): array
    {
        $messages = [];

        // Public replies only: internal notes and events never reach the visitor.
        foreach ($ticket->publicMessages()->with('author')->limit(500)->get() as $message) {
            $messages[] = [
                'key' => (string) $message->getKey(),
                'from' => $message->from_requester ? 'visitor' : 'agent',
                'name' => $message->from_requester ? (string) __('alfakit-helpdesk::helpdesk.portal.you') : $message->getAuthorName(),
                'html' => $message->body,
                'createdAt' => $message->created_at?->toAtomString(),
            ];
        }

        return [
            'key' => (string) $ticket->getKey(),
            'status' => $ticket->getStatus()->value,
            'closed' => ! $ticket->acceptsReplies() ?: null,
            'messages' => $messages,
            'agentTyping' => Typing::agentTyping($ticket),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function config(): array
    {
        $plugin = AlfakitHelpdesk::plugin();

        return [
            'enabled' => true,
            'greeting' => $plugin->getChatGreeting(),
            'pollingInterval' => max(2, (int) config('alfakit-helpdesk.chat.polling_interval', 4)),
            'requireEmail' => (bool) config('alfakit-helpdesk.chat.require_email', false) && AlfakitHelpdesk::customer() === null,
            'askContact' => AlfakitHelpdesk::customer() === null,
            'maxLength' => $this->maxLength(),
            'honeypot' => SpamGuard::honeypotField(),
            'contactUrl' => config('alfakit-helpdesk.contact.enabled', true) ? AlfakitHelpdesk::route('contact') : null,
            'strings' => __('alfakit-helpdesk::helpdesk.chat'),
            'poweredBy' => $plugin->getPoweredByHtml(),
        ];
    }

    protected function maxLength(): int
    {
        return max(100, min(20000, (int) config('alfakit-helpdesk.chat.max_length', 4000)));
    }

    protected function cookieName(): string
    {
        return (string) config('alfakit-helpdesk.chat.cookie', 'alfakit_helpdesk_chat');
    }

    protected function cookie(string $token): \Symfony\Component\HttpFoundation\Cookie
    {
        return Cookie::make($this->cookieName(), $token, max(1, (int) config('alfakit-helpdesk.chat.cookie_minutes', 43200)), '/', null, null, true, false, 'lax');
    }

    protected function tooManyAttempts(string $limit, string $key = ''): JsonResponse
    {
        $seconds = SpamGuard::availableIn($limit, $key);

        return new JsonResponse([
            'message' => __('alfakit-helpdesk::helpdesk.portal.rate_limited'),
            'retryAfter' => $seconds,
        ], 429, ['Retry-After' => (string) $seconds]);
    }

    /**
     * @param  array<string, array<int, string>>  $rules
     * @return array<string, mixed>
     */
    protected function validate(Request $request, array $rules): array
    {
        return validator($request->only(array_keys($rules)), $rules)->validate();
    }
}
