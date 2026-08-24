<?php

namespace App\Console\Commands;

use App\Engines\Ads\Services\IndustryClassifier;
use App\Engines\Ads\Services\InventoryProfileService;
use Illuminate\Console\Command;

/**
 * ADS888 P0a — build and inspect the ad inventory intelligence layer.
 *
 * This is the operational entry point for the enrichment described in §7A of
 * the ADS888 enterprise plan. It is intentionally a console command rather than
 * an HTTP endpoint: P0a adds no routes, so it cannot affect any request path,
 * and `routes/api.php` is under concurrent edit by another workstream.
 *
 * USAGE
 *   php artisan ads:profile-inventory --dry-run     preview, write nothing
 *   php artisan ads:profile-inventory               profile every website
 *   php artisan ads:profile-inventory --stale       only new or expired profiles
 *   php artisan ads:profile-inventory --website=62  a single site
 *   php artisan ads:profile-inventory --force       override admin overrides too
 *   php artisan ads:profile-inventory --report      show the unclassified worklist
 *   php artisan ads:profile-inventory --prune       reap profiles for dead sites
 *
 * SCHEDULING (not wired in P0a — deliberate)
 * A nightly `--stale` run is the intended steady state. It is left unscheduled
 * until an admin has reviewed the first backfill, because auto-reprofiling
 * before anyone has validated the classifier would bury bad classifications
 * under a daily rewrite.
 */
class AdsProfileInventoryCommand extends Command
{
    protected $signature = 'ads:profile-inventory
        {--website= : Profile a single website id}
        {--stale : Only websites with no profile, or a profile past stale_after}
        {--force : Re-derive even where an admin override (source=explicit) exists}
        {--dry-run : Show what would happen; write nothing}
        {--report : Print the unclassified-inventory worklist and exit}
        {--prune : Delete profiles whose website no longer exists}
        {--limit= : Cap the number of websites processed}';

    protected $description = 'ADS888: derive industry, location and contextual interests for ad inventory';

    public function handle(InventoryProfileService $service): int
    {
        $this->line('');
        $this->info('ADS888 — Inventory Intelligence (P0a)');
        $this->line(str_repeat('─', 72));

        if ($this->option('report')) {
            return $this->report($service);
        }

        if ($this->option('prune')) {
            return $this->prune($service);
        }

        $dryRun  = (bool) $this->option('dry-run');
        $force   = (bool) $this->option('force');
        $stale   = (bool) $this->option('stale');
        $single  = $this->option('website');
        $limit   = $this->option('limit') !== null ? (int) $this->option('limit') : null;

        if ($dryRun) {
            $this->warn('DRY RUN — no database writes will be performed.');
            $this->line('');

            return $this->dryRun($service, $single !== null ? (int) $single : null, $limit);
        }

        if ($single !== null) {
            $profile = $service->profile((int) $single, $force);

            if ($profile === null) {
                $this->error("Website {$single} not found, or the profile write failed. Check laravel.log.");

                return self::FAILURE;
            }

            $this->renderProfile($profile);

            return self::SUCCESS;
        }

        $this->line('Profiling websites' . ($stale ? ' (stale + unprofiled only)' : ' (all)') . '…');

        $outcome = $service->profileAll(staleOnly: $stale, force: $force, limit: $limit);

        $this->line('');
        $this->info("Processed: {$outcome['processed']}   Failed: {$outcome['failed']}");
        $this->line('');

        $this->renderSummary($service);

        return $outcome['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Dry run derives exactly as a real run would, then discards the result —
     * it exercises the real cascade rather than a simplified preview, so what
     * you see is what a live run would store.
     */
    private function dryRun(InventoryProfileService $service, ?int $singleId, ?int $limit): int
    {
        $websites = \Illuminate\Support\Facades\DB::table('websites')
            ->whereNull('deleted_at')
            ->when($singleId !== null, fn ($q) => $q->where('id', $singleId))
            ->when($limit !== null, fn ($q) => $q->limit($limit))
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'subdomain', 'template_industry', 'status']);

        if ($websites->isEmpty()) {
            $this->warn('No websites matched.');

            return self::SUCCESS;
        }

        $classifier = app(IndustryClassifier::class);
        $parser     = app(\App\Engines\Ads\Services\LocationParser::class);

        $rows = [];

        foreach ($websites as $w) {
            $workspace = \Illuminate\Support\Facades\DB::table('workspaces')
                ->where('id', $w->workspace_id)->first();

            $industry = $classifier->classify(
                ['template_industry' => $w->template_industry ?? null],
                [
                    'industry'      => $workspace->industry ?? null,
                    'business_name' => $workspace->business_name ?? null,
                    'name'          => $workspace->name ?? null,
                ]
            );

            $location = $parser->parse($workspace->location ?? null);

            $rows[] = [
                $w->id,
                $w->workspace_id,
                mb_strimwidth((string) ($w->subdomain ?? '—'), 0, 24, '…'),
                $industry['industry_slug'] ?? '—',
                $industry['archetype'] ?? '—',
                number_format($industry['confidence'], 2),
                $industry['source'],
                $location['country'] ?? '—',
                number_format((float) $location['confidence'], 2),
                IndustryClassifier::isSellableForTargeting($industry['source'], $industry['confidence']) ? 'YES' : 'no',
            ];
        }

        $this->table(
            ['site', 'ws', 'subdomain', 'industry', 'archetype', 'conf', 'source', 'ctry', 'loc', 'sellable'],
            $rows
        );

        $sellable = count(array_filter($rows, static fn ($r) => $r[9] === 'YES'));
        $this->line('');
        $this->info(sprintf(
            'Would profile %d website(s). %d sellable for targeting, %d house-ads-only.',
            count($rows), $sellable, count($rows) - $sellable
        ));
        $this->warn('Nothing was written (--dry-run).');

        return self::SUCCESS;
    }

