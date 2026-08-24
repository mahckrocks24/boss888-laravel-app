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
        // REMOVED 2026-08-24 (MISSION-018 WS-1, RISK-0005 census): this loaded
        // Http/Routes.php, whose only two registrations (GET+POST api/crm/leads
        // via LeadController) were both fully shadowed by the later
        // crm-01.php registrations (CrmController) and could never serve.
        // Proven by the registration-time census (tools/route-registration-census.php)
        // before removal; the serving routes are unchanged.

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
