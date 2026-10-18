<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Inbound;

use Illuminate\Http\Request;

/**
 * Turns a verified webhook request into an `InboundMessage`. AlfaKit ships a parser for a
 * plain JSON format (`JsonInboundMailParser`); adapt your provider's payload in your own:
 *
 * ```php
 * $this->app->bind(InboundMailParser::class, MailgunParser::class);
 * ```
 *
 * Return null for requests that aren't an email (answered with 422).
 */
interface InboundMailParser
{
    public function parse(Request $request): ?InboundMessage;
}
