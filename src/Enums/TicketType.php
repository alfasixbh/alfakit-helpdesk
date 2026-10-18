<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Enums;

use AlfaSix\Alfakit\Support\Contracts\HasLabel;

enum TicketType: string implements HasLabel
{
    case Question = 'question';
    case Incident = 'incident';
    case Problem = 'problem';
    case Task = 'task';

    public function getLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.type.'.$this->value);
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
