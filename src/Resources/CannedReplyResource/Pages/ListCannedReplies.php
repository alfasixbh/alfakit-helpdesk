<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Resources\CannedReplyResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Resources\CannedReplyResource;

class ListCannedReplies extends ListRecords
{
    protected static string $resource = CannedReplyResource::class;

    public static function getResource(): string
    {
        return HelpdeskPlugin::get()->getCannedReplyResource();
    }
}
