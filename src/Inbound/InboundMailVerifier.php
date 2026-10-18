<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Inbound;

use Illuminate\Http\Request;

/**
 * Proves an inbound mail webhook request comes from your email provider. Bind your own
 * (most providers sign their webhooks their own way):
 *
 * ```php
 * $this->app->bind(InboundMailVerifier::class, PostmarkVerifier::class);
 * ```
 *
 * Requests that fail are answered with 403 and nothing is read.
 */
interface InboundMailVerifier
{
    public function verify(Request $request): bool;
}
