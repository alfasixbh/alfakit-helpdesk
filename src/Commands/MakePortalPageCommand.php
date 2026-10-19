<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitHelpdesk\Commands;

use AlfaSix\Alfakit\Commands\Support\FrontendFormatter;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Creates the Inertia page the portal routes render (`config('alfakit-helpdesk.page')`)
 * for the application's renderer.
 */
class MakePortalPageCommand extends Command
{
    protected $signature = 'alfakit-helpdesk:pages {--adapter= : react, vue or svelte (detected from package.json)} {--path= : The pages directory (default: resources/js/pages)} {--force : Overwrite the page}';

    protected $description = 'Create the Inertia page of the AlfaKit helpdesk portal and contact form';

    /** @var array<string, string> */
    protected array $extensions = ['react' => 'tsx', 'vue' => 'vue', 'svelte' => 'svelte'];

    public function handle(Filesystem $files): int
    {
        $adapter = $this->option('adapter') ?? $this->detectAdapter() ?? 'react';

        if (! is_string($adapter) || ! isset($this->extensions[$adapter])) {
            $this->components->error('Unknown adapter. Use react, vue or svelte.');

            return self::FAILURE;
        }

        $directory = $this->option('path');
        $directory = is_string($directory) && $directory !== ''
            ? base_path(trim($directory, '/'))
            : ($files->isDirectory(resource_path('js/Pages')) && ! $files->isDirectory(resource_path('js/pages')) ? resource_path('js/Pages') : resource_path('js/pages'));

        $page = (string) config('alfakit-helpdesk.page', 'alfakit-helpdesk/portal');
        $path = $directory.'/'.$page.'.'.$this->extensions[$adapter];

        if ($files->exists($path) && ! $this->option('force')) {
            $this->components->warn("The page [{$path}] already exists. Use --force to overwrite it.");

            return self::SUCCESS;
        }

        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, (string) $files->get(dirname(__DIR__, 2)."/stubs/pages/{$adapter}.{$this->extensions[$adapter]}.stub"));
        // In the app's own style when it has a formatter (`vp fmt`, Oxfmt or Prettier).
        FrontendFormatter::format([$path]);

        $this->components->info("Created [{$path}]. The portal is served at /".trim((string) config('alfakit-helpdesk.route.prefix', 'support'), '/').'.');

        return self::SUCCESS;
    }

    protected function detectAdapter(): ?string
    {
        $packageJson = base_path('package.json');

        if (! is_file($packageJson)) {
            return null;
        }

        $json = json_decode((string) file_get_contents($packageJson), true);
        $dependencies = is_array($json) ? [...(array) ($json['dependencies'] ?? []), ...(array) ($json['devDependencies'] ?? [])] : [];

        foreach (['react', 'vue', 'svelte'] as $adapter) {
            if (isset($dependencies["@alfakit/{$adapter}"]) || isset($dependencies["@inertiajs/{$adapter}"]) || ($adapter === 'vue' && isset($dependencies['@inertiajs/vue3']))) {
                return $adapter;
            }
        }

        return null;
    }
}
