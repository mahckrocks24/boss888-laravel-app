<?php

namespace App\Engines\Studio\Projection\Html;

use Illuminate\Support\ServiceProvider;

/**
 * STUDIO888 · Projection/Html — Phase 4B service provider (dormant).
 *
 * The HTML adapter is constructed per-document via HtmlProjectionAdapter::forRawHtml
 * / ::forStructured, so there is no global adapter singleton to bind. This
 * provider binds only the stateless security policy for dependency injection.
 *
 * PHASE 4B IS DORMANT: no routes, middleware, listeners, or transport; nothing
 * in the running app consumes these bindings; the provider is NOT registered.
 * It loads no live design, persists nothing, and changes no live behaviour.
 */
final class StudioHtmlProjectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HtmlProjectionSecurityPolicy::class, fn () => new HtmlProjectionSecurityPolicy());
    }

    public function boot(): void
    {
        // Intentionally empty in Phase 4B. Server-side compatibility adapter only.
    }
}
