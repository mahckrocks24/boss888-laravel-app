<?php

namespace App\Console\Commands;

use App\Engines\Ads\Support\AdIndustryTaxonomy;
use App\Engines\Ads\Support\AdInterestTaxonomy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * ADS888 P0a — project the code-defined vocabularies into `ad_taxonomy`.
 *
 * WHY BOTH A PHP CONSTANT AND A TABLE
 * The vocabulary lives in PHP because it must be reviewable and diffable: a
 * change to what "medical" means is a code review, not a silent UPDATE. The
 * table exists because the admin console, the reach estimator and reporting all
 * need to JOIN against it. This command keeps the projection in step.
 *
 * SAFETY
 * Upsert-only. Codes are never deleted, because a campaign may already target
 * one — removing it would silently widen or void that campaign's targeting.
 * Retired codes are marked `is_active = false` instead, which hides them from
 * the targeting UI while leaving existing campaigns intact and auditable.
 *
 *   php artisan ads:sync-taxonomy [--dry-run]
 */
class AdsSyncTaxonomyCommand extends Command
{
    protected $signature = 'ads:sync-taxonomy {--dry-run : Report differences without writing}';

    protected $description = 'ADS888: sync controlled targeting vocabularies into the ad_taxonomy table';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->line('');
        $this->info('ADS888 — taxonomy sync' . ($dryRun ? ' (DRY RUN)' : ''));
        $this->line(str_repeat('─', 72));

        $desired = $this->desiredRows();

        $existing = DB::table('ad_taxonomy')
            ->get(['kind', 'code', 'label', 'parent_code', 'is_active'])
            ->keyBy(fn ($r) => $r->kind . '|' . $r->code);

        $inserted = 0;
        $updated  = 0;
        $now      = now();

        foreach ($desired as $key => $row) {
            $current = $existing->get($key);

            if ($current === null) {
                $inserted++;
                if (! $dryRun) {
                    DB::table('ad_taxonomy')->insert($row + [
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                }
                continue;
            }

            $changed = $current->label !== $row['label']
                || $current->parent_code !== $row['parent_code']
                || (bool) $current->is_active !== true;

            if ($changed) {
                $updated++;
                if (! $dryRun) {
                    DB::table('ad_taxonomy')
                        ->where('kind', $row['kind'])
                        ->where('code', $row['code'])
                        ->update([
                            'label'       => $row['label'],
                            'parent_code' => $row['parent_code'],
                            'sort_order'  => $row['sort_order'],
                            'is_active'   => true,
                            'updated_at'  => $now,
                        ]);
                }
            }
        }

        // Codes present in the table but no longer defined in code are retired,
        // never deleted — an active campaign may still reference them.
        $retired = 0;
        foreach ($existing as $key => $row) {
            if (! isset($desired[$key]) && (bool) $row->is_active === true) {
                $retired++;
                if (! $dryRun) {
                    DB::table('ad_taxonomy')
                        ->where('kind', $row->kind)
                        ->where('code', $row->code)
                        ->update(['is_active' => false, 'updated_at' => $now]);
                }
            }
        }

        $this->line("  desired codes : " . count($desired));
        $this->line("  inserted      : {$inserted}");
        $this->line("  updated       : {$updated}");
        $this->line("  retired       : {$retired}" . ($retired > 0 ? '  (deactivated, not deleted)' : ''));
        $this->line('');

        if ($dryRun) {
            $this->warn('Nothing written (--dry-run).');
        } else {
            $this->info('Taxonomy in sync.');
        }

        return self::SUCCESS;
    }

    /** @return array<string, array<string,mixed>> keyed "kind|code" */
    private function desiredRows(): array
    {
        $rows = [];
        $sort = 0;

        foreach (AdIndustryTaxonomy::ARCHETYPES as $code => $label) {
            $rows['archetype|' . $code] = [
                'kind' => 'archetype', 'code' => $code, 'label' => $label,
                'parent_code' => null, 'is_active' => true, 'sort_order' => $sort++,
            ];
        }

        $sort = 0;
        foreach (AdIndustryTaxonomy::INDUSTRIES as $code => $label) {
            $rows['industry|' . $code] = [
                'kind' => 'industry', 'code' => $code, 'label' => $label,
                'parent_code' => AdIndustryTaxonomy::archetypeForIndustry($code),
                'is_active' => true, 'sort_order' => $sort++,
            ];
        }

        $sort = 0;
        $iabSeen = [];
        foreach (AdIndustryTaxonomy::IAB_MAP as $categories) {
            foreach ($categories as $category) {
                $code = $this->iabCode($category);
                if (isset($iabSeen[$code])) {
                    continue;
                }
                $iabSeen[$code] = true;
                $rows['iab|' . $code] = [
                    'kind' => 'iab', 'code' => $code, 'label' => $category,
                    'parent_code' => null, 'is_active' => true, 'sort_order' => $sort++,
                ];
            }
        }

        $sort = 0;
        foreach (AdInterestTaxonomy::INTERESTS as $code => $label) {
            $rows['interest|' . $code] = [
                'kind' => 'interest', 'code' => $code, 'label' => $label,
                'parent_code' => null, 'is_active' => true, 'sort_order' => $sort++,
            ];
        }

        return $rows;
    }

    /** "Food & Drink > Dining Out" → "food_drink__dining_out" (stable, <=96 chars). */
    private function iabCode(string $label): string
    {
        $code = mb_strtolower($label);
        $code = str_replace('>', '__', $code);
        $code = preg_replace('/[^a-z0-9_]+/', '_', $code) ?? '';
        $code = preg_replace('/_+/', '_', $code) ?? '';

        return substr(trim($code, '_'), 0, 96);
    }
}
