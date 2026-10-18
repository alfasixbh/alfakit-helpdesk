<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\Alfakit\Support\Html;
use Illuminate\Support\Str;

/**
 * Message bodies are stored as sanitised HTML: rich editor content goes through
 * `Html::sanitize()` (no scripts, event handlers or unsafe URLs), plain text (chat,
 * emails) is escaped into paragraphs.
 */
final class MessageBody
{
    public static function sanitize(?string $html): string
    {
        return trim(Html::sanitize((string) $html));
    }

    /**
     * Plain text as escaped paragraphs (`<p>`, line breaks as `<br>`). Nothing in the text
     * is ever interpreted as markup.
     */
    public static function fromText(?string $text): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", trim((string) $text));

        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\n{2,}/', $text) ?: [$text];

        return implode('', array_map(static fn (string $paragraph): string => '<p>'.nl2br(e(trim($paragraph)), false).'</p>', $paragraphs));
    }

    public static function isBlank(?string $html): bool
    {
        if ($html === null) {
            return true;
        }

        return self::toText($html) === '' && ! str_contains($html, '<img');
    }

    /**
     * The body as one line of plain text.
     */
    public static function toText(string $html): string
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('/<\/(p|div|li|h[1-6]|blockquote|pre)>|<br\s*\/?>/i', ' ', $html)), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace('/[\s\x{00A0}]+/u', ' ', $text));
    }

    /**
     * The body as plain text with line breaks (emails).
     */
    public static function toPlainText(string $html): string
    {
        $text = (string) preg_replace('/<br\s*\/?>/i', "\n", $html);
        $text = (string) preg_replace('/<\/(p|div|li|h[1-6]|blockquote|pre)>/i', "\n\n", $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5);

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    public static function excerpt(string $html, int $limit = 140): string
    {
        return Str::limit(self::toText($html), $limit);
    }
}
