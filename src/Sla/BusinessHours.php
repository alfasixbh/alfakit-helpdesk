<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Sla;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * When the support team works, in its own timezone: open intervals per weekday and
 * holidays. SLA targets count only these minutes (unless a policy uses calendar hours).
 *
 * ```php
 * BusinessHours::make('Asia/Bahrain')
 *     ->days(['sun', 'mon', 'tue', 'wed', 'thu'], '08:00', '17:00')
 *     ->holidays(['2026-12-16', '2026-12-17']);
 *
 * BusinessHours::make()->weekdays('09:00', '12:30')->weekdays('13:30', '18:00'); // a lunch break
 * BusinessHours::always();                                                        // 24/7
 * ```
 */
final class BusinessHours
{
    public const DAYS = ['mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6, 'sun' => 7];

    /** @var array<int, array<int, array{0: int, 1: int}>> ISO weekday => [[from minute, to minute]] */
    private array $intervals = [];

    /** @var array<string, true> Y-m-d */
    private array $holidays = [];

    private function __construct(private readonly ?string $timezone) {}

    /**
     * Without hours, Monday to Friday 09:00 to 17:00 is used.
     */
    public static function make(?string $timezone = null): self
    {
        return new self($timezone);
    }

    /** Every minute of every day. */
    public static function always(?string $timezone = null): self
    {
        return self::make($timezone)->days(array_keys(self::DAYS), '00:00', '24:00');
    }

    /**
     * Open from `$from` to `$to` (`HH:MM`, `24:00` for midnight) on these days (`mon`…`sun`).
     * Call again to add more intervals (e.g. after a break).
     *
     * @param  array<int, string>  $days
     */
    public function days(array $days, string $from, string $to): self
    {
        $start = self::parseTime($from);
        $end = self::parseTime($to);

        if ($end <= $start) {
            throw new InvalidArgumentException("Business hours must end after they start ([{$from}] to [{$to}]).");
        }

        foreach ($days as $day) {
            $iso = self::DAYS[strtolower(substr($day, 0, 3))] ?? null;

            if ($iso === null) {
                throw new InvalidArgumentException("Unknown day [{$day}]: use mon, tue, wed, thu, fri, sat or sun.");
            }

            $this->intervals[$iso][] = [$start, $end];
            usort($this->intervals[$iso], static fn (array $a, array $b): int => $a[0] <=> $b[0]);
        }

        return $this;
    }

    public function day(string $day, string $from, string $to): self
    {
        return $this->days([$day], $from, $to);
    }

    /** Monday to Friday. */
    public function weekdays(string $from, string $to): self
    {
        return $this->days(['mon', 'tue', 'wed', 'thu', 'fri'], $from, $to);
    }

    /**
     * Closed on these dates (`Y-m-d`, in the business timezone).
     *
     * @param  array<int, string|DateTimeInterface>  $dates
     */
    public function holidays(array $dates): self
    {
        foreach ($dates as $date) {
            $this->holidays[$date instanceof DateTimeInterface ? $date->format('Y-m-d') : CarbonImmutable::parse($date)->format('Y-m-d')] = true;
        }

        return $this;
    }

    public function getTimezone(): string
    {
        return $this->timezone ?? (string) config('app.timezone', 'UTC');
    }

    /**
     * The open intervals of a date, as minutes since midnight.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    public function intervalsOn(CarbonImmutable $date): array
    {
        if (isset($this->holidays[$date->format('Y-m-d')])) {
            return [];
        }

        $intervals = $this->intervals === [] ? self::defaultIntervals() : $this->intervals;

        return $intervals[$date->dayOfWeekIso] ?? [];
    }

    public function hasOpenHours(): bool
    {
        foreach ($this->intervals === [] ? self::defaultIntervals() : $this->intervals as $day) {
            if ($day !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the team works at this moment.
     */
    public function isOpenAt(DateTimeInterface $moment): bool
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($this->getTimezone());
        $minute = $local->hour * 60 + $local->minute;

        foreach ($this->intervalsOn($local->startOfDay()) as [$from, $to]) {
            if ($minute >= $from && $minute < $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<int, array{0: int, 1: int}>>
     */
    private static function defaultIntervals(): array
    {
        return array_fill_keys([1, 2, 3, 4, 5], [[9 * 60, 17 * 60]]);
    }

    private static function parseTime(string $time): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $match) !== 1) {
            throw new InvalidArgumentException("Invalid time [{$time}]: use HH:MM.");
        }

        $minutes = (int) $match[1] * 60 + (int) $match[2];

        if ((int) $match[2] > 59 || $minutes > 24 * 60) {
            throw new InvalidArgumentException("Invalid time [{$time}]: use HH:MM.");
        }

        return $minutes;
    }
}
