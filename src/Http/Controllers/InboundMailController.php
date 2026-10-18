<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Http\Controllers;

use AlfaSix\AlfakitHelpdesk\Inbound\InboundMailHandler;
use AlfaSix\AlfakitHelpdesk\Inbound\InboundMailParser;
use AlfaSix\AlfakitHelpdesk\Inbound\InboundMailVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /{prefix}/inbound/mail: the webhook your email provider calls for each email sent
 * to the helpdesk (`inbound.enabled`). Verified, parsed, then filed as a reply or a new
 * ticket.
 */
class InboundMailController
{
    public function __invoke(Request $request, InboundMailVerifier $verifier, InboundMailParser $parser, InboundMailHandler $handler): JsonResponse
    {
        abort_unless($verifier->verify($request), 403);

        $message = $parser->parse($request);

        abort_if($message === null, 422);

        return new JsonResponse($handler->handle($message), 202);
    }
}
