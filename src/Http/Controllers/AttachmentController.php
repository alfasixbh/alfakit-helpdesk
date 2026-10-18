<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Http\Controllers;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use AlfaSix\AlfakitHelpdesk\Support\Attachments;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /{prefix}/attachments/{message}/{index} (signed, short-lived): a file of a message.
 * Signed URLs are only generated for people who may see the message.
 */
class AttachmentController
{
    public function __invoke(string $message, string $index): StreamedResponse
    {
        $model = AlfakitHelpdesk::messageModel();
        $record = ctype_digit($message) ? $model::query()->whereKey((int) $message)->first() : null;

        abort_unless($record instanceof TicketMessage && ctype_digit($index), 404);

        $attachment = $record->getAttachments()[(int) $index] ?? null;
        $disk = Storage::disk(Attachments::disk());

        abort_if($attachment === null || ! $disk->exists($attachment['path']), 404);

        return $disk->download($attachment['path'], $attachment['name'], [
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
