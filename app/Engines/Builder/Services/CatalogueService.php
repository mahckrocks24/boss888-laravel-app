<?php

namespace App\Engines\Builder\Services;

use App\Engines\Builder\Support\CatalogueKinds;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * CATALOGUE888 — one catalogue engine, many kinds (DEC-0049, 2026-09-14; grown out of the DEC-0048 listings engine).
 *
 * A website carries a catalogue of a KIND (listing, service, menu …) when its design declares one in the manifest
 * (`catalogues: [{kind, family, home_block, slots, closed?}]`, or the older single `catalogue`) or when the design
 * simply has the kind's variable family (service_1_title …) — derived, no manifest edit needed. Everything else
 * about a kind is data in CatalogueKinds. The customer can switch a kind off per site.
 *
 * Rows live in catalogue_items; the deployed site is a PROJECTION: the home block's family_N_* slots (and a closed
 * row such as the realtor's sold portfolio), hide rules for unfilled slots (re-applied on every render), an index
 * page and — for kinds that want them — one page per item, each with an enquiry form into the workspace CRM, and a
 * menu link. Facts are never invented: no stated price → "Price on request"; an outcome note is only what the
 * customer typed.
 */
class CatalogueService
{
    public const PLACEHOLDER = '/storage/template-images/listing-placeholder.svg';
    private const SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ', 'PHP' => '₱', 'CAD' => 'C$', 'AUD' => 'A$', 'SGD' => 'S$', 'INR' => '₹', 'ZAR' => 'R', 'NZD' => 'NZ$', 'CHF' => 'CHF ', 'SAR' => 'SAR ', 'QAR' => 'QAR '];
    /** Words that never name an item: verbs, articles, status words and the generic nouns of every kind. */
    private const STOP = ['the', 'a', 'an', 'and', 'or', 'of', 'in', 'at', 'on', 'to', 'for', 'as', 'is', 'it', 'that', 'this', 'mark', 'set', 'flag', 'sold', 'let', 'under', 'offer', 'remove', 'delete', 'price', 'change', 'update', 'please', 'take', 'down', 'withdrawn', 'reduce', 'lower', 'raise', 'drop', 'with', 'from', 'our', 'my', 'now', 'has', 'been', 'was', 'rename', 'call', 'hide', 'out', 'available', 'again', 'back', 'full', 'reserved', 'show', 'reprice', 'cut', 'increase',
        'listing', 'listings', 'property', 'properties', 'house', 'home', 'apartment', 'flat', 'villa', 'condo', 'service', 'services', 'item', 'items', 'dish', 'dishes', 'treatment', 'treatments', 'product', 'products', 'course', 'courses', 'room', 'rooms', 'suite', 'package', 'packages', 'plan', 'plans', 'membership', 'memberships', 'tier', 'session', 'sessions', 'class', 'classes', 'programme', 'programmes', 'program', 'programs', 'project', 'projects', 'case', 'study', 'studies', 'event', 'events', 'vehicle', 'vehicles', 'car', 'cars', 'menu', 'one', 'first', 'second', 'third', 'last'];

    public function __construct(private TemplateService $templates) {}

    /** Credits charged during the current public call. OWNER RULE 2026-09-15: only Arthur's work is priced — the panel is free. */
    private int $charged = 0;
    private bool $viaArthur = false;

    private function affordOrFail(int $wsId): ?array
    {
        if (! $this->viaArthur) return null;
        return \App\Engines\Builder\Support\EditorCredits::canAfford($wsId, 'catalogue') ? null : $this->fail('NO_CREDITS', \App\Engines\Builder\Support\EditorCredits::refusal('catalogue'));
    }

    private function chargeItem(int $wsId, int $websiteId, string $what, int $itemId): void
    {
        if (! $this->viaArthur) return;
        $this->charged += \App\Engines\Builder\Support\EditorCredits::charge($wsId, 'catalogue', $websiteId, ['what' => $what, 'item' => $itemId]);
    }

    /** Attach what this call cost to a result that succeeded. */
    private function priced(array $res): array
    {
        if (! empty($res['success']) && $this->charged > 0) { $res['credits'] = $this->charged; $res['message'] = rtrim((string) ($res['message'] ?? ''), ' .') . '.' . \App\Engines\Builder\Support\EditorCredits::suffix($this->charged); }
        $this->charged = 0;
        return $res;
    }

    // ───────────────────────────── declarations ─────────────────────────────

    private function site(int $websiteId): ?object
    {
        return DB::table('websites')->where('id', $websiteId)->whereNull('deleted_at')->first();
    }

    private function owned(int $wsId, int $websiteId): ?object
    {
        $site = $this->site($websiteId);
        return ($site && (int) $site->workspace_id === $wsId) ? $site : null;
    }

    private function designOf(object $site, array $settings): string
    {
        foreach ([(string) ($settings['template'] ?? ''), (string) ($settings['industry'] ?? ''), (string) ($site->template_industry ?? ''), (string) ($site->template ?? '')] as $cand) {
            $cand = preg_replace('/[^a-z0-9_]/', '', strtolower($cand));
            if ($cand !== '' && is_file(storage_path("templates/{$cand}/manifest.json"))) { return $cand; }
        }
        return '';
    }

    /**
     * SGTRAVEL CAT-1 (2026-09-21) — renderer-path sites (settings_json.theme + pages.sections_json) have no template design
     * or variable slots; they declare the kinds they carry in settings_json.catalogue = {kind: {enabled, currency, slots}}. The
     * theme reads catalogue_items at render time, so there is nothing to project — sync() only clears the page cache.
     */
    private function rendererSpecs(object $site, array $settings): array
    {
        if (empty($settings['theme']) || ! is_array($settings['catalogue'] ?? null)) return [];
        $out = [];
        foreach ($settings['catalogue'] as $kind => $ls) {
            $def = CatalogueKinds::get((string) $kind); if (! $def || ! is_array($ls)) continue;
            $labels = CatalogueKinds::labels((string) $kind, (string) ($site->template_industry ?? ''));
            $out[$kind] = [
                'kind' => $kind, 'design' => 'renderer:' . $settings['theme'], 'industry' => (string) ($site->template_industry ?? ''), 'family' => $def['family'], 'renderer' => true,
                'label' => $labels['plural'], 'singular' => $labels['singular'], 'page_slug' => (string) ($ls['page_slug'] ?? $labels['page_slug']), 'nouns' => $labels['nouns'],
                'detail_prefix' => $def['detail_prefix'], 'pages' => 'index', 'cta' => $def['cta'], 'enquiry_source' => $def['enquiry_source'],
                'home_block' => '', 'slots' => max(1, (int) ($ls['slots'] ?? 3)), 'suffixes' => ['title', 'text', 'price', 'image', 'badge', 'cta'], 'style' => 'card',
                'closed' => null, 'closed_label' => $def['closed_label'],
                'statuses' => $def['statuses'], 'open' => $def['open'], 'closed_statuses' => $def['closed'], 'default_status' => $def['default_status'],
                'attrs' => $def['attrs'], 'title_from' => (array) ($def['title_from'] ?? []),
                'enabled' => ! (isset($ls['enabled']) && $ls['enabled'] === false), 'seeded_at' => $ls['seeded_at'] ?? now()->toDateTimeString(),
                'currency' => (string) ($ls['currency'] ?? 'USD'),
            ];
        }
        return $out;
    }

    /** Every catalogue this website's design carries, keyed by kind, with the per-site switch folded in. */
    public function specs(int $websiteId, ?object $site = null): array
    {
        $site = $site ?: $this->site($websiteId);
        if (! $site) return [];
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $design = $this->designOf($site, $settings);
        if ($design === '') return $this->rendererSpecs($site, $settings); // SGTRAVEL CAT-1 renderer specs
        $manifest = json_decode((string) @file_get_contents(storage_path("templates/{$design}/manifest.json")), true) ?: [];
        $vars = is_array($manifest['variables'] ?? null) ? $manifest['variables'] : [];
        $industry = (string) ($manifest['industry'] ?? '');
        $tplHtml = null;
        $declared = [];
        foreach ((array) ($manifest['catalogues'] ?? []) as $d) { if (is_array($d) && ! empty($d['kind'])) $declared[(string) $d['kind']] = $d; }
        if (is_array($manifest['catalogue'] ?? null) && ! empty($manifest['catalogue']['kind'])) {
            $d = $manifest['catalogue'];
            $kind = (string) $d['kind'] === 'listings' ? 'listing' : (string) $d['kind'];
            $declared[$kind] = $declared[$kind] ?? ['kind' => $kind, 'family' => 'listing', 'home_block' => $d['home_block'] ?? 'listings', 'slots' => $d['slots'] ?? 6,
                'closed' => ! empty($d['sold_block']) ? ['family' => 'portfolio', 'block' => $d['sold_block'], 'slots' => (int) ($d['sold_slots'] ?? 0)] : null];
        }
        $out = [];
        $claimed = [];   // families already taken by an earlier kind (as its slots or its closed row): the realtor's portfolio is the listing's sold row, not a project list
        foreach (CatalogueKinds::KINDS as $kind => $def) {
            $candidates = isset($declared[$kind]['family']) ? [(string) $declared[$kind]['family']] : (array) ($def['families'] ?? [$def['family']]);
            $family = ''; $slots = 0;
            foreach ($candidates as $cand) {
                if (in_array($cand, $claimed, true)) continue;
                $n = 0;
                foreach ($vars as $k => $_) { if (preg_match('/^' . preg_quote($cand, '/') . '_(\d+)_/', (string) $k, $m)) $n = max($n, (int) $m[1]); }
                if ($n > 0) { $family = $cand; $slots = $n; break; }
            }
            if ($slots === 0) continue;                       // the design has no such family
            $claimed[] = $family;
            $d = $declared[$kind] ?? null;
            $homeBlock = (string) ($d['home_block'] ?? '');
            if ($homeBlock === '') {
                $tplHtml = $tplHtml ?? (string) @file_get_contents(storage_path("templates/{$design}/template.html"));
                $homeBlock = $this->enclosingBlock($tplHtml, $family) ?: $family . 's';
            }
            $suffixes = [];
            foreach ($vars as $k => $_) { if (preg_match('/^' . preg_quote($family, '/') . '_1_([a-z_]+)$/', (string) $k, $m)) $suffixes[] = $m[1]; }
            $closed = null;
            $closedFamily = (string) ($d['closed']['family'] ?? ($def['closed'] !== [] ? $def['closed_family_default'] : ''));
            if ($closedFamily !== '') {
                $cs = 0; $csuf = [];
                foreach ($vars as $k => $_) { if (preg_match('/^' . preg_quote($closedFamily, '/') . '_(\d+)_([a-z_]+)$/', (string) $k, $m)) { $cs = max($cs, (int) $m[1]); if ((int) $m[1] === 1) $csuf[] = $m[2]; } }
                if ($cs > 0) {
                    $tplHtml = $tplHtml ?? (string) @file_get_contents(storage_path("templates/{$design}/template.html"));
                    $closed = ['family' => $closedFamily, 'slots' => (int) ($d['closed']['slots'] ?? $cs), 'block' => (string) ($d['closed']['block'] ?? ($this->enclosingBlock($tplHtml, $closedFamily) ?: $closedFamily)), 'suffixes' => $csuf];
                    $claimed[] = $closedFamily;
                }
            }
            $labels = CatalogueKinds::labels($kind, $industry);
            $ls = (array) ($settings['catalogue'][$kind] ?? ($kind === 'listing' ? ($settings['listings'] ?? []) : []));
            $out[$kind] = [
                'kind' => $kind, 'design' => $design, 'industry' => $industry, 'family' => $family,
                'label' => $labels['plural'], 'singular' => $labels['singular'], 'page_slug' => $labels['page_slug'], 'nouns' => $labels['nouns'],
                'detail_prefix' => $def['detail_prefix'], 'pages' => $def['pages'], 'cta' => $def['cta'], 'enquiry_source' => $def['enquiry_source'],
                'home_block' => $homeBlock, 'slots' => (int) ($d['slots'] ?? $slots), 'suffixes' => $suffixes,
                'style' => in_array('area', $suffixes, true) && ! in_array('title', $suffixes, true) ? 'agency' : 'card',
                'closed' => $closed, 'closed_label' => $def['closed_label'],
                'statuses' => $def['statuses'], 'open' => $def['open'], 'closed_statuses' => $def['closed'], 'default_status' => $def['default_status'],
                'attrs' => $def['attrs'], 'title_from' => (array) ($def['title_from'] ?? []),
                'enabled' => ! (isset($ls['enabled']) && $ls['enabled'] === false),
                'seeded_at' => $ls['seeded_at'] ?? null,
                'currency' => (string) ($ls['currency'] ?? 'USD'),
            ];
        }
        return $out;
    }

    private function enclosingBlock(string $html, string $family): string
    {
        $p = strpos($html, 'data-field="' . $family . '_1_');
        if ($p === false) return '';
        if (! preg_match_all('/data-block="([a-z_\-]+)"/', substr($html, 0, $p), $m)) return '';
        return (string) end($m[1]);
    }

    public function spec(int $websiteId, string $kind, ?object $site = null): ?array
    {
        return $this->specs($websiteId, $site)[$kind] ?? null;
    }

    public function enabledSpecs(int $websiteId, ?object $site = null): array
    {
        return array_filter($this->specs($websiteId, $site), fn($s) => $s['enabled']);
    }

    private function saveKindSettings(int $websiteId, string $kind, array $patch): void
    {
        $site = $this->site($websiteId);
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $settings['catalogue'][$kind] = array_merge((array) ($settings['catalogue'][$kind] ?? []), $patch);
        if ($kind === 'listing') { $settings['listings'] = array_merge((array) ($settings['listings'] ?? []), $patch); }   // DEC-0048 readers
        DB::table('websites')->where('id', $websiteId)->update(['settings_json' => json_encode($settings), 'updated_at' => now()]);
    }

    // ───────────────────────────── read ─────────────────────────────

    /** Everything the editor panel needs: each kind the design carries, its schema, its items. Seeds on first sight. */
    public function overview(int $wsId, int $websiteId): array
    {
        $site = $this->owned($wsId, $websiteId);
        if (! $site) return ['catalogues' => [], 'error' => 'not_found'];
        $specs = $this->specs($websiteId, $site);
        $out = [];
        foreach ($specs as $kind => $spec) {
            if ($spec['enabled'] && empty($spec['seeded_at'])) { $this->seedFromSite($wsId, $websiteId, $site, $spec); $spec = $this->spec($websiteId, $kind) ?: $spec; }
            $out[$kind] = $this->publicSpec($spec) + ['items' => array_map(fn($r) => $this->present($spec, $r), $this->rows($websiteId, $kind))];
        }
        return ['catalogues' => $out, 'pages' => ['index' => '/<page_slug>/', 'detail' => '/<detail_prefix>-<slug>/']];
    }

