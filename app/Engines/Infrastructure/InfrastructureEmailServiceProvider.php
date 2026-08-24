<?php

namespace App\Engines\Infrastructure;

use App\Engines\Infrastructure\Email\Console\ReconcileBusinessEmail;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/**
 * INFRA888 — Business Email scheduling, owned by this workstream.
 *
 * WHY A PROVIDER RATHER THAN bootstrap/app.php
 * That file holds every workstream's schedule entries and is edited by several
 * parallel sessions; at the moment this was written it had been modified nine
 * minutes earlier by the EXPERIENCE888 session. A merge conflict there does not
 * break one feature, it takes the whole scheduler down — every reconciler,
 * reaper and sweep on the platform. Email888 set this precedent for the same
 * reason, and this follows it: one line in config/app.php, everything else in a
 * file this workstream owns outright.
 *
 * The command itself is registered here too, because engine-namespaced commands
 * are not auto-discovered.
 */
class InfrastructureEmailServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReconcileBusinessEmail::class,
            ]);
        }

        $this->app->booted(function () {
            /**
             * Hourly, not every ten minutes.
             *
             * Each pass enumerates every mailbox, alias, forwarder and
             * catch-all at the provider for every domain. That is a real API
             * cost against a vendor with rate limits, and provider drift is
             * measured in hours — somebody changing a mailbox in the vendor's
             * own console — not in minutes. Ten-minute polling would spend
             * quota to answer a question whose answer changes daily.
             *
             * withoutOverlapping: a slow provider must never stack passes on
             * top of each other. onOneServer: this is a read against a shared
             * external resource; running it once is the point.
             */
            $this->app->make(Schedule::class)
                ->command('infra:reconcile-business-email')
                ->name('infra:reconcile-business-email')
                ->hourly()
                ->withoutOverlapping()
                ->onOneServer()
                ->runInBackground();
        });
    }
}
