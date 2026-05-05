<?php

namespace App\Engines\CRM;

use Illuminate\Support\ServiceProvider;
use App\Engines\CRM\Contracts\LeadRepositoryContract;
use App\Engines\CRM\Repositories\LeadRepository;
use App\Core\EngineKernel\EngineRegistryService;
use App\Core\EngineKernel\EngineManifestLoader;

class EngineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(LeadRepositoryContract::class, LeadRepository::class);
    }

    public function boot(): void
    {
        // Load engine routes
        $this->loadRoutesFrom(__DIR__ . '/Http/Routes.php');

        // Register engine manifest (deferred to avoid DB calls during boot if DB not ready)
        $this->app->booted(function () {
            try {
                $loader = $this->app->make(EngineManifestLoader::class);
                $manifest = $loader->load(__DIR__);

                if ($manifest) {
                    $registry = $this->app->make(EngineRegistryService::class);
                    $registry->register($manifest);
                }
            } catch (\Throwable) {
                // DB not ready yet (pre-migration), skip silently
            }
        });
    }
}
