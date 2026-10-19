<?php

declare(strict_types=1);

use AlfaSix\AlfakitHelpdesk\Tests\TestCase;
use Illuminate\Support\Facades\File;

uses(TestCase::class);

afterEach(function (): void {
    File::deleteDirectory(base_path('alfakit-helpdesk-pages-test'));
});

it('creates the customer pages outside the app layout for each renderer', function (string $adapter, string $file, string $import, string $layout): void {
    $this->artisan('alfakit-helpdesk:pages', ['--adapter' => $adapter, '--path' => 'alfakit-helpdesk-pages-test'])->assertSuccessful();

    $path = base_path('alfakit-helpdesk-pages-test/alfakit-helpdesk/'.$file);

    expect(File::exists($path))->toBeTrue()
        ->and(File::get($path))->toContain($import)->toContain('AlfakitComponent')
        // Public pages: not inside the app's default layout (a starter kit's AppLayout).
        ->and(File::get($path))->toContain($layout);
})->with([
    'react' => ['react', 'portal.tsx', '@alfakit/react', 'HelpdeskPortal.layout = (page: ReactNode) => <>{page}</>'],
    'vue' => ['vue', 'portal.vue', '@alfakit/vue', 'defineOptions({ layout: (_h: unknown, page: unknown) => page as VNode })'],
    'svelte' => ['svelte', 'portal.svelte', '@alfakit/svelte', 'export const layout = (_h: unknown, page: unknown) => page'],
]);
