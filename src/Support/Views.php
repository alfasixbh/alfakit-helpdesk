<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Support;

use AlfaSix\AlfakitHelpdesk\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * The ticket views of the inbox and the tickets list: my open tickets, unassigned, due
 * soon, breached, pending, solved and every active ticket.
 */
final class Views
{
    public const MINE = 'mine';

    public const UNASSIGNED = 'unassigned';

    public const DUE_SOON = 'due_soon';

    public const BREACHED = 'breached';

    public const ACTIVE = 'active';

    public const PENDING = 'pending';

    public const SOLVED = 'solved';

    public const ALL = 'all';

    /**
     * @return array<int, string>
     */
    public static function keys(): array
    {
        return [self::MINE, self::UNASSIGNED, self::DUE_SOON, self::BREACHED, self::ACTIVE, self::PENDING, self::SOLVED, self::ALL];
    }

    public static function label(string $view): string
    {
        return (string) __('alfakit-helpdesk::helpdesk.views.'.$view);
    }

    public static function icon(string $view): string
    {
        return match ($view) {
            self::MINE => 'user-round',
            self::UNASSIGNED => 'inbox',
            self::DUE_SOON => 'alarm-clock',
            self::BREACHED => 'triangle-alert',
            self::PENDING => 'clock',
            self::SOLVED => 'circle-check',
            default => 'list',
        };
    }

    public static function color(string $view): ?string
    {
        return match ($view) {
            self::DUE_SOON => 'warning',
            self::BREACHED => 'danger',
            default => null,
        };
    }

    /**
     * Narrow a ticket query to a view.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function apply(Builder $query, string $view, ?Model $agent): Builder
    {
        match ($view) {
            self::MINE => $query->scopes('active')->where('assignee_id', $agent?->getKey() ?? '__none__'),
            self::UNASSIGNED => $query->scopes('active')->whereNull('assignee_id'),
            self::DUE_SOON => $query->scopes('dueSoon'),
            self::BREACHED => $query->scopes('breached'),
            self::ACTIVE => $query->scopes('active'),
            self::PENDING => $query->where('status', TicketStatus::Pending->value),
            self::SOLVED => $query->whereIn('status', [TicketStatus::Solved->value, TicketStatus::Closed->value]),
            default => $query,
        };

        return $query->whereNull('merged_into_id');
    }

    /**
     * How the view sorts: SLA views by the nearest due time, the others by activity.
     *
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public static function order(Builder $query, string $view): Builder
    {
        if ($view === self::DUE_SOON || $view === self::BREACHED) {
            return $query->orderByRaw('COALESCE(CASE WHEN first_responded_at IS NULL THEN first_response_due_at END, resolution_due_at) ASC')->orderBy('id');
        }

        return $query->orderByDesc('last_activity_at')->orderByDesc('id');
    }
}
