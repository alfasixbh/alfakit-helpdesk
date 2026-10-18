<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Tests;

use AlfaSix\Alfakit\Tests\TestCase as AlfakitTestCase;
use AlfaSix\AlfakitHelpdesk\AlfakitHelpdeskServiceProvider;
use AlfaSix\AlfakitHelpdesk\Support\Placeholders;
use AlfaSix\AlfakitHelpdesk\Tests\Fixtures\HelpdeskUser;
use AlfaSix\AlfakitHelpdesk\Tests\Fixtures\Panels\HelpdeskPanelProvider;
use AlfaSix\AlfakitHelpdesk\Tests\Fixtures\Panels\HelpdeskTeamPanelProvider;
use Illuminate\Testing\TestResponse;
use RuntimeException;

abstract class TestCase extends AlfakitTestCase
{
    /**
     * The running test (Pest binds test closures to it).
     */
    public static function current(): static
    {
        $test = \Pest\TestSuite::getInstance()->test;

        if (! $test instanceof static) {
            throw new RuntimeException('No '.static::class.' is running.');
        }

        return $test;
    }

    protected function setUp(): void
    {
        parent::setUp();

        Placeholders::flush();
    }

    protected function getPackageProviders($app): array
    {
        return [
            ...parent::getPackageProviders($app),
            AlfakitHelpdeskServiceProvider::class,
            HelpdeskPanelProvider::class,
            HelpdeskTeamPanelProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('auth.providers.users.model', HelpdeskUser::class);
        $app['config']->set('alfakit-helpdesk.panel', 'helpdesk');
        $app['config']->set('alfakit-helpdesk.spam.min_seconds', 0);
        $app['config']->set('mail.default', 'array');
        $app['config']->set('filesystems.disks.local', ['driver' => 'local', 'root' => storage_path('framework/testing/helpdesk')]);
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        $this->loadMigrationsFrom(dirname(__DIR__, 3).'/alfakit/database/migrations');
        $this->loadMigrationsFrom(dirname(__DIR__).'/database/migrations');
    }

    /**
     * The Inertia props of a page.
     *
     * @return array<string, mixed>
     */
    public function inertiaProps(string $url): array
    {
        $response = $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => '']);
        $response->assertOk();

        return $response->json('props');
    }

    /**
     * Run an action of the inbox (`helpdesk` component) as the renderer's runner does.
     *
     * @param  array<string, mixed>  $payload
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    public function inboxAction(string $panelPath, string $token, string $action, mixed $record = null, array $payload = []): TestResponse
    {
        return $this->postJson("/{$panelPath}/_alfakit/actions", array_filter([
            'token' => $token,
            'component' => 'helpdesk',
            'action' => $action,
            'record' => $record,
            ...$payload,
        ], static fn (mixed $value): bool => $value !== null));
    }
}
