<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Enums;

use AlfaSix\Alfakit\Support\Contracts\HasColor;
use AlfaSix\Alfakit\Support\Contracts\HasIcon;
use AlfaSix\Alfakit\Support\Contracts\HasLabel;

/**
 * The state of one SLA target of a ticket (first response or resolution), or of the
 * ticket as a whole (its most urgent target).
 */
enum SlaState: string implements HasColor, HasIcon, HasLabel
{
    case None = 'none';
    case OnTrack = 'on_track';
    case DueSoon = 'due_soon';
    case Breached = 'breached';
    case Paused = 'paused';
    case Met = 'met';
    case Missed = 'missed';

    public function getLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.sla.state.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None, self::Paused => 'gray',
            self::OnTrack, self::Met => 'success',
            self::DueSoon => 'warning',
            self::Breached, self::Missed => 'danger',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::None => 'minus',
            self::OnTrack => 'timer',
            self::DueSoon => 'alarm-clock',
            self::Breached => 'triangle-alert',
            self::Paused => 'pause',
            self::Met => 'circle-check',
            self::Missed => 'circle-x',
        };
    }

    /** How urgent the state is (the ticket shows its most urgent target). */
    public function urgency(): int
    {
        return match ($this) {
            self::Breached => 5,
            self::DueSoon => 4,
            self::OnTrack => 3,
            self::Paused => 2,
            self::Missed => 1,
            self::Met, self::None => 0,
        };
    }
}
