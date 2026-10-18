<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Widgets;

use AlfaSix\Alfakit\Widgets\StatsOverviewWidget;
use AlfaSix\Alfakit\Widgets\StatsOverviewWidget\Stat;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Models\Ticket;
use AlfaSix\AlfakitHelpdesk\Support\Reports;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Number;

/**
 * Helpdesk reports for the last `$days` days: tickets created (with a daily sparkline)
 * and solved, the median first response time, SLA compliance and CSAT. Tenant-scoped.
 */
class HelpdeskStatsWidget extends StatsOverviewWidget
{
    /** @var int|array<string, int|null>|null */
    protected int|array|null $columns = ['default' => 1, 'sm' => 2, 'xl' => 5];

    protected int $days = 30;

    protected function getStats(): array
    {
        /** @var Builder<Ticket> $query */
        $query = HelpdeskPlugin::get()->getTicketResource()::getEloquentQuery();
        $report = Reports::summary($query, CarbonImmutable::now()->subDays($this->days));
        $locale = app()->getLocale();
        $period = __('alfakit-helpdesk::helpdesk.reports.last_days', ['days' => $this->days]);

        return [
            Stat::make(__('alfakit-helpdesk::helpdesk.reports.created'), Number::format($report['created'], locale: $locale))
                ->icon('inbox')
                ->description($period)
                ->chart($report['daily_created'])
                ->color('primary'),
            Stat::make(__('alfakit-helpdesk::helpdesk.reports.solved'), Number::format($report['solved'], locale: $locale))
                ->icon('circle-check')
                ->description($period)
                ->color('success'),
            Stat::make(__('alfakit-helpdesk::helpdesk.reports.first_response'), Reports::duration($report['median_first_response']))
                ->icon('timer')
                ->description(__('alfakit-helpdesk::helpdesk.reports.median')),
            Stat::make(__('alfakit-helpdesk::helpdesk.reports.sla_compliance'), $report['sla_compliance'] === null ? '–' : Number::percentage($report['sla_compliance'], maxPrecision: 1, locale: $locale))
                ->icon('shield-check')
                ->color($report['sla_compliance'] === null ? null : ($report['sla_compliance'] >= 90 ? 'success' : ($report['sla_compliance'] >= 75 ? 'warning' : 'danger'))),
            Stat::make(__('alfakit-helpdesk::helpdesk.reports.csat'), $report['csat'] === null ? '–' : Number::percentage($report['csat'], maxPrecision: 1, locale: $locale))
                ->icon('smile')
                ->description(trans_choice('alfakit-helpdesk::helpdesk.reports.ratings', $report['ratings'], ['count' => $report['ratings']]))
                ->color($report['csat'] === null ? null : ($report['csat'] >= 80 ? 'success' : 'warning')),
        ];
    }
}
