<?php

namespace App\Engines\Builder\Services;

use App\Engines\Builder\Support\DesignMerge;
use App\Engines\Builder\Support\DesignVersions;
use App\Engines\Builder\Support\DraftEdits;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

/**
 * DESIGN-UPDATES-1 (Owner 2026-10-06): "whenever we are pushing updates, we need to inform them using a design preview of
 * what the website will look and the possible changes on their customizations, with and agree or cancel option and a revert
 * back option if needed." Nothing is ever applied on its own.
 *
 *   detect   designs:check-updates finds sites whose design has a newer version (off-peak, light)
 *   build    the candidate is built beside the site (storage/app/design-updates/{site}/{update}/), never on the live path:
 *            the design the site was built on, the design now and the site as the owner has it, merged (DesignMerge)
 *   report   every owner value checked present; customisations at risk listed in plain words; overflow/contrast probe of
 *            the candidate against the current page (one Chrome, the render lock, the load gate)
 *   offer    a closed card in Sarah's Needs your OK; it opens the preview (now / after, desktop / phone) with Agree / Cancel
 *   agree    snapshot (every file of the site, its record), candidate written, menus and pages refreshed, Revert for 30 days
 *   cancel   nothing changes; that version is not offered again
 *   revert   the snapshot back, byte for byte, checked file by file
 * Kill switch: storage/app/designupd.on lists the workspace ids it covers ("*" = every workspace); absent = off.
 */
final class DesignUpdateService
{
    public const SWITCH = 'app/designupd.on';
    public const REVERT_DAYS = 30;
    private const LOCK = '/run/lug-render.lock';   // shared with the template render runner: one Chrome on the box at a time

    public function __construct(private TemplateService $templates) {}

    /** Tests set the covered workspaces here instead of touching the switch file. */
    public static ?array $onlyWorkspaces = null;

    public static function workspaces(): ?array
    {
        if (self::$onlyWorkspaces !== null) return self::$onlyWorkspaces;
        $p = storage_path(self::SWITCH); if (! is_file($p)) return [];
        $t = preg_split('/[\s,]+/', trim((string) file_get_contents($p))) ?: [];
        if (in_array('*', $t, true)) return null;   // every workspace
        return array_values(array_filter(array_map('intval', $t)));
    }

    public static function on(int $workspaceId): bool
    {
        $w = self::workspaces(); return $w === null || in_array($workspaceId, $w, true);
    }

    public static function dir(int $websiteId, int $updateId): string
    {
        return storage_path("app/design-updates/{$websiteId}/{$updateId}");
    }

    // ───────────────────────── detect ─────────────────────────

    /** The nightly pass. Returns what it did, line by line. */
    public function check(?int $onlySite = null, bool $probe = true): array
    {
        $log = [];
        $ws = self::workspaces();
        if ($ws === [] && $onlySite === null) return ['off: no workspace in ' . self::SWITCH];
        $q = DB::table('websites')->whereNull('deleted_at')->where('type', 'template');
        if ($onlySite !== null) $q->where('id', $onlySite); elseif ($ws !== null) $q->whereIn('workspace_id', $ws);
        foreach ($q->orderBy('id')->get(['id', 'workspace_id']) as $w) {
            if (! self::on((int) $w->workspace_id)) continue;
            try { $log[] = $this->checkSite((int) $w->id); } catch (\Throwable $e) { $log[] = "site {$w->id}: error " . $e->getMessage(); Log::warning('[DesignUpdates] check failed', ['site' => $w->id, 'e' => $e->getMessage()]); }
        }
        if ($probe) {
            $pending = DB::table('design_updates')->where('status', 'probing'); if ($onlySite !== null) $pending->where('website_id', $onlySite);
            foreach ($pending->orderBy('id')->pluck('id') as $id) { $r = $this->probe((int) $id); $log[] = "update {$id}: probe {$r}"; if (in_array($r, ['busy', 'load'], true)) break; }
        }
        return $log;
    }

    public function checkSite(int $websiteId): string
    {
        if (! is_file(storage_path("app/public/sites/{$websiteId}/index.html"))) return "site {$websiteId}: no page on disk";
        $d = DesignVersions::ofSite($websiteId);
        if ($d['slug'] === '' || ! is_file(storage_path("templates/{$d['slug']}/template.html"))) return "site {$websiteId}: no design";
        if ($d['version'] === '') { $v = DesignVersions::stampSite($websiteId, null, 'baseline'); return "site {$websiteId}: baseline {$v}"; }
        $now = DesignVersions::archive($d['slug']);
        $open = DB::table('design_updates')->where('website_id', $websiteId)->where('kind', 'version')->whereIn('status', ['building', 'probing', 'ready', 'held']);   // UPGRADE-OFFER-1: upgrade offers are their own
        if ($now === $d['version']) { (clone $open)->update(['status' => 'superseded', 'updated_at' => now()]); return "site {$websiteId}: up to date"; }
        if (DB::table('design_updates')->where('website_id', $websiteId)->where('kind', 'version')->where('to_version', $now)->exists()) return "site {$websiteId}: {$now} already handled";
        (clone $open)->update(['status' => 'superseded', 'updated_at' => now()]);
        $id = (int) DB::table('design_updates')->insertGetId(['workspace_id' => (int) DB::table('websites')->where('id', $websiteId)->value('workspace_id'), 'website_id' => $websiteId,
            'design_slug' => $d['slug'], 'from_version' => $d['version'], 'to_version' => $now, 'status' => 'building', 'created_at' => now(), 'updated_at' => now()]);
        $r = $this->build($id);
        return "site {$websiteId}: {$d['version']} → {$now} update {$id} " . $r['status'] . (isset($r['reason']) ? ' (' . $r['reason'] . ')' : '');
    }

