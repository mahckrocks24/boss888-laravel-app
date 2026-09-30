<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

/**
 * ONBOARD-PUBLISH-1 (Owner 2026-09-30: "the trigger for her to know the user is after the website is published").
 *
 * Until now Sarah's discovery run (welcome, site audit, page index, brand extraction, competitors, first strategy meeting)
 * only ran inside the 08:00 morning brief, and only for a workspace whose server-side `onboarded` flag was set - a flag
 * the publish path never set. So a customer whose site Arthur published waited a day at best and, for most, forever.
 * BuilderService::publishWebsite now marks the workspace onboarded and dispatches this job; the run itself is unchanged
 * and idempotent (runIfNew), and a plan that does not include Sarah gets nothing (SARAH-GATE-2).
 */
class SarahDiscoveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;
    public int $timeout = 600;

    public function __construct(public int $wsId, public string $source = 'website_published')
    {
        $this->onQueue('default');
    }

    /**
     * ONBOARD-PUBLISH-1b: the one place every publish path calls (the Publish button's route in routes/api.php and
     * BuilderService::publishWebsite for Sarah/Arthur/capabilities). Marks the workspace onboarded and wakes Sarah.
     * Fail open: never breaks a publish.
     */
    public static function onPublished(int $wsId, string $source = 'website_published'): void
    {
        if ($wsId <= 0) return;
        try {
            if (! \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->value('onboarded')) {
                \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $wsId)->update(['onboarded' => 1, 'onboarded_at' => now(), 'updated_at' => now()]);
            }
            self::dispatch($wsId, $source)->delay(now()->addSeconds(20));
        } catch (\Throwable $e) {
            Log::warning('[Discovery] onPublished failed', ['ws' => $wsId, 'error' => $e->getMessage()]);
        }
    }

    public function handle(): void
    {
        try {
            if (! app(\App\Core\Billing\FeatureGateService::class)->canAccessSarah($this->wsId)) {
                Log::info('[Discovery] skipped: plan does not include Sarah', ['ws' => $this->wsId, 'source' => $this->source]);
                return;
            }
        } catch (\Throwable $e) {}
        $r = app(\App\Core\Strategy\DiscoveryRunOrchestrator::class)->runIfNew($this->wsId);
        Log::info('[Discovery] after publish', ['ws' => $this->wsId, 'source' => $this->source, 'skipped' => $r['skipped'] ?? false, 'reason' => $r['reason'] ?? null,
            'steps_ok' => isset($r['steps']) ? count(array_filter($r['steps'], fn ($s) => ($s['ok'] ?? false))) : null]);
    }
}
