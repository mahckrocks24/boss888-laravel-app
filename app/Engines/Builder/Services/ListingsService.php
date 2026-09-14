<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PROPERTY LISTINGS (DEC-0048, 2026-09-14). Owner: "It should have a backend of its own … inside laravel, no separate
 * login … this should not be visible to other users that do not sell properties."
 *
 * The catalogue is declared by the DESIGN: only a manifest carrying `catalogue: {kind: "listings", …}` says the site
 * sells or lets property. Every other site never sees a Listings tab, gets `enabled:false` from the route and
 * Arthur never treats "listing" as a catalogue command there. A realtor can still switch it off per site.
 *
 * Rows live in property_listings; the deployed site is a PROJECTION of them:
 *   • the home block's listing_N_* slots (and the realtor design's sold row, portfolio_N_*), written through
 *     TemplateService::updateField and mirrored into template_variables so layout switches and undo carry them;
 *   • slots without a listing are hidden with a small CSS block (re-applied on every render, so rebuild-safe);
 *   • a /listings/ index page and one /property-<slug>/ page per listing, each with an enquiry form that lands in
 *     the workspace CRM through the public contact endpoint.
 * Facts are never invented: no stated price → "Price on request"; a sale outcome is only what the customer typed.
 */
class ListingsService
{
    public const STATUSES = [
        'for_sale' => 'For Sale', 'to_let' => 'To Let', 'under_offer' => 'Under Offer',
        'sold' => 'Sold', 'let' => 'Let', 'withdrawn' => 'Withdrawn',
    ];
    public const ACTIVE = ['for_sale', 'to_let', 'under_offer'];
    public const CLOSED = ['sold', 'let'];
    public const PLACEHOLDER = '/storage/template-images/listing-placeholder.svg';
    private const SYMBOLS = ['USD' => '$', 'EUR' => '€', 'GBP' => '£', 'AED' => 'AED ', 'PHP' => '₱', 'CAD' => 'C$', 'AUD' => 'A$', 'SGD' => 'S$', 'INR' => '₹', 'ZAR' => 'R', 'NZD' => 'NZ$', 'CHF' => 'CHF ', 'SAR' => 'SAR ', 'QAR' => 'QAR '];

    public function __construct(private TemplateService $templates) {}

    // ───────────────────────────── gate ─────────────────────────────

    /** The catalogue this website's DESIGN declares, or null. `enabled` folds in the per-site switch. */
    public function catalogueFor(int $websiteId, ?object $site = null): ?array
    {
        $site = $site ?: DB::table('websites')->where('id', $websiteId)->whereNull('deleted_at')->first();
        if (! $site) return null;
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $design = '';
        foreach ([(string) ($settings['template'] ?? ''), (string) ($settings['industry'] ?? ''), (string) ($site->template_industry ?? ''), (string) ($site->template ?? '')] as $cand) {
            $cand = preg_replace('/[^a-z0-9_]/', '', strtolower($cand));
            if ($cand !== '' && is_file(storage_path("templates/{$cand}/manifest.json"))) { $design = $cand; break; }
        }
        if ($design === '') return null;
        $manifest = json_decode((string) file_get_contents(storage_path("templates/{$design}/manifest.json")), true) ?: [];
        $cat = $manifest['catalogue'] ?? null;
        if (! is_array($cat) || (string) ($cat['kind'] ?? '') !== 'listings') return null;
        $vars = is_array($manifest['variables'] ?? null) ? $manifest['variables'] : [];
        $ls = $settings['listings'] ?? [];
        return [
            'design'      => $design,
            'kind'        => 'listings',
            'label'       => (string) ($cat['label'] ?? 'Listings'),
            'home_block'  => (string) ($cat['home_block'] ?? 'listings'),
            'slots'       => max(1, (int) ($cat['slots'] ?? 6)),
            'sold_block'  => (string) ($cat['sold_block'] ?? ''),
            'sold_slots'  => (int) ($cat['sold_slots'] ?? 0),
            'style'       => isset($vars['listing_1_area']) ? 'agency' : 'card',   // agency: area/beds/baths/sqft/currency; card: title/location/specs
            'enabled'     => ! (isset($ls['enabled']) && $ls['enabled'] === false),
            'seeded_at'   => $ls['seeded_at'] ?? null,
            'currency'    => (string) ($ls['currency'] ?? 'USD'),
        ];
    }

    public function enabledFor(int $websiteId): bool
    {
        try { $c = $this->catalogueFor($websiteId); } catch (\Throwable $e) { return false; }
        return $c !== null && $c['enabled'];
    }

    private function owned(int $wsId, int $websiteId): ?object
    {
        $site = DB::table('websites')->where('id', $websiteId)->whereNull('deleted_at')->first();
        return ($site && (int) $site->workspace_id === $wsId) ? $site : null;
    }

    // ───────────────────────────── read ─────────────────────────────

    public function overview(int $wsId, int $websiteId): array
    {
        $site = $this->owned($wsId, $websiteId);
        if (! $site) return ['enabled' => false, 'catalogue' => null, 'listings' => [], 'error' => 'not_found'];
        $cat = $this->catalogueFor($websiteId, $site);
        if (! $cat) return ['enabled' => false, 'catalogue' => null, 'listings' => []];
        if ($cat['enabled'] && empty($cat['seeded_at'])) {
            $this->seedFromSite($wsId, $websiteId, $site, $cat);
            $cat = $this->catalogueFor($websiteId) ?: $cat;
        }
        return [
            'enabled'   => $cat['enabled'],
            'catalogue' => $cat,
            'statuses'  => self::STATUSES,
            'listings'  => array_map(fn($r) => $this->present($r), $this->rows($websiteId)),
            'pages'     => ['index' => '/listings/', 'detail' => '/property-<slug>/'],
        ];
    }

    private function rows(int $websiteId): array
    {
        return DB::table('property_listings')->where('website_id', $websiteId)->whereNull('deleted_at')
            ->orderByDesc('featured')->orderBy('sort_order')->orderByDesc('id')->get()->all();
    }

    private function present(object $r): array
    {
        $photos = json_decode((string) ($r->photos_json ?: '[]'), true) ?: [];
        return [
            'id' => (int) $r->id, 'slug' => $r->slug, 'title' => $r->title, 'status' => $r->status,
            'status_label' => self::STATUSES[$r->status] ?? ucfirst($r->status),
            'price' => $r->price !== null ? (float) $r->price : null, 'currency' => $r->currency, 'price_period' => $r->price_period,
            'price_label' => $r->price_label, 'price_display' => $this->priceText($r),
            'location' => $r->location, 'property_type' => $r->property_type,
            'beds' => $r->beds !== null ? (float) $r->beds : null, 'baths' => $r->baths !== null ? (float) $r->baths : null,
            'size_value' => $r->size_value !== null ? (float) $r->size_value : null, 'size_unit' => $r->size_unit,
            'specs_text' => $r->specs_text, 'specs_display' => $this->specsText($r),
            'description' => (string) $r->description, 'photos' => array_values($photos),
            'features' => json_decode((string) ($r->features_json ?: '[]'), true) ?: [],
            'featured' => (bool) $r->featured, 'sort_order' => (int) $r->sort_order,
            'sold_note' => $r->sold_note, 'sold_at' => $r->sold_at, 'source' => $r->source,
            'page' => '/property-' . $r->slug . '/',
            'updated_at' => $r->updated_at,
        ];
    }

    // ───────────────────────────── write ─────────────────────────────

