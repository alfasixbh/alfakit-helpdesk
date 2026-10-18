<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Inbound;

use Illuminate\Http\Request;

/**
 * Reads the documented JSON format:
 *
 * ```json
 * {
 *   "from": {"email": "dana@example.com", "name": "Dana"},
 *   "to": ["support+42.3f9c0b1d2e4a5f60@example.com"],
 *   "subject": "Re: [#42] Printer on fire",
 *   "text": "It works now, thanks!",
 *   "html": "<p>It works now, thanks!</p>",
 *   "message_id": "<CAF=abc@mail.example.com>"
 * }
 * ```
 */
class JsonInboundMailParser implements InboundMailParser
{
    public function parse(Request $request): ?InboundMessage
    {
        $from = $request->input('from');
        $email = is_array($from) ? ($from['email'] ?? null) : $from;
        $name = is_array($from) ? ($from['name'] ?? null) : null;

        if (! is_string($email) || filter_var(trim($email), FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $to = $request->input('to', []);
        $to = is_string($to) ? [$to] : (is_array($to) ? $to : []);
        $max = max(1000, (int) config('alfakit-helpdesk.inbound.max_body', 200000));
        $text = $request->input('text');
        $html = $request->input('html');
        $messageId = $request->input('message_id');
        $subject = $request->input('subject');

        return new InboundMessage(
            fromEmail: trim($email),
            fromName: is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 255) : null,
            to: array_values(array_filter($to, is_string(...))),
            subject: is_string($subject) ? mb_substr(trim($subject), 0, 255) : '',
            text: is_string($text) ? mb_substr($text, 0, $max) : null,
            html: is_string($html) ? mb_substr($html, 0, $max) : null,
            messageId: is_string($messageId) ? mb_substr($messageId, 0, 255) : null,
        );
    }
}
