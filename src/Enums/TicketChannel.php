<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Enums;

use AlfaSix\Alfakit\Support\Contracts\HasIcon;
use AlfaSix\Alfakit\Support\Contracts\HasLabel;

/**
 * Where a ticket came from.
 */
enum TicketChannel: string implements HasIcon, HasLabel
{
    case Agent = 'agent';
    case Portal = 'portal';
    case Contact = 'contact';
    case Chat = 'chat';
    case Email = 'email';
    case Api = 'api';

    public function getLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.channel.'.$this->value);
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Agent => 'headset',
            self::Portal => 'layout-panel-top',
            self::Contact => 'file-text',
            self::Chat => 'messages-square',
            self::Email => 'mail',
            self::Api => 'code',
        };
    }
}
