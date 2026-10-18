<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Tests\Fixtures\Panels;

use AlfaSix\Alfakit\Panel;
use AlfaSix\Alfakit\PanelProvider;
use AlfaSix\Alfakit\Tests\Fixtures\Models\Team;
use AlfaSix\AlfakitHelpdesk\HelpdeskPlugin;
use AlfaSix\AlfakitHelpdesk\Tests\Fixtures\HelpdeskUser;
use Illuminate\Database\Eloquent\Model;

class HelpdeskTeamPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('helpdesk-team')
            ->path('helpdesk-team')
            ->login()
            ->middleware(['web'])
            ->tenant(Team::class, slugAttribute: 'slug')
            ->plugin(HelpdeskPlugin::make()->authorize(static fn (Model $user): bool => $user instanceof HelpdeskUser && $user->isAgent()));
    }
}