    /** Validate + normalise a payload. Returns [attrs, errors]. */
    private function normalise(array $in, ?object $existing, string $defaultCurrency): array
    {
        $errors = [];
        $a = [];
        $title = trim((string) ($in['title'] ?? ($existing->title ?? '')));
        if ($title === '') $errors[] = 'A listing needs a title (e.g. "3-bed townhouse in Travis Heights").';
        $a['title'] = mb_substr($title, 0, 190);
        $status = (string) ($in['status'] ?? ($existing->status ?? 'for_sale'));
        if (! isset(self::STATUSES[$status])) $errors[] = 'Status must be one of: ' . implode(', ', array_keys(self::STATUSES)) . '.';
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
        if (isset($in['currency'])) {
            $c = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $in['currency']));
            $a['currency'] = strlen($c) === 3 ? $c : $defaultCurrency;
        } elseif (! $existing) { $a['currency'] = $defaultCurrency; }
        foreach (['price_period' => 12, 'price_label' => 80, 'location' => 190, 'property_type' => 60, 'size_unit' => 12, 'specs_text' => 190, 'sold_note' => 190] as $k => $max) {
            if (array_key_exists($k, $in)) { $v = trim((string) $in[$k]); $a[$k] = $v === '' ? null : mb_substr($v, 0, $max); }
        }
        foreach (['beds', 'baths', 'size_value'] as $k) {
            if (array_key_exists($k, $in)) { $v = $in[$k]; $a[$k] = ($v === '' || $v === null) ? null : (float) preg_replace('/[^\d.]/', '', (string) $v); }
        }
        if (array_key_exists('description', $in)) $a['description'] = mb_substr(trim(strip_tags((string) $in['description'])), 0, 6000);
        if (array_key_exists('photos', $in)) {
            $photos = [];
            foreach ((array) $in['photos'] as $u) {
                $u = trim((string) $u);
                if ($u === '') continue;
                if (! preg_match('~^(https?://[^\s"\'<>]+|/storage/[^\s"\'<>]+)$~i', $u)) { $errors[] = 'Photo must be a web address (https://…) or an uploaded file.'; continue; }
                $photos[] = mb_substr($u, 0, 1024);
            }
            $a['photos_json'] = json_encode(array_values(array_unique($photos)));
        }
        if (array_key_exists('features', $in)) {
            $f = is_array($in['features']) ? $in['features'] : preg_split('/[\n,]+/', (string) $in['features']);
            $a['features_json'] = json_encode(array_values(array_filter(array_map(fn($x) => mb_substr(trim(strip_tags((string) $x)), 0, 80), $f))));
        }
        if (array_key_exists('featured', $in)) $a['featured'] = (bool) $in['featured'] ? 1 : 0;
        if (array_key_exists('sort_order', $in)) $a['sort_order'] = max(0, (int) $in['sort_order']);
        if (in_array($status, self::CLOSED, true)) { $a['sold_at'] = $existing && $existing->sold_at && $existing->status === $status ? $existing->sold_at : now(); }
        elseif ($existing && in_array($existing->status, self::CLOSED, true)) { $a['sold_at'] = null; }
        return [$a, $errors];
    }

    private function uniqueSlug(int $websiteId, string $title, ?int $exceptId = null): string
    {
        $base = Str::slug(mb_substr($title, 0, 70)) ?: 'listing';
        $slug = $base; $i = 2;
        while (DB::table('property_listings')->where('website_id', $websiteId)->where('slug', $slug)->when($exceptId, fn($q) => $q->where('id', '!=', $exceptId))->exists()) { $slug = $base . '-' . $i++; }
        return $slug;
    }

    public function create(int $wsId, int $websiteId, array $in, ?int $actorId = null, string $source = 'editor'): array
    {
        $site = $this->owned($wsId, $websiteId);
        if (! $site) return ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Website not found in this workspace.'];
        $cat = $this->catalogueFor($websiteId, $site);
        if (! $cat || ! $cat['enabled']) return ['success' => false, 'code' => 'NO_CATALOGUE', 'message' => 'This design does not carry a property catalogue.'];
        [$a, $errors] = $this->normalise($in, null, $cat['currency']);
        if ($errors) return ['success' => false, 'code' => 'INVALID', 'message' => implode(' ', $errors), 'errors' => $errors];
        $a['slug'] = $this->uniqueSlug($websiteId, $a['title']);
        $a += ['workspace_id' => $wsId, 'website_id' => $websiteId, 'source' => $source, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()];
        $id = (int) DB::table('property_listings')->insertGetId($a);
        $sync = $this->sync($websiteId, 'listing_add');
        $row = DB::table('property_listings')->where('id', $id)->first();
        return ['success' => true, 'listing' => $this->present($row), 'sync' => $sync,
            'message' => '“' . $row->title . '” is on the site' . ($row->price === null && empty($row->price_label) ? ' as “Price on request” — add the price when you have it' : '') . '.'];
    }

    public function update(int $wsId, int $websiteId, int $listingId, array $in, string $reason = 'listing_edit'): array
    {
        $site = $this->owned($wsId, $websiteId);
        if (! $site) return ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Website not found in this workspace.'];
        $cat = $this->catalogueFor($websiteId, $site);
        if (! $cat) return ['success' => false, 'code' => 'NO_CATALOGUE', 'message' => 'This design does not carry a property catalogue.'];
        $row = DB::table('property_listings')->where('id', $listingId)->where('website_id', $websiteId)->whereNull('deleted_at')->first();
        if (! $row) return ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'That listing is not on this site.'];
        [$a, $errors] = $this->normalise($in, $row, $cat['currency']);
        if ($errors) return ['success' => false, 'code' => 'INVALID', 'message' => implode(' ', $errors), 'errors' => $errors];
        $oldSlug = $row->slug;
        if ($a['title'] !== $row->title) $a['slug'] = $this->uniqueSlug($websiteId, $a['title'], $listingId);
        $a['updated_at'] = now();
        DB::table('property_listings')->where('id', $listingId)->update($a);
        if (isset($a['slug']) && $a['slug'] !== $oldSlug) $this->removePage($websiteId, 'property-' . $oldSlug);
        $sync = $this->sync($websiteId, $reason);
        $row = DB::table('property_listings')->where('id', $listingId)->first();
        return ['success' => true, 'listing' => $this->present($row), 'sync' => $sync, 'message' => '“' . $row->title . '” updated — now ' . strtolower(self::STATUSES[$row->status] ?? $row->status) . ', ' . $this->priceText($row) . '.'];
    }

    public function setStatus(int $wsId, int $websiteId, int $listingId, string $status, ?string $note = null): array
    {
        $in = ['status' => $status];
        if ($note !== null) $in['sold_note'] = $note;
        return $this->update($wsId, $websiteId, $listingId, $in, 'listing_status');
    }

    public function delete(int $wsId, int $websiteId, int $listingId): array
    {
        $site = $this->owned($wsId, $websiteId);
        if (! $site) return ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Website not found in this workspace.'];
        $row = DB::table('property_listings')->where('id', $listingId)->where('website_id', $websiteId)->whereNull('deleted_at')->first();
        if (! $row) return ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'That listing is not on this site.'];
        DB::table('property_listings')->where('id', $listingId)->update(['deleted_at' => now(), 'updated_at' => now()]);
        $this->removePage($websiteId, 'property-' . $row->slug);
        $sync = $this->sync($websiteId, 'listing_remove');
        return ['success' => true, 'sync' => $sync, 'message' => '“' . $row->title . '” removed from the site.'];
    }

    /** Per-site switch. Off: the tab disappears, the index and property pages are removed, the menu link goes back to the home block. Rows are kept. */
    public function setEnabled(int $wsId, int $websiteId, bool $enabled): array
    {
        $site = $this->owned($wsId, $websiteId);
        if (! $site) return ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Website not found in this workspace.'];
        $cat = $this->catalogueFor($websiteId, $site);
        if (! $cat) return ['success' => false, 'code' => 'NO_CATALOGUE', 'message' => 'This design does not carry a property catalogue.'];
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $settings['listings'] = array_merge((array) ($settings['listings'] ?? []), ['enabled' => $enabled]);
        DB::table('websites')->where('id', $websiteId)->update(['settings_json' => json_encode($settings), 'updated_at' => now()]);
        if ($enabled) {
            $cat = $this->catalogueFor($websiteId) ?: $cat;
            if (empty($cat['seeded_at'])) $this->seedFromSite($wsId, $websiteId, DB::table('websites')->where('id', $websiteId)->first(), $cat);
            $sync = $this->sync($websiteId, 'listings_on');
            return ['success' => true, 'enabled' => true, 'sync' => $sync, 'message' => 'Listings are on: the Listings page and property pages are live on the site.'];
        }
        try { $this->templates->snapshotToHistory($websiteId, 'listings_off'); } catch (\Throwable $e) {}
        $this->removeNav($websiteId, $cat);
        $this->removePage($websiteId, 'listings');
        foreach (DB::table('property_listings')->where('website_id', $websiteId)->get(['slug']) as $r) $this->removePage($websiteId, 'property-' . $r->slug);
        $this->writeHideCss($websiteId, '');
        return ['success' => true, 'enabled' => false, 'message' => 'Listings are off for this site. The home page keeps what it shows now; the Listings and property pages are removed.'];
    }

    // ───────────────────────────── seed ─────────────────────────────

    /** First enable: what the page shows today becomes rows, so nothing the customer already saw disappears. */
    private function seedFromSite(int $wsId, int $websiteId, object $site, array $cat): int
    {
        $tv = json_decode((string) ($site->template_variables ?: '{}'), true) ?: [];
        $n = 0;
        $seenSlugs = [];
        for ($i = 1; $i <= $cat['slots']; $i++) {
            $title = trim((string) ($tv["listing_{$i}_title"] ?? $tv["listing_{$i}_area"] ?? ''));
            if ($title === '') continue;
            $badge = strtolower(trim((string) ($tv["listing_{$i}_badge"] ?? '')));
            $status = str_contains($badge, 'sold') ? 'sold' : (preg_match('/rent|let/', $badge) ? 'to_let' : (str_contains($badge, 'offer') ? 'under_offer' : 'for_sale'));
            $priceRaw = trim((string) ($tv["listing_{$i}_price"] ?? ''));
            $currency = strtoupper(trim((string) ($tv["listing_{$i}_currency"] ?? ''))) ?: $this->currencyFromText($priceRaw, $cat['currency']);
            $row = [
                'workspace_id' => $wsId, 'website_id' => $websiteId, 'title' => mb_substr($title, 0, 190), 'status' => $status,
                'currency' => strlen($currency) === 3 ? $currency : $cat['currency'],
                'location' => $cat['style'] === 'agency' ? mb_substr($title, 0, 190) : (trim((string) ($tv["listing_{$i}_location"] ?? '')) ?: null),
                'specs_text' => trim((string) ($tv["listing_{$i}_specs"] ?? '')) ?: null,
                'beds' => is_numeric($tv["listing_{$i}_beds"] ?? null) ? (float) $tv["listing_{$i}_beds"] : null,
                'baths' => is_numeric($tv["listing_{$i}_baths"] ?? null) ? (float) $tv["listing_{$i}_baths"] : null,
                'size_value' => isset($tv["listing_{$i}_sqft"]) && preg_match('/[\d,]+/', (string) $tv["listing_{$i}_sqft"], $sm) ? (float) str_replace(',', '', $sm[0]) : null,
                'size_unit' => isset($tv["listing_{$i}_sqft"]) ? 'sq ft' : null,
                'photos_json' => json_encode(array_values(array_filter([trim((string) ($tv["listing_{$i}_image"] ?? ''))]))),
                'sort_order' => $i, 'source' => 'seed', 'created_at' => now(), 'updated_at' => now(),
            ];
            $clean = preg_replace('/[^\d.]/', '', $priceRaw);
            if ($priceRaw !== '' && is_numeric($clean) && preg_match('/^\s*(?:[^\d]{0,4})[\d,]+(?:\.\d+)?\s*$/u', $priceRaw)) { $row['price'] = round((float) $clean, 2); }
            elseif ($priceRaw !== '') { $row['price_label'] = mb_substr($priceRaw, 0, 80); }
            if ($status === 'sold') $row['sold_at'] = now();
            $row['slug'] = $this->uniqueSlug($websiteId, $row['title']);
            DB::table('property_listings')->insert($row); $n++;
        }
        for ($i = 1; $i <= (int) $cat['sold_slots']; $i++) {
            $title = trim((string) ($tv["portfolio_{$i}_title"] ?? ''));
            if ($title === '') continue;
            $priceRaw = trim((string) ($tv["portfolio_{$i}_price"] ?? ''));
            $row = [
                'workspace_id' => $wsId, 'website_id' => $websiteId, 'title' => mb_substr($title, 0, 190), 'status' => 'sold', 'currency' => $this->currencyFromText($priceRaw, $cat['currency']),
                'sold_note' => mb_substr(trim((string) ($tv["portfolio_{$i}_price_note"] ?? $tv["portfolio_{$i}_note"] ?? '')), 0, 190) ?: null,
                'photos_json' => json_encode(array_values(array_filter([trim((string) ($tv["portfolio_{$i}_image"] ?? ''))]))),
                'sort_order' => 100 + $i, 'source' => 'seed', 'sold_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ];
            $clean = preg_replace('/[^\d.]/', '', $priceRaw);
            if ($priceRaw !== '' && is_numeric($clean)) $row['price'] = round((float) $clean, 2); elseif ($priceRaw !== '') $row['price_label'] = mb_substr($priceRaw, 0, 80);
            $row['slug'] = $this->uniqueSlug($websiteId, $row['title']);
            DB::table('property_listings')->insert($row); $n++;
        }
        $settings = json_decode((string) ($site->settings_json ?: '{}'), true) ?: [];
        $settings['listings'] = array_merge((array) ($settings['listings'] ?? []), ['seeded_at' => now()->toDateTimeString(), 'seeded' => $n]);
        DB::table('websites')->where('id', $websiteId)->update(['settings_json' => json_encode($settings), 'updated_at' => now()]);
        Log::info('[Listings] seeded from site', ['website_id' => $websiteId, 'rows' => $n]);
        return $n;
    }

    private function currencyFromText(string $s, string $default): string
    {
        if (preg_match('/\b(USD|EUR|GBP|AED|PHP|CAD|AUD|SGD|INR|ZAR|NZD|CHF|SAR|QAR)\b/i', $s, $m)) return strtoupper($m[1]);
        if (str_contains($s, '€')) return 'EUR';
        if (str_contains($s, '£')) return 'GBP';
        if (str_contains($s, '₱')) return 'PHP';
        if (str_contains($s, '$')) return $default === 'USD' || ! isset(self::SYMBOLS[$default]) || ! str_contains(self::SYMBOLS[$default], '$') ? 'USD' : $default;
        return $default;
    }

    // ───────────────────────────── text ─────────────────────────────

    public function priceText(object $r): string
    {
        if (! empty($r->price_label)) return (string) $r->price_label;
        if ($r->price === null) return 'Price on request';
        $sym = self::SYMBOLS[$r->currency] ?? ($r->currency . ' ');
        $n = (float) $r->price;
        $txt = $sym . number_format($n, fmod($n, 1.0) !== 0.0 ? 2 : 0);
        if (! empty($r->price_period)) $txt .= ' / ' . $r->price_period;
        return $txt;
    }

    /** Agency designs show the number and the currency code in two spans. */
    private function priceParts(object $r): array
    {
        if (! empty($r->price_label) || $r->price === null) return [$this->priceText($r), ''];
        $n = (float) $r->price;
        return [number_format($n, fmod($n, 1.0) !== 0.0 ? 2 : 0) . (! empty($r->price_period) ? ' / ' . $r->price_period : ''), (string) $r->currency];
    }

    public function specsText(object $r): string
    {
        if (! empty($r->specs_text)) return (string) $r->specs_text;
        $p = [];
        if ($r->beds !== null) { $b = (float) $r->beds; $p[] = $this->num($b) . ' ' . ($b == 1 ? 'bed' : 'beds'); }
        if ($r->baths !== null) { $b = (float) $r->baths; $p[] = $this->num($b) . ' ' . ($b == 1 ? 'bath' : 'baths'); }
        if ($r->size_value !== null) { $p[] = number_format((float) $r->size_value, fmod((float) $r->size_value, 1.0) !== 0.0 ? 1 : 0) . ' ' . ($r->size_unit ?: 'sq ft'); }
        return implode(' • ', $p);
    }

    private function num(float $n): string { return fmod($n, 1.0) !== 0.0 ? rtrim(rtrim(number_format($n, 1), '0'), '.') : (string) (int) $n; }

    private function photos(object $r): array { return array_values(json_decode((string) ($r->photos_json ?: '[]'), true) ?: []); }

    private function cardImage(object $r): string { $p = $this->photos($r); return $p[0] ?? self::PLACEHOLDER; }

    // ───────────────────────────── projection ─────────────────────────────

    /** Rewrite everything the site shows from the rows. One history snapshot, then field patches, CSS, pages, nav, record. */
    public function sync(int $websiteId, string $reason = 'listings'): array
    {
        $site = DB::table('websites')->where('id', $websiteId)->whereNull('deleted_at')->first();
        $cat = $site ? $this->catalogueFor($websiteId, $site) : null;
        if (! $site || ! $cat || ! $cat['enabled']) return ['synced' => false];
        if (! is_file(storage_path("app/public/sites/{$websiteId}/index.html"))) return ['synced' => false, 'reason' => 'no_export'];
        try { $this->templates->snapshotToHistory($websiteId, $reason); } catch (\Throwable $e) {}
        $rows = $this->rows($websiteId);
        $active = array_values(array_filter($rows, fn($r) => in_array($r->status, self::ACTIVE, true)));
        $closed = array_values(array_filter($rows, fn($r) => in_array($r->status, self::CLOSED, true)));
        usort($closed, fn($a, $b) => strcmp((string) $b->sold_at, (string) $a->sold_at));
        $tv = json_decode((string) ($site->template_variables ?: '{}'), true) ?: [];
        $changes = [];
        for ($i = 1; $i <= $cat['slots']; $i++) {
            $r = $active[$i - 1] ?? null;
            if (! $r) continue;
            $badge = self::STATUSES[$r->status] ?? ucfirst($r->status);
            if ($cat['style'] === 'agency') {
                [$num, $code] = $this->priceParts($r);
                $vals = ["listing_{$i}_image" => $this->cardImage($r), "listing_{$i}_badge" => $badge, "listing_{$i}_price" => $num, "listing_{$i}_currency" => $code,
                    "listing_{$i}_area" => (string) ($r->location ?: $r->title),
                    "listing_{$i}_beds" => $r->beds !== null ? $this->num((float) $r->beds) : '–', "listing_{$i}_baths" => $r->baths !== null ? $this->num((float) $r->baths) : '–',
                    "listing_{$i}_sqft" => $r->size_value !== null ? number_format((float) $r->size_value) : '–'];
            } else {
                $vals = ["listing_{$i}_image" => $this->cardImage($r), "listing_{$i}_badge" => $badge, "listing_{$i}_price" => $this->priceText($r),
                    "listing_{$i}_title" => (string) $r->title, "listing_{$i}_location" => (string) ($r->location ?? ''), "listing_{$i}_specs" => $this->specsText($r)];
            }
            $vals["listing_{$i}_cta"] = 'View details';
            foreach ($vals as $k => $v) { if ((string) ($tv[$k] ?? null) !== $v) $changes[$k] = $v; }
        }
        if ($cat['sold_block'] !== '' && $cat['sold_slots'] > 0) {
            for ($i = 1; $i <= $cat['sold_slots']; $i++) {
                $r = $closed[$i - 1] ?? null;
                if (! $r) continue;
                $vals = ["portfolio_{$i}_image" => $this->cardImage($r), "portfolio_{$i}_badge" => self::STATUSES[$r->status] ?? 'Sold',
                    "portfolio_{$i}_price" => ($r->price !== null || ! empty($r->price_label)) ? $this->priceText($r) : '',
                    "portfolio_{$i}_title" => (string) $r->title, "portfolio_{$i}_price_note" => (string) ($r->sold_note ?? '')];
                foreach ($vals as $k => $v) { if ((string) ($tv[$k] ?? null) !== $v) $changes[$k] = $v; }
            }
        }
        $patched = 0;
        foreach ($changes as $k => $v) {
            try { if ($this->templates->updateField($websiteId, $k, $v, false)) $patched++; } catch (\Throwable $e) { Log::warning('[Listings] field patch failed', ['field' => $k, 'error' => $e->getMessage()]); }
        }
        // The CTA of a filled card opens its property page (the template's own link points at #contact).
        $this->pointCardsAtPages($websiteId, $cat, $active);
        if ($changes !== []) {
            DB::table('websites')->where('id', $websiteId)->update(['template_variables' => json_encode(array_merge($tv, $changes)), 'updated_at' => now()]);
        }
        $this->writeHideCss($websiteId, $this->hideCss($cat, count($active), count($closed)));
        $pages = $this->writePages($websiteId, $site, $cat, $active, $closed);
        $this->ensureNav($websiteId, $cat);
        return ['synced' => true, 'active' => count($active), 'closed' => count($closed), 'fields' => $patched, 'pages' => $pages];
    }

    private function hideCss(array $cat, int $activeCount, int $closedCount): string
    {
        $css = [];
        $hb = $cat['home_block'];
        if ($activeCount === 0) { $css[] = "[data-block=\"{$hb}\"]{display:none!important}"; }
        else {
            for ($i = $activeCount + 1; $i <= $cat['slots']; $i++) {
                $css[] = "[data-block=\"{$hb}\"] :is(article,.listing,.listing-card,.card):has([data-field=\"listing_{$i}_title\"],[data-field=\"listing_{$i}_area\"],[data-field=\"listing_{$i}_price\"]){display:none!important}";
            }
        }
        if ($cat['sold_block'] !== '' && $cat['sold_slots'] > 0) {
            $sb = $cat['sold_block'];
            if ($closedCount === 0) { $css[] = "[data-block=\"{$sb}\"]{display:none!important}"; }
            else { for ($i = $closedCount + 1; $i <= $cat['sold_slots']; $i++) { $css[] = "[data-block=\"{$sb}\"] :is(article,.card):has([data-field=\"portfolio_{$i}_title\"]){display:none!important}"; } }
        }
        return implode("\n", $css);
    }

    /** Insert / replace the `lu-listings-css` block in the served home page (and drop it when empty). */
    private function writeHideCss(int $websiteId, string $css): void
    {
        $path = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($path)) return;
        $html = (string) file_get_contents($path);
        $new = self::injectCss($html, $css);
        if ($new !== $html) file_put_contents($path, $new);
    }

    public static function injectCss(string $html, string $css): string
    {
        $html = preg_replace('~\s*<style id="lu-listings-css"[^>]*>.*?</style>~is', '', $html) ?? $html;
        if (trim($css) === '') return $html;
        $block = "\n" . '<style id="lu-listings-css" data-owner="listings">' . $css . '</style>';
        return stripos($html, '</head>') !== false ? preg_replace('~</head>~i', $block . "\n</head>", $html, 1) : $html . $block;
    }

    /** Rebuild hook (TemplateService::render): the freshly rendered home carries the hide rules for its current rows. */
    public function decorateRendered(int $websiteId, string $html): string
    {
        try {
            $cat = $this->catalogueFor($websiteId);
            if (! $cat || ! $cat['enabled'] || empty($cat['seeded_at'])) return $html;
            $rows = $this->rows($websiteId);
            $active = count(array_filter($rows, fn($r) => in_array($r->status, self::ACTIVE, true)));
            $closed = count(array_filter($rows, fn($r) => in_array($r->status, self::CLOSED, true)));
            return self::injectCss($html, $this->hideCss($cat, $active, $closed));
        } catch (\Throwable $e) { return $html; }
    }

    private function pointCardsAtPages(int $websiteId, array $cat, array $active): void
    {
        $path = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($path)) return;
        $html = (string) file_get_contents($path);
        $new = $html;
        for ($i = 1; $i <= $cat['slots']; $i++) {
            $r = $active[$i - 1] ?? null;
            $href = $r ? 'property-' . $r->slug . '/' : '#contact';
            $new = preg_replace_callback('/<a\b([^>]*\bdata-field="listing_' . $i . '_cta"[^>]*)>/i', function ($m) use ($href) {
                $attrs = preg_match('/\shref="/i', $m[1]) ? (preg_replace('/\shref="[^"]*"/i', ' href="' . $href . '"', $m[1], 1) ?? $m[1]) : $m[1] . ' href="' . $href . '"';
                return '<a' . $attrs . '>';
            }, $new) ?? $new;
        }
        if ($new !== $html) file_put_contents($path, $new);
    }

    // ───────────────────────────── pages ─────────────────────────────

    private function writePages(int $websiteId, object $site, array $cat, array $active, array $closed): array
    {
        $written = 0;
        $index = $this->templates->deployPage($websiteId, 'listings', $this->indexBody($active, $closed), $cat['label'] ?: 'Listings');
        if ($index) $written++;
        $keep = ['listings' => true];
        foreach (array_merge($active, $closed) as $r) {
            $slug = 'property-' . $r->slug; $keep[$slug] = true;
            if ($this->templates->deployPage($websiteId, $slug, $this->detailBody($r), $r->title)) $written++;
        }
        // withdrawn listings keep no page
        foreach (DB::table('property_listings')->where('website_id', $websiteId)->whereNull('deleted_at')->where('status', 'withdrawn')->get(['slug']) as $w) { $this->removePage($websiteId, 'property-' . $w->slug); }
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

    private function ensureNav(int $websiteId, array $cat): void
    {
        $home = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($home)) return;
        $html = (string) file_get_contents($home);
        if (str_contains($html, 'data-page="listings"')) return;
        $label = $cat['label'] ?: 'Listings';
        if (preg_match('/<a\b(?![^>]*class="sr")[^>]*href="#' . preg_quote($cat['home_block'], '/') . '"[^>]*class="[^"]*nav-link[^"]*"[^>]*>\s*([^<]{2,30}?)\s*<\/a>/i', $html, $m)
            || preg_match('/<a\b(?![^>]*class="sr")[^>]*class="[^"]*nav-link[^"]*"[^>]*href="#' . preg_quote($cat['home_block'], '/') . '"[^>]*>\s*([^<]{2,30}?)\s*<\/a>/i', $html, $m)) {
            $label = trim(html_entity_decode($m[1]));
        }
        try { $this->templates->addNavLink($websiteId, 'listings', $label); } catch (\Throwable $e) { Log::warning('[Listings] nav link failed: ' . $e->getMessage()); }
    }

    private function removeNav(int $websiteId, array $cat): void
    {
        try { $this->templates->removeNavLink($websiteId, 'listings'); } catch (\Throwable $e) {}
        // The template's own "Listings" link was repointed to the page; send it back to the home block.
        $home = storage_path("app/public/sites/{$websiteId}/index.html");
        if (! is_file($home)) return;
        $html = (string) file_get_contents($home);
        $new = preg_replace('/(<a\b[^>]*)\shref="listings\/"([^>]*\bdata-page="listings")/i', '$1 href="#' . $cat['home_block'] . '"$2', $html) ?? $html;
        $new = preg_replace('/(<a\b[^>]*)\shref="listings\/"/i', '$1 href="#' . $cat['home_block'] . '"', $new) ?? $new;
        if ($new !== $html) file_put_contents($home, $new);
    }

    private static function pageCss(): string
    {
        return '<style id="lu-listings-page-css">'
            . '.lu-lst{max-width:1140px;margin:0 auto;padding:clamp(28px,5vw,64px) 20px 72px;font-family:inherit;color:inherit}'
            . '.lu-lst h1{font-size:clamp(28px,4vw,44px);margin:0 0 6px;line-height:1.1}.lu-lst .lede{opacity:.75;margin:0 0 28px;max-width:60ch}'
            . '.lu-lst-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:22px}'
            . '.lu-lst-card{display:flex;flex-direction:column;border:1px solid rgba(0,0,0,.1);border-radius:14px;overflow:hidden;background:#fff;color:#1a1a1a;text-decoration:none;transition:transform .2s,box-shadow .2s}'
            . '.lu-lst-card:hover{transform:translateY(-2px);box-shadow:0 12px 30px rgba(0,0,0,.12)}'
            . '.lu-lst-card figure{margin:0;position:relative;aspect-ratio:4/3;background:#eef0f3;overflow:hidden}.lu-lst-card img{width:100%;height:100%;object-fit:cover;display:block}'
            . '.lu-lst-badge{position:absolute;top:12px;left:12px;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;background:var(--brand,var(--primary,#1f2937));color:#fff;padding:7px 10px;border-radius:999px}'
            . '.lu-lst-badge.closed{background:#4b5563}.lu-lst-body{padding:16px 18px 20px;display:flex;flex-direction:column;gap:5px}'
            . '.lu-lst-price{font-weight:800;font-size:19px}.lu-lst-card h3{margin:0;font-size:17px;line-height:1.3}.lu-lst-where,.lu-lst-specs{margin:0;font-size:14px;opacity:.75}'
            . '.lu-lst-note{margin:6px 0 0;font-size:13.5px;opacity:.85}'
            . '.lu-lst h2{font-size:clamp(22px,3vw,30px);margin:56px 0 18px}'
            . '.lu-prop{max-width:1100px;margin:0 auto;padding:clamp(24px,4vw,56px) 20px 80px;color:inherit}'
            . '.lu-prop .back{display:inline-block;margin-bottom:18px;font-size:14px;text-decoration:none;opacity:.8}.lu-prop .back:hover{opacity:1}'
            . '.lu-prop-gallery{display:grid;gap:10px;margin-bottom:26px}.lu-prop-gallery .main{aspect-ratio:16/10;background:#eef0f3;border-radius:16px;overflow:hidden}'
            . '.lu-prop-gallery img{width:100%;height:100%;object-fit:cover;display:block}.lu-prop-thumbs{display:grid;grid-template-columns:repeat(auto-fill,minmax(110px,1fr));gap:10px}'
            . '.lu-prop-thumbs a{aspect-ratio:4/3;border-radius:10px;overflow:hidden;background:#eef0f3;display:block}'
            . '.lu-prop-head{display:grid;grid-template-columns:1fr auto;gap:18px;align-items:start;margin-bottom:18px}.lu-prop-head h1{margin:0 0 6px;font-size:clamp(26px,3.6vw,40px);line-height:1.1}'
            . '.lu-prop-where{margin:0;opacity:.75;font-size:15px}.lu-prop-price{font-size:clamp(22px,3vw,30px);font-weight:800;white-space:nowrap}'
            . '.lu-prop-status{display:inline-block;font-size:11px;font-weight:700;letter-spacing:.12em;text-transform:uppercase;background:var(--brand,var(--primary,#1f2937));color:#fff;padding:7px 10px;border-radius:999px;margin-bottom:12px}'
            . '.lu-prop-status.closed{background:#4b5563}'
            . '.lu-prop-facts{display:flex;flex-wrap:wrap;gap:10px;margin:0 0 26px;padding:0;list-style:none}.lu-prop-facts li{border:1px solid rgba(0,0,0,.12);border-radius:10px;padding:10px 14px;font-size:14px;background:rgba(255,255,255,.6)}'
            . '.lu-prop-facts b{display:block;font-size:11px;letter-spacing:.1em;text-transform:uppercase;opacity:.6;margin-bottom:2px}'
            . '.lu-prop-grid{display:grid;grid-template-columns:minmax(0,1.5fr) minmax(300px,1fr);gap:36px}'
            . '.lu-prop-desc{font-size:16px;line-height:1.7}.lu-prop-desc p{margin:0 0 14px}.lu-prop-features{columns:2;padding-left:18px;margin:0 0 20px;font-size:15px}'
            . '.lu-enq{border:1px solid rgba(0,0,0,.12);border-radius:16px;padding:22px;background:rgba(255,255,255,.7);position:sticky;top:96px}'
            . '.lu-enq h2{margin:0 0 4px;font-size:20px}.lu-enq p{margin:0 0 14px;font-size:14px;opacity:.75}.lu-enq label{display:block;font-size:12px;font-weight:600;margin:10px 0 4px;letter-spacing:.04em}'
            . '.lu-enq input,.lu-enq textarea{width:100%;box-sizing:border-box;border:1px solid rgba(0,0,0,.18);border-radius:10px;padding:11px 12px;font:inherit;font-size:15px;background:#fff;color:#111}'
            . '.lu-enq textarea{min-height:110px;resize:vertical}.lu-enq button{margin-top:14px;width:100%;border:0;border-radius:10px;padding:13px 16px;font:inherit;font-weight:700;font-size:15px;cursor:pointer;background:var(--brand,var(--primary,#1f2937));color:#fff}'
            . '.lu-enq button[disabled]{opacity:.6;cursor:default}.lu-enq-msg{margin:12px 0 0;font-size:14px;min-height:20px}'
            . '@media (max-width:820px){.lu-prop-grid{grid-template-columns:1fr}.lu-enq{position:static}.lu-prop-head{grid-template-columns:1fr}.lu-prop-features{columns:1}}'
            . '</style>';
    }

    private function card(object $r, bool $link = true): string
    {
        $closed = in_array($r->status, self::CLOSED, true);
        $tag = $link && ! $closed ? 'a' : 'div';
        $href = $link && ! $closed ? ' href="../property-' . e($r->slug) . '/"' : '';
        $h = '<' . $tag . ' class="lu-lst-card"' . $href . '>';
        $h .= '<figure><img src="' . e($this->cardImage($r)) . '" alt="' . e($r->title) . '" loading="lazy"><span class="lu-lst-badge' . ($closed ? ' closed' : '') . '">' . e(self::STATUSES[$r->status] ?? $r->status) . '</span></figure>';
        $h .= '<div class="lu-lst-body">';
        if (! $closed || $r->price !== null || ! empty($r->price_label)) $h .= '<span class="lu-lst-price">' . e($this->priceText($r)) . '</span>';
        $h .= '<h3>' . e($r->title) . '</h3>';
        if (! empty($r->location)) $h .= '<p class="lu-lst-where">' . e($r->location) . '</p>';
        $specs = $this->specsText($r);
        if ($specs !== '') $h .= '<p class="lu-lst-specs">' . e($specs) . '</p>';
        if ($closed && ! empty($r->sold_note)) $h .= '<p class="lu-lst-note">' . e($r->sold_note) . '</p>';
        $h .= '</div></' . $tag . '>';
        return $h;
    }

    private function indexBody(array $active, array $closed): string
    {
        $h = self::pageCss() . '<section class="lu-lst" id="listings-index">';
        $h .= '<h1>Property listings</h1>';
        $h .= '<p class="lede">' . ($active === [] ? 'No properties are listed at the moment — get in touch and I will let you know the moment something suitable comes up.' : count($active) . ' ' . (count($active) === 1 ? 'property' : 'properties') . ' currently available. Open one for the full details and to enquire.') . '</p>';
        if ($active !== []) { $h .= '<div class="lu-lst-grid">'; foreach ($active as $r) $h .= $this->card($r); $h .= '</div>'; }
        if ($closed !== []) {
            $h .= '<h2>Recently sold &amp; let</h2><div class="lu-lst-grid">';
            foreach ($closed as $r) $h .= $this->card($r, false);
            $h .= '</div>';
        }
        $h .= '</section>';
        return $h;
    }

    private function detailBody(object $r): string
    {
        $closed = in_array($r->status, self::CLOSED, true);
        $photos = $this->photos($r) ?: [self::PLACEHOLDER];
        $h = self::pageCss() . '<section class="lu-prop" id="property">';
        $h .= '<a class="back" href="../listings/">&larr; All listings</a>';
        $h .= '<div class="lu-prop-gallery"><div class="main"><img id="lu-prop-main" src="' . e($photos[0]) . '" alt="' . e($r->title) . '"></div>';
        if (count($photos) > 1) {
            $h .= '<div class="lu-prop-thumbs">';
            foreach ($photos as $p) $h .= '<a href="' . e($p) . '" data-lu-thumb><img src="' . e($p) . '" alt="" loading="lazy"></a>';
            $h .= '</div>';
        }
        $h .= '</div>';
        $h .= '<span class="lu-prop-status' . ($closed ? ' closed' : '') . '">' . e(self::STATUSES[$r->status] ?? $r->status) . '</span>';
        $h .= '<div class="lu-prop-head"><div><h1>' . e($r->title) . '</h1>' . (! empty($r->location) ? '<p class="lu-prop-where">' . e($r->location) . '</p>' : '') . '</div>';
        if (! $closed || $r->price !== null || ! empty($r->price_label)) $h .= '<div class="lu-prop-price">' . e($this->priceText($r)) . '</div>';
        $h .= '</div>';
        $facts = [];
        if ($r->beds !== null) $facts[] = ['Bedrooms', $this->num((float) $r->beds)];
        if ($r->baths !== null) $facts[] = ['Bathrooms', $this->num((float) $r->baths)];
        if ($r->size_value !== null) $facts[] = ['Size', number_format((float) $r->size_value) . ' ' . ($r->size_unit ?: 'sq ft')];
        if (! empty($r->property_type)) $facts[] = ['Type', $r->property_type];
        if ($facts === [] && ! empty($r->specs_text)) $facts[] = ['Details', $r->specs_text];
        if ($facts !== []) { $h .= '<ul class="lu-prop-facts">'; foreach ($facts as [$k, $v]) $h .= '<li><b>' . e($k) . '</b>' . e($v) . '</li>'; $h .= '</ul>'; }
        $h .= '<div class="lu-prop-grid"><div>';
        $h .= '<div class="lu-prop-desc">';
        $desc = trim((string) $r->description);
        if ($desc !== '') { foreach (preg_split('/\n{2,}/', $desc) as $para) { $h .= '<p>' . nl2br(e(trim($para))) . '</p>'; } }
        else { $h .= '<p>Full details on request — send an enquiry and I will come back to you with everything you need to know about this property.</p>'; }
        $h .= '</div>';
        $features = json_decode((string) ($r->features_json ?: '[]'), true) ?: [];
        if ($features !== []) { $h .= '<h2 style="font-size:20px;margin:8px 0 10px">Features</h2><ul class="lu-prop-features">'; foreach ($features as $f) $h .= '<li>' . e($f) . '</li>'; $h .= '</ul>'; }
        if ($closed && ! empty($r->sold_note)) $h .= '<p class="lu-lst-note"><b>' . e(self::STATUSES[$r->status] ?? '') . ':</b> ' . e($r->sold_note) . '</p>';
        $h .= '</div>';
        // Enquiry form → workspace CRM through the public contact endpoint (same lane as every site form).
        $api = rtrim((string) config('app.url'), '/');
        $prefill = $closed ? 'I saw that ' . $r->title . ' has been ' . strtolower(self::STATUSES[$r->status] ?? 'sold') . ' — please let me know about similar properties.' : "I'm interested in " . $r->title . (! empty($r->location) ? ' (' . $r->location . ')' : '') . ', listed at ' . $this->priceText($r) . '. Please get in touch.';
        $h .= '<form class="lu-enq" id="lu-enq" method="post" action="#" novalidate><h2>' . ($closed ? 'Looking for something similar?' : 'Enquire about this property') . '</h2><p>Your message goes straight to me — I reply personally.</p>'
            . '<label for="lu-enq-name">Name</label><input id="lu-enq-name" name="name" type="text" autocomplete="name" required>'
            . '<label for="lu-enq-email">Email</label><input id="lu-enq-email" name="email" type="email" autocomplete="email" required>'
            . '<label for="lu-enq-phone">Phone</label><input id="lu-enq-phone" name="phone" type="tel" autocomplete="tel">'
            . '<label for="lu-enq-message">Message</label><textarea id="lu-enq-message" name="message" required>' . e($prefill) . '</textarea>'
            . '<button type="submit">Send enquiry</button><p class="lu-enq-msg" id="lu-enq-msg" aria-live="polite"></p></form>';
        $h .= '</div></section>';
        $h .= '<script>(function(){var t=document.querySelectorAll("[data-lu-thumb]"),m=document.getElementById("lu-prop-main");for(var i=0;i<t.length;i++){t[i].addEventListener("click",function(e){e.preventDefault();if(m)m.src=this.getAttribute("href");});}'
            . 'var f=document.getElementById("lu-enq");if(!f)return;f.addEventListener("submit",function(e){e.preventDefault();var b=f.querySelector("button"),g=document.getElementById("lu-enq-msg");'
            . 'var n=f.querySelector("[name=name]").value.trim(),em=f.querySelector("[name=email]").value.trim(),ms=f.querySelector("[name=message]").value.trim();'
            . 'if(!n||!em||!ms){g.textContent="Please add your name, email and a message.";return;}b.disabled=true;g.textContent="Sending…";'
            . 'fetch(' . json_encode($api . '/api/public/contact/by-host', JSON_UNESCAPED_SLASHES) . ',{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify({name:n,firstname:n,email:em,phone:f.querySelector("[name=phone]").value.trim(),message:ms,source:"listing_enquiry",listing:' . json_encode($r->title) . '})})'
            . '.then(function(r){return r.json().catch(function(){return {};}).then(function(j){return {ok:r.ok,j:j};});})'
            . '.then(function(x){if(x.ok){g.textContent="Thank you — your enquiry has been sent.";f.reset();}else{g.textContent=(x.j&&x.j.message)||"Sorry, that did not go through. Please try again or use the contact details on the home page.";b.disabled=false;}})'
            . '.catch(function(){g.textContent="Sorry, that did not go through. Please try again.";b.disabled=false;});});})();</script>';
        return $h;
    }

    // ───────────────────────────── Arthur ─────────────────────────────

    /** Does this message talk about the property catalogue? Only consulted on sites whose design carries one. */
    public static function looksLikeListingRequest(string $text): bool
    {
        $t = strtolower($text);
        $noun = '(listing|listings|property|properties|house|home|homes|apartment|apartments|flat|condo|villa|townhouse|townhome|bungalow|penthouse|duplex|studio|plot|land|unit|office)';
        if (preg_match('/\b(mark|set|flag)\b.*\b(sold|let|under offer|withdrawn|off the market|off market)\b/', $t)) return true;
        if (preg_match('/\b(sold|under offer|off the market)\b.*\b' . $noun . '\b/', $t) && ! preg_match('/\b(section|headline|heading|paragraph|hero)\b/', $t)) return true;
        if (preg_match('/\b(add|list|create|post|put up|publish)\b.*\b(a |an |new |this |another )?' . $noun . '\b/', $t) && preg_match('/\b(listing|property|properties|for sale|to let|for rent|\$|€|£|aed|usd|bed|beds|bedroom|bath|sq ?ft|sqm)\b|[\$€£]/', $t)) return true;
        if (preg_match('/\b(remove|delete|take down|drop|withdraw|hide)\b.*\b(listing|property|properties)\b/', $t)) return true;
        if (preg_match('/\b(remove|delete|take down|withdraw)\b.*\b(house|apartment|flat|condo|villa|townhouse|townhome|bungalow|penthouse|duplex|cottage|plot|land)\b/', $t)
            && ! preg_match('/\b(photo|image|picture|section|block|page|headline|heading|text|paragraph|button|logo|gallery|video)\b/', $t)) return true;
        if (preg_match('/\b(listing|property)\b.*\b(remove|delete|take down|withdraw)\b/', $t)) return true;
        if (preg_match('/\b(change|update|set|reduce|lower|raise|increase|drop|cut)\b.*\bprice\b.*\b(listing|property|house|home|apartment|villa|condo|flat|townhouse|bungalow|penthouse)\b/', $t)) return true;
        if (preg_match('/\b(listing|property)\b.*\bprice\b.*\bto\b/', $t)) return true;
        if (preg_match('/\b(price|reduce|lower|raise)\b.*\b(listing|property)\b.*\bto\b/', $t)) return true;
        if (preg_match('/\b(turn|switch)\b.*\blistings?\b.*\b(on|off)\b|\blistings?\b.*\b(on|off)\b.*\b(site|page|website)\b/', $t)) return true;
        return false;
    }

    /** Arthur-chat entry: deterministic parsing, honest replies, no invented facts. */
    public function arthur(int $wsId, int $websiteId, string $request, array $ctx = []): array
    {
        $base = ['kind' => 'listing', 'credits' => 0, 'applied' => 0, 'actions_applied' => 0];
        $site = $this->owned($wsId, $websiteId);
        $cat = $site ? $this->catalogueFor($websiteId, $site) : null;
        if (! $site || ! $cat) return $base + ['success' => false, 'code' => 'NO_CATALOGUE', 'message' => 'This design does not carry a property catalogue.'];
        $t = trim($request);
        $lower = strtolower($t);
        // on / off
        if (preg_match('/\b(turn|switch)\b.*\blistings?\b.*\b(on|off)\b|\blistings?\b.*\b(on|off)\b.*\b(site|page|website)\b/', $lower, $m)) {
            $on = ! preg_match('/\boff\b/', $lower);
            $res = $this->setEnabled($wsId, $websiteId, $on);
            return $base + ['success' => (bool) ($res['success'] ?? false), 'applied' => 1, 'actions_applied' => 1, 'message' => (string) ($res['message'] ?? '')];
        }
        if (! $cat['enabled']) return $base + ['success' => false, 'code' => 'LISTINGS_OFF', 'message' => 'Listings are switched off for this site. Say "turn listings on" and I will bring the Listings page back.'];
        if (empty($cat['seeded_at'])) { $this->seedFromSite($wsId, $websiteId, $site, $cat); }
        $rows = $this->rows($websiteId);
        $active = array_values(array_filter($rows, fn($r) => in_array($r->status, self::ACTIVE, true)));
        $actor = (int) ($ctx['user_id'] ?? 0) ?: null;

        // ADD
        if (preg_match('/\b(add|list|create|post|put up|publish)\b/', $lower) && ! preg_match('/\b(mark|sold|remove|delete|take down)\b/', $lower)) {
            $in = $this->parseAdd($t, $cat['currency']);
            if ($in['title'] === '') return $base + ['success' => false, 'code' => 'NEED_TITLE', 'message' => 'Tell me what the property is and where — for example: "Add a listing: 3-bed townhouse in Travis Heights, $925,000, 2 baths, 1,850 sq ft".'];
            $res = $this->create($wsId, $websiteId, $in, $actor, 'arthur');
            if (empty($res['success'])) return $base + ['success' => false, 'code' => $res['code'] ?? 'INVALID', 'message' => (string) ($res['message'] ?? 'I could not add that listing.')];
            $L = $res['listing'];
            $missing = [];
            if ($L['price'] === null && empty($L['price_label'])) $missing[] = 'price';
            if ($L['photos'] === []) $missing[] = 'photo';
            if ($L['beds'] === null && empty($L['specs_text'])) $missing[] = 'beds/baths/size';
            $msg = 'Added “' . $L['title'] . '” — ' . strtolower($L['status_label']) . ', ' . $L['price_display'] . ($L['location'] ? ', ' . $L['location'] : '') . ($L['specs_display'] !== '' ? ' (' . $L['specs_display'] . ')' : '') . '. It is on the home page, on the Listings page and has its own page at ' . $L['page'] . '.';
            if ($missing !== []) $msg .= ' I did not invent the ' . implode(', ', $missing) . ' — add ' . (count($missing) > 1 ? 'them' : 'it') . ' in the Listings panel or tell me here.';
            return $base + ['success' => true, 'applied' => 1, 'actions_applied' => 1, 'message' => $msg, 'listing' => $L];
        }

        // STATUS (sold / let / under offer / withdrawn)
        if (preg_match('/\b(sold|under offer|let|withdrawn|off the market|off market|rented)\b/', $lower) && ! preg_match('/\b(remove|delete|take down|price)\b/', $lower)) {
            $status = preg_match('/\bunder offer\b/', $lower) ? 'under_offer' : (preg_match('/\b(withdrawn|off the market|off market)\b/', $lower) ? 'withdrawn' : (preg_match('/\b(let|rented)\b/', $lower) && ! preg_match('/\bsold\b/', $lower) ? 'let' : 'sold'));
            $target = $this->findTarget($t, $rows, $active);
            if (! $target) return $base + ['success' => false, 'code' => 'WHICH', 'message' => $this->whichOne($active, 'mark as ' . strtolower(self::STATUSES[$status]))];
            $note = null;
            if (preg_match('/\b(for|at)\s+((?:[\$€£₱]|AED|USD|EUR|GBP)\s?\d[\d,\.]*\d\s*[kKmM]?|(?:[\$€£₱]|AED|USD|EUR|GBP)\s?\d\s*[kKmM]?)\b/u', $t, $pm)) $note = 'Sold for ' . rtrim(trim($pm[2]), ',.');
            if (preg_match('/\b(over asking|under asking|above asking|below asking|in \d+ days|within \d+ days|multiple offers|full asking)\b[^.]*/i', $t, $nm)) $note = trim(($note ? $note . ', ' : '') . $nm[0]);
            $res = $this->setStatus($wsId, $websiteId, (int) $target->id, $status, $note);
            if (empty($res['success'])) return $base + ['success' => false, 'message' => (string) ($res['message'] ?? 'I could not change that listing.')];
            $msg = '“' . $target->title . '” is now marked ' . strtolower(self::STATUSES[$status]) . '.';
            if (in_array($status, self::CLOSED, true)) $msg .= $cat['sold_block'] !== '' ? ' It moved from the available listings to the sold row on the home page and the Listings page' : ' It left the available listings and shows under recently sold on the Listings page';
            if ($note) { $msg .= ', with the note “' . $note . '” — only what you told me.'; }
            else { $msg .= in_array($status, self::CLOSED, true) ? '. I did not add a sale price or outcome; tell me and I will note it.' : '.'; }
            return $base + ['success' => true, 'applied' => 1, 'actions_applied' => 1, 'message' => $msg];
        }

        // PRICE
        if (preg_match('/\bprice\b|\b(reduce|lower|raise|increase|drop|cut)\b/', $lower) && preg_match('/\bto\s+((?:[\$€£₱]|AED|USD|EUR|GBP|PHP)\s?[\d,\.]+\s*[kKmM]?|[\d,\.]+\s*[kKmM]?\s*(?:AED|USD|EUR|GBP|PHP|dollars|euros|pounds)?)\b/iu', $t, $pm)) {
            $target = $this->findTarget($t, $rows, $active);
            if (! $target) return $base + ['success' => false, 'code' => 'WHICH', 'message' => $this->whichOne($active, 'reprice')];
            $price = $this->moneyToNumber($pm[1]);
            if ($price === null) return $base + ['success' => false, 'message' => 'I could not read the new price — say it as a number, e.g. "to $899,000".'];
            $in = ['price' => $price, 'price_label' => ''];
            $cur = $this->currencyFromText($pm[1], (string) $target->currency);
            if ($cur !== (string) $target->currency) $in['currency'] = $cur;
            $res = $this->update($wsId, $websiteId, (int) $target->id, $in, 'listing_price');
            if (empty($res['success'])) return $base + ['success' => false, 'message' => (string) ($res['message'] ?? 'I could not change that price.')];
            return $base + ['success' => true, 'applied' => 1, 'actions_applied' => 1, 'message' => '“' . $target->title . '” is now ' . $res['listing']['price_display'] . ' on the home page, the Listings page and its own page.'];
        }

        // REMOVE
        if (preg_match('/\b(remove|delete|take down|drop|withdraw|hide)\b/', $lower)) {
            $target = $this->findTarget($t, $rows, $rows);
            if (! $target) return $base + ['success' => false, 'code' => 'WHICH', 'message' => $this->whichOne($rows, 'remove')];
            $res = $this->delete($wsId, $websiteId, (int) $target->id);
            return $base + ['success' => (bool) ($res['success'] ?? false), 'applied' => 1, 'actions_applied' => 1, 'message' => (string) ($res['message'] ?? '')];
        }

        return $base + ['success' => false, 'code' => 'UNCLEAR', 'message' => 'I can add a listing, change its price, mark it sold, let, under offer or withdrawn, or remove it. ' . $this->whichOne($active, 'work on')];
    }

    private function whichOne(array $rows, string $verb): string
    {
        if ($rows === []) return 'There are no listings on this site yet — say "add a listing: …" with the property, place and price.';
        $names = array_map(fn($r) => '“' . $r->title . '”' . ($r->location ? ' (' . $r->location . ')' : ''), array_slice($rows, 0, 8));
        return 'Which one should I ' . $verb . '? Current listings: ' . implode(', ', $names) . (count($rows) > 8 ? ' and ' . (count($rows) - 8) . ' more' : '') . '.';
    }

    /** Which listing is meant: an ordinal ("listing 2", "the third"), a price, or the best word overlap with title/location. */
    private function findTarget(string $text, array $all, array $pool): ?object
    {
        $pool = $pool ?: $all;
        if ($pool === []) return null;
        $t = strtolower($text);
        if (count($pool) === 1 && preg_match('/\b(it|that|this|the (listing|property|one))\b/', $t)) return $pool[0];
        $ordinals = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'fifth' => 5, 'sixth' => 6, 'last' => count($pool)];
        if (preg_match('/\b(?:listing|property)\s*(?:#|no\.?|number)?\s*(\d)\b/', $t, $m) && isset($pool[(int) $m[1] - 1])) return $pool[(int) $m[1] - 1];
        if (preg_match('/\b(first|second|third|fourth|fifth|sixth|last)\b\s+(?:listing|property|one)\b/', $t, $m) && isset($pool[$ordinals[$m[1]] - 1])) return $pool[$ordinals[$m[1]] - 1];
        if (preg_match_all('/(?:[\$€£₱]|AED|USD|EUR|GBP)\s?([\d,\.]+\s*[kKmM]?)/iu', $text, $pm)) {
            foreach ($pm[1] as $raw) { $n = $this->moneyToNumber($raw); foreach ($pool as $r) { if ($n !== null && $r->price !== null && abs((float) $r->price - $n) < 0.5) return $r; } }
        }
        $stop = ['the', 'a', 'an', 'and', 'or', 'of', 'in', 'at', 'on', 'to', 'for', 'as', 'is', 'it', 'that', 'this', 'mark', 'set', 'sold', 'let', 'under', 'offer', 'remove', 'delete', 'listing', 'property', 'price', 'change', 'update', 'please', 'take', 'down', 'withdrawn', 'reduce', 'lower', 'raise', 'drop', 'with', 'from', 'our', 'my', 'now', 'has', 'been', 'was', 'house', 'home', 'apartment', 'flat', 'villa', 'condo'];
        $words = array_values(array_diff(array_filter(preg_split('/[^a-z0-9]+/', $t)), $stop));
        $best = null; $bestScore = 0;
        foreach ($pool as $r) {
            $hay = strtolower($r->title . ' ' . ($r->location ?? ''));
            $hw = array_filter(preg_split('/[^a-z0-9]+/', $hay));
            $score = 0;
            foreach ($words as $w) { if (strlen($w) < 3) continue; if (in_array($w, $hw, true)) $score += 2; elseif (str_contains($hay, $w)) $score += 1; }
            if ($score > $bestScore) { $bestScore = $score; $best = $r; }
            elseif ($score === $bestScore && $score > 0) { $best = null; }  // tie → ask
        }
        return $bestScore >= 2 ? $best : null;
    }

    private function moneyToNumber(string $raw): ?float
    {
        $raw = strtolower(trim($raw));
        $mult = str_ends_with($raw, 'm') ? 1000000 : (str_ends_with($raw, 'k') ? 1000 : 1);
        $n = preg_replace('/[^\d.]/', '', $raw);
        if ($n === '' || ! is_numeric($n)) return null;
        return round((float) $n * $mult, 2);
    }

    /** "Add a listing: 3-bed townhouse in Travis Heights, $925,000, 2 baths, 1,850 sq ft, to let" → attrs. */
    private function parseAdd(string $text, string $defaultCurrency): array
    {
        $in = ['title' => '', 'status' => 'for_sale'];
        $t = $text;
        $lower = strtolower($t);
        if (preg_match('/\b(to let|for rent|rental|to rent|per month|\/month|a month|pcm)\b/', $lower)) { $in['status'] = 'to_let'; $in['price_period'] = 'month'; }
        if (preg_match('/\bunder offer\b/', $lower)) $in['status'] = 'under_offer';
        if (preg_match('/(?:[\$€£₱]|\b(?:AED|USD|EUR|GBP|PHP|CAD|AUD)\b)\s?([\d][\d,\.]*\s*[kKmM]?)\b|\b([\d][\d,\.]*\s*[kKmM]?)\s*(?:AED|USD|EUR|GBP|PHP|dollars|euros|pounds)\b/iu', $t, $pm, PREG_OFFSET_CAPTURE)) {
            $rawFull = $pm[0][0];
            $num = $this->moneyToNumber(($pm[1][0] ?? '') !== '' ? $pm[1][0] : ($pm[2][0] ?? ''));
            if ($num !== null) { $in['price'] = $num; $in['currency'] = $this->currencyFromText($rawFull, $defaultCurrency); }
            $t = str_replace($rawFull, ' ', $t);
        }
        if (preg_match('/\b(\d+(?:\.\d)?)\s*[- ]?\s*(?:bed|beds|bedroom|bedrooms|br|bd)\b/i', $t, $m)) { $in['beds'] = (float) $m[1]; $bedsRaw = $m[0]; }
        if (preg_match('/\b(\d+(?:\.\d)?)\s*[- ]?\s*(?:bath|baths|bathroom|bathrooms|ba)\b/i', $t, $m)) { $in['baths'] = (float) $m[1]; $t = str_replace($m[0], ' ', $t); }
        if (preg_match('/\b([\d][\d,]*(?:\.\d+)?)\s*(sq\.? ?ft|sqft|square feet|sq\.? ?m|sqm|m2|m²|square met(?:er|re)s|acres?|ha|hectares?)\b/iu', $t, $m)) {
            $in['size_value'] = (float) str_replace(',', '', $m[1]);
            $u = strtolower($m[2]);
            $in['size_unit'] = preg_match('/acre/', $u) ? 'acres' : (preg_match('/^(ha|hect)/', $u) ? 'ha' : (preg_match('/m/', $u) && ! preg_match('/ft|feet/', $u) ? 'sq m' : 'sq ft'));
            $t = str_replace($m[0], ' ', $t);
        }
        if (preg_match('/\b(?:in|at|on)\s+([A-Z][A-Za-z0-9\'’.\- ]{2,60}?(?:,\s*[A-Z][A-Za-z\- ]{1,30}){0,2})(?=\s*(?:,|\.|;|$|\bfor\b|\bat\b|\bwith\b|\bpriced\b|\b\d))/u', $t, $m)) { $in['location'] = trim($m[1], " ,.;"); $t = str_replace($m[0], ' ', $t); }
        // title = what is left after the verb and filler
        $core = preg_replace('/^.*?\b(?:add|list|create|post|put up|publish)\b\s*(?:a |an |new |another |this )?(?:(?:property |house |home )?listings?\b\s*(?:for|of|:)?\s*)?(?:a |an )?/i', '', $t, 1) ?? $t;
        $core = preg_replace('/\b(to let|for rent|for sale|to rent|under offer|please|on the site|to the site|to the website|on the website|to my listings|to the listings|listing|as a listing)\b/i', ' ', $core) ?? $core;
        $core = preg_replace('/\s+[\-–—]\s+/', ' ', $core) ?? $core;   // " - " separators go; "2-bed" keeps its hyphen
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
        if (! $site) return ['success' => false, 'code' => 'NOT_FOUND', 'message' => 'Website not found in this workspace.'];
        if (! $this->catalogueFor($websiteId, $site)) return ['success' => false, 'code' => 'NO_CATALOGUE', 'message' => 'This design does not carry a property catalogue.'];
        if ($file->getSize() > 8 * 1024 * 1024) return ['success' => false, 'code' => 'TOO_LARGE', 'message' => 'Photos must be under 8 MB.'];
        $ext = strtolower($file->getClientOriginalExtension() ?: 'jpg');
        $mime = (string) $file->getMimeType();
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) return ['success' => false, 'code' => 'TYPE', 'message' => 'Photos must be JPG, PNG or WEBP.'];
        $dir = storage_path("app/public/sites/{$websiteId}/listings");
        if (! is_dir($dir)) mkdir($dir, 0755, true);
        $name = 'p-' . date('Ymd') . '-' . Str::lower(Str::random(8)) . '.' . ($ext === 'jpeg' ? 'jpg' : $ext);
        $file->move($dir, $name);
        @chmod($dir . '/' . $name, 0644);
        try { \App\Engines\Builder\Support\ImagePolicy::normaliseInPlace($dir . '/' . $name, 'gallery'); } catch (\Throwable $e) {}
        return ['success' => true, 'url' => "/storage/sites/{$websiteId}/listings/{$name}"];
    }
}
