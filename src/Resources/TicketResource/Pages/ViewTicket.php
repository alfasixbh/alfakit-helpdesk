<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Resources\TicketResource\Pages;

use AlfaSix\Alfakit\Actions\ActionGroup;
use AlfaSix\Alfakit\Resources\Pages\ViewRecord;
use AlfaSix\AlfakitHelpdesk\Actions\TicketActions;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Resources\TicketResource;

/**
 * The ticket with its conversation (replies, internal notes, events) and its actions.
 */
class ViewTicket extends ViewRecord
{
    protected static string $resource = TicketResource::class;

    public static function getResource(): string
    {
        return HelpdeskPlugin::get()->getTicketResource();
    }

    public function getTitle(): string
    {
        $record = $this->getRecord();

        return $record instanceof Ticket ? $record->getReference().' '.$record->subject : parent::getTitle();
    }

    protected function getHeaderActions(): array
    {
        $actions = TicketActions::all();

        return [
            $actions['reply'],
            $actions['note'],
            $actions['solve'],
            ActionGroup::make([
                $actions['take'],
                $actions['assign'],
                $actions['status'],
                $actions['details'],
                $actions['merge'],
            ])->label(__('alfakit-helpdesk::helpdesk.actions.more'))->icon('ellipsis')->color('gray'),
        ];
    }
}
