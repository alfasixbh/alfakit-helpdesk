<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Enums;

use AlfaSix\Alfakit\Support\Contracts\HasColor;
use AlfaSix\Alfakit\Support\Contracts\HasIcon;
use AlfaSix\Alfakit\Support\Contracts\HasLabel;

/**
 * Where a ticket is in its life: `open` (waiting for an agent), `pending` (waiting for the
 * requester; the resolution clock is paused), `solved` (the requester can still reply,
 * which reopens it) and `closed` (final).
 */
enum TicketStatus: string implements HasColor, HasIcon, HasLabel
{
    case Open = 'open';
    case Pending = 'pending';
    case Solved = 'solved';
    case Closed = 'closed';

    public function getLabel(): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.status.'.$this->value);
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Open => 'danger',
            self::Pending => 'warning',
            self::Solved => 'success',
            self::Closed => 'gray',
        };
    }

    public function getIcon(): string
    {
        return match ($this) {
            self::Open => 'circle-dot',
            self::Pending => 'clock',
            self::Solved => 'circle-check',
            self::Closed => 'archive',
        };
    }

    /** Open and pending tickets still need work. */
    public function isActive(): bool
    {
        return $this === self::Open || $this === self::Pending;
    }

    /**
     * @return array<int, self>
     */
    public static function active(): array
    {
        return [self::Open, self::Pending];
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
