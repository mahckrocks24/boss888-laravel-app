<?php

namespace App\Engines\Builder\Services;

use App\Engines\Builder\Support\DesignCatalog;
use App\Engines\Builder\Support\DesignMerge;
use App\Engines\Builder\Support\DesignVersions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * UPGRADE-OFFER-1 (Owner 2026-10-07, "okay go"): a website built on a Classic design is offered the new designs, through the
 * design-updates machinery (DESIGN-UPDATES-1): snapshot, candidate built beside the site, report, Agree / Cancel, Revert for
 * 30 days. Nothing is applied without the owner's Agree.
 *
 *   map       storage/app/upgrade-maps/{old design}.json — every owner field of the old design, the new design's name for it
 *             (most names are shared; ALIASES translate the rest) and, per layout, whether the new design has a place for it
 *   suggest   two or three new designs of the site's industry, different styles, chosen for the industry's character and
 *             the layouts that hold most of THIS site's content (no model call)
 *   build     one design_updates row per suggestion (kind upgrade, one offer_group): the site's content composed into the
 *             new design (ArthurService::composeForUpgrade), finished as a deploy would be (settings as they would be after,
 *             inside a transaction that is rolled back), the page now and after written beside the site
 *   check     every value the owner entered (anything that differs from the old design's default) must be found on the
 *             candidate; what has no place in the new design is listed for the owner in plain words and kept in the site's
 *             notes on Agree — never silently dropped. A value neither found nor listed holds the offer.
 *   offer     only to sites whose industry's new designs are on (activate3.php --go), only in workspaces in storage/app/
 *             upgradeoffer.on, paced (designs:upgrade-offers, a few sites an hour). Bespoke, external and empty sites are never
 *             offered; a cancelled offer is not repeated.
 */
final class UpgradeOfferService
{
    public const SWITCH = 'app/upgradeoffer.on';
    public const MAPS = 'app/upgrade-maps';
    /** A look that would leave more than this share of the owner's content in notes is not offered (it waits for the kit). */
    public const MAX_NO_PLACE = 0.30;

    /** Old field name → new design's name, where they differ. N is any number. */
    public const ALIASES = [
        'proof_N' => 'marq_N',
        'hero_form_title' => 'book_title', 'hero_form_cta' => 'book_cta', 'hero_form_note' => 'book_note',
        'hero_note_value' => 'hero_chip', 'hero_card_value' => 'hero_chip',
        'portfolio_N_title' => 'listing_N_title', 'portfolio_N_image' => 'listing_N_image', 'portfolio_N_price' => 'listing_N_price', 'portfolio_N_location' => 'listing_N_location',
        'credential_N' => 'story_point_N',
        'contact_eyebrow' => 'contact_subtitle', 'hero_note' => 'hero_chip',
        'listings_eyebrow' => 'offers_eyebrow', 'listings_title' => 'offers_title', 'listings_subtitle' => 'offers_subtitle', 'listings_cta' => 'offers_cta',
        'portfolio_eyebrow' => 'gallery_eyebrow', 'portfolio_title' => 'gallery_title',
        // the base designs before the 10-02 remake (most sites built before October stand on these pages)
        'testimonial_N_author' => 'testimonial_N_name', 'testimonial_N_company' => 'testimonial_N_role', 'testimonial_N_condition' => 'testimonial_N_role',
        'testimonial_N_result' => 'testimonial_N_role', 'testimonial_N_event' => 'testimonial_N_role',
        'step_N_text' => 'step_N_body', 'process_N_title' => 'step_N_title', 'process_N_text' => 'step_N_body', 'process_title' => 'steps_title', 'process_eyebrow' => 'steps_eyebrow',
        'services_title' => 'offers_title', 'services_eyebrow' => 'offers_eyebrow', 'services_intro' => 'offers_subtitle',
        'menu_N_title' => 'service_N_title', 'menu_N_price' => 'service_N_price', 'menu_N_desc' => 'service_N_text', 'menu_title' => 'offers_title', 'menu_intro' => 'offers_subtitle',
        'booking_title' => 'book_title', 'booking_intro' => 'book_sub', 'booking_cta' => 'book_cta', 'booking_submit' => 'book_cta',
        'contact_intro' => 'contact_subtitle', 'contact_cta_title' => 'cta_title', 'contact_cta_text' => 'cta_body', 'contact_cta_button' => 'cta_button',
        'footer_tagline' => 'footer_blurb', 'footer_text' => 'footer_blurb',
        'hero_trust_N' => 'marq_N', 'gallery_N_image' => 'gallery_N', 'hero_cta_secondary' => 'hero_cta_2',
        'cta_banner_title' => 'cta_title', 'cta_banner_subtitle' => 'cta_body', 'cta_banner_cta' => 'cta_button', 'cta_banner_eyebrow' => 'cta_eyebrow',
        'story_p1' => 'story_body', 'about_title' => 'story_title', 'about_eyebrow' => 'story_eyebrow', 'about_text' => 'story_body', 'about_body' => 'story_body', 'about_image' => 'story_image',
        'pillar_N_title' => 'story_point_N',
    ];

    /**
     * The design's interface words, not the owner's content: form labels and buttons, footer link lists, icons, link targets,
     * the text logo (the business name) and the blog teasers (the blog engine fills those from real articles). The new look
     * brings its own; the report says so once.
     */
    private const INTERFACE = '/(_label|_placeholder|_submit|_submit_text|_success_message|_success|_icon|_link|_empty|_cta_text)$|^(form_.*|footer_link_\d+|footer_col_\d+_title|footer_logo|logo|blog_.*|nav_\d+|nav_.*)$/';

    /** Where the new design keeps its catalogue under another family: a hotel's rooms, a travel agent's packages, an agent's listings. */
    private const CATALOGUE_FAMILIES = ['room', 'package', 'listing', 'service'];

    /** Fields that are not the owner's content (addresses of the page, derived colours, internal markers). */
    private const TECHNICAL = '/^(canonical_url|og_locale|og_image|primary_deep|robots|lu_.*|section:.*|nav_cta|footer_col_\d+|ink_\d*_?color|.*_alt)$/';

    /** The industry's character: styles in order of fit, by the Classic design's category. */
    private const STYLE_FIT = [
        'health' => ['clinic', 'soft', 'glass', 'coastal', 'mono'], 'hospitality' => ['craft', 'editorial', 'rouge', 'glass', 'luxe', 'coastal'],
        'cafe' => ['craft', 'soft', 'editorial', 'glass'], 'professional' => ['editorial', 'mono', 'glass', 'clinic', 'brutal'],
        'trades' => ['brutal', 'craft', 'mono', 'glass'], 'services' => ['craft', 'glass', 'clinic', 'brutal'],
        'personal_care' => ['soft', 'luxe', 'glass', 'craft'], 'education' => ['soft', 'glass', 'craft', 'clinic'],
        'design' => ['mono', 'editorial', 'luxe', 'glass'], 'retail' => ['brutal', 'editorial', 'craft', 'glass'],
        'fitness' => ['brutal', 'mono', 'glass'], 'media' => ['editorial', 'mono', 'brutal'],
        'property' => ['luxe', 'editorial', 'glass', 'mono'], 'pets' => ['craft', 'soft', 'coastal'],
        'travel' => ['coastal', 'editorial', 'glass', 'luxe'],
    ];

    public function __construct(private TemplateService $templates) {}

    public static ?array $onlyWorkspaces = null;

    public static function workspaces(): ?array
    {
        if (self::$onlyWorkspaces !== null) return self::$onlyWorkspaces;
        $p = storage_path(self::SWITCH); if (! is_file($p)) return [];
        $t = preg_split('/[\s,]+/', trim((string) file_get_contents($p))) ?: [];
        if (in_array('*', $t, true)) return null;
        return array_values(array_filter(array_map('intval', $t)));
    }

    public static function on(int $workspaceId): bool
    {
        $w = self::workspaces(); return $w === null || in_array($workspaceId, $w, true);
    }

    // ───────────────────────── maps ─────────────────────────

    private static function family(string $k): string { return (string) preg_replace('/_\d+(?=_|$)/', '_N', $k); }

    /** The new design's name for an old field ('' = not owner content). */
    public static function targetName(string $k): string
    {
        if (preg_match(self::TECHNICAL, $k)) return '';
        $fam = self::family($k);
        if (isset(self::ALIASES[$fam])) {
            preg_match_all('/_(\d+)(?=_|$)/', $k, $m); $nums = $m[1]; $i = 0;
            return (string) preg_replace_callback('/_N(?=_|$)/', function () use (&$i, $nums) { return '_' . ($nums[$i++] ?? '1'); }, self::ALIASES[$fam]);
        }
        return $k;
    }

    /**
     * The new design's name for an old field, given the new design's fields: the shared or translated name, else the same
     * catalogue item under the family the new design uses (service_2_title → room_2_title in a hotel design). '' = no place.
     */
    public static function resolve(string $k, array $targetVars): string
    {
        $t = self::targetName($k); if ($t === '') return '';
        if ($targetVars === [] || array_key_exists($t, $targetVars)) return $t;
        if (preg_match('/^(service|room|package|listing)_(\d+)_([a-z]+)$/', $t, $m)) foreach (self::CATALOGUE_FAMILIES as $fam) { $c = "{$fam}_{$m[2]}_{$m[3]}"; if (array_key_exists($c, $targetVars)) return $c; }
        return $t;
    }

    /** old name => new name, for the fields that are named differently (in the new design $targetVars, when given). */
    public static function aliasesFor(array $keys, array $targetVars = []): array
    {
        $out = []; foreach ($keys as $k) { $t = self::resolve((string) $k, $targetVars); if ($t !== '' && $t !== $k) $out[(string) $k] = $t; } return $out;
    }

    /** Build (or refresh) the reviewable map of one Classic design. */
    public function writeMap(string $slug): ?array
    {
        $m = $this->templates->getManifest($slug); if (! $m || DesignCatalog::isV3($slug, $m) || DesignCatalog::isV3Artefact($slug, $m)) return null;
        $ind = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($m['industry'] ?? $slug)));
        $layouts = [];
        foreach (glob(storage_path("templates/{$ind}_*_*/manifest.json")) ?: [] as $mf) {
            $s = basename(dirname($mf)); $x = json_decode((string) @file_get_contents($mf), true) ?: [];
            if (! DesignCatalog::isV3($s, $x)) continue; $l = (string) $x['layout']; $layouts[$l] ??= ($x['variables'] ?? []);
        }
        $fields = []; $covered = array_fill_keys(array_keys($layouts), 0); $own = 0;
        foreach (($m['variables'] ?? []) as $k => $spec) {
            $t = self::targetName((string) $k); if ($t === '' || preg_match('/_colou?r$/', (string) $k)) continue; $own++;
            $homes = []; foreach ($layouts as $l => $vars) { $tl = self::resolve((string) $k, $vars); if (isset($vars[$tl])) { $homes[] = $l; $covered[$l]++; } }
            $fields[(string) $k] = ['to' => $t, 'label' => is_array($spec) ? (string) ($spec['label'] ?? '') : '', 'layouts' => $homes];
        }
        $cov = []; foreach ($covered as $l => $n) $cov[$l] = $own ? (int) round(100 * $n / $own) : 100;
        arsort($cov);
        $map = ['design' => $slug, 'industry' => $ind, 'family' => (string) ($m['design']['generator'] ?? 'other'), 'made' => date('c'),
            'aliases' => self::aliasesFor(array_keys($m['variables'] ?? [])), 'owner_fields' => $own, 'coverage_by_layout' => $cov,
            'no_place_anywhere' => array_keys(array_filter($fields, fn ($f) => $f['layouts'] === [])), 'fields' => $fields];
        $dir = storage_path(self::MAPS); @mkdir($dir, 0775, true);
        file_put_contents("{$dir}/{$slug}.json", json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $map;
    }

    // ───────────────────────── who is offered ─────────────────────────

    /** Are this industry's new designs on for this workspace (switched on, or the QA override)? */
    public static function industryOn(string $industry, int $workspaceId): bool
    {
        $list = glob(storage_path("templates/{$industry}_*_*/manifest.json")) ?: [];
        foreach ($list as $mf) {
            $x = json_decode((string) @file_get_contents($mf), true) ?: [];
            if (! DesignCatalog::isV3(basename(dirname($mf)), $x)) continue;
            if (! empty($x['is_active']) || DesignCatalog::previewFor($workspaceId)) return true;
        }
        return false;
    }

    /** Why this site is not offered an upgrade now ('' = it can be). */
    public function ineligible(int $sid): string
    {
        $w = DB::table('websites')->where('id', $sid)->whereNull('deleted_at')->first(['id', 'workspace_id', 'type', 'external_url']);
        if (! $w) return 'no site';
        if (! self::on((int) $w->workspace_id)) return 'workspace not on';
        if ((string) $w->type !== 'template' || ! empty($w->external_url)) return 'not a template site';
        if (! is_file(storage_path("app/public/sites/{$sid}/index.html"))) return 'no page on disk';
        $slug = DesignVersions::ofSite($sid)['slug']; $m = $slug !== '' ? $this->templates->getManifest($slug) : null;
        if (! $m) return 'no design';
        if (! empty($m['bespoke'])) return 'bespoke design';
        if (DesignCatalog::isV3($slug, $m) || DesignCatalog::isV3Artefact($slug, $m)) return 'already on a new design';
        $ind = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($m['industry'] ?? $slug)));
        if (! self::industryOn($ind, (int) $w->workspace_id)) return 'industry not on';
        if (DB::table('design_updates')->where('website_id', $sid)->where('kind', 'upgrade')->whereIn('status', ['building', 'probing', 'ready', 'applying', 'agreed', 'cancelled', 'reverted'])->exists()) return 'already offered';   // a revert is an answer too
        // a held offer (a look with too little room for this site's content) is tried again after a week: the kit grows
        if (DB::table('design_updates')->where('website_id', $sid)->where('kind', 'upgrade')->where('status', 'held')->where('updated_at', '>', now()->subDays(7))->exists()) return 'held recently';
        if ($this->ownerValues($sid, $m) === []) return 'no owner content';
        return '';
    }

    /**
     * Every value the owner entered on this site: the page's fields and the record's, wherever they differ from the old
     * design's default. Colours are checked separately (they become the palette).
     * @return array<string,array{value:string,kind:string,label:string}>
     */
    public function ownerValues(int $sid, ?array $oldManifest = null): array
    {
        $oldManifest = $oldManifest ?? ($this->templates->getManifest(DesignVersions::ofSite($sid)['slug']) ?: []);
        $defs = []; $labels = [];
        foreach ((array) ($oldManifest['variables'] ?? []) as $k => $spec) { $defs[$k] = is_array($spec) ? (string) ($spec['default'] ?? '') : (string) $spec; $labels[$k] = is_array($spec) ? (string) ($spec['label'] ?? '') : ''; }
        $tv = json_decode((string) (DB::table('websites')->where('id', $sid)->value('template_variables') ?: '{}'), true) ?: [];
        $page = DesignMerge::values((string) @file_get_contents(storage_path("app/public/sites/{$sid}/index.html")));
        $n = fn ($v) => trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $v), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
        $out = [];
        // the page decides what the owner has (visitors see it); a link's value is its address, kept in the record
        foreach ($page as $k => $v) if (preg_match('/^social_/', (string) $k)) unset($page[$k]);
        foreach ([$tv, $page] as $si => $src) foreach ($src as $k => $v) {
            $k = (string) $k;
            if ($si === 1) unset($out[$k]);
            if (! is_scalar($v) || preg_match(self::TECHNICAL, $k) || preg_match(self::INTERFACE, $k) || preg_match('/_colou?r$/', $k) || str_starts_with($k, 'added_')) continue;
            $val = $n($v); if ($val === '' || str_starts_with($val, '{{')) continue;
            if (array_key_exists($k, $defs) && ($n($defs[$k]) === $val || str_contains($defs[$k], '{{'))) continue;   // the design's own sample wording
            if (! array_key_exists($k, $defs) && ! array_key_exists($k, $page)) continue;   // a record key the old design never showed
            $pic = (bool) preg_match('/(image|photo|avatar|img|logo_url|logo)$/', $k) || (bool) preg_match('/_image_/', $k) || (bool) preg_match('~^(/storage/|https?://\S+\.(jpe?g|png|webp|gif|avif)(\?|$))~i', $val) || (bool) preg_match('~\.(jpe?g|png|webp|gif|avif)(\?.*)?$~i', $val);
            if (str_starts_with($k, 'social_')) $pic = false;
            if ($pic && str_contains($val, 'width%3D%221%22')) continue;
            $out[$k] = ['value' => $val, 'kind' => $pic ? 'picture' : (str_starts_with($k, 'social_') ? 'link' : 'text'), 'label' => $labels[$k] ?? ''];
        }
        unset($out['business_name']);   // always carried (the record's name)
        return $out;
    }

    // ───────────────────────── suggest ─────────────────────────

    /** @return string[] up to $n new designs of the industry, different styles, best fit first */
    public function suggest(int $sid, int $n = 3, bool $evenInactive = false): array
    {
        $slug = DesignVersions::ofSite($sid)['slug']; $m = $this->templates->getManifest($slug) ?: [];
        $ws = (int) DB::table('websites')->where('id', $sid)->value('workspace_id');
        $ind = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($m['industry'] ?? $slug)));
        $ownKeys = array_keys($this->ownerValues($sid, $m));
        $dark = (string) ($m['palette_scheme'] ?? '') === 'dark';
        $hint = isset($m['variables']['hero_form_title']) || isset($m['variables']['hero_form_cta']) ? 'booking' : (isset($m['variables']['listing_1_title']) ? 'showcase' : 'split');
        $fit = self::STYLE_FIT[(string) ($m['category'] ?? '')] ?? ['glass', 'editorial', 'craft'];
        if ($dark) $fit = array_merge(['rouge', 'luxe'], $fit);
        $fit = array_values(array_unique(array_merge($fit, array_keys(DesignCatalog::STYLES))));
        $cand = [];
        foreach (glob(storage_path("templates/{$ind}_*_*/manifest.json")) ?: [] as $mf) {
            $s = basename(dirname($mf)); $x = json_decode((string) @file_get_contents($mf), true) ?: [];
            if (! DesignCatalog::isV3($s, $x)) continue;
            if (! $evenInactive && array_key_exists('is_active', $x) && ! $x['is_active'] && ! DesignCatalog::previewFor($ws)) continue;   // the coverage scan looks ahead
            $vars = $x['variables'] ?? []; $hit = 0; foreach ($ownKeys as $k) { $t = self::resolve($k, $vars); if ($t !== '' && array_key_exists($t, $vars)) $hit++; }
            $layout = (string) $x['layout'];
            $score = $hit * 10 + ($layout === $hint ? 4 : 0) + ($layout === 'split' ? 2 : 0) + ($layout === 'cinematic' ? 1 : 0) - ($layout === 'minimal' ? 6 : 0);
            $cand[(string) $x['style']][] = ['slug' => $s, 'score' => $score, 'layout' => $layout];
        }
        $out = []; $used = [];
        foreach ($fit as $style) {
            if (! isset($cand[$style])) continue;
            $adj = fn ($c) => $c['score'] - (in_array($c['layout'], $used, true) ? 3 : 0);
            usort($cand[$style], fn ($a, $b) => ($adj($b) <=> $adj($a)) ?: strcmp($a['slug'], $b['slug']));
            $out[] = $cand[$style][0]['slug']; $used[] = $cand[$style][0]['layout'];
            if (count($out) >= $n) break;
        }
        return $out;
    }

    // ───────────────────────── build ─────────────────────────

    /** Create the offer for one site: one row per suggestion, candidates built and checked. @return array<int,array> */
    public function offer(int $sid, bool $force = false): array
    {
        if (! $force && ($why = $this->ineligible($sid)) !== '') return [['site' => $sid, 'status' => 'skipped', 'reason' => $why]];
        $w = DB::table('websites')->where('id', $sid)->first(['workspace_id']); $d = DesignVersions::ofSite($sid);
        if ($d['version'] === '') $d['version'] = (string) DesignVersions::stampSite($sid, null, 'baseline');
        $group = 'upg-' . $sid . '-' . date('YmdHis');
        $out = [];
        foreach ($this->suggest($sid) as $to) {
            $id = (int) DB::table('design_updates')->insertGetId(['workspace_id' => (int) $w->workspace_id, 'website_id' => $sid, 'kind' => 'upgrade',
                'design_slug' => $d['slug'], 'to_slug' => $to, 'offer_group' => $group, 'from_version' => substr($d['version'], 0, 16),
                'to_version' => substr(DesignVersions::current($to) ?: 'upgrade', 0, 16), 'status' => 'building', 'created_at' => now(), 'updated_at' => now()]);
            $out[] = ['site' => $sid, 'update' => $id, 'to' => $to] + $this->build($id);
        }
        return $out ?: [['site' => $sid, 'status' => 'skipped', 'reason' => 'no new design to suggest']];
    }

    /** Build (or rebuild) one suggestion's candidate and report. Never touches the site's own files or record. */
    public function build(int $updateId, bool $andProbe = true): array
    {
        $u = DB::table('design_updates')->where('id', $updateId)->first(); if (! $u || ($u->kind ?? '') !== 'upgrade') return ['status' => 'missing'];
        $sid = (int) $u->website_id; $to = (string) $u->to_slug; $ws = (int) $u->workspace_id;
        $hold = function (string $why) use ($updateId) { DB::table('design_updates')->where('id', $updateId)->update(['status' => 'held', 'reason' => mb_substr($why, 0, 250), 'updated_at' => now()]); return ['status' => 'held', 'reason' => $why]; };
        $cur = (string) @file_get_contents(storage_path("app/public/sites/{$sid}/index.html")); if ($cur === '') return $hold('no home page on disk');
        $oldM = $this->templates->getManifest((string) $u->design_slug) ?: [];
        $newM = $this->templates->getManifest($to); if (! $newM) return $hold('the new design is missing');
        $aliases = self::aliasesFor(array_merge(array_keys($oldM['variables'] ?? []), array_keys(DesignMerge::values($cur))), $newM['variables'] ?? []);
        try {
            $c = app(ArthurService::class)->composeForUpgrade($ws, $sid, $to, $aliases);
            $vars = $c['variables']; unset($vars['lu_hidden_blocks']);
            $cand = $this->finishAsAfter($sid, $to, $vars, $c['html']);
        } catch (\Throwable $e) { Log::warning('[Upgrade] build failed', ['update' => $updateId, 'e' => $e->getMessage()]); return $hold('render failed: ' . $e->getMessage()); }
        $report = $this->report($sid, $cur, $cand, $oldM, $vars);
        $report['current_md5'] = md5($cur); $report['candidate_md5'] = md5($cand); $report['note'] = '';
        $report['to'] = ['slug' => $to, 'style' => (string) ($newM['style'] ?? ''), 'layout' => (string) ($newM['layout'] ?? ''), 'label' => self::label($to, $newM)];
        $dir = DesignUpdateService::dir($sid, $updateId); @mkdir($dir, 0775, true);
        file_put_contents("{$dir}/now.html", $cur); file_put_contents("{$dir}/after.html", $cand);
        file_put_contents("{$dir}/vars.json", json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $ok = $report['content']['ok'];
        $share = $report['content']['checked'] ? count($report['no_place']) / $report['content']['checked'] : 0.0;
        $why = ! $ok ? 'owner content unaccounted for' : ($share > self::MAX_NO_PLACE ? sprintf('%d%% of the owner content has no place in this look', (int) round(100 * $share)) : null);
        $status = $why === null ? ($andProbe ? 'probing' : 'ready') : 'held';
        DB::table('design_updates')->where('id', $updateId)->update(['status' => $status, 'reason' => $why,
            'to_version' => substr(DesignVersions::ofHtml($cand) ?: (string) $u->to_version, 0, 16),
            'report_json' => json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'updated_at' => now()]);
        return ['status' => $status, 'report' => $report];
    }

    public static function label(string $slug, ?array $m = null): string
    {
        $m = $m ?? (json_decode((string) @file_get_contents(storage_path("templates/{$slug}/manifest.json")), true) ?: []);
        $st = DesignCatalog::STYLES[$m['style'] ?? ''][0] ?? ucfirst((string) ($m['style'] ?? ''));
        $ly = DesignCatalog::LAYOUTS[$m['layout'] ?? ''][0] ?? ucfirst((string) ($m['layout'] ?? ''));
        return trim($st . ' · ' . $ly, ' ·');
    }

    /**
     * The candidate exactly as a deploy would finish it AFTER the switch: the site's settings and record as Agree will
     * write them (new design, no remembered moves of the old design's sections), set inside a transaction that is rolled
     * back — so the finish reads them, and nothing of the site changes.
     */
    private function finishAsAfter(int $sid, string $to, array $vars, string $html): string
    {
        DB::beginTransaction();
        try {
            $row = DB::table('websites')->where('id', $sid)->first(['settings_json']);
            $st = self::settingsAfter(json_decode((string) ($row->settings_json ?: '{}'), true) ?: [], $to, '');
            DB::table('websites')->where('id', $sid)->update(['settings_json' => json_encode($st), 'template_industry' => $to, 'template_variables' => json_encode($vars, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            return $this->templates->finishForDeploy($sid, $html);
        } finally { DB::rollBack(); }
    }

    /** The site's settings once it stands on $to (also used by Agree). */
    public static function settingsAfter(array $st, string $to, string $from): array
    {
        if ($from !== '') { $st['layout_previous'] = $from; $st['layout_switched_at'] = now()->toIso8601String(); }
        $st['template'] = $to; unset($st['design'], $st['section_ops'], $st['element_ops']);
        return $st;
    }

    private function report(int $sid, string $cur, string $cand, array $oldM, array $vars): array
    {
        $own = $this->ownerValues($sid, $oldM);
        $norm = fn ($h) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', (string) $h)), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
        $candText = $norm($cand); $candVals = DesignMerge::values($cand);
        $found = 0; $noPlace = [];
        foreach ($own as $k => $o) {
            $v = $o['value'];
            $hit = $o['kind'] !== 'text' ? (str_contains($cand, $v) || str_contains($cand, htmlspecialchars($v, ENT_QUOTES))) : str_contains($candText, mb_substr($norm($v), 0, 200));
            if (! $hit) foreach ($candVals as $cv) { if (is_string($cv) && $norm($cv) === $norm($v)) { $hit = true; break; } }
            if ($hit) { $found++; continue; }
            $noPlace[] = ['field' => $k, 'kind' => $o['kind'], 'label' => self::human($k, $o['label']), 'value' => mb_substr($v, 0, 160)];
        }
        $lostColours = [];
        foreach ($vars as $k => $v) { if (is_string($v) && preg_match('/^(primary|secondary|accent)_colou?r$/', $k) && preg_match('/^#[0-9a-f]{3,8}$/i', $v) && stripos($cur, $v) !== false && stripos($cand, $v) === false) $lostColours[] = $k; }
        $tv = json_decode((string) (DB::table('websites')->where('id', $sid)->value('template_variables') ?: '{}'), true) ?: [];
        $st = json_decode((string) (DB::table('websites')->where('id', $sid)->value('settings_json') ?: '{}'), true) ?: [];
        $changes = [];
        $groups = [];
        foreach ($noPlace as $p) { $g = preg_match('/^([a-z]+)_\d+/', $p['field'], $gm) ? $gm[1] : $p['field']; $groups[$g][] = $p; }
        foreach ($groups as $g => $list) {
            if (count($list) === 1) { $p = $list[0]; $changes[] = ['kind' => 'no_place', 'block' => '', 'text' => ($p['kind'] === 'picture' ? 'Your photo for “' . $p['label'] . '”' : ($p['kind'] === 'link' ? 'Your ' . $p['label'] . ' (' . $p['value'] . ')' : 'Your “' . $p['label'] . '”: “' . $p['value'] . (mb_strlen($p['value']) >= 160 ? '…' : '') . '”')) . ' has no place in this look. We keep it in your website notes, so nothing is lost.']; continue; }
            $pics = count(array_filter($list, fn ($x) => $x['kind'] === 'picture')); $txts = count($list) - $pics;
            $what = trim(($txts ? $txts . ' ' . ($txts === 1 ? 'text' : 'texts') : '') . ($txts && $pics ? ' and ' : '') . ($pics ? $pics . ' ' . ($pics === 1 ? 'photo' : 'photos') : ''));
            $eg = ''; foreach ($list as $x) if ($x['kind'] === 'text') { $eg = ', such as “' . mb_substr($x['value'], 0, 60) . (mb_strlen($x['value']) > 60 ? '…' : '') . '”'; break; }
            $changes[] = ['kind' => 'no_place', 'block' => '', 'text' => 'Your “' . ucfirst(str_replace('_', ' ', $g)) . '” section (' . $what . $eg . ') has no place in this look. We keep it all in your website notes, so nothing is lost.'];
        }
        foreach ($lostColours as $k) $changes[] = ['kind' => 'no_place', 'block' => '', 'text' => 'Your ' . str_replace('_', ' ', (string) preg_replace('/_colou?r$/', '', $k)) . ' colour is not used by this design. You can choose it again under Colours.'];
        $added = array_values(array_filter(array_map(fn ($s) => ucwords(str_replace('_', ' ', (string) ($s['type'] ?? ''))), (array) ($st['arthur_sections'] ?? []))));
        if ($added) $changes[] = ['kind' => 'added_kept', 'block' => 'added_' . (string) ($st['arthur_sections'][0]['type'] ?? ''), 'text' => 'The ' . implode(' and ', $added) . ' you added ' . (count($added) === 1 ? 'comes' : 'come') . ' with you.'];
        $notes = [['kind' => 'interface', 'text' => 'Buttons, form labels and the menu use the new look’s own wording. You can change any of them after you switch.']];
        if (! empty($st['section_ops']) || ! empty($st['element_ops'])) $notes[] = ['kind' => 'order', 'text' => 'The new look has its own order of sections. You can move them again after you switch.'];
        if (! empty($tv['lu_hidden_blocks'])) $notes[] = ['kind' => 'hidden', 'text' => 'Sections you hid are not carried over; the new look shows its own. You can hide any of them after you switch.'];
        $pages = 0;
        foreach (glob(storage_path("app/public/sites/{$sid}") . '/*/index.html') ?: [] as $p) { $rel = basename(dirname($p)); if ($rel === 'blog' || str_starts_with($rel, '.')) continue; if (str_contains((string) file_get_contents($p), '<main data-lu-page=')) $pages++; }
        if ($pages) $notes[] = ['kind' => 'pages', 'text' => "Your {$pages} other " . ($pages === 1 ? 'page gets' : 'pages get') . ' the new menu and footer. What is on them stays the same.'];
        $txt = count(array_filter($own, fn ($o) => $o['kind'] === 'text')); $pic = count($own) - $txt;
        $carried = 'Your business name' . ($txt ? ', ' . $txt . ' ' . ($txt === 1 ? 'text you wrote' : 'texts you wrote') : '') . ($pic ? ', ' . $pic . ' ' . ($pic === 1 ? 'photo you chose' : 'photos you chose') : '') . ' and your colours move into the new look' . ($noPlace ? ', except what is listed below.' : '.');
        $checked = count($own);
        return [
            'kind' => 'upgrade', 'carried' => $carried, 'changes' => $changes, 'notes' => $notes, 'pending_draft' => 0, 'no_place' => $noPlace,
            'content' => ['ok' => $found + count($noPlace) === $checked, 'checked' => $checked, 'found' => $found, 'lost' => [],
                'text' => $noPlace === [] ? "All {$checked} of your words and pictures have a place in this design." : ($found . ' of your ' . $checked . ' words and pictures have a place in this design; ' . count($noPlace) . ' ' . (count($noPlace) === 1 ? 'is' : 'are') . ' kept in your notes.')],
        ];
    }

    /** A field's name in the owner's words. */
    private static function human(string $k, string $label): string
    {
        if ($label !== '' && ! preg_match('/^[a-z0-9_]+$/', $label)) return $label;
        $k = (string) preg_replace(['/^social_x$/', '/^social_linkedin$/', '/^proof_(\d+)$/', '/^footer_blurb$/'], ['X (Twitter) link', 'LinkedIn link', 'highlight $1', 'footer text'], $k);
        return ucfirst(trim(str_replace('_', ' ', $k)));
    }

    // ───────────────────────── paced run ─────────────────────────

    /** The paced pass: a few eligible sites per run, most recently changed first; then the browser check of what is waiting. */
    public function run(int $max = 2, bool $probe = true): array
    {
        $log = []; $ws = self::workspaces();
        if ($ws === []) return ['off: no workspace in ' . self::SWITCH];
        if ((sys_getloadavg()[0] ?? 0) > 6) return ['load high: skipped'];   // building is light (no browser); the browser check keeps its own gate
        $q = DB::table('websites')->whereNull('deleted_at')->where('type', 'template'); if ($ws !== null) $q->whereIn('workspace_id', $ws);
        $made = 0;
        // customers first; the QA override's workspaces after them
        $ids = $q->orderByDesc('updated_at')->limit(400)->get(['id', 'workspace_id'])->sortBy(fn ($w) => DesignCatalog::previewFor((int) $w->workspace_id) ? 1 : 0)->pluck('id');
        foreach ($ids as $sid) {
            if ($made >= $max) break;
            if ($this->ineligible((int) $sid) !== '') continue;
            foreach ($this->offer((int) $sid) as $r) $log[] = "site {$sid}: " . ($r['update'] ?? '-') . ' ' . ($r['to'] ?? '') . ' ' . ($r['status'] ?? '') . (isset($r['reason']) ? ' (' . $r['reason'] . ')' : '');
            $made++;
        }
        if ($probe) {
            $svc = app(DesignUpdateService::class);
            $wait = DB::table('design_updates')->where('kind', 'upgrade')->where('status', 'probing')->orderBy('id')->limit(200)->get(['id', 'workspace_id'])
                ->sortBy(fn ($r) => DesignCatalog::previewFor((int) $r->workspace_id) ? 1 : 0)->take(6)->pluck('id');   // customers' looks first
            foreach ($wait as $id) { $r = $svc->probe((int) $id); $log[] = "update {$id}: probe {$r}"; if (in_array($r, ['busy', 'load'], true)) break; }
        }
        return $log ?: ['nothing to offer'];
    }
}