    private function report(InventoryProfileService $service): int
    {
        $rows = $service->unclassified();

        if ($rows === []) {
            $this->info('No unclassified inventory. Every profile is sellable for targeting.');

            return self::SUCCESS;
        }

        $this->warn(sprintf(
            '%d site(s) cannot be sold to a targeted campaign (house ads only):',
            count($rows)
        ));
        $this->line('');

        $this->table(
            ['site', 'ws', 'subdomain', 'status', 'industry', 'archetype', 'country', 'conf', 'source'],
            array_map(static fn ($r) => [
                $r['website_id'],
                $r['workspace_id'],
                mb_strimwidth((string) ($r['subdomain'] ?? '—'), 0, 26, '…'),
                $r['status'] ?? '—',
                $r['industry_slug'] ?? '—',
                $r['archetype'] ?? '—',
                $r['business_country'] ?? '—',
                number_format((float) $r['confidence'], 2),
                $r['source'],
            ], $rows)
        );

        $this->line('');
        $this->line('Resolve by setting an admin override (source=explicit), or by');
        $this->line('populating workspaces.industry / workspaces.location for the workspace.');

        return self::SUCCESS;
    }

    private function prune(InventoryProfileService $service): int
    {
        $deleted = $service->prune();
        $this->info("Pruned {$deleted} orphaned profile(s).");

        return self::SUCCESS;
    }

    private function renderProfile(array $profile): void
    {
        $this->line('');
        $this->table(['field', 'value'], [
            ['website_id',      $profile['website_id']],
            ['workspace_id',    $profile['workspace_id']],
            ['industry_slug',   $profile['industry_slug'] ?? '—'],
            ['archetype',       $profile['archetype'] ?? '—'],
            ['iab_categories',  implode(', ', (array) $profile['iab_categories']) ?: '—'],
            ['business_country',$profile['business_country'] ?? '—'],
            ['business_region', $profile['business_region'] ?? '—'],
            ['business_city',   $profile['business_city'] ?? '—'],
            ['service_areas',   implode(', ', (array) $profile['service_areas']) ?: '—'],
            ['location_conf',   number_format((float) $profile['location_confidence'], 2)],
            ['interests',       implode(', ', (array) $profile['interests']) ?: '—'],
            ['page_count',      $profile['page_count']],
            ['has_blog',        $profile['has_blog'] ? 'yes' : 'no'],
            ['quality_score',   number_format((float) $profile['quality_score'], 2)],
            ['confidence',      number_format((float) $profile['confidence'], 2)],
            ['source',          $profile['source']],
            ['SELLABLE',        $profile['sellable_for_targeting'] ? 'YES — may serve paid targeted campaigns' : 'NO — house ads only'],
        ]);
    }

    private function renderSummary(InventoryProfileService $service): void
    {
        $s = $service->summary();

        $this->line('Inventory summary');
        $this->line(str_repeat('─', 72));
        $this->line("  total profiles          {$s['total']}");
        $this->line("  sellable for targeting  {$s['sellable_for_targeting']}");
        $this->line("  house-ads-only          {$s['unsellable']}");

        if ($s['by_source'] !== []) {
            $this->line('  by source:');
            foreach ($s['by_source'] as $source => $count) {
                $this->line(sprintf('    %-12s %d', $source, $count));
            }
        }

        if ($s['by_archetype'] !== []) {
            $this->line('  by archetype:');
            foreach ($s['by_archetype'] as $archetype => $count) {
                $this->line(sprintf('    %-22s %d', $archetype, $count));
            }
        }

        if ($s['by_country'] !== []) {
            $this->line('  by business country:');
            foreach ($s['by_country'] as $country => $count) {
                $this->line(sprintf('    %-4s %d', $country, $count));
            }
        }

        $this->line('');
    }
}
