<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Inbound;

use Illuminate\Http\Request;

/**
 * The default verifier: `X-Helpdesk-Signature: sha256=<hex HMAC-SHA256 of the raw body>`
 * with `inbound.secret`. Without a secret every request is refused. Replace it with your
 * provider's own scheme (signing key, timestamp and token) when it has one.
 */
class HmacSignatureVerifier implements InboundMailVerifier
{
    public const HEADER = 'X-Helpdesk-Signature';

    public function verify(Request $request): bool
    {
        $secret = config('alfakit-helpdesk.inbound.secret');
        $header = $request->header(self::HEADER);

        if (! is_string($secret) || $secret === '' || ! is_string($header) || ! str_starts_with($header, 'sha256=')) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', (string) $request->getContent(), $secret), substr($header, 7));
    }
}
