<?php

namespace App\Providers;

use App\Core\EngineKernel\CapabilityMapService;
use App\Core\Governance\GovernedCapabilityMap;
use Illuminate\Support\ServiceProvider;

/**
 * MANDATE-1 (DEC-0018, 2026-09-25): the capability map the container hands out knows the platform's own governance
 * actions (the Plan of Action gate) as well as the engines'. Registered after the framework's providers so the binding wins.
 */
class MandateServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CapabilityMapService::class, function ($app) {
            return new GovernedCapabilityMap($app->make(\App\Connectors\ConnectorResolver::class));
        });
    }
}
