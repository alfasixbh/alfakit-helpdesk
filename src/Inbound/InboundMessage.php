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
     */
    public function __construct(
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly string $subject,
        public readonly ?string $text = null,
        public readonly ?string $html = null,
        public readonly ?string $messageId = null,
    ) {}
}
