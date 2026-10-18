<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\AlfakitHelpdesk;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Files of messages: stored on a private disk, described in the message
 * (`[{path, name, size, mime}]`) and downloaded through short-lived signed URLs that are
 * only generated for people who may see the message.
 */
final class Attachments
{
    public static function disk(): string
    {
        return (string) config('alfakit-helpdesk.attachments.disk', 'local');
    }

    public static function directory(): string
    {
        return trim((string) config('alfakit-helpdesk.attachments.directory', 'helpdesk'), '/');
    }

    public static function maxSize(): int
    {
        return max(1, (int) config('alfakit-helpdesk.attachments.max_size', 10240));
    }

    public static function maxFiles(): int
    {
        return max(0, (int) config('alfakit-helpdesk.attachments.max_files', 5));
    }

    /**
     * @return array<int, string>
     */
    public static function acceptedFileTypes(): array
    {
        $types = config('alfakit-helpdesk.attachments.accepted_file_types', []);

        return is_array($types) ? array_values(array_filter($types, is_string(...))) : [];
    }

    /**
     * Describe stored files (paths from a `FileUpload`, original names from
     * `storeFileNamesIn()`). Paths outside the helpdesk directory are dropped.
     *
     * @param  array<mixed>|string|null  $paths
     * @param  array<mixed>|null  $names
     * @return array<int, array{path: string, name: string, size: int|null, mime: string|null}>
     */
    public static function describe(array|string|null $paths, ?array $names = null): array
    {
        $paths = is_string($paths) ? [$paths] : ($paths ?? []);
        $disk = Storage::disk(self::disk());
        $prefix = self::directory().'/';
        $described = [];

        foreach ($paths as $key => $path) {
            if (! is_string($path) || ! str_starts_with($path, $prefix) || str_contains($path, '..')) {
                continue;
            }

            $name = $names[$path] ?? $names[$key] ?? null;

            try {
                $size = $disk->exists($path) ? $disk->size($path) : null;
                $mime = $disk->exists($path) ? $disk->mimeType($path) : null;
            } catch (Throwable) {
                $size = null;
                $mime = null;
            }

            $described[] = [
                'path' => $path,
                'name' => is_string($name) && $name !== '' ? mb_substr(basename($name), 0, 200) : basename($path),
                'size' => is_int($size) ? $size : null,
                'mime' => is_string($mime) ? $mime : null,
            ];
        }

        return $described;
    }

    /**
     * The signed download URL of a message's attachment.
     */
    public static function url(TicketMessage $message, int $index): ?string
    {
        $name = AlfakitHelpdesk::routeName('attachments');

        if (! Route::has($name)) {
            return null;
        }

        return URL::temporarySignedRoute($name, now()->addMinutes(max(1, (int) config('alfakit-helpdesk.attachments.url_minutes', 30))), [
            'message' => $message->getKey(),
            'index' => $index,
        ]);
    }

    /**
     * The attachments of a message with their download URLs.
     *
     * @return array<int, array{name: string, size: int|null, mime: string|null, url: string|null}>
     */
    public static function forDisplay(TicketMessage $message): array
    {
        $files = [];

        foreach ($message->getAttachments() as $index => $attachment) {
            $files[] = [
                'name' => $attachment['name'],
                'size' => $attachment['size'],
                'mime' => $attachment['mime'],
                'url' => self::url($message, $index),
            ];
        }

        return $files;
    }
}