    // ───────────────────────── build + report ─────────────────────────

    /** Build (or rebuild) the candidate and its report. Never touches the site's own files. */
    public function build(int $updateId, bool $andProbe = true): array
    {
        $u = DB::table('design_updates')->where('id', $updateId)->first(); if (! $u) return ['status' => 'missing'];
        $sid = (int) $u->website_id; $slug = (string) $u->design_slug;
        $hold = function (string $why) use ($updateId) { DB::table('design_updates')->where('id', $updateId)->update(['status' => 'held', 'reason' => mb_substr($why, 0, 250), 'updated_at' => now()]); return ['status' => 'held', 'reason' => $why]; };
        if (! DesignVersions::archived($slug, (string) $u->from_version)) return $hold('the version the site was built on was never archived');
        $root = storage_path("app/public/sites/{$sid}");
        $cur = (string) @file_get_contents("{$root}/index.html"); if ($cur === '') return $hold('no home page on disk');
        $vars = json_decode((string) (DB::table('websites')->where('id', $sid)->value('template_variables') ?: '{}'), true) ?: [];
        try {
            $base = $this->templates->finishForDeploy($sid, $this->templates->renderVersion($slug, (string) $u->from_version, $vars, $sid));
            $new = $this->templates->finishForDeploy($sid, $this->templates->render($slug, $vars, $sid));
        } catch (\Throwable $e) { return $hold('render failed: ' . $e->getMessage()); }
        if (DesignVersions::ofHtml($new) !== (string) $u->to_version) return $hold('the design changed again while building');
        $labels = $this->labels($slug, (string) $u->from_version);
        $m = DesignMerge::merge($cur, $base, $new, $labels);
        if (! $m['ok']) return $hold('merge: ' . $m['why']);
        $cand = $m['html'];
        $dir = self::dir($sid, $updateId); @mkdir($dir, 0775, true);
        if (DesignMerge::values($cand) === DesignMerge::values($cur) && $this->sameLook($cur, $cand)) {   // the site already looks like the new design
            DesignVersions::stampSite($sid, (string) $u->to_version, 'no_change');
            DB::table('design_updates')->where('id', $updateId)->update(['status' => 'no_change', 'updated_at' => now()]);
            return ['status' => 'no_change'];
        }
        $report = $this->report($sid, $cur, $base, $cand, $vars, $m['changes'], $labels);
        $report['current_md5'] = md5($cur); $report['candidate_md5'] = md5($cand);
        $report['note'] = $this->note($slug, (string) $u->to_version);
        file_put_contents("{$dir}/now.html", $cur); file_put_contents("{$dir}/after.html", $cand);
        $status = $report['content']['ok'] ? ($andProbe ? 'probing' : 'ready') : 'held';
        DB::table('design_updates')->where('id', $updateId)->update(['status' => $status, 'reason' => $report['content']['ok'] ? null : 'owner content would be lost',
            'report_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        return ['status' => $status, 'report' => $report];
    }

    /** Head (styles, scripts) and every section the same, ignoring whitespace: nothing for the owner to decide. */
    private function sameLook(string $a, string $b): bool
    {
        $n = fn ($h) => preg_replace(['/<meta name="lu-dv"[^>]*>/', '/\s+/'], ['', ' '], $h);
        return $n($a) === $n($b);
    }

    private function labels(string $slug, string $fromVersion): array
    {
        $out = [];
        foreach ([storage_path("templates/{$slug}/manifest.json"), DesignVersions::archiveDir($slug, $fromVersion) . '/manifest.json'] as $p) {
            $mf = json_decode((string) @file_get_contents($p), true) ?: [];
            foreach ((array) ($mf['blocks'] ?? []) as $b) { if (is_array($b) && ! empty($b['id']) && ! empty($b['name'])) $out[(string) $b['id']] ??= (string) $b['name']; }
        }
        $out += ['nav' => 'Menu bar', 'hero' => 'Top of the page', 'footer' => 'Footer', 'contact' => 'Contact', 'faq' => 'Questions and answers', 'gallery' => 'Gallery', 'testimonials' => 'Reviews'];
        foreach (['added_booking_form' => 'Booking form', 'added_faq' => 'Questions and answers', 'added_pricing' => 'Prices', 'added_events_calendar' => 'Events calendar', 'added_video_embed' => 'Video'] as $k => $v) $out[$k] ??= $v;
        return $out;
    }

    /** "What improves", when the design carries a note for this version. */
    private function note(string $slug, string $version): string
    {
        $mf = json_decode((string) @file_get_contents(storage_path("templates/{$slug}/manifest.json")), true) ?: [];
        return trim((string) ($mf['design_note'] ?? ''));
    }

    private function report(int $sid, string $cur, string $base, string $cand, array $vars, array $changes, array $labels): array
    {
        $vc = DesignMerge::values($cur); $vb = DesignMerge::values($base); $vn = DesignMerge::values($cand);
        // the fields inside a section the new design drops are reported with that section, not as losses
        $dropped = [];
        foreach ($changes as $c) if ($c['kind'] === 'removed_by_design') foreach (DesignMerge::blocks($cur)['list'] as $b) if ($b['id'] === $c['block'] && preg_match_all('/data-field="([a-z0-9_\-]+)"/', $b['html'], $fm)) foreach ($fm[1] as $f) $dropped[$f] = $c['block'];
        $lost = []; $checked = 0; $hiddenOk = true;
        foreach ($vc as $k => $v) {
            if (str_starts_with($k, 'section:')) { if ($v === 'hidden' && isset($vn[$k]) && $vn[$k] !== 'hidden') $hiddenOk = false; continue; }
            $checked++;
            if (($vn[$k] ?? null) === $v || isset($dropped[$k])) continue;
            if (! array_key_exists($k, $vn) && ($vb[$k] ?? null) === $v) continue;   // the design's own sample wording, gone with the design's change
            $lost[] = ['field' => $k, 'value' => mb_substr((string) $v, 0, 120), 'kind' => preg_match('/(image|photo|avatar|img|logo_url)/', $k) ? 'picture' : 'text'];
        }
        foreach ($vars as $k => $v) {
            if (! is_string($v) || ! preg_match('/_colou?r$/', $k) || ! preg_match('/^#[0-9a-f]{3,8}$/i', $v)) continue;
            if (stripos($cur, $v) !== false && stripos($cand, $v) === false) $lost[] = ['field' => $k, 'value' => $v, 'kind' => 'colour'];
        }
        if (! $hiddenOk) $lost[] = ['field' => 'hidden sections', 'value' => 'a section you hid would show again', 'kind' => 'section'];
        // the other pages: those built from the home page's menu and footer get the new ones; their content stays
        $root = storage_path("app/public/sites/{$sid}"); $pages = 0; $other = [];
        foreach (glob("{$root}/*/index.html") ?: [] as $p) { $rel = basename(dirname($p)); if (in_array($rel, ['blog'], true) || str_starts_with($rel, '.')) continue; str_contains((string) file_get_contents($p), '<main data-lu-page=') ? $pages++ : $other[] = $rel; }
        $notes = [];
        if ($pages) $notes[] = ['kind' => 'pages', 'text' => "Your {$pages} other " . ($pages === 1 ? 'page gets' : 'pages get') . ' the new menu and footer. What is on them stays the same.'];
        if ($other) $notes[] = ['kind' => 'pages_kept', 'text' => count($other) . ' ' . (count($other) === 1 ? 'page keeps' : 'pages keep') . ' its current look: ' . implode(', ', array_map(fn ($x) => ucfirst(str_replace('-', ' ', $x)), $other)) . '.'];
        $pending = 0;
        try { if (DraftEdits::on() && DraftEdits::hasLive($sid)) $pending = (int) (DraftEdits::changes($sid)['count'] ?? 0); } catch (\Throwable $e) {}
        if ($pending) $notes[] = ['kind' => 'draft', 'text' => 'You have ' . $pending . ' ' . ($pending === 1 ? 'edit' : 'edits') . ' that visitors do not see yet. After you agree, the new design goes into your draft with them; visitors see it when you publish.'];
        // what the owner made their own, said back to them: edits, photos, added sections, the order they set
        $carried = [];
        try {
            $slug = DesignVersions::ofSite($sid)['slug'];
            $mf = json_decode((string) @file_get_contents(storage_path("templates/{$slug}/manifest.json")), true) ?: [];
            $defs = []; foreach ((array) ($mf['variables'] ?? []) as $k => $spec) $defs[$k] = is_array($spec) ? (string) ($spec['default'] ?? '') : (string) $spec;
            $txt = 0; $pic = 0;
            foreach ($vc as $k => $v) { if (str_starts_with($k, 'section:') || ! array_key_exists($k, $vars) || ! isset($defs[$k]) || (string) $vars[$k] === $defs[$k]) continue; preg_match('/(image|photo|avatar|img|logo_url)/', $k) ? $pic++ : $txt++; }
            $st = json_decode((string) (DB::table('websites')->where('id', $sid)->value('settings_json') ?: '{}'), true) ?: [];
            $added = array_values(array_filter(array_map(fn ($s) => $labels['added_' . ($s['type'] ?? '')] ?? ucwords(str_replace('_', ' ', (string) ($s['type'] ?? ''))), (array) ($st['arthur_sections'] ?? []))));
            if ($txt) $carried[] = $txt . ' ' . ($txt === 1 ? 'text you wrote' : 'texts you wrote');
            if ($pic) $carried[] = $pic . ' ' . ($pic === 1 ? 'photo you chose' : 'photos you chose');
            if ($added) $carried[] = 'the ' . implode(' and ', $added) . ' you added';
            if (! empty($st['section_ops'])) $carried[] = 'the section order you set';
            if (! empty($st['element_ops'])) $carried[] = 'the elements you moved';
            if (! empty($vars['lu_hidden_blocks'])) $carried[] = 'the sections you hid';
        } catch (\Throwable $e) {}
        $last = array_pop($carried);
        $carriedText = $last === null ? '' : 'Carried across as you have them: ' . ($carried ? implode(', ', $carried) . ' and ' : '') . $last . '.';
        return [
            'carried' => $carriedText,
            'changes' => $changes,
            'content' => ['ok' => $lost === [], 'checked' => $checked, 'lost' => $lost,
                'text' => $lost === [] ? "All {$checked} of your words and pictures are carried across, and your colours stay yours." : count($lost) . ' of your words or pictures would not carry across.'],
            'notes' => $notes, 'pending_draft' => $pending,
        ];
    }

    // ───────────────────────── probe ─────────────────────────

    /** Overflow, script errors and text-over-photo contrast of the candidate, against the page the owner has now. */
    public function probe(int $updateId): string
    {
        $u = DB::table('design_updates')->where('id', $updateId)->first(); if (! $u || $u->status !== 'probing') return 'skip';
        $l = sys_getloadavg()[0] ?? 0; if ($l > 4) return 'load';   // 4 cores since 10-06 (EV-1343): the render runner waits above 4 too
        $fh = @fopen(self::LOCK, 'r'); if (! $fh) $fh = @fopen(sys_get_temp_dir() . '/lug-dupd.lock', 'c');
        // the render runner releases the lock between batches: wait for that gap (checked every 2 s, up to 10 min), never start a second Chrome
        $got = false; for ($i = 0; $fh && $i < 300; $i++) { if (flock($fh, LOCK_EX | LOCK_NB)) { $got = true; break; } usleep(2000000); }
        if (! $got) { if ($fh) fclose($fh); return 'busy'; }
        if ((sys_getloadavg()[0] ?? 0) > 4) { flock($fh, LOCK_UN); fclose($fh); return 'load'; }
        try {
            $dir = self::dir((int) $u->website_id, $updateId);
            $job = ['base' => rtrim((string) (config('app.probe_base') ?: 'https://staging.levelupgrowth.io'), '/'), 'out' => "{$dir}/probe.json", 'shots' => $dir,
                'urls' => ['now' => $this->signed($updateId, 'now', 30), 'after' => $this->signed($updateId, 'after', 30)]];
            file_put_contents("{$dir}/probe-job.json", json_encode($job, JSON_UNESCAPED_SLASHES));
            $cmd = 'cd ' . escapeshellarg(base_path()) . ' && HOME=/tmp nice -n 19 ionice -c3 timeout -k 10 300 node scripts/design-update-probe.cjs ' . escapeshellarg("{$dir}/probe-job.json") . ' 2>&1';
            exec($cmd, $outLines, $rc);
            $res = json_decode((string) @file_get_contents("{$dir}/probe.json"), true);
            if (! is_array($res) || $rc !== 0) { Log::warning('[DesignUpdates] probe failed', ['update' => $updateId, 'rc' => $rc, 'out' => implode(' | ', array_slice($outLines, -3))]); return 'error'; }
            $worse = [];
            foreach (['desk', 'phone'] as $m) foreach (['overflow', 'errors', 'contrast', 'narrow'] as $k) {
                $a = (array) ($res['now'][$m][$k] ?? []); $b = (array) ($res['after'][$m][$k] ?? []);
                $new = array_values(array_diff($b, $a)); if (count($b) > count($a) && $new) $worse[] = "{$m} {$k}: " . implode('; ', array_slice($new, 0, 3));
            }
            $report = json_decode((string) $u->report_json, true) ?: [];
            $report['probe'] = ['at' => date('c'), 'now' => $res['now'] ?? [], 'after' => $res['after'] ?? [], 'worse' => $worse];
            DB::table('design_updates')->where('id', $updateId)->update(['status' => $worse ? 'held' : 'ready', 'reason' => $worse ? mb_substr('probe: ' . implode(' | ', $worse), 0, 250) : null,
                'report_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
            return $worse ? 'held' : 'ready';
        } finally { flock($fh, LOCK_UN); fclose($fh); }
    }

    public function signed(int $updateId, string $which, int $minutes = 120): string
    {
        return URL::temporarySignedRoute('design-update.view', now()->addMinutes($minutes), ['id' => $updateId, 'which' => $which], false);
    }

    // ───────────────────────── owner ─────────────────────────

    /** The offers waiting for the owner, as the card and the preview screen show them. */
    public function offers(int $workspaceId): array
    {
        $ver = self::on($workspaceId); $upg = UpgradeOfferService::on($workspaceId);   // UPGRADE-OFFER-1
        if (! $ver && ! $upg) return [];
        try {
            $rows = DB::table('design_updates')->where('workspace_id', $workspaceId)->where('status', 'ready')->orderByDesc('id')->limit(40)->get(['id', 'kind', 'offer_group']);
            $seen = []; $ids = [];
            foreach ($rows as $r) {
                if (($r->kind ?? 'version') === 'upgrade') { if (! $upg || isset($seen[$r->offer_group])) continue; $seen[$r->offer_group] = true; $ids[] = (int) DB::table('design_updates')->where('offer_group', $r->offer_group)->where('status', 'ready')->min('id'); }
                elseif ($ver) $ids[] = (int) $r->id;
                if (count($ids) >= 5) break;
            }
            return collect($ids)->map(fn ($id) => $this->forOwner((int) $id))->filter()->values()->all();
        } catch (\Throwable $e) { return []; }
    }

    public function forOwner(int $updateId): ?array
    {
        $u = DB::table('design_updates')->where('id', $updateId)->first(); if (! $u) return null;
        $site = DB::table('websites')->where('id', $u->website_id)->first(['id', 'name']);
        $r = json_decode((string) $u->report_json, true) ?: [];
        $dir = self::dir((int) $u->website_id, $updateId);
        $shot = fn ($n) => is_file("{$dir}/{$n}.webp") ? $this->signed($updateId, $n) : null;
        return [
            'id' => (int) $u->id, 'status' => (string) $u->status, 'website_id' => (int) $u->website_id, 'website' => (string) ($site->name ?? 'Your website'),
            'note' => (string) ($r['note'] ?? ''), 'changes' => array_values($r['changes'] ?? []), 'notes' => array_values($r['notes'] ?? []),
            'content' => $r['content'] ?? null, 'carried' => (string) ($r['carried'] ?? ''), 'pending_draft' => (int) ($r['pending_draft'] ?? 0),
            'now_url' => $this->signed($updateId, 'now'), 'after_url' => $this->signed($updateId, 'after'),
            // RECHROME-1: every inner page, now and after, so the owner sees the whole site in the new design
            'pages' => array_values(array_map(fn ($slug, $p) => ['slug' => $slug, 'title' => $p['title'], 'now_url' => $this->signed($updateId, 'page-' . $slug . '-now'), 'after_url' => $this->signed($updateId, 'page-' . $slug . '-after')], array_keys($__ip = $this->templates->innerPages((int) $u->website_id)), $__ip)),
            'shot_desk' => $shot('after-desk'), 'shot_phone' => $shot('after-phone'),
            'created_at' => (string) $u->created_at, 'decided_at' => $u->decided_at, 'revert_until' => $u->revert_until,
            'can_revert' => $u->status === 'agreed' && $u->revert_until && now()->lt($u->revert_until),
            // UPGRADE-OFFER-1
            'kind' => (string) ($u->kind ?? 'version'), 'to_label' => (string) ($r['to']['label'] ?? ''), 'no_place' => array_values($r['no_place'] ?? []),
            'choices' => ($u->kind ?? 'version') === 'upgrade' && $u->offer_group ? DB::table('design_updates')->where('offer_group', $u->offer_group)->whereIn('status', ['ready', 'agreed'])->orderBy('id')->get(['id', 'status', 'report_json'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'status' => (string) $c->status, 'label' => (string) ((json_decode((string) $c->report_json, true) ?: [])['to']['label'] ?? 'New look'), 'shot' => is_file(self::dir((int) $u->website_id, (int) $c->id) . '/after-desk.webp') ? $this->signed((int) $c->id, 'after-desk') : null])->values()->all() : [],
        ];
    }

    /** The website's design state for Site settings: an offer waiting, and the latest update that can still be reverted. */
    public function forSite(int $websiteId, int $workspaceId): array
    {
        if (! self::on($workspaceId) && ! UpgradeOfferService::on($workspaceId)) return ['on' => false];   // UPGRADE-OFFER-1
        $offer = DB::table('design_updates')->where('website_id', $websiteId)->where('workspace_id', $workspaceId)->where('status', 'ready')->orderByDesc('id')->value('id');
        $applied = DB::table('design_updates')->where('website_id', $websiteId)->where('workspace_id', $workspaceId)->where('status', 'agreed')->where('revert_until', '>', now())->orderByDesc('id')->value('id');
        return ['on' => true, 'offer' => $offer ? $this->forOwner((int) $offer) : null, 'applied' => $applied ? $this->forOwner((int) $applied) : null];
    }

    public function cancel(int $updateId, int $workspaceId, ?int $userId = null): array
    {
        $g = DB::table('design_updates')->where('id', $updateId)->where('workspace_id', $workspaceId)->where('kind', 'upgrade')->value('offer_group');   // UPGRADE-OFFER-1: every suggestion of the offer
        if ($g) { $n = DB::table('design_updates')->where('offer_group', $g)->where('workspace_id', $workspaceId)->whereIn('status', ['ready', 'probing', 'held', 'building'])->update(['status' => 'cancelled', 'decided_by' => $userId, 'decided_at' => now(), 'updated_at' => now()]);
            return $n ? ['success' => true, 'message' => 'Nothing changed. Your website keeps its current look.'] : ['success' => false, 'message' => 'This offer is no longer waiting for you.']; }
        $n = DB::table('design_updates')->where('id', $updateId)->where('workspace_id', $workspaceId)->where('status', 'ready')
            ->update(['status' => 'cancelled', 'decided_by' => $userId, 'decided_at' => now(), 'updated_at' => now()]);
        return $n ? ['success' => true, 'message' => 'Nothing changed. Your website stays exactly as it is.'] : ['success' => false, 'message' => 'This update is no longer waiting for you.'];
    }

    public function agree(int $updateId, int $workspaceId, ?int $userId = null): array
    {
        $u = DB::table('design_updates')->where('id', $updateId)->where('workspace_id', $workspaceId)->first();
        if (! $u || $u->status !== 'ready') return ['success' => false, 'code' => 'not_waiting', 'message' => 'This update is no longer waiting for you.'];
        if (($u->kind ?? 'version') === 'upgrade') return $this->agreeUpgrade($u, $userId);   // UPGRADE-OFFER-1
        $sid = (int) $u->website_id; $root = storage_path("app/public/sites/{$sid}"); $dir = self::dir($sid, $updateId);
        $r = json_decode((string) $u->report_json, true) ?: [];
        // the site changed since the preview was made: rebuild, and show the owner the fresh preview when the list changed
        if (md5((string) @file_get_contents("{$root}/index.html")) !== ($r['current_md5'] ?? '')) {
            DB::table('design_updates')->where('id', $updateId)->update(['status' => 'building', 'updated_at' => now()]);
            $b = $this->build($updateId, false);
            if (($b['status'] ?? '') !== 'ready') return ['success' => false, 'code' => 'changed', 'message' => 'Your website changed since this preview, and the update could not be prepared again. Nothing changed.'];
            $old = array_column($r['changes'] ?? [], 'text'); $now = array_column($b['report']['changes'] ?? [], 'text');
            if ($old !== $now) return ['success' => false, 'code' => 'changed', 'message' => 'Your website changed since this preview. Here is the fresh preview.', 'update' => $this->forOwner($updateId)];
            $r = $b['report'];
        }
        $cand = (string) @file_get_contents("{$dir}/after.html");
        if ($cand === '' || md5($cand) !== ($r['candidate_md5'] ?? '')) return ['success' => false, 'code' => 'missing', 'message' => 'The preview could not be found. Nothing changed.'];
        $claimed = DB::table('design_updates')->where('id', $updateId)->where('status', 'ready')->update(['status' => 'applying', 'updated_at' => now()]);
        if (! $claimed) return ['success' => false, 'code' => 'not_waiting', 'message' => 'This update is no longer waiting for you.'];
        try {
            $snap = $this->snapshot($sid, "{$dir}/snapshot");
            try { $this->templates->snapshotToHistory($sid, 'design_update'); } catch (\Throwable $e) {}
            $fp = fopen("{$root}/index.html", 'r+'); flock($fp, LOCK_EX); ftruncate($fp, 0); rewind($fp); fwrite($fp, $cand); fflush($fp); flock($fp, LOCK_UN); fclose($fp);
            $this->rechromePages($sid);
            $this->templates->afterHomeWritten($sid, $cand);
            DesignVersions::stampSite($sid, (string) $u->to_version, 'design_update');
            $promoted = false;
            if (($r['pending_draft'] ?? 0) === 0 && DraftEdits::on() && DraftEdits::hasLive($sid)) { $promoted = (bool) (DraftEdits::promote($sid)['promoted'] ?? false); }
            try { \App\Http\Controllers\PublishedSiteController::invalidateCache($sid); } catch (\Throwable $e) {}
            $r['applied'] = ['at' => date('c'), 'files' => $snap['files'], 'promoted' => $promoted, 'home_md5' => md5_file("{$root}/index.html")];
            DB::table('design_updates')->where('id', $updateId)->update(['status' => 'agreed', 'decided_by' => $userId, 'decided_at' => now(), 'revert_until' => now()->addDays(self::REVERT_DAYS),
                'report_json' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
            DB::table('design_updates')->where('website_id', $sid)->where('id', '!=', $updateId)->whereIn('status', ['ready', 'held', 'probing'])->update(['status' => 'superseded', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('[DesignUpdates] agree failed — restoring the snapshot', ['update' => $updateId, 'e' => $e->getMessage()]);
            if (is_dir("{$dir}/snapshot/tree")) $this->restore($sid, "{$dir}/snapshot");
            DB::table('design_updates')->where('id', $updateId)->update(['status' => 'ready', 'updated_at' => now()]);
            return ['success' => false, 'code' => 'failed', 'message' => 'The update could not be applied, so your website was put back exactly as it was.'];
        }
        $until = now()->addDays(self::REVERT_DAYS)->format('j F');
        return ['success' => true, 'promoted' => $promoted, 'revert_until' => $until,
            'message' => ($promoted || ! DraftEdits::hasLive($sid) ? 'Your website has the new design.' : 'The new design is in your draft; visitors see it when you publish.') . " You can revert it from Site settings until {$until}.", 'update' => $this->forOwner($updateId)];
    }

    /**
     * UPGRADE-OFFER-1 (Owner 2026-10-07): Agree on one suggestion of an upgrade offer. Snapshot of every file and of the record
     * (design columns included), the previewed page written byte for byte, the record moved to the new design (its
     * composed content, the old design's section moves dropped, what has no place kept in the site's notes), the other
     * pages given the new menu and footer, the version recorded, Revert for 30 days. Any failure puts the snapshot back.
     */
    private function agreeUpgrade(object $u, ?int $userId): array
    {
        $updateId = (int) $u->id; $sid = (int) $u->website_id; $root = storage_path("app/public/sites/{$sid}"); $dir = self::dir($sid, $updateId);
        $r = json_decode((string) $u->report_json, true) ?: [];
        if (md5((string) @file_get_contents("{$root}/index.html")) !== ($r['current_md5'] ?? '')) {
            DB::table('design_updates')->where('id', $updateId)->update(['status' => 'building', 'updated_at' => now()]);
            $b = app(UpgradeOfferService::class)->build($updateId, false);
            return ['success' => false, 'code' => 'changed', 'message' => ($b['status'] ?? '') === 'ready' ? 'Your website changed since this preview. Here is the fresh preview.' : 'Your website changed since this preview, and the new look could not be prepared again. Nothing changed.', 'update' => $this->forOwner($updateId)];
        }
        $cand = (string) @file_get_contents("{$dir}/after.html");
        $vars = json_decode((string) @file_get_contents("{$dir}/vars.json"), true);
        if ($cand === '' || md5($cand) !== ($r['candidate_md5'] ?? '') || ! is_array($vars)) return ['success' => false, 'code' => 'missing', 'message' => 'The preview could not be found. Nothing changed.'];
        if (! DB::table('design_updates')->where('id', $updateId)->where('status', 'ready')->update(['status' => 'applying', 'updated_at' => now()])) return ['success' => false, 'code' => 'not_waiting', 'message' => 'This offer is no longer waiting for you.'];
        $from = (string) $u->design_slug; $to = (string) $u->to_slug;
        try {
            $snap = $this->snapshot($sid, "{$dir}/snapshot");
            try { $this->templates->snapshotToHistory($sid, 'design_upgrade'); } catch (\Throwable $e) {}
            $fp = fopen("{$root}/index.html", 'r+'); flock($fp, LOCK_EX); ftruncate($fp, 0); rewind($fp); fwrite($fp, $cand); fflush($fp); flock($fp, LOCK_UN); fclose($fp);
            $row = DB::table('websites')->where('id', $sid)->first(['settings_json', 'template_variables']);
            $st = UpgradeOfferService::settingsAfter(json_decode((string) ($row->settings_json ?: '{}'), true) ?: [], $to, $from);
            $old = json_decode((string) ($row->template_variables ?: '{}'), true) ?: [];
            $notes = array_values(array_merge((array) ($old['upgrade_notes'] ?? []), array_map(fn ($p) => ['from_design' => $from, 'field' => $p['field'], 'label' => $p['label'], 'kind' => $p['kind'], 'value' => $p['value'], 'kept_at' => date('c')], (array) ($r['no_place'] ?? []))));
            if ($notes) $vars['upgrade_notes'] = $notes;
            DB::table('websites')->where('id', $sid)->update(['settings_json' => json_encode($st), 'template_industry' => $to, 'template_variables' => json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
            $this->rechromePages($sid);
            $this->templates->afterHomeWritten($sid, $cand);
            DesignVersions::stampSite($sid, DesignVersions::ofHtml($cand) ?: null, 'design_upgrade');
            $promoted = false;
            if (DraftEdits::on() && DraftEdits::hasLive($sid)) { $promoted = (bool) (DraftEdits::promote($sid)['promoted'] ?? false); }
            try { \App\Http\Controllers\PublishedSiteController::invalidateCache($sid); } catch (\Throwable $e) {}
            $r['applied'] = ['at' => date('c'), 'files' => $snap['files'], 'promoted' => $promoted, 'home_md5' => md5_file("{$root}/index.html"), 'from' => $from, 'to' => $to];
            DB::table('design_updates')->where('id', $updateId)->update(['status' => 'agreed', 'decided_by' => $userId, 'decided_at' => now(), 'revert_until' => now()->addDays(self::REVERT_DAYS),
                'report_json' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
            DB::table('design_updates')->where('website_id', $sid)->where('id', '!=', $updateId)->whereIn('status', ['ready', 'held', 'probing', 'building'])->update(['status' => 'superseded', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            Log::error('[DesignUpdates] upgrade agree failed — restoring the snapshot', ['update' => $updateId, 'e' => $e->getMessage()]);
            if (is_dir("{$dir}/snapshot/tree")) $this->restore($sid, "{$dir}/snapshot");
            DB::table('design_updates')->where('id', $updateId)->update(['status' => 'ready', 'updated_at' => now()]);
            return ['success' => false, 'code' => 'failed', 'message' => 'The new look could not be applied, so your website was put back exactly as it was.'];
        }
        $until = now()->addDays(self::REVERT_DAYS)->format('j F');
        return ['success' => true, 'promoted' => $promoted, 'revert_until' => $until,
            'message' => ($promoted || ! DraftEdits::hasLive($sid) ? 'Your website has its new look.' : 'The new look is in your draft; visitors see it when you publish.') . " You can go back from Site settings until {$until}.", 'update' => $this->forOwner($updateId)];
    }

    public function revert(int $updateId, int $workspaceId, ?int $userId = null): array
    {
        $u = DB::table('design_updates')->where('id', $updateId)->where('workspace_id', $workspaceId)->first();
        if (! $u || $u->status !== 'agreed') return ['success' => false, 'message' => 'There is no design update to revert.'];
        if (! $u->revert_until || now()->gte($u->revert_until)) return ['success' => false, 'message' => 'The revert window for this update has closed. Earlier versions are still in Versions.'];
        $sid = (int) $u->website_id; $dir = self::dir($sid, $updateId);
        if (! is_dir("{$dir}/snapshot/tree")) return ['success' => false, 'message' => 'The saved copy could not be found.'];
        try { $this->templates->snapshotToHistory($sid, 'before_design_revert'); } catch (\Throwable $e) {}   // what is live now stays in Versions
        $res = $this->restore($sid, "{$dir}/snapshot");
        try { \App\Http\Controllers\PublishedSiteController::invalidateCache($sid); } catch (\Throwable $e) {}
        $r = json_decode((string) $u->report_json, true) ?: []; $r['reverted'] = ['at' => date('c')] + $res;
        DB::table('design_updates')->where('id', $updateId)->update(['status' => 'reverted', 'reverted_at' => now(), 'report_json' => json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        return ['success' => (bool) $res['identical'], 'identical' => (bool) $res['identical'], 'message' => $res['identical'] ? 'Your website is back to the design it had before the update.' : 'Your website was put back, but some files did not match the saved copy. Our team has been told.'];
    }

    // ───────────────────────── files ─────────────────────────

    /** Every file of the site (draft, live copy, pages, uploads) except its Versions history, plus its record. */
    private function snapshot(int $sid, string $to): array
    {
        $root = storage_path("app/public/sites/{$sid}");
        $this->rmTree($to); @mkdir("{$to}/tree", 0775, true);
        $hashes = [];
        $this->copyTree($root, "{$to}/tree", '', $hashes);
        $row = DB::table('websites')->where('id', $sid)->first(['template_variables', 'settings_json', 'template_industry', 'template']);
        file_put_contents("{$to}/record.json", json_encode(['template_variables' => $row->template_variables, 'settings_json' => $row->settings_json, 'template_industry' => $row->template_industry, 'template' => $row->template]));   // UPGRADE-OFFER-1: + the design columns
        file_put_contents("{$to}/hashes.json", json_encode($hashes));
        return ['files' => count($hashes)];
    }

    /** Put the snapshot back: the same files, the same bytes, nothing extra (Versions history left alone), and the record. */
    private function restore(int $sid, string $from): array
    {
        $root = storage_path("app/public/sites/{$sid}");
        foreach (scandir($root) ?: [] as $e) { if ($e === '.' || $e === '..' || $e === '.history') continue; $p = "{$root}/{$e}"; is_dir($p) ? $this->rmTree($p) : @unlink($p); }
        $h = []; $this->copyTree("{$from}/tree", $root, '', $h);
        $rec = json_decode((string) file_get_contents("{$from}/record.json"), true) ?: [];
        DB::table('websites')->where('id', $sid)->update(['template_variables' => $rec['template_variables'] ?? null, 'settings_json' => $rec['settings_json'] ?? null, 'updated_at' => now()]
            + (array_key_exists('template_industry', $rec) ? ['template_industry' => $rec['template_industry'], 'template' => $rec['template'] ?? null] : []));   // UPGRADE-OFFER-1
        $want = json_decode((string) file_get_contents("{$from}/hashes.json"), true) ?: [];
        $have = []; $this->hashTree($root, '', $have);
        $row = DB::table('websites')->where('id', $sid)->first(['template_variables', 'settings_json', 'template_industry']);
        $recordOk = $row && $row->template_variables === ($rec['template_variables'] ?? null) && $row->settings_json === ($rec['settings_json'] ?? null)
            && (! array_key_exists('template_industry', $rec) || $row->template_industry === $rec['template_industry']);
        ksort($want); ksort($have);
        return ['identical' => $want === $have && $recordOk, 'files' => count($have), 'record' => $recordOk, 'diff' => array_slice(array_keys(array_diff_assoc($want, $have) + array_diff_assoc($have, $want)), 0, 10)];
    }

    /** The other pages built from the home page's chrome get the new menu and footer; their own content is untouched. */
    private function rechromePages(int $sid): void
    {
        $this->templates->rechromePages($sid);   // RECHROME-1: one rebuild for every design change (TemplateService)
    }

    private function copyTree(string $src, string $dst, string $rel, array &$hashes): void
    {
        @mkdir($dst, 0775, true);
        foreach (scandir($src) ?: [] as $e) {
            if ($e === '.' || $e === '..' || ($rel === '' && $e === '.history')) continue;
            $s = "{$src}/{$e}"; $d = "{$dst}/{$e}"; $r = $rel === '' ? $e : "{$rel}/{$e}";
            if (is_link($s)) continue;
            if (is_dir($s)) { $this->copyTree($s, $d, $r, $hashes); continue; }
            if (@copy($s, $d)) { @touch($d, (int) filemtime($s)); $hashes[$r] = sha1_file($d); }
        }
    }

    private function hashTree(string $dir, string $rel, array &$out): void
    {
        foreach (scandir($dir) ?: [] as $e) {
            if ($e === '.' || $e === '..' || ($rel === '' && $e === '.history')) continue;
            $p = "{$dir}/{$e}"; $r = $rel === '' ? $e : "{$rel}/{$e}";
            if (is_link($p)) continue;
            is_dir($p) ? $this->hashTree($p, $r, $out) : $out[$r] = sha1_file($p);
        }
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) return;
        foreach (scandir($dir) ?: [] as $e) { if ($e === '.' || $e === '..') continue; $p = "{$dir}/{$e}"; is_dir($p) && ! is_link($p) ? $this->rmTree($p) : @unlink($p); }
        @rmdir($dir);
    }
}
