<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Resources\CannedReplyResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\AlfakitHelpdesk\Actions\TicketActions;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Resources\CannedReplyResource;

class CreateCannedReply extends CreateRecord
{
    protected static string $resource = CannedReplyResource::class;

    public static function getResource(): string
    {
        return HelpdeskPlugin::get()->getCannedReplyResource();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['owner_id'] = TicketActions::agent()?->getKey();

        return $data;
    }
}
