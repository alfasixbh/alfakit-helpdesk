<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\AlfakitHelpdesk\Enums\TicketPriority;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Sla\BusinessHours;
use AlfaSix\AlfakitHelpdesk\Sla\SlaPolicy;
use AlfaSix\AlfakitHelpdesk\Tests\Fixtures\HelpdeskUser;
use Illuminate\Database\Eloquent\Model;

class HelpdeskPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('helpdesk')
            ->path('helpdesk')
            ->login()
            ->middleware(['web'])
            ->plugin(self::plugin());
    }

    public static function plugin(): HelpdeskPlugin
    {
        return HelpdeskPlugin::make()
            ->authorize(static fn (Model $user): bool => $user instanceof HelpdeskUser && $user->isAgent())
            ->agents(static fn () => HelpdeskUser::query()->where('email', 'like', '%@agents.test'))
            ->businessHours(BusinessHours::make('UTC')->weekdays('09:00', '17:00'))
            ->slaPolicies([
                SlaPolicy::make('urgent')->priorities([TicketPriority::Urgent])->firstResponseWithin(30)->resolveWithin(120)->calendarHours(),
                SlaPolicy::make('standard')->firstResponseWithin(60)->resolveWithin(8 * 60),
            ]);
    }
}
