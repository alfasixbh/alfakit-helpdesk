<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Enums;

use AlfaSix\Alfakit\Support\Contracts\HasColor;
use AlfaSix\Alfakit\Support\Contracts\HasIcon;
use AlfaSix\Alfakit\Support\Contracts\HasLabel;

enum TicketPriority: string implements HasColor, HasIcon, HasLabel
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function getLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.priority.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Low => 'gray',
            self::Normal => 'info',
            self::High => 'warning',
            self::Urgent => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Low => 'signal-low',
            self::Normal => 'signal-medium',
            self::High => 'signal-high',
            self::Urgent => 'siren',
        };
    }

    /** Higher is more urgent (sorting). */
    public function weight(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Normal => 2,
            self::High => 3,
            self::Urgent => 4,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->getLabel();
        }

        return $options;
    }
}
