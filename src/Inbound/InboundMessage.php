<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Inbound;

/**
 * An email received through the inbound mail webhook.
 */
final class InboundMessage
{
    /**
     * @param  array<int, string>  $to  every recipient address (To and Cc), to find the signed Reply-To address
     * @param  bool  $autoSubmitted  sent by a machine (an out-of-office reply, a bounce): it is filed, but never answered
     */
    public function __construct(
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly string $subject,
        public readonly ?string $text = null,
        public readonly ?string $html = null,
        public readonly ?string $messageId = null,
        public readonly bool $autoSubmitted = false,
    ) {}

    /**
     * Whether the headers (and the sender) mark an email as sent by a machine: RFC 3834's
     * `Auto-Submitted`, the `Precedence` and `X-Auto…` headers mail servers and
     * auto-responders set, or a bounce from the mail system itself.
     *
     * @param  array<array-key, mixed>  $headers
     */
    public static function isAutoSubmitted(array $headers, string $fromEmail): bool
    {
        $headers = array_change_key_case(array_filter($headers, is_string(...)), CASE_LOWER);
        $value = static fn (string $name): string => strtolower(trim($headers[$name] ?? ''));

        if ($value('auto-submitted') !== '' && $value('auto-submitted') !== 'no') {
            return true;
        }

        if (in_array($value('precedence'), ['bulk', 'junk', 'list', 'auto_reply'], true)) {
            return true;
        }

        foreach (['x-autoreply', 'x-autorespond', 'x-auto-response-suppress', 'x-failed-recipients'] as $header) {
            if ($value($header) !== '') {
                return true;
            }
        }

        return preg_match('/^(mailer-daemon|postmaster|no-?reply|bounces?)([+.-][^@]*)?@/i', $fromEmail) === 1;
    }
}
