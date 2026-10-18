<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Resources\CannedReplyResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Resources\CannedReplyResource;

class EditCannedReply extends EditRecord
{
    protected static string $resource = CannedReplyResource::class;

    public static function getResource(): string
    {
        return HelpdeskPlugin::get()->getCannedReplyResource();
    }
}
