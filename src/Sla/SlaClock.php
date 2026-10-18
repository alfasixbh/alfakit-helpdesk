<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Sla;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * Counts SLA minutes: business minutes of `BusinessHours`, or every minute.
 */
final class SlaClock
{
    /** Safety net: never look further than this many days ahead. */
    private const MAX_DAYS = 3660;

    public function __construct(private readonly ?BusinessHours $hours) {}

    public static function calendar(): self
    {
        return new self(null);
    }

    public static function business(BusinessHours $hours): self
    {
        return new self($hours);
    }

    /**
     * The moment `$minutes` counted minutes after `$from` (in the application timezone).
     */
    public function addMinutes(DateTimeInterface $from, int $minutes): CarbonImmutable
    {
        $start = CarbonImmutable::instance($from);
        $timezone = (string) config('app.timezone', 'UTC');

        if ($this->hours === null) {
            return $start->addMinutes(max(0, $minutes))->setTimezone($timezone);
        }

        if (! $this->hours->hasOpenHours()) {
            return $start->addMinutes(max(0, $minutes))->setTimezone($timezone);
        }

        $local = $start->setTimezone($this->hours->getTimezone())->startOfMinute();
        $remaining = max(0, $minutes);
        $day = $local->startOfDay();

        for ($i = 0; $i < self::MAX_DAYS; $i++) {
            foreach ($this->hours->intervalsOn($day) as [$from, $to]) {
                $intervalStart = $day->addMinutes($from);
                $intervalEnd = $day->addMinutes($to);

                if ($intervalEnd <= $local) {
                    continue;
                }

                $cursor = $intervalStart > $local ? $intervalStart : $local;
                $available = (int) $cursor->diffInMinutes($intervalEnd, true);

                if ($remaining <= $available) {
                    // A target of zero minutes outside hours is the next opening.
                    return $cursor->addMinutes($remaining)->setTimezone($timezone);
                }

                $remaining -= $available;
            }

            $day = $day->addDay()->startOfDay();
            $local = $day;
        }

        return $start->addMinutes($minutes)->setTimezone($timezone);
    }

    /**
     * The counted minutes from `$from` to `$to` (0 when `$to` is earlier).
     */
    public function minutesBetween(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $start = CarbonImmutable::instance($from);
        $end = CarbonImmutable::instance($to);

        if ($end <= $start) {
            return 0;
        }

        if ($this->hours === null || ! $this->hours->hasOpenHours()) {
            return (int) floor($start->diffInMinutes($end, true));
        }

        $tz = $this->hours->getTimezone();
        $start = $start->setTimezone($tz);
        $end = $end->setTimezone($tz);
        $day = $start->startOfDay();
        $total = 0;

        for ($i = 0; $i < self::MAX_DAYS && $day < $end; $i++) {
            foreach ($this->hours->intervalsOn($day) as [$from, $to]) {
                $intervalStart = $day->addMinutes($from);
                $intervalEnd = $day->addMinutes($to);
                $a = $intervalStart > $start ? $intervalStart : $start;
                $b = $intervalEnd < $end ? $intervalEnd : $end;

                if ($b > $a) {
                    $total += (int) floor($a->diffInMinutes($b, true));
                }
            }

            $day = $day->addDay()->startOfDay();
        }

        return $total;
    }
}