    private function publicSpec(array $spec): array
    {
        return array_intersect_key($spec, array_flip(['kind', 'family', 'label', 'singular', 'page_slug', 'detail_prefix', 'pages', 'home_block', 'slots', 'suffixes', 'closed', 'closed_label', 'statuses', 'open', 'closed_statuses', 'default_status', 'attrs', 'enabled', 'seeded_at', 'currency', 'industry', 'design']));
    }

    private function rows(int $websiteId, string $kind): array
    {
        return DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', $kind)->whereNull('deleted_at')
            ->orderByDesc('featured')->orderBy('sort_order')->orderBy('id')->get()->all();   // page order = featured, then the order they were added
    }

    private function attrs(object $r): array { return json_decode((string) ($r->attrs_json ?: '{}'), true) ?: []; }
    private function photos(object $r): array { return array_values(json_decode((string) ($r->photos_json ?: '[]'), true) ?: []); }

    private function present(array $spec, object $r): array
    {
        return [
            'id' => (int) $r->id, 'kind' => $r->kind, 'slug' => $r->slug, 'title' => $r->title, 'status' => $r->status,
            'status_label' => $spec['statuses'][$r->status] ?? ucfirst(str_replace('_', ' ', $r->status)),
            'price' => $r->price !== null ? (float) $r->price : null, 'currency' => $r->currency, 'price_period' => $r->price_period,
            'price_label' => $r->price_label, 'price_display' => $this->priceText($r),
            'summary' => (string) ($r->summary ?? ''), 'description' => (string) ($r->description ?? ''),
            'attrs' => $this->attrs($r), 'specs_display' => $this->specsText($spec, $r),
            'photos' => $this->photos($r), 'features' => json_decode((string) ($r->features_json ?: '[]'), true) ?: [],
            'featured' => (bool) $r->featured, 'sort_order' => (int) $r->sort_order,
            'closed_note' => $r->closed_note, 'closed_at' => $r->closed_at, 'source' => $r->source,
            'page' => $spec['pages'] === 'index+detail' ? '/' . $spec['detail_prefix'] . '-' . $r->slug . '/' : null,
            'updated_at' => $r->updated_at,
        ];
    }

    // ───────────────────────────── write ─────────────────────────────

