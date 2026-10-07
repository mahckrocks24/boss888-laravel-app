<?php

namespace App\Console\Commands;

use App\Engines\Builder\Services\UpgradeOfferService;
use App\Engines\Builder\Support\DesignCatalog;
use App\Engines\Builder\Support\DesignVersions;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * UPGRADE-OFFER-1 (Owner 2026-10-07): the paced pass that offers websites on a Classic design the new designs.
 *   designs:upgrade-offers                  a few eligible sites (upgradeoffer.on workspaces, activated industries), then the browser check
 *   designs:upgrade-offers --site=123       one site (still only when eligible; --force skips the eligibility check, QA only)
 *   designs:upgrade-offers --maps           write storage/app/upgrade-maps/{design}.json for every Classic design
 *   designs:upgrade-offers --scan           read-only: per site, how many owner values the best new design would hold (no writes)
 */
class DesignsUpgradeOffersCommand extends Command
{
    protected $signature = 'designs:upgrade-offers {--site=*} {--max=2} {--no-probe} {--force} {--maps} {--scan} {--out=}';
    protected $description = 'UPGRADE-OFFER-1: offer websites on a Classic design the new designs (paced, owner decides)';

    public function handle(UpgradeOfferService $svc): int
    {
        if ($this->option('maps')) {
            $n = 0; $sum = [];
            foreach (glob(storage_path('templates/*/manifest.json')) ?: [] as $mf) {
                $slug = basename(dirname($mf)); $m = $svc->writeMap($slug); if (! $m) continue; $n++;
                $best = $m['coverage_by_layout'] ? max($m['coverage_by_layout']) : 0; $sum[] = sprintf('%-28s %-15s %3d owner fields, best layout %3d%%, nowhere: %d', $slug, $m['industry'], $m['owner_fields'], $best, count($m['no_place_anywhere']));
            }
            foreach ($sum as $l) $this->line($l); $this->info("{$n} maps written to storage/" . UpgradeOfferService::MAPS);
            return 0;
        }
        if ($this->option('scan')) return $this->scan($svc);
        $sites = array_map('intval', (array) $this->option('site'));
        if ($sites) { foreach ($sites as $sid) foreach ($svc->offer($sid, (bool) $this->option('force')) as $r) $this->line("site {$sid}: " . ($r['update'] ?? '-') . ' ' . ($r['to'] ?? '') . ' ' . ($r['status'] ?? '') . (isset($r['reason']) ? ' (' . $r['reason'] . ')' : '')); return 0; }
        foreach ($svc->run((int) $this->option('max'), ! $this->option('no-probe')) as $l) $this->line($l);
        return 0;
    }

    /** Read-only readiness of the existing base: nothing is written to sites, offers or the record. */
    private function scan(UpgradeOfferService $svc): int
    {
        $rows = []; $tot = ['sites' => 0, 'bespoke' => 0, 'already_new' => 0, 'no_design' => 0, 'no_content' => 0, 'scanned' => 0, 'values' => 0, 'mapped' => 0, 'full' => 0];
        foreach (DB::table('websites')->whereNull('deleted_at')->where('type', 'template')->orderBy('id')->get(['id', 'workspace_id']) as $w) {
            $sid = (int) $w->id; $tot['sites']++;
            if (! is_file(storage_path("app/public/sites/{$sid}/index.html"))) { $tot['no_design']++; continue; }
            $slug = DesignVersions::ofSite($sid)['slug']; $m = $slug !== '' ? app(\App\Engines\Builder\Services\TemplateService::class)->getManifest($slug) : null;
            if (! $m) { $tot['no_design']++; continue; }
            if (! empty($m['bespoke'])) { $tot['bespoke']++; continue; }
            if (DesignCatalog::isV3($slug, $m) || DesignCatalog::isV3Artefact($slug, $m)) { $tot['already_new']++; continue; }
            $own = $svc->ownerValues($sid, $m); if (! $own) { $tot['no_content']++; continue; }
            $best = null; $bestHit = -1;
            foreach ($svc->suggest($sid, 3, true) as $to) {
                $vars = (json_decode((string) @file_get_contents(storage_path("templates/{$to}/manifest.json")), true) ?: [])['variables'] ?? [];
                $hit = 0; foreach (array_keys($own) as $k) { $t = UpgradeOfferService::resolve($k, $vars); if ($t !== '' && array_key_exists($t, $vars)) $hit++; }
                if ($hit > $bestHit) { $bestHit = $hit; $best = $to; }
            }
            $tot['scanned']++; $tot['values'] += count($own); $tot['mapped'] += max(0, $bestHit); if ($bestHit === count($own)) $tot['full']++;
            $rows[] = ['site' => $sid, 'ws' => (int) $w->workspace_id, 'design' => $slug, 'owner_values' => count($own), 'would_hold' => max(0, $bestHit), 'best' => $best];
        }
        $out = (string) ($this->option('out') ?: storage_path('app/upgrade-maps/_scan-' . date('Ymd-His') . '.json'));
        @mkdir(dirname($out), 0775, true); file_put_contents($out, json_encode(['at' => date('c'), 'totals' => $tot, 'sites' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->line(json_encode($tot)); $this->info("per-site detail (ids and counts only): {$out}");
        return 0;
    }
}
