<?php

namespace App\Console\Commands;

use App\Engines\Ads\Services\AdDecisionService;
use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Support\AdSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ADS888 P0 — simulate an ad decision without serving anything.
 *
 * WHY THIS COMMAND EXISTS
 * The delivery plane (P0.3) is the only part of ADS888 that touches a live
 * customer website, and it is gated on the advertising terms being in force.
 * This command exercises the ENTIRE decision engine — gate, slot eligibility,
 * inventory profile, targeting, budget, pacing, frequency, ranking — against
 * real production data, and prints exactly what WOULD be served.
 *
 * That means the engine is proven before a single byte is injected into anyone's
 * page. When the delivery plane is finally wired, it calls the same
 * AdDecisionService this command already validated.
 *
 *   php artisan ads:simulate --website=3
 *   php artisan ads:simulate --all --country=AE --device=mobile
 *   php artisan ads:simulate --website=3 --explain
 */
class AdsSimulateCommand extends Command
{
    protected $signature = 'ads:simulate
        {--website= : Website id to simulate; omit with --all}
        {--all : Simulate every published website}
        {--slot=footer_sticky : Slot code}
        {--country= : Visitor country (ISO-3166-1 alpha-2), simulates CF-IPCountry}
        {--device=desktop : mobile | tablet | desktop}
        {--language=en : Visitor language}
        {--page-type=home : home | blog_post | services | contact | listing}
        {--explain : Print full decision diagnostics}';

    protected $description = 'ADS888: simulate the ad decision for a website (serves nothing)';

    public function handle(AdDecisionService $decisions, AdSettingsService $settings): int
    {
        $this->line('');
        $this->info('ADS888 — decision simulation (nothing is served)');
        $this->line(str_repeat('─', 78));

        if (! $settings->bool(AdSettings::MASTER_ENABLED)) {
            $this->warn('  ads_master_enabled = FALSE — every decision below will correctly be "no fill".');
            $this->line('  This is the expected state until the advertising terms are in force.');
            $this->line('');
        }

        $context = [
            'visitor_country' => $this->option('country') ?: null,
            'device'          => $this->option('device'),
            'language'        => $this->option('language'),
            'page_type'       => $this->option('page-type'),
            'ip_hash'         => hash('sha256', 'simulated-visitor'),
        ];

        $websiteIds = $this->resolveWebsites();

        if ($websiteIds === []) {
            $this->warn('No websites matched.');

            return self::SUCCESS;
        }

        $slot = (string) $this->option('slot');
        $rows = [];

        foreach ($websiteIds as $websiteId) {
            $result = $decisions->decide((int) $websiteId, $slot, $context);

            $fill = $result['fill'];
            $rows[] = [
                $websiteId,
                mb_strimwidth((string) (DB::table('websites')->where('id', $websiteId)->value('subdomain') ?? '—'), 0, 26, '…'),
                $fill ? 'FILL' : 'none',
                $fill['kind'] ?? '—',
                $fill['creative_id'] ?? '—',
                $result['reason'],
            ];

            if ($this->option('explain')) {
                $this->line('');
                $this->line("  ── website {$websiteId} diagnostics ──");
                $this->line('  ' . json_encode($result['diagnostics'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            }
        }

        $this->line('');
        $this->table(['site', 'subdomain', 'outcome', 'kind', 'creative', 'reason'], $rows);

        $filled = count(array_filter($rows, static fn ($r) => $r[2] === 'FILL'));
        $this->line('');
        $this->info(sprintf('  %d/%d would fill.', $filled, count($rows)));
        $this->line('  Nothing was served — this command never touches a live page.');
        $this->line('');

        return self::SUCCESS;
    }

    /** @return array<int,int> */
    private function resolveWebsites(): array
    {
        if ($this->option('website')) {
            return [(int) $this->option('website')];
        }

        if ($this->option('all')) {
            return DB::table('websites')
                ->whereNull('deleted_at')
                ->where('status', 'published')
                ->orderBy('id')
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();
        }

        $this->warn('Specify --website=ID or --all.');

        return [];
    }
}
