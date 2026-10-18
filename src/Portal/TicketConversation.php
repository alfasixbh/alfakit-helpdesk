<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Portal;

use AlfaSix\Alfakit\Infolists\Components\TextEntry;
use AlfaSix\Alfakit\Schemas\Components\Grid;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\Alfakit\Standalone\Infolist;
use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Portal\Concerns\InteractsWithPortal;

/**
 * A requester's ticket on the portal: its status and the public conversation.
 */
class TicketConversation extends Infolist
{
    use InteractsWithPortal;

    public ?Ticket $ticket = null;

    /** Opened from a signed link (no account needed). */
    public bool $guest = false;

    public function authorize(): bool
    {
        return $this->mayView($this->ticket, $this->guest);
    }

    public function mount(mixed ...$parameters): void
    {
        if ($this->ticket !== null) {
            $this->heading($this->ticket->getReference().' '.$this->ticket->subject);
        }
    }

    public function infolist(Schema $schema): Schema
    {
        $ticket = $this->ticket;

        if ($ticket === null) {
            return $schema;
        }

        return $schema->record($ticket)->components([
            Grid::make(['default' => 2, 'sm' => 3])->schema([
                TextEntry::make('status')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.status'))
                    ->state(static fn (): TicketStatus => $ticket->getStatus())
                    ->badge(),
                TextEntry::make('created_at')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.created_at'))
                    ->dateTime(),
                TextEntry::make('last_activity_at')
                    ->label(__('alfakit-helpdesk::helpdesk.fields.last_activity'))
                    ->since(),
            ]),
            ConversationSchema::make($ticket),
        ]);
    }
}