    private function normalise(array $spec, array $in, ?object $existing): array
    {
        $errors = []; $a = [];
        $title = trim((string) ($in['title'] ?? ($existing->title ?? '')));
        if ($title === '') $errors[] = 'Give the ' . $spec['singular'] . ' a title.';
        $a['title'] = mb_substr($title, 0, 190);
        $status = (string) ($in['status'] ?? ($existing->status ?? $spec['default_status']));
        if (! isset($spec['statuses'][$status])) $errors[] = 'Status must be one of: ' . implode(', ', array_keys($spec['statuses'])) . '.';
        $a['status'] = $status;
        if (array_key_exists('price', $in)) {
            $p = $in['price'];
            if ($p === '' || $p === null) { $a['price'] = null; }
            else {
                $n = is_numeric($p) ? (float) $p : (float) preg_replace('/[^\d.]/', '', (string) $p);
                if ($n < 0 || $n > 999999999999) $errors[] = 'That price does not look right.';
                $a['price'] = round($n, 2);
            }
        }
        if (isset($in['currency'])) { $c = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $in['currency'])); $a['currency'] = strlen($c) === 3 ? $c : $spec['currency']; }
        elseif (! $existing) { $a['currency'] = $spec['currency']; }
        foreach (['price_period' => 12, 'price_label' => 80, 'summary' => 300, 'closed_note' => 190] as $k => $max) {
            if (array_key_exists($k, $in)) { $v = trim(strip_tags((string) $in[$k])); $a[$k] = $v === '' ? null : mb_substr($v, 0, $max); }
        }
        if (array_key_exists('description', $in)) $a['description'] = mb_substr(trim(strip_tags((string) $in['description'])), 0, 6000);
        if (array_key_exists('photos', $in)) {
            $photos = [];
            foreach ((array) $in['photos'] as $u) {
                $u = trim((string) $u);
                if ($u === '') continue;
                if (! preg_match('~^(https?://[^\s"\'<>]+|/storage/[^\s"\'<>]+)$~i', $u)) { $errors[] = 'A photo must be a web address (https://…) or an uploaded file.'; continue; }
                $photos[] = mb_substr($u, 0, 1024);
            }
            $a['photos_json'] = json_encode(array_values(array_unique($photos)));
        }
        if (array_key_exists('features', $in)) {
            $f = is_array($in['features']) ? $in['features'] : preg_split('/[\n,]+/', (string) $in['features']);
            $a['features_json'] = json_encode(array_values(array_filter(array_map(fn($x) => mb_substr(trim(strip_tags((string) $x)), 0, 80), $f))));
        }
        // kind attributes
        $attrs = $existing ? $this->attrs($existing) : [];
        $inAttrs = is_array($in['attrs'] ?? null) ? $in['attrs'] : [];
        foreach ($spec['attrs'] as $def) {
            $k = $def['key'];
            $has = array_key_exists($k, $inAttrs) ? 'attrs' : (array_key_exists($k, $in) ? 'top' : null);
            if ($has === null) continue;
            $v = $has === 'attrs' ? $inAttrs[$k] : $in[$k];
            if ($v === '' || $v === null) { unset($attrs[$k]); continue; }
            if ($def['type'] === 'number') { $attrs[$k] = (float) preg_replace('/[^\d.]/', '', (string) $v); }
            elseif ($def['type'] === 'select') { $attrs[$k] = in_array((string) $v, $def['options'] ?? [], true) ? (string) $v : ($def['default'] ?? (string) $v); }
            else { $attrs[$k] = mb_substr(trim(strip_tags((string) $v)), 0, $def['type'] === 'textarea' ? 2000 : 190); }
        }
        $a['attrs_json'] = json_encode($attrs);
        if (array_key_exists('featured', $in)) $a['featured'] = (bool) $in['featured'] ? 1 : 0;
        if (array_key_exists('sort_order', $in)) $a['sort_order'] = max(0, (int) $in['sort_order']);
        if (in_array($status, $spec['closed_statuses'], true)) { $a['closed_at'] = $existing && $existing->closed_at && $existing->status === $status ? $existing->closed_at : now(); }
        elseif ($existing && in_array($existing->status, $spec['closed_statuses'], true)) { $a['closed_at'] = null; }
        return [$a, $errors];
    }

    private function uniqueSlug(int $websiteId, string $kind, string $title, ?int $exceptId = null): string
    {
        $base = Str::slug(mb_substr($title, 0, 70)) ?: $kind;
        $slug = $base; $i = 2;
        while (DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', $kind)->where('slug', $slug)->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))->exists()) { $slug = $base . '-' . $i++; }
        return $slug;
    }

    private function fail(string $code, string $message): array { return ['success' => false, 'code' => $code, 'message' => $message]; }

    public function create(int $wsId, int $websiteId, string $kind, array $in, ?int $actorId = null, string $source = 'editor'): array
    {
        $site = $this->owned($wsId, $websiteId);
        if (! $site) return $this->fail('NOT_FOUND', 'Website not found in this workspace.');
        $spec = $this->spec($websiteId, $kind, $site);
        if (! $spec || ! $spec['enabled']) return $this->fail('NO_CATALOGUE', 'This design does not carry that catalogue.');
        if ($no = $this->affordOrFail($wsId)) return $no;
        [$a, $errors] = $this->normalise($spec, $in, null);
        if ($errors) return $this->fail('INVALID', implode(' ', $errors)) + ['errors' => $errors];
        $dup = DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', $kind)->whereNull('deleted_at')->whereRaw('LOWER(title) = ?', [mb_strtolower($a['title'])])->first();
        if ($dup) return $this->fail('DUPLICATE', 'There is already a ' . $spec['singular'] . ' called “' . $dup->title . '” — edit that one instead (say "change the price of ' . $dup->title . ' to …", or open it in the ' . $spec['label'] . ' panel).');
        // an item removed earlier and added again by the same name comes back (its photos and details with it)
        $gone = DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', $kind)->whereNotNull('deleted_at')->whereRaw('LOWER(title) = ?', [mb_strtolower($a['title'])])->orderByDesc('deleted_at')->first();
        if ($gone) {
            DB::table('catalogue_items')->where('id', $gone->id)->update($a + ['deleted_at' => null, 'updated_at' => now()]);
            $sync = $this->sync($websiteId, $kind, 'catalogue_restore');
            $row = DB::table('catalogue_items')->where('id', $gone->id)->first();
            $this->chargeItem($wsId, $websiteId, 'restore', (int) $gone->id);
            return $this->priced(['success' => true, 'item' => $this->present($spec, $row), 'sync' => $sync, 'restored' => true, 'message' => '“' . $row->title . '” is back on the site.']);
        }
        $a['slug'] = $this->uniqueSlug($websiteId, $kind, $a['title']);
        if (! isset($a['sort_order'])) { $a['sort_order'] = (int) DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', $kind)->whereNull('deleted_at')->max('sort_order') + 1; }   // new items go last; Featured puts one first
        $a += ['workspace_id' => $wsId, 'website_id' => $websiteId, 'kind' => $kind, 'source' => $source, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()];
        $id = (int) DB::table('catalogue_items')->insertGetId($a);
        $sync = $this->sync($websiteId, $kind, 'catalogue_add');
        $this->chargeItem($wsId, $websiteId, 'add', $id);
        $row = DB::table('catalogue_items')->where('id', $id)->first();
        $openIds = array_map(fn($r) => (int) $r->id, array_values(array_filter($this->rows($websiteId, $kind), fn($r) => in_array($r->status, $spec['open'], true))));
        $pos = array_search($id, $openIds, true);
        $onHome = $pos !== false && $pos < $spec['slots'];
        $where = $onHome
            ? ($spec['pages'] === 'index+detail' ? ' It is on the home page, on the ' . $spec['label'] . ' page and has its own page.' : ' It is on the home page and on the ' . $spec['label'] . ' page.')
            : ' It is on the ' . $spec['label'] . ' page' . ($spec['pages'] === 'index+detail' ? ' with its own page' : '') . '; the home page shows the first ' . $spec['slots'] . ' — tick Featured to put it there.';
        return $this->priced(['success' => true, 'item' => $this->present($spec, $row), 'sync' => $sync,
            'message' => '“' . $row->title . '” added.' . $where . ($row->price === null && empty($row->price_label) && in_array('price', $spec['suffixes'], true) ? ' No price yet — it shows “Price on request” until you add one.' : '')]);
    }

    public function update(int $wsId, int $websiteId, string $kind, int $itemId, array $in, string $reason = 'catalogue_edit'): array
    {
        $site = $this->owned($wsId, $websiteId);
        if (! $site) return $this->fail('NOT_FOUND', 'Website not found in this workspace.');
        $spec = $this->spec($websiteId, $kind, $site);
        if (! $spec) return $this->fail('NO_CATALOGUE', 'This design does not carry that catalogue.');
        $row = DB::table('catalogue_items')->where('id', $itemId)->where('website_id', $websiteId)->where('kind', $kind)->whereNull('deleted_at')->first();
        if (! $row) return $this->fail('NOT_FOUND', 'That ' . $spec['singular'] . ' is not on this site.');
        if ($no = $this->affordOrFail($wsId)) return $no;
        [$a, $errors] = $this->normalise($spec, $in, $row);
        if ($errors) return $this->fail('INVALID', implode(' ', $errors)) + ['errors' => $errors];
        $oldSlug = $row->slug;
        if ($a['title'] !== $row->title) $a['slug'] = $this->uniqueSlug($websiteId, $kind, $a['title'], $itemId);
        $a['updated_at'] = now();
        DB::table('catalogue_items')->where('id', $itemId)->update($a);
        if (isset($a['slug']) && $a['slug'] !== $oldSlug) $this->removePage($websiteId, $spec['detail_prefix'] . '-' . $oldSlug);
        $sync = $this->sync($websiteId, $kind, $reason);
        $this->chargeItem($wsId, $websiteId, $reason, $itemId);
        $row = DB::table('catalogue_items')->where('id', $itemId)->first();
        return $this->priced(['success' => true, 'item' => $this->present($spec, $row), 'sync' => $sync,
            'message' => '“' . $row->title . '” updated — ' . strtolower($spec['statuses'][$row->status] ?? $row->status) . (in_array('price', $spec['suffixes'], true) || $row->price !== null ? ', ' . $this->priceText($row) : '') . '.']);
    }

    public function setStatus(int $wsId, int $websiteId, string $kind, int $itemId, string $status, ?string $note = null): array
    {
        $in = ['status' => $status];
        if ($note !== null) $in['closed_note'] = $note;
        return $this->update($wsId, $websiteId, $kind, $itemId, $in, 'catalogue_status');
    }

    public function delete(int $wsId, int $websiteId, string $kind, int $itemId): array
    {
        $site = $this->owned($wsId, $websiteId);
        if (! $site) return $this->fail('NOT_FOUND', 'Website not found in this workspace.');
        $spec = $this->spec($websiteId, $kind, $site);
        $row = DB::table('catalogue_items')->where('id', $itemId)->where('website_id', $websiteId)->where('kind', $kind)->whereNull('deleted_at')->first();
        if (! $spec || ! $row) return $this->fail('NOT_FOUND', 'That item is not on this site.');
        if ($no = $this->affordOrFail($wsId)) return $no;
        DB::table('catalogue_items')->where('id', $itemId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->removePage($websiteId, $spec['detail_prefix'] . '-' . $row->slug);
        $sync = $this->sync($websiteId, $kind, 'catalogue_remove');
        $this->chargeItem($wsId, $websiteId, 'remove', $itemId);
        return $this->priced(['success' => true, 'sync' => $sync, 'message' => '“' . $row->title . '” removed from the site.']);
    }

    /** Per-site switch for one kind. Off: the pages and the menu link go, the home page keeps what it shows; rows are kept. */
    public function setEnabled(int $wsId, int $websiteId, string $kind, bool $enabled): array
    {
        $site = $this->owned($wsId, $websiteId);
        if (! $site) return $this->fail('NOT_FOUND', 'Website not found in this workspace.');
        $spec = $this->spec($websiteId, $kind, $site);
        if (! $spec) return $this->fail('NO_CATALOGUE', 'This design does not carry that catalogue.');
        $this->saveKindSettings($websiteId, $kind, ['enabled' => $enabled]);
        if ($enabled) {
            $spec = $this->spec($websiteId, $kind) ?: $spec;
            if (empty($spec['seeded_at'])) $this->seedFromSite($wsId, $websiteId, $this->site($websiteId), $spec);
            $sync = $this->sync($websiteId, $kind, 'catalogue_on');
            return ['success' => true, 'enabled' => true, 'sync' => $sync, 'message' => $spec['label'] . ' are on: the ' . $spec['label'] . ' page is live on the site.'];
        }
        try { $this->templates->snapshotToHistory($websiteId, 'catalogue_off'); } catch (\Throwable $e) {}
        $this->removeNav($websiteId, $spec);
        $this->removePage($websiteId, $spec['page_slug']);
        foreach (DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', $kind)->get(['slug']) as $r) $this->removePage($websiteId, $spec['detail_prefix'] . '-' . $r->slug);
        $this->writeHideCss($websiteId);
        return ['success' => true, 'enabled' => false, 'message' => $spec['label'] . ' are off for this site. The home page keeps what it shows now; the ' . $spec['label'] . ' page is removed.'];
    }

    // ───────────────────────────── seed ─────────────────────────────

    private function slotValue(array $tv, string $family, int $i, array $suffixes): ?string
    {
        foreach ($suffixes as $s) { $v = trim((string) ($tv["{$family}_{$i}_{$s}"] ?? '')); if ($v !== '') return $v; }
        return null;
    }

    /** First enable: what the page shows today becomes rows, so nothing the customer already saw disappears. */
    private function seedFromSite(int $wsId, int $websiteId, object $site, array $spec): int
    {
        $tv = json_decode((string) ($site->template_variables ?: '{}'), true) ?: [];
        $kind = $spec['kind']; $fam = $spec['family']; $n = 0;
        $seed = function (string $family, int $slots, bool $closedRow) use (&$n, $tv, $spec, $wsId, $websiteId, $kind) {
            for ($i = 1; $i <= $slots; $i++) {
                $title = $this->slotValue($tv, $family, $i, CatalogueKinds::SHARED_SUFFIXES['title']) ?? $this->slotValue($tv, $family, $i, ['area']);
                if ($title === null && $spec['title_from'] !== []) {   // a vehicle is "make model"
                    $parts = array_filter(array_map(fn($s) => $this->slotValue($tv, $family, $i, [$s]), $spec['title_from']));
                    if ($parts !== []) $title = implode(' ', $parts);
                }
                if ($title === null) continue;
                $periodRaw = trim((string) ($this->slotValue($tv, $family, $i, ['period']) ?? ''), " /");
                $badge = strtolower((string) ($this->slotValue($tv, $family, $i, ['badge']) ?? ''));
                $status = $closedRow ? ($spec['closed_statuses'][0] ?? $spec['default_status']) : $spec['default_status'];
                if ($kind === 'listing' && ! $closedRow) { $status = str_contains($badge, 'sold') ? 'sold' : (preg_match('/rent|let/', $badge) ? 'to_let' : (str_contains($badge, 'offer') ? 'under_offer' : 'for_sale')); }
                if ($kind === 'menu' && preg_match('/sold out/', $badge)) $status = 'sold_out';
                $priceRaw = (string) ($this->slotValue($tv, $family, $i, ['price']) ?? '');
                $cur = strtoupper((string) ($this->slotValue($tv, $family, $i, ['currency']) ?? '')) ?: $this->currencyFromText($priceRaw, $spec['currency']);
                $attrs = [];
                foreach ($spec['attrs'] as $def) {
                    if (empty($def['from'])) continue;
                    $v = $this->slotValue($tv, $family, $i, $def['from']);
                    if ($v === null) continue;
                    if ($def['type'] === 'number') { if (preg_match('/[\d,]+(?:\.\d+)?/', $v, $nm)) $attrs[$def['key']] = (float) str_replace(',', '', $nm[0]); }
                    else $attrs[$def['key']] = mb_substr($v, 0, 190);
                }
                if ($kind === 'listing' && isset($attrs['size_value'])) $attrs['size_unit'] = 'sq ft';
                if ($spec['style'] === 'agency' && ! isset($attrs['location'])) $attrs['location'] = $title;
                $image = $this->slotValue($tv, $family, $i, ['image']);
                $row = [
                    'workspace_id' => $wsId, 'website_id' => $websiteId, 'kind' => $kind, 'title' => mb_substr($title, 0, 190), 'status' => $status,
                    'currency' => strlen($cur) === 3 ? $cur : $spec['currency'],
                    'summary' => mb_substr((string) ($this->slotValue($tv, $family, $i, CatalogueKinds::SHARED_SUFFIXES['summary']) ?? ''), 0, 300) ?: null,
                    'attrs_json' => json_encode($attrs), 'photos_json' => json_encode($image ? [$image] : []),
                    'closed_note' => $closedRow ? (mb_substr((string) ($this->slotValue($tv, $family, $i, ['price_note', 'note', 'result']) ?? ''), 0, 190) ?: null) : null,
                    'closed_at' => $closedRow ? now() : null,
                    'sort_order' => ($closedRow ? 100 : 0) + $i, 'source' => 'seed', 'created_at' => now(), 'updated_at' => now(),
                    'price_period' => $periodRaw !== '' ? mb_substr($periodRaw, 0, 12) : null,
                ];
                $clean = preg_replace('/[^\d.]/', '', $priceRaw);
                if ($priceRaw !== '' && is_numeric($clean) && preg_match('/^\s*(?:[^\d]{0,4})[\d,]+(?:\.\d+)?\s*$/u', $priceRaw)) { $row['price'] = round((float) $clean, 2); }
                elseif ($priceRaw !== '') { $row['price_label'] = mb_substr($priceRaw, 0, 80); }
                $row['slug'] = $this->uniqueSlug($websiteId, $kind, $row['title']);
                DB::table('catalogue_items')->insert($row); $n++;
            }
        };
        $seed($fam, $spec['slots'], false);
        if ($spec['closed']) $seed($spec['closed']['family'], $spec['closed']['slots'], true);
        $this->saveKindSettings($websiteId, $kind, ['seeded_at' => now()->toDateTimeString(), 'seeded' => $n]);
        Log::info('[Catalogue] seeded from site', ['website_id' => $websiteId, 'kind' => $kind, 'rows' => $n]);
        return $n;
    }

    private function currencyFromText(string $s, string $default): string
    {
        if (preg_match('/\b(USD|EUR|GBP|AED|PHP|CAD|AUD|SGD|INR|ZAR|NZD|CHF|SAR|QAR)\b/i', $s, $m)) return strtoupper($m[1]);
        if (str_contains($s, '€')) return 'EUR';
        if (str_contains($s, '£')) return 'GBP';
        if (str_contains($s, '₱')) return 'PHP';
        if (str_contains($s, '$')) return isset(self::SYMBOLS[$default]) && str_contains(self::SYMBOLS[$default], '$') ? $default : 'USD';
        return $default;
    }

    // ───────────────────────────── text ─────────────────────────────

    public function priceText(object $r, bool $withPeriod = true): string
    {
        if (! empty($r->price_label)) return (string) $r->price_label;
        if ($r->price === null) return 'Price on request';
        $sym = self::SYMBOLS[$r->currency] ?? ($r->currency . ' ');
        $n = (float) $r->price;
        $txt = $sym . number_format($n, fmod($n, 1.0) !== 0.0 ? 2 : 0);
        if ($withPeriod && ! empty($r->price_period)) $txt .= ' / ' . $r->price_period;
        return $txt;
    }

    private function priceParts(object $r, bool $withPeriod = true): array
    {
        if (! empty($r->price_label) || $r->price === null) return [$this->priceText($r, $withPeriod), ''];
        $n = (float) $r->price;
        return [number_format($n, fmod($n, 1.0) !== 0.0 ? 2 : 0) . ($withPeriod && ! empty($r->price_period) ? ' / ' . $r->price_period : ''), (string) $r->currency];
    }

    private function num(float $n): string { return fmod($n, 1.0) !== 0.0 ? rtrim(rtrim(number_format($n, 1), '0'), '.') : (string) (int) $n; }

    /** The one-line facts of an item: "3 beds • 2 baths • 1,850 sq ft" for a listing, "60 min" for a treatment. */
    public function specsText(array $spec, object $r): string
    {
        $a = $this->attrs($r);
        if ($spec['kind'] === 'listing') {
            if (! empty($a['specs_text'])) return (string) $a['specs_text'];
            $p = [];
            if (isset($a['beds'])) { $b = (float) $a['beds']; $p[] = $this->num($b) . ' ' . ($b == 1 ? 'bed' : 'beds'); }
            if (isset($a['baths'])) { $b = (float) $a['baths']; $p[] = $this->num($b) . ' ' . ($b == 1 ? 'bath' : 'baths'); }
            if (isset($a['size_value'])) { $p[] = number_format((float) $a['size_value'], fmod((float) $a['size_value'], 1.0) !== 0.0 ? 1 : 0) . ' ' . ($a['size_unit'] ?? 'sq ft'); }
            return implode(' • ', $p);
        }
        $p = [];
        foreach ($spec['attrs'] as $def) { $k = $def['key']; if (! empty($def['in_title'])) continue; if (isset($a[$k]) && $a[$k] !== '' && $def['type'] !== 'textarea') $p[] = $def['type'] === 'number' ? $this->num((float) $a[$k]) : (string) $a[$k]; }
        return implode(' • ', $p);
    }

    private function cardImage(object $r): string { $p = $this->photos($r); return $p[0] ?? self::PLACEHOLDER; }

    // ───────────────────────────── projection ─────────────────────────────

    /** The template suffix → value pairs an item projects into slot $i of its family. Only suffixes the design has are written. */
    private function slotValues(array $spec, object $r, bool $closedRow = false): array
    {
        $kind = $spec['kind'];
        $vals = [];
        $suffixes = $closedRow ? ($spec['closed']['suffixes'] ?? []) : $spec['suffixes'];
        $has = fn(string $s) => in_array($s, $suffixes, true);
        $a = $this->attrs($r);
        $badge = $spec['statuses'][$r->status] ?? ucfirst($r->status);
        foreach (CatalogueKinds::SHARED_SUFFIXES['title'] as $s) { if ($has($s)) { $vals[$s] = (string) $r->title; break; } }
        foreach (CatalogueKinds::SHARED_SUFFIXES['summary'] as $s) { if ($has($s)) { $vals[$s] = (string) ($r->summary ?? ''); break; } }
        if ($has('badge')) $vals['badge'] = $closedRow ? $badge : ($kind === 'menu' && $r->status === 'sold_out' ? 'Sold out' : $badge);
        if ($has('image') && ($this->photos($r) !== [] || $kind === 'listing')) $vals['image'] = $this->cardImage($r);
        if ($has('cta')) $vals['cta'] = $spec['cta'];
        if ($kind === 'listing') {
            if ($spec['style'] === 'agency' && ! $closedRow) {
                [$num, $code] = $this->priceParts($r);
                if ($has('price')) $vals['price'] = $num;
                if ($has('currency')) $vals['currency'] = $code;
                if ($has('area')) $vals['area'] = (string) ($a['location'] ?? $r->title);
                if ($has('beds')) $vals['beds'] = isset($a['beds']) ? $this->num((float) $a['beds']) : '–';
                if ($has('baths')) $vals['baths'] = isset($a['baths']) ? $this->num((float) $a['baths']) : '–';
                if ($has('sqft')) $vals['sqft'] = isset($a['size_value']) ? number_format((float) $a['size_value']) : '–';
            } else {
                if ($has('price')) $vals['price'] = $closedRow ? (($r->price !== null || ! empty($r->price_label)) ? $this->priceText($r) : '') : $this->priceText($r);
                if ($has('location')) $vals['location'] = (string) ($a['location'] ?? '');
                if ($has('specs')) $vals['specs'] = $this->specsText($spec, $r);
                if ($closedRow && $has('price_note')) $vals['price_note'] = (string) ($r->closed_note ?? '');
                if ($closedRow && $has('note')) $vals['note'] = (string) ($r->closed_note ?? '');
            }
            return $vals;
        }
        $ownPeriodSlot = $has('period');                          // the design shows "/ month" in its own slot → keep it out of the price text
        if ($has('currency')) {                                  // designs that show the number and the currency code apart (plans, agency listings)
            [$num, $code] = $this->priceParts($r, ! $ownPeriodSlot);
            if ($has('price')) $vals['price'] = ($r->price !== null || ! empty($r->price_label)) ? $num : '';
            $vals['currency'] = $code;
        } elseif ($has('price')) { $vals['price'] = ($r->price !== null || ! empty($r->price_label)) ? $this->priceText($r, ! $ownPeriodSlot) : ''; }
        if ($ownPeriodSlot) { $p = (string) ($r->price_period ?? ''); $vals['period'] = $p === '' ? '' : (in_array($p, ['month', 'week', 'year', 'night', 'hour', 'person', 'session', 'visit', 'day', 'class'], true) ? '/ ' . $p : $p); }
        foreach ($spec['attrs'] as $def) {
            foreach ((array) ($def['from'] ?? []) as $s) { if ($has($s)) { $v = $a[$def['key']] ?? ''; $vals[$s] = $def['type'] === 'number' && $v !== '' ? $this->num((float) $v) : (string) $v; break; } }
        }
        if ($spec['title_from'] !== [] && ($a['make'] ?? '') === '' && $has('make')) {   // a vehicle added by name only: "Toyota Camry 2021"
            $parts = explode(' ', trim((string) $r->title), 2);
            $vals['make'] = $parts[0]; if ($has('model')) $vals['model'] = trim((string) ($parts[1] ?? '')) ?: $parts[0];
        }
        return $vals;
    }

    /** Rewrite everything the site shows for one kind (or every enabled kind) from the rows. One history snapshot. */
    public function sync(int $websiteId, ?string $onlyKind = null, string $reason = 'catalogue'): array
    {
        $site = $this->site($websiteId);
        if ($site && ! is_file(storage_path("app/public/sites/{$websiteId}/index.html"))) {
            // SGTRAVEL CAT-1 renderer cache — the theme reads the rows at render time; just drop the cached pages.
            $sub = str_replace('.levelupgrowth.io', '', (string) ($site->subdomain ?? ''));
            foreach (DB::table('pages')->where('website_id', $websiteId)->pluck('slug') as $slug) \Illuminate\Support\Facades\Cache::forget("published_site:{$sub}:{$slug}");
            return ['synced' => true, 'renderer' => true];
        }
        if (! $site || ! is_file(storage_path("app/public/sites/{$websiteId}/index.html"))) return ['synced' => false];
        $specs = $this->enabledSpecs($websiteId, $site);
        if ($onlyKind !== null) $specs = array_intersect_key($specs, [$onlyKind => 1]);
        if ($specs === []) return ['synced' => false, 'reason' => 'no_catalogue'];
        try { $this->templates->snapshotToHistory($websiteId, $reason); } catch (\Throwable $e) {}
        $tv = json_decode((string) ($site->template_variables ?: '{}'), true) ?: [];
        $changes = []; $result = ['synced' => true, 'kinds' => []];
        foreach ($specs as $kind => $spec) {
            $rows = $this->rows($websiteId, $kind);
            $open = array_values(array_filter($rows, fn($r) => in_array($r->status, $spec['open'], true)));
            $closed = array_values(array_filter($rows, fn($r) => in_array($r->status, $spec['closed_statuses'], true)));
            usort($closed, fn($a, $b) => strcmp((string) $b->closed_at, (string) $a->closed_at));
            for ($i = 1; $i <= $spec['slots']; $i++) {
                $r = $open[$i - 1] ?? null;
                if (! $r) continue;
                foreach ($this->slotValues($spec, $r) as $s => $v) {
                    $k = "{$spec['family']}_{$i}_{$s}";
                    // the design's own button wording stays ("Book Test Drive", "Start Classes") unless the slot is empty; listings say "View details" because the button opens the property page
                    if ($s === 'cta' && $kind !== 'listing' && trim((string) ($tv[$k] ?? '')) !== '') continue;
                    if ((string) ($tv[$k] ?? null) !== $v) $changes[$k] = $v;
                }
            }
            if ($spec['closed']) {
                for ($i = 1; $i <= $spec['closed']['slots']; $i++) {
                    $r = $closed[$i - 1] ?? null;
                    if (! $r) continue;
                    foreach ($this->slotValues($spec, $r, true) as $s => $v) { $k = "{$spec['closed']['family']}_{$i}_{$s}"; if ((string) ($tv[$k] ?? null) !== $v) $changes[$k] = $v; }
                }
            }
            $this->pointCardsAtPages($websiteId, $spec, $open);
            $pages = $this->writePages($websiteId, $spec, $open, $closed);
            $this->ensureNav($websiteId, $spec);
            $result['kinds'][$kind] = ['open' => count($open), 'closed' => count($closed), 'pages' => $pages];
        }
        $patched = 0;
        foreach ($changes as $k => $v) {
            try { if ($this->templates->updateField($websiteId, $k, $v, false)) $patched++; } catch (\Throwable $e) { Log::warning('[Catalogue] field patch failed', ['field' => $k, 'error' => $e->getMessage()]); }
        }
        if ($changes !== []) {
            $tv = array_merge($tv, $changes);
            DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
            if (isset($specs['service'])) { try { $this->templates->refreshServiceSelects($websiteId, $tv); } catch (\Throwable $e) {} }   // booking-form dropdowns follow the services
        }
        $this->writeHideCss($websiteId);
        $result['fields'] = $patched;
        return $result;
    }

    private function hideCssFor(int $websiteId, ?object $site = null): string
    {
        $css = [];
        foreach ($this->enabledSpecs($websiteId, $site) as $kind => $spec) {
            if (empty($spec['seeded_at'])) continue;
            $rows = $this->rows($websiteId, $kind);
            $open = count(array_filter($rows, fn($r) => in_array($r->status, $spec['open'], true)));
            $closed = count(array_filter($rows, fn($r) => in_array($r->status, $spec['closed_statuses'], true)));
            $hb = $spec['home_block']; $fam = $spec['family'];
            if ($open === 0) { $css[] = "[data-block=\"{$hb}\"]{display:none!important}"; }
            else { for ($i = $open + 1; $i <= $spec['slots']; $i++) { $css[] = "[data-block=\"{$hb}\"] :is(article,li,.card,.listing,.listing-card,.service,.service-card,.menu-item,.item,[class*=\"card\"]):has([data-field=\"{$fam}_{$i}_title\"],[data-field=\"{$fam}_{$i}_name\"],[data-field=\"{$fam}_{$i}_area\"],[data-field=\"{$fam}_{$i}_price\"]){display:none!important}"; } }
            if ($spec['closed']) {
                $sb = $spec['closed']['block']; $cf = $spec['closed']['family'];
                if ($closed === 0) { $css[] = "[data-block=\"{$sb}\"]{display:none!important}"; }
                else { for ($i = $closed + 1; $i <= $spec['closed']['slots']; $i++) { $css[] = "[data-block=\"{$sb}\"] :is(article,li,.card,[class*=\"card\"]):has([data-field=\"{$cf}_{$i}_title\"],[data-field=\"{$cf}_{$i}_name\"]){display:none!important}"; } }
            }
        }
        return implode("\n", $css);
    }

    private function writeHideCss(int $websiteId): void
    {
        $path = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($path)) return;
        $html = (string) file_get_contents($path);
        $new = self::injectCss($html, $this->hideCssFor($websiteId));
        if ($new !== $html) file_put_contents($path, $new);
    }

    public static function injectCss(string $html, string $css): string
    {
        $html = preg_replace('~\s*<style id="lu-(?:listings|catalogue)-css"[^>]*>.*?</style>~is', '', $html) ?? $html;
        if (trim($css) === '') return $html;
        $block = "\n" . '<style id="lu-catalogue-css" data-owner="catalogue">' . $css . '</style>';
        return stripos($html, '</head>') !== false ? preg_replace('~</head>~i', $block . "\n</head>", $html, 1) : $html . $block;
    }

    /** Rebuild hook (TemplateService::render): a freshly rendered home hides the slots its catalogues do not fill. */
    public function decorateRendered(int $websiteId, string $html): string
    {
        try { return self::injectCss($html, $this->hideCssFor($websiteId)); } catch (\Throwable $e) { return $html; }
    }

    private function pointCardsAtPages(int $websiteId, array $spec, array $open): void
    {
        if ($spec['pages'] !== 'index+detail' || ! in_array('cta', $spec['suffixes'], true)) return;
        $path = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($path)) return;
        $html = (string) file_get_contents($path);
        $new = $html;
        for ($i = 1; $i <= $spec['slots']; $i++) {
            $r = $open[$i - 1] ?? null;
            $href = $r ? $spec['detail_prefix'] . '-' . $r->slug . '/' : '#contact';
            $new = preg_replace_callback('/<a\b([^>]*\bdata-field="' . $spec['family'] . '_' . $i . '_cta"[^>]*)>/i', function ($m) use ($href) {
                $attrs = preg_match('/\shref="/i', $m[1]) ? (preg_replace('/\shref="[^"]*"/i', ' href="' . $href . '"', $m[1], 1) ?? $m[1]) : $m[1] . ' href="' . $href . '"';
                return '<a' . $attrs . '>';
            }, $new) ?? $new;
        }
        if ($new !== $html) file_put_contents($path, $new);
    }

    // ───────────────────────────── pages ─────────────────────────────

    private function writePages(int $websiteId, array $spec, array $open, array $closed): array
    {
        if ($spec['pages'] === 'none') return ['written' => 0];
        $written = 0;
        if ($this->templates->deployPage($websiteId, $spec['page_slug'], $this->indexBody($spec, $open, $closed), $spec['label'])) $written++;
        if ($spec['pages'] === 'index+detail') {
            foreach (array_merge($open, $closed) as $r) { if ($this->templates->deployPage($websiteId, $spec['detail_prefix'] . '-' . $r->slug, $this->detailBody($spec, $r), $r->title)) $written++; }
            $shown = array_map(fn($r) => $r->slug, array_merge($open, $closed));
            foreach (DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', $spec['kind'])->whereNull('deleted_at')->get(['slug']) as $w) { if (! in_array($w->slug, $shown, true)) $this->removePage($websiteId, $spec['detail_prefix'] . '-' . $w->slug); }
        }
        return ['written' => $written];
    }

    private function removePage(int $websiteId, string $slug): void
    {
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($slug));
        if ($slug === '' || in_array($slug, ['index', 'blog', 'home'], true)) return;
        $dir = storage_path("app/public/sites/{$websiteId}/{$slug}");
        if (! is_dir($dir)) return;
        foreach (glob($dir . '/*') ?: [] as $f) { if (is_file($f)) @unlink($f); }
        @rmdir($dir);
    }

    private function ensureNav(int $websiteId, array $spec): void
    {
        if ($spec['pages'] === 'none') return;
        $home = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($home)) return;
        $html = (string) file_get_contents($home);
        if (str_contains($html, 'data-page="' . $spec['page_slug'] . '"')) return;
        $label = $spec['label'];
        if (preg_match('/<a\b(?![^>]*class="sr")[^>]*href="#' . preg_quote($spec['home_block'], '/') . '"[^>]*class="[^"]*nav-link[^"]*"[^>]*>\s*([^<]{2,30}?)\s*<\/a>/i', $html, $m)
            || preg_match('/<a\b(?![^>]*class="sr")[^>]*class="[^"]*nav-link[^"]*"[^>]*href="#' . preg_quote($spec['home_block'], '/') . '"[^>]*>\s*([^<]{2,30}?)\s*<\/a>/i', $html, $m)) {
            $label = trim(html_entity_decode($m[1]));
        }
        try { $this->templates->addNavLink($websiteId, $spec['page_slug'], $label); } catch (\Throwable $e) { Log::warning('[Catalogue] nav link failed: ' . $e->getMessage()); }
    }

    private function removeNav(int $websiteId, array $spec): void
    {
        try { $this->templates->removeNavLink($websiteId, $spec['page_slug']); } catch (\Throwable $e) {}
        $home = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($home)) return;
        $html = (string) file_get_contents($home);
        $new = preg_replace('/(<a\b[^>]*)\shref="' . preg_quote($spec['page_slug'], '/') . '\/"/i', '$1 href="#' . $spec['home_block'] . '"', $html) ?? $html;
        if ($new !== $html) file_put_contents($home, $new);
    }

    private static function pageCss(): string
    {
        return '<style id="lu-catalogue-page-css">'
            . '.lu-cat{max-width:1140px;margin:0 auto;padding:clamp(28px,5vw,64px) 20px 72px;color:inherit}'
            . '.lu-cat h1{font-size:clamp(28px,4vw,44px);margin:0 0 6px;line-height:1.1}.lu-cat .lede{opacity:.75;margin:0 0 28px;max-width:60ch}'
            . '.lu-cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:22px}'
            . '.lu-cat-card{display:flex;flex-direction:column;border:1px solid rgba(0,0,0,.1);border-radius:14px;overflow:hidden;background:#fff;color:#1a1a1a;text-decoration:none;transition:transform .2s,box-shadow .2s}'
            . 'a.lu-cat-card:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(0,0,0,.12)}'
            . '.lu-cat-card figure{margin:0;position:relative;aspect-ratio:4/3;background:#eef0f3;overflow:hidden}.lu-cat-card img{width:100%;height:100%;object-fit:cover;display:block}'
            . '.lu-cat-badge{position:absolute;top:12px;left:12px;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;background:var(--brand,var(--primary,#1f2937));color:#fff;padding:7px 10px;border-radius:999px}'
            . '.lu-cat-badge.closed{background:#4b5563}.lu-cat-badge.inline{position:static;display:inline-block;margin-bottom:8px}'
            . '.lu-cat-body{padding:16px 18px 20px;display:flex;flex-direction:column;gap:5px}'
            . '.lu-cat-price{font-weight:800;font-size:19px}.lu-cat-card h3{margin:0;font-size:17px;line-height:1.3}.lu-cat-where,.lu-cat-specs,.lu-cat-sum{margin:0;font-size:14px;opacity:.78}'
            . '.lu-cat-note{margin:6px 0 0;font-size:13.5px;opacity:.85}.lu-cat h2{font-size:clamp(22px,3vw,30px);margin:56px 0 18px}'
            . '.lu-cat-list{display:flex;flex-direction:column;gap:0;border-top:1px solid rgba(0,0,0,.1)}'
            . '.lu-cat-row{display:grid;grid-template-columns:1fr auto;gap:18px;padding:18px 0;border-bottom:1px solid rgba(0,0,0,.1);align-items:start}'
            . '.lu-cat-row h3{margin:0 0 4px;font-size:18px}.lu-cat-row .lu-cat-price{white-space:nowrap}'
            . '.lu-prop{max-width:1100px;margin:0 auto;padding:clamp(24px,4vw,56px) 20px 80px;color:inherit}'
            . '.lu-prop .back{display:inline-block;margin-bottom:18px;font-size:14px;text-decoration:none;opacity:.8}'
            . '.lu-prop-gallery{display:grid;gap:10px;margin-bottom:26px}.lu-prop-gallery .main{aspect-ratio:16/10;background:#eef0f3;border-radius:16px;overflow:hidden}'
            . '.lu-prop-gallery img{width:100%;height:100%;object-fit:cover;display:block}.lu-prop-thumbs{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px}'
            . '.lu-prop-thumbs a{aspect-ratio:4/3;border-radius:10px;overflow:hidden;background:#eef0f3;display:block}'
            . '.lu-prop-head{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:start;margin-bottom:18px}.lu-prop-head h1{margin:0 0 6px;font-size:clamp(26px,3.6vw,40px);line-height:1.1}'
            . '.lu-prop-where{margin:0;opacity:.75;font-size:15px}.lu-prop-price{font-size:clamp(22px,3vw,30px);font-weight:800;white-space:nowrap}'
            . '.lu-prop-status{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;background:var(--brand,var(--primary,#1f2937));color:#fff;padding:7px 10px;border-radius:999px;margin-bottom:12px}.lu-prop-status.closed{background:#4b5563}'
            . '.lu-prop-facts{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 26px;padding:0;list-style:none}.lu-prop-facts li{border:1px solid rgba(0,0,0,.12);border-radius:10px;padding:10px 14px;font-size:14px;background:rgba(255,255,255,.6)}'
            . '.lu-prop-facts b{display:block;font-size:11px;letter-spacing:.1em;text-transform:uppercase;opacity:.6;margin-bottom:2px}'
            . '.lu-prop-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(300px,1fr);gap:36px}.lu-prop-desc{font-size:16px;line-height:1.7}.lu-prop-desc p{margin:0 0 14px}.lu-prop-features{columns:2;padding-left:18px;margin:0 0 20px;font-size:15px}'
            . '.lu-enq{border:1px solid rgba(0,0,0,.12);border-radius:16px;padding:22px;background:rgba(255,255,255,.7);position:sticky;top:96px}.lu-enq.wide{position:static;max-width:560px;margin:40px auto 0}'
            . '.lu-enq h2{margin:0 0 4px;font-size:20px}.lu-enq p{margin:0 0 14px;font-size:14px;opacity:.75}.lu-enq label{display:block;font-size:12px;font-weight:600;margin:10px 0 4px;letter-spacing:.04em}'
            . '.lu-enq input,.lu-enq textarea{width:100%;box-sizing:border-box;border:1px solid rgba(0,0,0,.18);border-radius:10px;padding:11px 12px;font:inherit;font-size:15px;background:#fff;color:#111}'
            . '.lu-enq textarea{min-height:110px;resize:vertical}.lu-enq button{margin-top:14px;width:100%;border:0;border-radius:10px;padding:13px 16px;font:inherit;font-weight:700;font-size:15px;cursor:pointer;background:var(--brand,var(--primary,#1f2937));color:#fff}'
            . '.lu-enq button[disabled]{opacity:.6;cursor:default}.lu-enq-msg{margin:12px 0 0;font-size:14px;min-height:20px}'
            . '@media (max-width:820px){.lu-prop-grid{grid-template-columns:1fr}.lu-enq{position:static}.lu-prop-head{grid-template-columns:1fr}.lu-prop-features{columns:1}.lu-cat-row{grid-template-columns:1fr}}'
            . '</style>';
    }

    private function enquiryForm(array $spec, string $heading, string $prefill, string $context, bool $wide): string
    {
        $api = rtrim((string) config('app.url'), '/');
        $h = '<form class="lu-enq' . ($wide ? ' wide' : '') . '" id="lu-enq" method="post" action="#" novalidate><h2>' . e($heading) . '</h2><p>Your message goes straight to us — we reply personally.</p>'
            . '<label for="lu-enq-name">Name</label><input id="lu-enq-name" name="name" type="text" autocomplete="name" required>'
            . '<label for="lu-enq-email">Email</label><input id="lu-enq-email" name="email" type="email" autocomplete="email" required>'
            . '<label for="lu-enq-phone">Phone</label><input id="lu-enq-phone" name="phone" type="tel" autocomplete="tel">'
            . '<label for="lu-enq-message">Message</label><textarea id="lu-enq-message" name="message" required>' . e($prefill) . '</textarea>'
            . '<button type="submit">Send</button><p class="lu-enq-msg" id="lu-enq-msg" aria-live="polite"></p></form>';
        $h .= '<script>(function(){var t=document.querySelectorAll("[data-lu-thumb]"),m=document.getElementById("lu-prop-main");for(var i=0;i<t.length;i++){t[i].addEventListener("click",function(e){e.preventDefault();if(m)m.src=this.getAttribute("href");});}'
            . 'var f=document.getElementById("lu-enq");if(!f)return;f.addEventListener("submit",function(e){e.preventDefault();var b=f.querySelector("button"),g=document.getElementById("lu-enq-msg");'
            . 'var n=f.querySelector("[name=name]").value.trim(),em=f.querySelector("[name=email]").value.trim(),ms=f.querySelector("[name=message]").value.trim();'
            . 'if(!n||!em||!ms){g.textContent="Please add your name, email and a message.";return;}b.disabled=true;g.textContent="Sending…";'
            . 'fetch(' . json_encode($api . '/api/public/contact/by-host', JSON_UNESCAPED_SLASHES) . ',{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify({name:n,firstname:n,email:em,phone:f.querySelector("[name=phone]").value.trim(),message:ms,source:' . json_encode($spec['enquiry_source']) . ',item:' . json_encode($context) . '})})'
            . '.then(function(r){return r.json().catch(function(){return {};}).then(function(j){return {ok:r.ok,j:j};});})'
            . '.then(function(x){if(x.ok){g.textContent="Thank you — your message has been sent.";f.reset();}else{g.textContent=(x.j&&x.j.message)||"Sorry, that did not go through. Please try again or use the contact details on the home page.";b.disabled=false;}})'
            . '.catch(function(){g.textContent="Sorry, that did not go through. Please try again.";b.disabled=false;});});})();</script>';
        return $h;
    }

    private function card(array $spec, object $r, bool $link): string
    {
        $closed = in_array($r->status, $spec['closed_statuses'], true);
        $photos = $this->photos($r);
        $withFigure = $photos !== [] || $spec['kind'] === 'listing';
        $tag = $link && ! $closed ? 'a' : 'div';
        $href = $link && ! $closed ? ' href="../' . e($spec['detail_prefix'] . '-' . $r->slug) . '/"' : '';
        $badge = $spec['statuses'][$r->status] ?? $r->status;
        $showBadge = $spec['kind'] === 'listing' || $r->status !== $spec['default_status'];
        $h = '<' . $tag . ' class="lu-cat-card"' . $href . '>';
        if ($withFigure) $h .= '<figure><img src="' . e($this->cardImage($r)) . '" alt="' . e($r->title) . '" loading="lazy">' . ($showBadge ? '<span class="lu-cat-badge' . ($closed ? ' closed' : '') . '">' . e($badge) . '</span>' : '') . '</figure>';
        $h .= '<div class="lu-cat-body">';
        if (! $withFigure && $showBadge) $h .= '<span class="lu-cat-badge inline' . ($closed ? ' closed' : '') . '">' . e($badge) . '</span>';
        if (! $closed || $r->price !== null || ! empty($r->price_label)) { if ($r->price !== null || ! empty($r->price_label) || $spec['kind'] === 'listing') $h .= '<span class="lu-cat-price">' . e($this->priceText($r)) . '</span>'; }
        $h .= '<h3>' . e($r->title) . '</h3>';
        $a = $this->attrs($r);
        if ($spec['kind'] === 'listing' && ! empty($a['location'])) $h .= '<p class="lu-cat-where">' . e($a['location']) . '</p>';
        $specs = $this->specsText($spec, $r);
        if ($specs !== '') $h .= '<p class="lu-cat-specs">' . e($specs) . '</p>';
        if (! empty($r->summary) && $spec['kind'] !== 'listing') $h .= '<p class="lu-cat-sum">' . e($r->summary) . '</p>';
        if ($closed && ! empty($r->closed_note)) $h .= '<p class="lu-cat-note">' . e($r->closed_note) . '</p>';
        $h .= '</div></' . $tag . '>';
        return $h;
    }

    private function indexBody(array $spec, array $open, array $closed): string
    {
        $detail = $spec['pages'] === 'index+detail';
        $h = self::pageCss() . '<section class="lu-cat" id="catalogue-' . e($spec['kind']) . '">';
        $h .= '<h1>' . e($spec['label']) . '</h1>';
        $count = count($open);
        $h .= '<p class="lede">' . ($count === 0 ? 'Nothing is listed at the moment — get in touch and we will let you know as soon as something is available.' : ($detail ? $count . ' ' . ($count === 1 ? 'property' : 'properties') . ' currently available. Open one for the full details and to enquire.' : 'Everything we offer, with prices where they apply. Ask us about anything here.')) . '</p>';
        if ($open !== []) {
            $anyPhoto = $spec['kind'] === 'listing' || array_filter($open, fn($r) => $this->photos($r) !== []) !== [];
            if ($anyPhoto) { $h .= '<div class="lu-cat-grid">'; foreach ($open as $r) $h .= $this->card($spec, $r, $detail); $h .= '</div>'; }
            else {
                $h .= '<div class="lu-cat-list">';
                foreach ($open as $r) {
                    $specs = $this->specsText($spec, $r);
                    $h .= '<div class="lu-cat-row"><div><h3>' . e($r->title) . ($r->status !== $spec['default_status'] ? ' <span class="lu-cat-badge inline">' . e($spec['statuses'][$r->status] ?? $r->status) . '</span>' : '') . '</h3>'
                        . (! empty($r->summary) ? '<p class="lu-cat-sum">' . e($r->summary) . '</p>' : '') . ($specs !== '' ? '<p class="lu-cat-specs">' . e($specs) . '</p>' : '') . '</div>'
                        . (($r->price !== null || ! empty($r->price_label)) ? '<div class="lu-cat-price">' . e($this->priceText($r)) . (function () use ($r, $spec) { try { return app(StorePaymentsService::class)->buttonHtml((int) $r->website_id, $r, $spec['kind'] === 'menu' ? 'Order' : 'Buy now', (string) config('app.url')); } catch (\Throwable $e) { return ''; } })() . '</div>' : '<div></div>') . '</div>';
                }
                $h .= '</div>';
            }
        }
        if ($closed !== [] && $spec['closed_label'] !== '') { $h .= '<h2>' . e($spec['closed_label']) . '</h2><div class="lu-cat-grid">'; foreach ($closed as $r) $h .= $this->card($spec, $r, false); $h .= '</div>'; }
        if (! $detail) $h .= $this->enquiryForm($spec, 'Ask about our ' . strtolower($spec['label']), "I'd like to ask about your " . strtolower($spec['label']) . '.', $spec['label'], true);
        $h .= '</section>';
        return $h;
    }

    private function detailBody(array $spec, object $r): string
    {
        $closed = in_array($r->status, $spec['closed_statuses'], true);
        $photos = $this->photos($r) ?: [self::PLACEHOLDER];
        $a = $this->attrs($r);
        $h = self::pageCss() . '<section class="lu-prop" id="item">';
        $h .= '<a class="back" href="../' . e($spec['page_slug']) . '/">&larr; All ' . e(strtolower($spec['label'])) . '</a>';
        $h .= '<div class="lu-prop-gallery"><div class="main"><img id="lu-prop-main" src="' . e($photos[0]) . '" alt="' . e($r->title) . '"></div>';
        if (count($photos) > 1) { $h .= '<div class="lu-prop-thumbs">'; foreach ($photos as $p) $h .= '<a href="' . e($p) . '" data-lu-thumb><img src="' . e($p) . '" alt="" loading="lazy"></a>'; $h .= '</div>'; }
        $h .= '</div>';
        $h .= '<span class="lu-prop-status' . ($closed ? ' closed' : '') . '">' . e($spec['statuses'][$r->status] ?? $r->status) . '</span>';
        $h .= '<div class="lu-prop-head"><div><h1>' . e($r->title) . '</h1>' . (! empty($a['location']) ? '<p class="lu-prop-where">' . e($a['location']) . '</p>' : '') . '</div>';
        if (! $closed || $r->price !== null || ! empty($r->price_label)) $h .= '<div class="lu-prop-price">' . e($this->priceText($r)) . '</div>';
        $h .= '</div>';
        $facts = [];
        foreach ($spec['attrs'] as $def) {
            $k = $def['key'];
            if (! isset($a[$k]) || $a[$k] === '' || in_array($k, ['location', 'size_unit', 'specs_text'], true)) continue;
            $facts[] = [$def['label'], $def['type'] === 'number' ? ($k === 'size_value' ? number_format((float) $a[$k]) . ' ' . ($a['size_unit'] ?? 'sq ft') : $this->num((float) $a[$k])) : (string) $a[$k]];
        }
        if ($facts === [] && ! empty($a['specs_text'])) $facts[] = ['Details', $a['specs_text']];
        if ($facts !== []) { $h .= '<ul class="lu-prop-facts">'; foreach ($facts as [$k, $v]) $h .= '<li><b>' . e(preg_replace('/\s*\(.*\)$/', '', $k)) . '</b>' . e($v) . '</li>'; $h .= '</ul>'; }
        $h .= '<div class="lu-prop-grid"><div><div class="lu-prop-desc">';
        $desc = trim((string) $r->description);
        if ($desc !== '') { foreach (preg_split('/\n{2,}/', $desc) as $para) $h .= '<p>' . nl2br(e(trim($para))) . '</p>'; }
        elseif (! empty($r->summary)) { $h .= '<p>' . e($r->summary) . '</p>'; }
        else { $h .= '<p>Full details on request — send an enquiry and we will come back to you with everything you need to know.</p>'; }
        $h .= '</div>';
        $features = json_decode((string) ($r->features_json ?: '[]'), true) ?: [];
        if ($features !== []) { $h .= '<h2 style="font-size:20px;margin:8px 0 10px">Features</h2><ul class="lu-prop-features">'; foreach ($features as $f) $h .= '<li>' . e($f) . '</li>'; $h .= '</ul>'; }
        if ($closed && ! empty($r->closed_note)) $h .= '<p class="lu-cat-note"><b>' . e($spec['statuses'][$r->status] ?? '') . ':</b> ' . e($r->closed_note) . '</p>';
        $h .= '</div>';
        // STORE PAYMENTS (DEC-0051): a priced open item gets a checkout button when the workspace takes payments
        if (! $closed && $r->price !== null && (float) $r->price > 0) { try { $h .= app(StorePaymentsService::class)->buttonHtml((int) $r->website_id, $r, $spec['kind'] === 'listing' ? 'Reserve with a deposit' : ($spec['kind'] === 'room' ? 'Book & pay' : 'Buy now'), (string) config('app.url')); } catch (\Throwable $e) {} }
        $prefill = $closed ? 'I saw that ' . $r->title . ' has been ' . strtolower($spec['statuses'][$r->status] ?? 'sold') . ' — please let me know about similar ones.' : "I'm interested in " . $r->title . (! empty($a['location']) ? ' (' . $a['location'] . ')' : '') . ', listed at ' . $this->priceText($r) . '. Please get in touch.';
        $h .= $this->enquiryForm($spec, $closed ? 'Looking for something similar?' : 'Enquire about this ' . $spec['singular'], $prefill, $r->title, false);
        $h .= '</div></section>';
        return $h;
    }

    // ───────────────────────────── Arthur ─────────────────────────────

    /** Does this message talk about one of the site's catalogues? Only consulted with the site's enabled specs. */
    public static function looksLikeCatalogueRequest(string $text, array $specs, int $websiteId = 0): bool
    {
        return self::pickSpec($text, $specs, $websiteId) !== null;
    }

    /** The kind a request is about, or null when it reads as a page/design/copy request. */
    private static function pickSpec(string $text, array $specs, int $websiteId = 0): ?array
    {
        $t = strtolower($text);
        if (preg_match('/\b(section|block|heading|headline|subtitle|eyebrow|intro|paragraph|colou?r|font|image|photo|picture|icon|layout|button|page background|gradient|text|wording|copy|title|label|link|menu link|nav)\b/', $t)) return null;
        // CATALOGUE VERIFICATION (2026-09-20): "add a portfolio page", "add a menu page", "add an events page" are PAGE requests
        // (the page picker's own wording) — they used to become a project/dish/event called "Page" and cost a credit. A location
        // phrase ("add a cardamom bun to the menu page") is stripped first so an item aimed at a page still reads as an item.
        $tp = preg_replace('/\b(to|on|onto|into|of|in|at|for|from|under)\s+(?:the\s+|my\s+|your\s+|this\s+|that\s+)?(?:[a-z\-\/]+\s+){0,3}pages?\b/', ' ', $t) ?? $t;
        if (preg_match('/\b(add|create|make|build|new|need|want)\b.{0,40}\bpages?\b/', $tp)) return null;
        // A catalogue INTENT, not merely a catalogue word: "Change Browse Properties to Check Properties" is a text edit
        // that happens to contain "properties" (Raymundo Realty, 2026-09-14). Only these shapes are catalogue commands:
        $intent = '(add|list|create|post|put up|publish|remove|delete|take down|withdraw|hide|unhide|mark|flag|rename|reprice|sold out|back on|under offer)';
        $priceAsk = (bool) preg_match('/\b(price|cost|rate|fee)\b.{0,40}\bto\b|\b(reduce|lower|raise|increase|cut|drop|change|update|set)\b.{0,30}\b(price|cost|rate|fee)\b/', $t);
        $verbs = $intent;
        foreach ($specs as $spec) {
            $nouns = '(' . $spec['nouns'] . ')';
            if (preg_match('/\b' . $intent . '\b.{0,60}\b' . $nouns . '\b/', $t) || preg_match('/\b' . $nouns . '\b.{0,60}\b' . $intent . '\b/', $t)) return $spec;
            if ($priceAsk && preg_match('/\b' . $nouns . '\b/', $t)) return $spec;
        }
        if ($priceAsk) $verbs = '(' . trim($intent, '()') . '|price|cost|rate|fee)';
        // an item named in full or by its distinctive words: "remove the cardamom bun", "mark the Land Cruiser as sold"
        if ($websiteId > 0 && preg_match('/\b' . $verbs . '\b/', $t)) {
            $phrase = (string) (preg_split('/\b(as|to|for|at|with|is|are|now|because|from)\b|[,;—–:]/', $t, 2)[0] ?? $t);
            $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', $phrase), fn($w) => strlen($w) >= 3 && ! in_array($w, self::STOP, true) && ! preg_match('/^\d+$/', $w)));
            $hits = [];
            foreach ($specs as $spec) {
                $nounWords = array_filter(explode('|', $spec['nouns']), fn($n) => ! str_contains($n, ' '));
                $ws = array_values(array_diff($words, $nounWords));
                foreach (DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', $spec['kind'])->whereNull('deleted_at')->pluck('title') as $title) {
                    $tl = strtolower($title);
                    if (mb_strlen($title) >= 3 && str_contains($t, $tl)) return $spec;
                    if ($ws !== [] && array_filter($ws, fn($w) => ! str_contains($tl, $w)) === []) $hits[$spec['kind']] = $spec;
                }
            }
            if (count($hits) === 1) return array_values($hits)[0];
        }
        // one catalogue only: "mark the Modern Bungalow as sold", "reduce the price of Deep Tissue to $90"
        if (count($specs) === 1 && preg_match('/\b(mark|sold|under offer|sold out|reprice|price of|the price)\b/', $t)) return array_values($specs)[0];
        return null;
    }

    public function arthur(int $wsId, int $websiteId, string $request, array $ctx = []): array
    {
        $this->viaArthur = true;
        $base = ['kind' => 'catalogue', 'credits' => 0, 'applied' => 0, 'actions_applied' => 0];
        $site = $this->owned($wsId, $websiteId);
        $specs = $site ? $this->specs($websiteId, $site) : [];
        $spec = self::pickSpec($request, $specs, $websiteId);
        if (! $site || ! $spec) return $base + ['success' => false, 'code' => 'NO_CATALOGUE', 'message' => 'This design does not carry that catalogue.'];
        $kind = $spec['kind']; $t = trim($request); $lower = strtolower($t);
        $actor = (int) ($ctx['user_id'] ?? 0) ?: null;
        if (preg_match('/\b(turn|switch)\b.{0,30}\b(on|off)\b|\b(on|off)\b.{0,20}\b(site|page|website)\b/', $lower)) {
            $on = ! preg_match('/\boff\b/', $lower);
            $res = $this->setEnabled($wsId, $websiteId, $kind, $on);
            return $base + ['success' => (bool) ($res['success'] ?? false), 'applied' => 1, 'actions_applied' => 1, 'credits' => (int) ($res['credits'] ?? 0), 'message' => (string) ($res['message'] ?? '')];
        }
        if (! $spec['enabled']) return $base + ['success' => false, 'code' => 'CATALOGUE_OFF', 'message' => $spec['label'] . ' are switched off for this site. Say "turn ' . strtolower($spec['label']) . ' on" and I will bring the page back.'];
        if (empty($spec['seeded_at'])) { $this->seedFromSite($wsId, $websiteId, $site, $spec); }
        $rows = $this->rows($websiteId, $kind);
        $open = array_values(array_filter($rows, fn($r) => in_array($r->status, $spec['open'], true)));
        $nouns = '(' . $spec['nouns'] . ')';

        // ADD
        if (preg_match('/\b(add|list|create|post|put up|publish)\b/', $lower) && ! preg_match('/\b(mark|sold|remove|delete|take down)\b/', $lower)) {
            $in = $kind === 'listing' ? $this->parseListingAdd($t, $spec['currency']) : $this->parseGenericAdd($t, $spec);
            if (($in['title'] ?? '') === '') return $base + ['success' => false, 'code' => 'NEED_TITLE', 'message' => 'Tell me what to add — for example: "Add a ' . $spec['singular'] . ': ' . ($kind === 'listing' ? '3-bed townhouse in Travis Heights, $925,000, 2 baths, 1,850 sq ft' : 'Deep tissue massage, $90, 60 minutes') . '".'];
            $res = $this->create($wsId, $websiteId, $kind, $in, $actor, 'arthur');
            if (empty($res['success'])) return $base + ['success' => false, 'code' => $res['code'] ?? 'INVALID', 'message' => (string) ($res['message'] ?? 'I could not add that.')];
            $L = $res['item'];
            $missing = [];   // the price note comes from create(); only a listing without a photo needs saying
            if ($kind === 'listing' && $L['photos'] === []) $missing[] = 'photo';
            $placement = (string) substr((string) $res['message'], (int) strpos((string) $res['message'], '.') + 1);   // where create() put it (home page or list page only)
            if ($L['page'] && str_contains($placement, 'its own page')) $placement = str_replace('its own page', 'its own page at ' . $L['page'], $placement);
            $msg = 'Added “' . $L['title'] . '”' . ($L['price'] !== null || $L['price_label'] ? ' at ' . $L['price_display'] : '') . ($L['specs_display'] !== '' ? ' (' . $L['specs_display'] . ')' : '') . '.' . $placement;
            if ($missing !== []) $msg .= ' I did not invent the ' . implode(' or ', $missing) . ' — add ' . (count($missing) > 1 ? 'them' : 'it') . ' in the ' . $spec['label'] . ' panel or tell me here.';
            return $base + ['success' => true, 'applied' => 1, 'actions_applied' => 1, 'message' => $msg, 'item' => $L];
        }

        // STATUS
        $statusWord = null;
        foreach ($spec['statuses'] as $code => $label) {
            $needle = strtolower($label);
            if ($needle !== '' && preg_match('/\b' . preg_quote($needle, '/') . '\b/', $lower) && $code !== $spec['default_status']) { $statusWord = $code; }
        }
        if ($kind === 'listing') {
            if (preg_match('/\bunder offer\b/', $lower)) $statusWord = 'under_offer';
            elseif (preg_match('/\b(withdrawn|off the market|off market)\b/', $lower)) $statusWord = 'withdrawn';
            elseif (preg_match('/\b(let|rented)\b/', $lower) && ! preg_match('/\bsold\b/', $lower)) $statusWord = 'let';
            elseif (preg_match('/\bsold\b/', $lower)) $statusWord = 'sold';
        } else {
            if (preg_match('/\b(sold out|out of stock|unavailable)\b/', $lower)) $statusWord = isset($spec['statuses']['sold_out']) ? 'sold_out' : 'hidden';
            elseif (preg_match('/\b(hide|hidden|take off|off the menu|off the list)\b/', $lower)) $statusWord = 'hidden';
            elseif (preg_match('/\b(available again|back on|back in stock|show again|unhide|restore)\b/', $lower)) $statusWord = 'active';
        }
        // back to the default state when said so: "as for sale", "available again", "back on the menu"
        $defaultLabel = strtolower($spec['statuses'][$spec['default_status']] ?? '');
        if ($defaultLabel !== '' && preg_match('/\b(as|back|to|now|again)\b.{0,12}\b' . preg_quote($defaultLabel, '/') . '\b|\b' . preg_quote($defaultLabel, '/') . '\b.{0,6}\bagain\b/', $lower)) $statusWord = $spec['default_status'];
        if ($statusWord !== null && ! preg_match('/\b(remove|delete|take down)\b/', $lower) && ! preg_match('/\bprice\b.{0,20}\bto\b/', $lower)) {
            $pool = $statusWord === 'active' ? $rows : $rows;
            $target = $this->findTarget($spec, $t,$rows, $pool);
            if (! $target) return $base + ['success' => false, 'code' => 'WHICH', 'message' => $this->whichOne($spec, $rows, 'mark as ' . strtolower($spec['statuses'][$statusWord]))];
            $note = null;
            if ($kind === 'listing') {
                if (preg_match('/\b(for|at)\s+((?:[\$€£₱]|AED|USD|EUR|GBP)\s?\d[\d,\.]*\d\s*[kKmM]?|(?:[\$€£₱]|AED|USD|EUR|GBP)\s?\d\s*[kKmM]?)\b/u', $t, $pm)) $note = 'Sold for ' . rtrim(trim($pm[2]), ',.');
                if (preg_match('/\b(over asking|under asking|above asking|below asking|in \d+ days|within \d+ days|multiple offers|full asking)\b[^.]*/i', $t, $nm)) $note = trim(($note ? $note . ', ' : '') . $nm[0]);
            }
            $res = $this->setStatus($wsId, $websiteId, $kind, (int) $target->id, $statusWord, $note);
            if (empty($res['success'])) return $base + ['success' => false, 'message' => (string) ($res['message'] ?? 'I could not change that.')];
            $msg = '“' . $target->title . '” is now marked ' . strtolower($spec['statuses'][$statusWord]);
            if (in_array($statusWord, $spec['closed_statuses'], true)) $msg .= $spec['closed'] ? ' — it moved to the ' . strtolower($spec['closed_label'] ?: 'closed') . ' row on the home page and the ' . $spec['label'] . ' page' : ' — it shows under ' . strtolower($spec['closed_label'] ?: 'closed') . ' on the ' . $spec['label'] . ' page';
            if ($note) { $msg .= ', with the note “' . $note . '” — only what you told me.'; }
            else { $msg .= in_array($statusWord, $spec['closed_statuses'], true) ? '. I did not add a sale price or outcome; tell me and I will note it.' : '.'; }
            return $base + ['success' => true, 'applied' => 1, 'actions_applied' => 1, 'message' => $msg];
        }

        // PRICE
        if (preg_match('/\bprice\b|\b(reduce|lower|raise|increase|drop|cut|reprice)\b/', $lower) && preg_match('/\bto\s+((?:[\$€£₱]|AED|USD|EUR|GBP|PHP)\s?[\d,\.]+\s*[kKmM]?|[\d,\.]+\s*[kKmM]?\s*(?:AED|USD|EUR|GBP|PHP|dollars|euros|pounds)?)\b/iu', $t, $pm)) {
            $target = $this->findTarget($spec, $t,$rows, $open ?: $rows);
            if (! $target) return $base + ['success' => false, 'code' => 'WHICH', 'message' => $this->whichOne($spec, $open ?: $rows, 'reprice')];
            $price = $this->moneyToNumber($pm[1]);
            if ($price === null) return $base + ['success' => false, 'message' => 'I could not read the new price — say it as a number, e.g. "to $90".'];
            $in = ['price' => $price, 'price_label' => ''];
            $cur = $this->currencyFromText($pm[1], (string) $target->currency);
            if ($cur !== (string) $target->currency) $in['currency'] = $cur;
            if (preg_match('/\b(per|a|\/)\s*(month|week|night|hour|person|session|year)\b/', $lower, $per)) $in['price_period'] = $per[2];
            $res = $this->update($wsId, $websiteId, $kind, (int) $target->id, $in, 'catalogue_price');
            if (empty($res['success'])) return $base + ['success' => false, 'message' => (string) ($res['message'] ?? 'I could not change that price.')];
            $openIds = array_map(fn($r) => (int) $r->id, $open);
            $pos = array_search((int) $target->id, $openIds, true);
            $onHome = $pos !== false && $pos < $spec['slots'];
            return $base + ['success' => true, 'applied' => 1, 'actions_applied' => 1, 'credits' => (int) ($res['credits'] ?? 0), 'message' => '“' . $target->title . '” is now ' . $res['item']['price_display'] . ($onHome ? ' on the home page and the ' : ' on the ') . $spec['label'] . ' page' . ($spec['pages'] === 'index+detail' ? ' and its own page' : '') . '.' . \App\Engines\Builder\Support\EditorCredits::suffix((int) ($res['credits'] ?? 0))];
        }

        // RENAME
        if (preg_match('/\b(rename|call)\b.{0,80}\b(to|as)\s+["“]?([^"”]{2,120})["”]?\s*$/iu', $t, $rm)) {
            $target = $this->findTarget($spec, preg_replace('/\b(to|as)\s+["“]?' . preg_quote($rm[3], '/') . '.*$/iu', '', $t) ?? $t, $rows, $rows);
            if (! $target) return $base + ['success' => false, 'code' => 'WHICH', 'message' => $this->whichOne($spec, $rows, 'rename')];
            $res = $this->update($wsId, $websiteId, $kind, (int) $target->id, ['title' => trim($rm[3], " .\"”“")], 'catalogue_rename');
            return $base + ['success' => (bool) ($res['success'] ?? false), 'applied' => 1, 'actions_applied' => 1, 'credits' => (int) ($res['credits'] ?? 0), 'message' => (string) ($res['message'] ?? '')];
        }

        // REMOVE
        if (preg_match('/\b(remove|delete|take down|drop|withdraw)\b/', $lower)) {
            $target = $this->findTarget($spec, $t,$rows, $rows);
            if (! $target) return $base + ['success' => false, 'code' => 'WHICH', 'message' => $this->whichOne($spec, $rows, 'remove')];
            $res = $this->delete($wsId, $websiteId, $kind, (int) $target->id);
            return $base + ['success' => (bool) ($res['success'] ?? false), 'applied' => 1, 'actions_applied' => 1, 'credits' => (int) ($res['credits'] ?? 0), 'message' => (string) ($res['message'] ?? '')];
        }

        // Nothing here matched a catalogue command: hand the request back so the copy / design paths answer it.
        return $base + ['success' => false, 'code' => 'PASS', 'message' => ''];
    }

    /**
     * Structured entry for the model-first path (DEC-0050): the model already parsed kind, action and parameters.
     * Ambiguity comes back as code WHICH with tappable options; an action this catalogue cannot do comes back as PASS.
     */
    public function execute(int $wsId, int $websiteId, array $p, array $ctx = []): array
    {
        $this->viaArthur = true;
        $base = ['kind' => 'catalogue', 'credits' => 0, 'applied' => 0, 'actions_applied' => 0];
        $site = $this->owned($wsId, $websiteId);
        $kind = (string) ($p['kind'] ?? '');
        $spec = $site ? $this->spec($websiteId, $kind, $site) : null;
        if (! $site || ! $spec) return $base + ['success' => false, 'code' => 'PASS', 'message' => ''];
        $action = strtolower((string) ($p['action'] ?? ''));
        $actor = (int) ($ctx['user_id'] ?? 0) ?: null;
        if ($action === 'toggle') {
            $on = ! in_array(strtolower((string) ($p['status'] ?? 'on')), ['off', 'hidden', 'disable', 'disabled'], true);
            $res = $this->setEnabled($wsId, $websiteId, $kind, $on);
            return $base + ['success' => (bool) ($res['success'] ?? false), 'applied' => 1, 'actions_applied' => 1, 'credits' => (int) ($res['credits'] ?? 0), 'message' => (string) ($res['message'] ?? '')];
        }
        if (! $spec['enabled']) return $base + ['success' => false, 'code' => 'CATALOGUE_OFF', 'message' => $spec['label'] . ' are switched off for this site — say "turn ' . strtolower($spec['label']) . ' on" and I will bring the page back.'];
        if (empty($spec['seeded_at'])) { $this->seedFromSite($wsId, $websiteId, $site, $spec); }
        $rows = $this->rows($websiteId, $kind);
        if ($action === 'add') {
            $in = ['title' => trim((string) ($p['title'] ?? $p['item'] ?? '')), 'status' => (string) ($p['status'] ?? $spec['default_status'])];
            if (! isset($spec['statuses'][$in['status']])) $in['status'] = $spec['default_status'];
            foreach (['price', 'currency', 'period' => 'price_period', 'summary', 'note' => 'closed_note'] as $from => $to) { if (is_int($from)) $from = $to; if (isset($p[$from]) && $p[$from] !== '' && $p[$from] !== null) $in[$to] = $p[$from]; }
            foreach ((array) ($p['attrs'] ?? []) as $k => $v) { if ($v !== '' && $v !== null) $in[$k] = $v; }
            if ($in['title'] === '') return $base + ['success' => false, 'code' => 'WHICH', 'message' => 'What should the new ' . $spec['singular'] . ' be called?', 'options' => []];
            $res = $this->create($wsId, $websiteId, $kind, $in, $actor, 'arthur');
            if (empty($res['success'])) return $base + ['success' => false, 'code' => $res['code'] ?? 'INVALID', 'message' => (string) ($res['message'] ?? '')];
            $L = $res['item'];
            $placement = (string) substr((string) $res['message'], (int) strpos((string) $res['message'], '.') + 1);
            if ($L['page'] && str_contains($placement, 'its own page')) $placement = str_replace('its own page', 'its own page at ' . $L['page'], $placement);
            return $base + ['success' => true, 'applied' => 1, 'actions_applied' => 1, 'item' => $L, 'credits' => (int) ($res['credits'] ?? 0), 'message' => 'Added “' . $L['title'] . '”' . ($L['price'] !== null || $L['price_label'] ? ' at ' . $L['price_display'] : '') . ($L['specs_display'] !== '' ? ' (' . $L['specs_display'] . ')' : '') . '.' . $placement];
        }
        if ($action === 'restore') {   // bring back something removed by chat or in the panel
            $name = mb_strtolower(trim((string) ($p['item'] ?? $p['title'] ?? '')));
            $q = DB::table('catalogue_items')->where('website_id', $websiteId)->where('kind', $kind)->whereNotNull('deleted_at')->orderByDesc('deleted_at');
            $gone = $name !== '' ? ((clone $q)->whereRaw('LOWER(title) = ?', [$name])->first() ?: (clone $q)->whereRaw('LOWER(title) LIKE ?', ['%' . $name . '%'])->first()) : $q->first();
            if (! $gone) return $base + ['success' => false, 'code' => 'NOTHING_TO_RESTORE', 'message' => 'Nothing has been removed from ' . strtolower($spec['label']) . ' that I could bring back.'];
            if ($no = $this->affordOrFail($wsId)) return $base + $no;
            DB::table('catalogue_items')->where('id', $gone->id)->update(['deleted_at' => null, 'updated_at' => now()]);
            $this->sync($websiteId, $kind, 'catalogue_restore');
            $this->chargeItem($wsId, $websiteId, 'restore', (int) $gone->id);
            return array_merge($base, $this->priced(['success' => true, 'applied' => 1, 'actions_applied' => 1, 'message' => '“' . $gone->title . '” is back on the site.']));
        }
        // every other action needs one item
        $name = trim((string) ($p['item'] ?? $p['title'] ?? ''));
        $target = null;
        foreach ($rows as $r) { if (mb_strtolower($r->title) === mb_strtolower($name)) { $target = $r; break; } }
        if (! $target && $name !== '') $target = $this->findTarget($spec, $name, $rows, $rows);
        if (! $target) {
            $opts = array_map(fn($r) => (string) $r->title, array_slice($rows, 0, 4));
            return $base + ['success' => false, 'code' => 'WHICH', 'message' => $rows === [] ? 'There are no ' . strtolower($spec['label']) . ' on this site yet — shall I add one?' : 'Which ' . $spec['singular'] . ' do you mean?', 'options' => $opts];
        }
        // A removal or a status change is judged on the CUSTOMER's words, not the model's pick: when two items share the words
        // they used ("the Hyde Park one" with two Hyde Park listings), ask — even if the model chose one of them.
        if (in_array($action, ['remove', 'status'], true) && ! empty($p['_customer'])) {
            $said = mb_strtolower((string) $p['_customer']);
            if (! str_contains($said, mb_strtolower($target->title))) {
                $phrase = (string) (preg_split('/\b(as|to|for|at|with|is|are|now|because|from)\b|[,;—–:]/', $said, 2)[0] ?? $said);
                // "the charming home" names exactly one title as a phrase, even though "charming" alone fits two
                $core = trim(preg_replace('/\s+/', ' ', preg_replace('/\b(the|a|an|that|this|one|please|remove|delete|take|down|mark|set|hide|unhide|show|flag|listing|listings|item|items|it)\b/', ' ', $phrase) ?? $phrase) ?? $phrase);
                $hay = fn($r) => mb_strtolower($r->title . ' ' . (($this->attrs($r)['location'] ?? '')));
                if (mb_strlen($core) >= 4) {
                    $byPhrase = array_values(array_filter($rows, fn($r) => str_contains($hay($r), $core)));
                    if (count($byPhrase) === 1) { $target = $byPhrase[0]; goto resolved; }
                }
                // otherwise every distinctive word the customer used must sit in ONE item; two → ask; none → trust the model's pick
                $nounWords = array_filter(explode('|', $spec['nouns']), fn($n) => ! str_contains($n, ' '));
                $words = array_values(array_filter(array_diff(preg_split('/[^a-z0-9]+/', $phrase), self::STOP, $nounWords), fn($w) => strlen($w) >= 3 && ! preg_match('/^\d+$/', $w)));
                if ($words !== []) {
                    $all = array_values(array_filter($rows, function ($r) use ($words, $hay) { $tl = $hay($r); foreach ($words as $w) { if (! str_contains($tl, $w)) return false; } return true; }));
                    if (count($all) > 1) {
                        return $base + ['success' => false, 'code' => 'WHICH', 'message' => count($all) . ' ' . strtolower($spec['label']) . ' match that — which one?', 'options' => array_map(fn($r) => (string) $r->title, array_slice($all, 0, 4))];
                    }
                    if (count($all) === 1) { $target = $all[0]; }
                }
            }
        }
        resolved:
        if ($action === 'price') {
            $in = [];
            if (isset($p['price']) && $p['price'] !== '' && $p['price'] !== null) { $in['price'] = $p['price']; $in['price_label'] = ''; }
            if (! empty($p['currency'])) $in['currency'] = $p['currency'];
            if (isset($p['period'])) $in['price_period'] = $p['period'];
            if ($in === []) return $base + ['success' => false, 'code' => 'WHICH', 'message' => 'What should the new price of “' . $target->title . '” be?', 'options' => []];
            $res = $this->update($wsId, $websiteId, $kind, (int) $target->id, $in, 'catalogue_price');
            if (empty($res['success'])) return $base + ['success' => false, 'message' => (string) ($res['message'] ?? '')];
            $open = array_values(array_filter($this->rows($websiteId, $kind), fn($r) => in_array($r->status, $spec['open'], true)));
            $pos = array_search((int) $target->id, array_map(fn($r) => (int) $r->id, $open), true);
            $onHome = $pos !== false && $pos < $spec['slots'];
            return $base + ['success' => true, 'applied' => 1, 'actions_applied' => 1, 'credits' => (int) ($res['credits'] ?? 0), 'message' => '“' . $target->title . '” is now ' . $res['item']['price_display'] . ($onHome ? ' on the home page and the ' : ' on the ') . $spec['label'] . ' page' . ($spec['pages'] === 'index+detail' ? ' and its own page' : '') . '.' . \App\Engines\Builder\Support\EditorCredits::suffix((int) ($res['credits'] ?? 0))];
        }
        if ($action === 'status') {
            $status = (string) ($p['status'] ?? '');
            if (! isset($spec['statuses'][$status])) { $status = (string) array_search(mb_strtolower($status), array_map('mb_strtolower', $spec['statuses']), true); }
            if ($status === '' || ! isset($spec['statuses'][$status])) return $base + ['success' => false, 'code' => 'WHICH', 'message' => 'Which status should “' . $target->title . '” have?', 'options' => array_slice(array_values($spec['statuses']), 0, 4)];
            $note = isset($p['note']) && trim((string) $p['note']) !== '' ? trim((string) $p['note']) : null;
            $res = $this->setStatus($wsId, $websiteId, $kind, (int) $target->id, $status, $note);
            if (empty($res['success'])) return $base + ['success' => false, 'message' => (string) ($res['message'] ?? '')];
            $msg = '“' . $target->title . '” is now marked ' . strtolower($spec['statuses'][$status]);
            if (in_array($status, $spec['closed_statuses'], true)) $msg .= $spec['closed'] ? ' — it moved to the ' . strtolower($spec['closed_label'] ?: 'closed') . ' row on the home page and the ' . $spec['label'] . ' page' : ' — it shows under ' . strtolower($spec['closed_label'] ?: 'closed') . ' on the ' . $spec['label'] . ' page';
            $msg .= $note ? ', with the note “' . $note . '” — only what you told me.' : '.';
            return $base + ['success' => true, 'applied' => 1, 'actions_applied' => 1, 'credits' => (int) ($res['credits'] ?? 0), 'message' => $msg . \App\Engines\Builder\Support\EditorCredits::suffix((int) ($res['credits'] ?? 0))];
        }
        if ($action === 'rename') {
            $new = trim((string) ($p['title'] ?? ''));
            if ($new === '' || mb_strtolower($new) === mb_strtolower($target->title)) return $base + ['success' => false, 'code' => 'WHICH', 'message' => 'What should “' . $target->title . '” be called?', 'options' => []];
            $res = $this->update($wsId, $websiteId, $kind, (int) $target->id, ['title' => $new], 'catalogue_rename');
            return $base + ['success' => (bool) ($res['success'] ?? false), 'applied' => 1, 'actions_applied' => 1, 'credits' => (int) ($res['credits'] ?? 0), 'message' => (string) ($res['message'] ?? '')];
        }
        if ($action === 'remove') {
            $res = $this->delete($wsId, $websiteId, $kind, (int) $target->id);
            return $base + ['success' => (bool) ($res['success'] ?? false), 'applied' => 1, 'actions_applied' => 1, 'credits' => (int) ($res['credits'] ?? 0), 'message' => (string) ($res['message'] ?? '')];
        }
        return $base + ['success' => false, 'code' => 'PASS', 'message' => ''];
    }

    private function whichOne(array $spec, array $rows, string $verb): string
    {
        if ($rows === []) return 'There are no ' . strtolower($spec['label']) . ' on this site yet — say "add a ' . $spec['singular'] . ': …" with the name and price.';
        $names = array_map(function ($r) { $a = $this->attrs($r); return '“' . $r->title . '”' . (! empty($a['location']) ? ' (' . $a['location'] . ')' : ''); }, array_slice($rows, 0, 8));
        return 'Which one should I ' . $verb . '? Current ' . strtolower($spec['label']) . ': ' . implode(', ', $names) . (count($rows) > 8 ? ' and ' . (count($rows) - 8) . ' more' : '') . '.';
    }

    /** Which item is meant: an ordinal ("the second service"), a price, or the best word overlap with title/location. */
    private function findTarget(array $spec, string $text, array $all, array $pool): ?object
    {
        $pool = $pool ?: $all;
        if ($pool === []) return null;
        $t = strtolower($text);
        if (count($pool) === 1 && preg_match('/\b(it|that|this|the (listing|property|one|service|item|dish))\b/', $t)) return $pool[0];
        $ordinals = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6, 'last' => count($pool)];
        if (preg_match('/\b(?:listing|property|service|item|dish|treatment|product|course|room|package|programme|program)\s*(?:#|no\.?|number)?\s*(\d)\b/', $t, $m) && isset($pool[(int) $m[1] - 1])) return $pool[(int) $m[1] - 1];
        if (preg_match('/\b(first|second|third|fourth|fifth|sixth|last)\b\s+(?:listing|property|service|item|dish|treatment|product|course|room|package|programme|program|one)\b/', $t, $m) && isset($pool[$ordinals[$m[1]] - 1])) return $pool[$ordinals[$m[1]] - 1];
        if (preg_match_all('/(?:[\$€£₱]|AED|USD|EUR|GBP)\s?([\d,\.]+\s*[kKmM]?)/iu', $text, $pm)) {
            foreach ($pm[1] as $raw) { $n = $this->moneyToNumber($raw); foreach ($pool as $r) { if ($n !== null && $r->price !== null && abs((float) $r->price - $n) < 0.5) return $r; } }
        }
        $stop = array_merge(self::STOP, array_filter(explode('|', $spec['nouns']), fn($n) => ! str_contains($n, ' ')));
        // an item named in full wins outright ("mark the cardamom bun as sold out")
        $byTitle = array_values(array_filter($pool, fn($r) => mb_strlen($r->title) >= 3 && str_contains($t, strtolower($r->title))));
        if (count($byTitle) === 1) return $byTitle[0];
        $words = array_values(array_diff(array_filter(preg_split('/[^a-z0-9]+/', $t)), $stop));
        $best = null; $bestScore = 0; $bestHit = 0;
        foreach ($pool as $r) {
            $a = $this->attrs($r);
            $hay = strtolower($r->title . ' ' . ($a['location'] ?? ''));
            $hw = array_filter(preg_split('/[^a-z0-9]+/', $hay));
            $score = 0; $hit = 0; $miss = 0;
            foreach ($words as $w) { if (strlen($w) < 3) continue; if (in_array($w, $hw, true)) { $score += 2; $hit++; } elseif (str_contains($hay, $w)) { $score += 1; $hit++; } else { $miss++; } }
            if ($score > $bestScore) { $bestScore = $score; $best = $r; $bestHit = $hit; }
            elseif ($score === $bestScore && $score > 0) { $best = null; }
        }
        if ($best === null || $bestScore < 2) return null;
        // every descriptive word the customer used must belong to the chosen item — "board advisory" never means "Advisory Retainer"
        // (judged on the naming phrase only — what comes before "as sold", "to $2,000", "for …" is the name, the rest is the instruction)
        $aB = $this->attrs($best); $hayB = strtolower($best->title . ' ' . ($aB['location'] ?? ''));
        $phrase = (string) (preg_split('/\b(as|to|for|at|with|is|are|now|because|from)\b|[,;—–:]/', $t, 2)[0] ?? $t);
        $descriptive = array_values(array_diff(array_filter(preg_split('/[^a-z0-9]+/', $phrase), fn($w) => strlen($w) >= 3 && ! preg_match('/^\d+$/', $w)), $stop));
        $unknown = array_filter($descriptive, fn($w) => ! str_contains($hayB, $w));
        if ($unknown !== []) return null;
        return $best;
    }

    private function moneyToNumber(string $raw): ?float
    {
        $raw = strtolower(trim($raw));
        $mult = str_ends_with($raw, 'm') ? 1000000 : (str_ends_with($raw, 'k') ? 1000 : 1);
        $n = preg_replace('/[^\d.]/', '', $raw);
        if ($n === '' || ! is_numeric($n)) return null;
        return round((float) $n * $mult, 2);
    }

    /** "Add a service: Deep tissue massage, $90, 60 minutes — releases knots and tension" */
    private function parseGenericAdd(string $text, array $spec): array
    {
        $in = ['title' => '', 'status' => $spec['default_status']];
        $t = $text;
        $attrKeys = array_column($spec['attrs'], 'key');
        // money: a currency-led amount first ("AED 245,000", "$199"), then a number followed by a currency word; never a bare year
        $pm = null;
        if (preg_match('/(?:[\$€£₱]|\b(?:AED|USD|EUR|GBP|PHP|CAD|AUD|SAR|QAR)\b)\s?(\d(?:[\d,\.]*\d)?\s*[kKmM]?)\b/iu', $t, $m1)) { $pm = [$m1[0], $m1[1]]; }
        elseif (preg_match('/\b(\d(?:[\d,\.]*\d)?\s*[kKmM]?)\s*(?:AED|USD|EUR|GBP|PHP|SAR|QAR|dollars|euros|pounds|dirhams)\b/iu', $t, $m2)) { $pm = [$m2[0], $m2[1]]; }
        if ($pm) {
            $num = $this->moneyToNumber($pm[1]);
            if ($num !== null) { $in['price'] = $num; $in['currency'] = $this->currencyFromText($pm[0], $spec['currency']); }
            if (preg_match('/' . preg_quote($pm[0], '/') . '\s*(?:per|a|\/)\s*(month|week|night|hour|person|session|year|visit|day|class)\b/i', $t, $per)) { $in['price_period'] = strtolower($per[1]); $t = str_replace($per[0], ' ', $t); }
            $t = str_replace($pm[0], ' ', $t);
        }
        if (in_array('duration', $attrKeys, true) && preg_match('/\b(\d+(?:\.\d)?)\s*(min|mins|minutes|minute|hr|hrs|hour|hours|h)\b/i', $t, $dm)) { $u = strtolower($dm[2]); $in['duration'] = $dm[1] . ' ' . (str_starts_with($u, 'h') ? ($dm[1] == 1 ? 'hour' : 'hours') : 'min'); $t = str_replace($dm[0], ' ', $t); }
        if (in_array('mileage', $attrKeys, true) && preg_match('/\b(\d[\d,\.]*)\s*(km|kms|miles|mi)\b/i', $t, $mm)) { $in['mileage'] = $mm[1] . ' ' . (str_starts_with(strtolower($mm[2]), 'k') ? 'km' : 'miles'); $t = str_replace($mm[0], ' ', $t); }
        if (in_array('year', $attrKeys, true) && preg_match('/\b((?:19|20)\d{2})\b/', $t, $ym)) { $in['year'] = $ym[1]; }
        if (in_array('engine', $attrKeys, true) && preg_match('/\b(\d\.\d\s?L(?:\s+[A-Za-z0-9\-]+){0,3}|electric|hybrid|diesel|petrol)\b/i', $t, $em)) { $in['engine'] = trim($em[1]); $t = str_replace($em[0], ' ', $t); }
        if (in_array('size', $attrKeys, true) && preg_match('/\b(\d[\d,\.]*\s*(?:sqm|sq ?m|m²|sq ?ft|sqft)[^,;]*)/iu', $t, $sm)) { $in['size'] = trim($sm[1], " ·-"); $t = str_replace($sm[0], ' ', $t); }
        if (in_array('age', $attrKeys, true) && preg_match('/\b(?:ages?|for)\s+(\d{1,2}\s*[-–]\s*\d{1,2}(?:\s*(?:years|yrs|months))?|\d{1,2}\+)/i', $t, $am)) { $in['age'] = trim($am[1]); $t = str_replace($am[0], ' ', $t); }
        if (in_array('time', $attrKeys, true) && preg_match('/\b(\d{1,2}[:.]\d{2}\s*(?:am|pm)?|\d{1,2}\s*(?:am|pm))\b/i', $t, $tm)) { $in['time'] = trim($tm[1]); $t = str_replace($tm[0], ' ', $t); }
        if (in_array('trainer', $attrKeys, true) && preg_match('/\b(?:coach|trainer|instructor|with)\s+([A-Z][a-z]+(?:\s[A-Z][a-z]+)?)/u', $t, $trm)) { $in['trainer'] = $trm[1]; $t = str_replace($trm[0], ' ', $t); }
        if (in_array('client', $attrKeys, true) && preg_match('/\bclient\s+([^,;—]+)/iu', $t, $cm)) { $in['client'] = trim($cm[1]); $t = str_replace($cm[0], ' ', $t); }
        if (in_array('venue', $attrKeys, true) && preg_match('/\b(\d[\d,]*\s*guests?)\b/iu', $t, $vm)) { $in['venue'] = $vm[1]; $t = str_replace($vm[0], ' ', $t); }
        $core = preg_replace('/^.*?\b(?:add|list|create|post|put up|publish)\b\s*(?:a |an |new |another |this )?(?:(?:' . $spec['nouns'] . ')\b\s*(?:called|named|for|of|:)?\s*)?(?:a |an )?/i', '', $t, 1) ?? $t;
        $parts = preg_split('/\s*(?:,|;|—|–| - |:)\s*/', trim($core), 2);
        $title = trim((string) ($parts[0] ?? ''), " ,.;:-\"”“");
        $rest = trim((string) ($parts[1] ?? ''), " ,.;:-");
        $title = preg_replace('/\b(to the (?:site|website|menu|list|page)|on the (?:site|website|menu|page)|please)\b/i', '', $title) ?? $title;
        $title = trim(preg_replace('/\s+/', ' ', $title) ?? $title, " ,.;:-");
        if (mb_strlen($title) >= 2 && mb_strlen($title) <= 120) $in['title'] = mb_strtoupper(mb_substr($title, 0, 1)) . mb_substr($title, 1);
        $rest = trim(preg_replace('/\s+/', ' ', preg_replace('/\b(to the (?:site|website|menu|list|page)|on the (?:site|website|menu|page)|please)\b/i', '', $rest) ?? $rest) ?? $rest, " ,.;:-");
        if (mb_strlen($rest) >= 8) $in['summary'] = mb_substr($rest, 0, 300);
        return $in;
    }

    /** "Add a listing: 3-bed townhouse in Travis Heights, $925,000, 2 baths, 1,850 sq ft, to let" */
    private function parseListingAdd(string $text, string $defaultCurrency): array
    {
        $in = ['title' => '', 'status' => 'for_sale'];
        $t = $text; $lower = strtolower($t);
        if (preg_match('/\b(to let|for rent|rental|to rent|per month|\/month|a month|pcm)\b/', $lower)) { $in['status'] = 'to_let'; $in['price_period'] = 'month'; }
        if (preg_match('/\bunder offer\b/', $lower)) $in['status'] = 'under_offer';
        if (preg_match('/(?:[\$€£₱]|\b(?:AED|USD|EUR|GBP|PHP|CAD|AUD)\b)\s?([\d][\d,\.]*\s*[kKmM]?)\b|\b([\d][\d,\.]*\s*[kKmM]?)\s*(?:AED|USD|EUR|GBP|PHP|dollars|euros|pounds)\b/iu', $t, $pm)) {
            $num = $this->moneyToNumber(($pm[1] ?? '') !== '' ? $pm[1] : ($pm[2] ?? ''));
            if ($num !== null) { $in['price'] = $num; $in['currency'] = $this->currencyFromText($pm[0], $defaultCurrency); }
            $t = str_replace($pm[0], ' ', $t);
        }
        if (preg_match('/\b(\d+(?:\.\d)?)\s*[- ]?\s*(?:bed|beds|bedroom|bedrooms|br|bd)\b/i', $t, $m)) { $in['beds'] = (float) $m[1]; }
        if (preg_match('/\b(\d+(?:\.\d)?)\s*[- ]?\s*(?:bath|baths|bathroom|bathrooms|ba)\b/i', $t, $m)) { $in['baths'] = (float) $m[1]; $t = str_replace($m[0], ' ', $t); }
        if (preg_match('/\b([\d][\d,]*(?:\.\d+)?)\s*(sq\.? ?ft|sqft|square feet|sq\.? ?m|sqm|m2|m²|square met(?:er|re)s|acres?|ha|hectares?)\b/iu', $t, $m)) {
            $in['size_value'] = (float) str_replace(',', '', $m[1]);
            $u = strtolower($m[2]);
            $in['size_unit'] = preg_match('/acre/', $u) ? 'acres' : (preg_match('/^(ha|hect)/', $u) ? 'ha' : (preg_match('/m/', $u) && ! preg_match('/ft|feet/', $u) ? 'sq m' : 'sq ft'));
            $t = str_replace($m[0], ' ', $t);
        }
        if (preg_match('/\b(?:in|at|on)\s+([A-Z][A-Za-z0-9\'’.\- ]{2,60}?(?:,\s*[A-Z][A-Za-z\- ]{1,30}){0,2})(?=\s*(?:,|\.|;|$|\bfor\b|\bat\b|\bwith\b|\bpriced\b|\b\d))/u', $t, $m)) { $in['location'] = trim($m[1], " ,.;"); $t = str_replace($m[0], ' ', $t); }
        $core = preg_replace('/^.*?\b(?:add|list|create|post|put up|publish)\b\s*(?:a |an |new |another |this )?(?:(?:property |house |home )?listings?\b\s*(?:for|of|:)?\s*)?(?:a |an )?/i', '', $t, 1) ?? $t;
        $core = preg_replace('/\b(to let|for rent|for sale|to rent|under offer|please|on the site|to the site|to the website|on the website|to my listings|to the listings|listing|as a listing)\b/i', ' ', $core) ?? $core;
        $core = preg_replace('/\s+[\-–—]\s+/', ' ', $core) ?? $core;
        $core = trim(preg_replace('/[\s,;:–—]+/', ' ', preg_replace('/\s*[,.;]\s*$/', '', $core)) ?? $core, " ,.;:-");
        if (isset($in['beds']) && stripos($core, 'bed') === false && preg_match('/^(townhouse|house|home|apartment|flat|condo|villa|bungalow|penthouse|duplex|studio|cottage|loft|unit)/i', $core)) { $core = $this->num($in['beds']) . '-bed ' . $core; }
        $core = preg_replace('/\s+/', ' ', $core) ?? $core;
        if (mb_strlen($core) >= 3 && mb_strlen($core) <= 120) $in['title'] = ucfirst($core);
        elseif (isset($in['beds']) || isset($in['location'])) { $in['title'] = (isset($in['beds']) ? $this->num($in['beds']) . '-bed property' : 'Property') . (isset($in['location']) ? ' in ' . $in['location'] : ''); }
        if ($in['title'] !== '' && isset($in['location']) && stripos($in['title'], $in['location']) === false) $in['title'] .= ' in ' . $in['location'];
        return $in;
    }

    // ───────────────────────────── photos ─────────────────────────────

    public function storePhoto(int $wsId, int $websiteId, \Illuminate\Http\UploadedFile $file): array
    {
        $site = $this->owned($wsId, $websiteId);
        if (! $site) return $this->fail('NOT_FOUND', 'Website not found in this workspace.');
        if ($this->specs($websiteId, $site) === []) return $this->fail('NO_CATALOGUE', 'This design does not carry a catalogue.');
        if ($file->getSize() > 8 * 1024 * 1024) return $this->fail('TOO_LARGE', 'Photos must be under 8 MB.');
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $mime = (string) $file->getMimeType();
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return $this->fail('TYPE', 'Photos must be JPG, PNG or WEBP.');
        $dir = storage_path("app/public/sites/{$websiteId}/catalogue");
        if (! is_dir($dir)) mkdir($dir, 0755, true);
        $name = 'p-' . date('Ymd') . '-' . Str::lower(Str::random(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $file->move($dir, $name);
        @chmod($dir . '/' . $name, 0644);
        try { \App\Engines\Builder\Support\ImagePolicy::normaliseInPlace($dir . '/' . $name, 'gallery'); } catch (\Throwable $e) {}
        return ['success' => true, 'url' => "/storage/sites/{$websiteId}/catalogue/{$name}"];
    }
}
