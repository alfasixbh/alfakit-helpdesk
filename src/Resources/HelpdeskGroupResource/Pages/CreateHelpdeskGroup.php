<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Resources\HelpdeskGroupResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Resources\HelpdeskGroupResource;

class CreateHelpdeskGroup extends CreateRecord
{
    protected static string $resource = HelpdeskGroupResource::class;

    public static function getResource(): string
    {
        return HelpdeskPlugin::get()->getGroupResource();
    }
}
