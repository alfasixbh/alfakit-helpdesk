<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Resources\TicketResource\Pages;

use AlfaSix\Alfakit\Actions\Action;
use AlfaSix\Alfakit\Actions\CreateAction;
use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Schemas\Components\Tabs\Tab;
use AlfaSix\AlfakitHelpdesk\Actions\TicketActions;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Resources\TicketResource;
use AlfaSix\AlfakitHelpdesk\Support\Views;
use AlfaSix\AlfakitHelpdesk\Widgets\HelpdeskStatsWidget;
use Illuminate\Database\Eloquent\Builder;

class ListTickets extends ListRecords
{
    protected static string $resource = TicketResource::class;

    public static function getResource(): string
    {
        return HelpdeskPlugin::get()->getTicketResource();
    }

    /**
     * The inbox views as tabs (with counts for the SLA ones).
     */
    public function getTabs(): array
    {
        $tabs = [];

        foreach ([Views::ACTIVE, Views::MINE, Views::UNASSIGNED, Views::DUE_SOON, Views::BREACHED, Views::PENDING, Views::SOLVED, Views::ALL] as $view) {
            $tab = Tab::make(Views::label($view))
                ->icon(Views::icon($view))
                ->modifyQueryUsing(static fn (Builder $query): Builder => Views::apply($query, $view, TicketActions::agent()));

            if (in_array($view, [Views::MINE, Views::UNASSIGNED, Views::DUE_SOON, Views::BREACHED], true)) {
                $tab->badge(static function () use ($view): ?int {
                    $count = Views::apply(static::getResource()::getEloquentQuery(), $view, TicketActions::agent())->count();

                    return $count > 0 ? $count : null;
                })->badgeColor(Views::color($view));
            }

            $tabs[$view] = $tab;
        }

        return $tabs;
    }

    protected function getHeaderActions(): array
    {
        $plugin = HelpdeskPlugin::get();
        $actions = [];

        if ($plugin->hasInbox()) {
            $inbox = $plugin->getInboxPage();
            $actions[] = Action::make('inbox')
                ->label(__('alfakit-helpdesk::helpdesk.inbox.title'))
                ->icon('inbox')
                ->color('gray')
                ->url(static fn (): string => $inbox::getUrl())
                ->visible(static fn (): bool => $inbox::canAccess());
        }

        $actions[] = CreateAction::make();

        return $actions;
    }

    protected function getHeaderWidgets(): array
    {
        return HelpdeskPlugin::get()->hasReports() ? [HelpdeskStatsWidget::class] : [];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
