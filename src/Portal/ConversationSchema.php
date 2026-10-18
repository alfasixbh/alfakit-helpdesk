<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Portal;

use AlfaSix\Alfakit\Infolists\Components\RepeatableEntry;
use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Models\TicketMessage;
use AlfaSix\AlfakitHelpdesk\Support\Attachments;

/**
 * The conversation as requesters see it: public replies only. Internal notes and events
 * are never loaded, so nothing about them can reach the page.
 */
final class ConversationSchema
{
    public static function make(Ticket $ticket): RepeatableEntry
    {
        return RepeatableEntry::make('conversation')
            ->hiddenLabel()
            ->state(static fn (): mixed => $ticket->publicMessages()->with('author')->get())
            ->columns(['default' => 1, 'sm' => 2])
            ->schema([
                TextEntry::make('author_label')
                    ->hiddenLabel()
                    ->state(static fn (mixed $record): string => ! $record instanceof TicketMessage ? '' : ($record->from_requester ? (string) __('alfakit-helpdesk::helpdesk.portal.you') : $record->getAuthorName()))
                    ->weight('semibold')
                    ->icon(static fn (mixed $record): string => $record instanceof TicketMessage && $record->from_requester ? 'user-round' : 'headset'),
                TextEntry::make('created_at')
                    ->hiddenLabel()
                    ->since()
                    ->dateTimeTooltip()
                    ->color('gray')
                    ->alignEnd(),
                TextEntry::make('body')
                    ->hiddenLabel()
                    ->html()
                    ->prose()
                    ->columnSpanFull(),
                TextEntry::make('files')
                    ->hiddenLabel()
                    ->state(static fn (mixed $record): ?string => $record instanceof TicketMessage ? self::attachmentLinks($record) : null)
                    ->html()
                    ->columnSpanFull()
                    ->visible(static fn (mixed $record): bool => $record instanceof TicketMessage && $record->getAttachments() !== []),
            ]);
    }

    private static function attachmentLinks(TicketMessage $message): ?string
    {
        $links = [];

        foreach (Attachments::forDisplay($message) as $file) {
            $links[] = $file['url'] === null
                ? '<li>'.e($file['name']).'</li>'
                : '<li><a href="'.e($file['url']).'" target="_blank" rel="noopener">'.e($file['name']).'</a></li>';
        }

        return $links === [] ? null : '<ul>'.implode('', $links).'</ul>';
    }
}
