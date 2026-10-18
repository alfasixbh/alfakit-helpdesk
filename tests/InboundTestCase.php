<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Tests;

/**
 * Reply by email turned on (the route is registered when the application boots).
 */
abstract class InboundTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('alfakit-helpdesk.inbound.enabled', true);
        $app['config']->set('alfakit-helpdesk.inbound.secret', 'inbound-secret');
        $app['config']->set('alfakit-helpdesk.inbound.reply_to', 'support@example.com');
    }
}
