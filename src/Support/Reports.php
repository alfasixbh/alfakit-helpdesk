<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\Enums\SlaState;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Sla\Sla;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Helpdesk figures for a period: tickets created and solved, the median first response
 * time, SLA compliance and customer satisfaction (CSAT: the share of 4 and 5 ratings).
 */
final class Reports
{
    /** Tickets read at most for the median and compliance (newest first). */
    public const SAMPLE = 5000;

    /**
     * @param  Builder<Ticket>  $query  the tickets to report on (tenant-scoped)
     * @return array{created: int, solved: int, median_first_response: int|null, sla_compliance: float|null, csat: float|null, ratings: int, daily_created: array<int, int>}
     */
    public static function summary(Builder $query, CarbonImmutable $from, ?CarbonImmutable $until = null): array
    {
        $until ??= CarbonImmutable::now();
        $base = static fn (): Builder => (clone $query)->whereNull('merged_into_id');

        $created = $base()->whereBetween('created_at', [$from, $until])->count();
        $solved = $base()->whereBetween('solved_at', [$from, $until])->count();

        $responses = $base()
            ->whereBetween('first_responded_at', [$from, $until])
            ->latest('first_responded_at')
            ->limit(self::SAMPLE)
            ->get(['id', 'created_at', 'first_responded_at']);

        $minutes = [];

        foreach ($responses as $ticket) {
            if ($ticket->created_at !== null && $ticket->first_responded_at !== null) {
                $minutes[] = (int) floor($ticket->created_at->diffInMinutes($ticket->first_responded_at, true));
            }
        }

        $met = 0;
        $missed = 0;

        $targets = $base()
            ->where(static fn (Builder $query) => $query->whereBetween('first_response_due_at', [$from, $until])->orWhereBetween('resolution_due_at', [$from, $until]))
            ->latest('created_at')
            ->limit(self::SAMPLE)
            ->get();

        foreach ($targets as $ticket) {
            foreach ([Sla::firstResponseState($ticket, $until), Sla::resolutionState($ticket, $until)] as $state) {
                if ($state === SlaState::Met) {
                    $met++;
                } elseif ($state === SlaState::Missed || $state === SlaState::Breached) {
                    $missed++;
                }
            }
        }

        $ratings = $base()->whereBetween('satisfaction_rated_at', [$from, $until])->whereNotNull('satisfaction_rating');
        $ratingCount = (clone $ratings)->count();
        $satisfied = (clone $ratings)->where('satisfaction_rating', '>=', 4)->count();

        return [
            'created' => $created,
            'solved' => $solved,
            'median_first_response' => self::median($minutes),
            'sla_compliance' => $met + $missed === 0 ? null : round($met / ($met + $missed) * 100, 1),
            'csat' => $ratingCount === 0 ? null : round($satisfied / $ratingCount * 100, 1),
            'ratings' => $ratingCount,
            'daily_created' => self::daily($base(), $until, 14),
        ];
    }

    /**
     * @param  array<int, int>  $values
     */
    public static function median(array $values): ?int
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? $values[$middle] : (int) round(($values[$middle - 1] + $values[$middle]) / 2);
    }

    /**
     * Tickets created per day for the last `$days` days, oldest first.
     *
     * @param  Builder<Ticket>  $query
     * @return array<int, int>
     */
    public static function daily(Builder $query, CarbonImmutable $until, int $days): array
    {
        $since = $until->startOfDay()->subDays($days - 1);
        $counts = array_fill(0, $days, 0);

        foreach ($query->where('created_at', '>=', $since)->limit(self::SAMPLE * 2)->pluck('created_at') as $date) {
            $index = (int) $since->diffInDays(CarbonImmutable::parse($date)->startOfDay());

            if ($index >= 0 && $index < $days) {
                $counts[$index]++;
            }
        }

        return $counts;
    }

    /**
     * A duration in minutes as `2h 15m` / `3d 4h` (localised units).
     */
    public static function duration(?int $minutes): string
    {
        if ($minutes === null) {
            return '–';
        }

        return \Carbon\CarbonInterval::minutes($minutes)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }
}
