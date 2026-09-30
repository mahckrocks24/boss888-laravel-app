<?php

namespace App\Core\Brand;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * BRAND-B1 (RFC-0017 section 5d): the brand preferences of ONE business profile.
 *
 * Storage is the business's creative_brand_identities row: the default business keeps the workspace-level row
 * (business_id NULL), every other business its own row (the same keying as WorkspaceBrandKitResolver and
 * PUT /workspace/brand). Every change records where each value came from and snapshots a version.
 */
final class BrandProfileService
{
    public const MAX_PICKS = 4;

    /** The business (given, else the default), or null for a workspace with no business rows. */
    public function business(int $wsId, ?int $bizId): ?object
    {
        $q = DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at');
        $b = $bizId ? (clone $q)->where('id', $bizId)->first() : null;
        return $b ?: (clone $q)->orderByDesc('is_default')->orderBy('id')->first();
    }

    /** @return array<int,object> businesses of the workspace, default first */
    public function businesses(int $wsId): array
    {
        return DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'name', 'industry', 'tone', 'is_default'])->all();
    }

    /** The row key for a business: the default business owns the workspace-level row. */
    public function key(int $wsId, ?object $biz): array
    {
        return ['workspace_id' => $wsId, 'business_id' => ($biz && ! $biz->is_default) ? (int) $biz->id : null];
    }

    public function row(int $wsId, ?object $biz, bool $create = false): ?object
    {
        $k = $this->key($wsId, $biz);
        $q = DB::table('creative_brand_identities')->where('workspace_id', $wsId)->whereNull('deleted_at');
        $k['business_id'] === null ? $q->whereNull('business_id') : $q->where('business_id', $k['business_id']);
        $row = $q->orderBy('id')->first();
        if (! $row && $create) {
            $id = DB::table('creative_brand_identities')->insertGetId($k + ['voice' => 'professional', 'tone' => 'professional', 'industry' => $biz->industry ?? null, 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('creative_brand_identities')->where('id', $id)->first();
        }
        return $row;
    }

    public static function json($row, string $col): array
    {
        $v = $row->$col ?? null;
        if ($v === null || $v === '') return [];
        $d = is_string($v) ? json_decode($v, true) : (array) $v;
        return is_array($d) ? $d : [];
    }

    /** Write fields with provenance, bump the version and snapshot it. */
    public function write(object $row, array $fields, string $source, string $reason): object
    {
        $sources = self::json($row, 'sources_json');
        foreach (array_keys($fields) as $f) { if (! in_array($f, ['updated_at', 'proposal_json', 'intake_status', 'intake_asked_at', 'confirmed_at'], true)) $sources[$f] = ['source' => $source, 'at' => now()->toIso8601String()]; }
        $fields['sources_json'] = json_encode($sources, JSON_UNESCAPED_SLASHES);
        $fields['version'] = (int) ($row->version ?? 1) + 1;
        $fields['updated_at'] = now();
        DB::table('creative_brand_identities')->where('id', $row->id)->update($fields);
        $fresh = DB::table('creative_brand_identities')->where('id', $row->id)->first();
        $snap = (array) $fresh; unset($snap['proposal_json']);
        DB::table('brand_profile_versions')->insert([
            'workspace_id' => (int) $fresh->workspace_id, 'business_id' => $fresh->business_id, 'brand_id' => (int) $fresh->id,
            'version' => (int) $fresh->version, 'snapshot_json' => json_encode($snap, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'reason' => mb_substr($reason, 0, 191), 'source' => $source, 'created_at' => now(),
        ]);
        return $fresh;
    }

    /** The owner's 1-4 picks (ranked) and the directions never to use. */
    public function setDirections(int $wsId, ?int $bizId, array $picks, array $never, string $source = 'picker'): array
    {
        $picks = DesignDirections::clean($picks, self::MAX_PICKS);
        $never = array_values(array_diff(DesignDirections::clean($never), $picks));
        if (! $picks) return ['success' => false, 'error' => 'Pick at least one style you like.'];
        $biz = $this->business($wsId, $bizId);
        $row = $this->row($wsId, $biz, true);
        $row = $this->write($row, [
            'directions_json' => json_encode(['picks' => $picks, 'never' => $never, 'chosen_at' => now()->toIso8601String(), 'source' => $source]),
            'intake_status' => 'confirmed', 'confirmed_at' => $row->confirmed_at ?? now(),
        ], $source, 'design directions chosen');
        return ['success' => true, 'business_id' => $biz->id ?? null, 'picks' => $picks, 'never' => $never, 'names' => array_map(fn ($id) => DesignDirections::ALL[$id]['name'], $picks)];
    }

    public function skip(int $wsId, ?int $bizId): void
    {
        $row = $this->row($wsId, $this->business($wsId, $bizId), true);
        DB::table('creative_brand_identities')->where('id', $row->id)->update(['intake_status' => 'skipped', 'updated_at' => now()]);
    }

    /** Store extracted brand facts for the owner to confirm. Returns the proposal token. */
    public function propose(int $wsId, ?int $bizId, array $proposal): string
    {
        $biz = $this->business($wsId, $bizId);
        $row = $this->row($wsId, $biz, true);
        $token = Str::random(24);
        $proposal['token'] = $token; $proposal['business_id'] = $biz->id ?? null; $proposal['at'] = now()->toIso8601String();
        DB::table('creative_brand_identities')->where('id', $row->id)->update(['proposal_json' => json_encode($proposal, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
        return $token;
    }

    /** Find the row holding a proposal token in this workspace. */
    public function proposalRow(int $wsId, string $token): ?object
    {
        if (! preg_match('/^[A-Za-z0-9]{24}$/', $token)) return null;
        return DB::table('creative_brand_identities')->where('workspace_id', $wsId)->whereNull('deleted_at')
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(proposal_json, '$.token')) = ?", [$token])->first();
    }

    /** Apply a confirmed proposal (optionally with the owner's edits) to the business's brand. */
    public function confirm(int $wsId, string $token, array $edits = []): array
    {
        $row = $this->proposalRow($wsId, $token);
        if (! $row) return ['success' => false, 'error' => 'That summary has expired or was already saved.'];
        $p = self::json($row, 'proposal_json');
        foreach (['colors', 'fonts', 'rules', 'tone', 'visual_style', 'logo', 'directions'] as $k) { if (array_key_exists($k, $edits)) $p[$k] = $edits[$k]; }
        $hex = fn ($v) => is_string($v) && preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($v)) ? strtoupper(trim($v)) : null;
        $f = [];
        $roles = [];
        foreach ((array) ($p['colors'] ?? []) as $c) { $h = $hex($c['hex'] ?? null); if ($h && ! WorkspaceBrandKitResolver::isPlatformColor($h) || ($h && $wsId === 1)) $roles[] = ['hex' => $h, 'role' => (string) ($c['role'] ?? '')]; }
        if ($roles) {
            $by = fn (string $r) => collect($roles)->firstWhere('role', $r)['hex'] ?? null;
            $ordered = array_values(array_unique(array_column($roles, 'hex')));
            $f['primary_color'] = $by('primary') ?? $ordered[0];
            $f['secondary_color'] = $by('secondary') ?? ($ordered[1] ?? null);
            $f['accent_color'] = $by('accent') ?? ($ordered[2] ?? null);
            $f['colors_json'] = json_encode($ordered);
        }
        $fonts = array_filter(array_map(fn ($v) => is_string($v) ? mb_substr(trim($v), 0, 60) : null, (array) ($p['fonts'] ?? [])));
        if ($fonts) $f['fonts_json'] = json_encode(array_merge(self::json($row, 'fonts_json'), array_intersect_key($fonts, array_flip(['heading', 'body', 'display']))));
        if (! empty($p['tone']) && is_string($p['tone'])) $f['tone'] = mb_substr(trim($p['tone']), 0, 250);
        if (! empty($p['visual_style']) && is_string($p['visual_style'])) $f['visual_style'] = mb_substr(trim($p['visual_style']), 0, 250);
        $assets = self::json($row, 'assets_json');
        foreach ((array) ($p['assets'] ?? []) as $a) { if (! empty($a['url'])) $assets[] = array_intersect_key($a, array_flip(['kind', 'media_id', 'url', 'name', 'notes'])) + ['at' => now()->toIso8601String()]; }
        if (! empty($p['logo']['url']) && preg_match('#^(https?://|/)#', (string) $p['logo']['url'])) { $f['logo_url'] = (string) $p['logo']['url']; }
        if ($assets) $f['assets_json'] = json_encode(array_slice($assets, -40), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rules = self::json($row, 'rules_json');
        foreach ((array) ($p['rules'] ?? []) as $r) { $r = is_string($r) ? trim($r) : ''; if ($r !== '' && ! in_array(mb_strtolower($r), array_map(fn ($x) => mb_strtolower($x['rule'] ?? ''), $rules), true)) $rules[] = ['rule' => mb_substr($r, 0, 200), 'source' => $p['source'] ?? 'chat', 'at' => now()->toIso8601String()]; }
        if ($rules) $f['rules_json'] = json_encode(array_slice($rules, -30), JSON_UNESCAPED_UNICODE);
        $dirs = self::json($row, 'directions_json');
        $sug = DesignDirections::clean((array) ($p['directions'] ?? []), self::MAX_PICKS);
        if ($sug && empty($dirs['picks'])) { $f['directions_json'] = json_encode(['picks' => $sug, 'never' => [], 'chosen_at' => now()->toIso8601String(), 'source' => 'suggested_from_upload']); }
        $f['proposal_json'] = null;
        $f['intake_status'] = 'confirmed';
        $f['confirmed_at'] = now();
        $this->write($row, $f, (string) ($p['source'] ?? 'chat'), 'brand confirmed from ' . ($p['source'] ?? 'chat'));
        Cache::put('brand:proposal:' . $token, 'saved', now()->addDays(90));
        return ['success' => true, 'business_id' => $p['business_id'] ?? null, 'saved' => array_values(array_diff(array_keys($f), ['proposal_json', 'intake_status', 'confirmed_at']))];
    }

    /** pending | saved | discarded | expired */
    public function proposalStatus(int $wsId, string $token): string
    {
        if ($this->proposalRow($wsId, $token)) return 'pending';
        return (string) (Cache::get('brand:proposal:' . $token) ?? 'expired');
    }

    public function discard(int $wsId, string $token): bool
    {
        $row = $this->proposalRow($wsId, $token);
        if (! $row) return false;
        DB::table('creative_brand_identities')->where('id', $row->id)->update(['proposal_json' => null, 'updated_at' => now()]);
        Cache::put('brand:proposal:' . $token, 'discarded', now()->addDays(90));
        return true;
    }

    public function removeRule(int $wsId, ?int $bizId, int $index): bool
    {
        $row = $this->row($wsId, $this->business($wsId, $bizId));
        if (! $row) return false;
        $rules = self::json($row, 'rules_json');
        if (! isset($rules[$index])) return false;
        array_splice($rules, $index, 1);
        $this->write($row, ['rules_json' => json_encode($rules, JSON_UNESCAPED_UNICODE)], 'settings', 'rule removed');
        return true;
    }

    /**
     * What the picker previews are made of — the business's own name, headline, colours and a real photo:
     * the website's hero (template variables, or og: tags of a custom site), else the industry library photo.
     */
    public function previewMaterial(int $wsId, ?int $bizId): array
    {
        $biz = $this->business($wsId, $bizId);
        $kit = app(WorkspaceBrandKitResolver::class)->resolve($wsId, $biz->id ?? null);
        $name = $kit['brand_name'];
        $site = null;
        $sq = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at');
        if ($biz) $site = (clone $sq)->where('business_id', $biz->id)->orderByDesc('published_at')->first(['id', 'template_variables', 'custom_domain', 'subdomain']);
        if (! $site && (! $biz || $biz->is_default) && (clone $sq)->count() === 1) $site = (clone $sq)->first(['id', 'template_variables', 'custom_domain', 'subdomain']);
        $tv = $site ? (json_decode((string) $site->template_variables, true) ?: []) : [];
        $clean = fn ($v, $n = 90) => is_string($v) && trim($v) !== '' && ! str_contains($v, '<') ? mb_substr(trim(strip_tags($v)), 0, $n) : null;
        $headline = $clean($tv['hero_title'] ?? null);
        $eyebrow = $clean($tv['hero_eyebrow'] ?? null, 50);
        $photo = is_string($tv['hero_image'] ?? null) && preg_match('#^(https?://|/)[^\s<>"]+$#', $tv['hero_image']) ? $tv['hero_image'] : null;
        if ($site && (! $headline || ! $photo)) {
            $host = $site->custom_domain ?: $site->subdomain;
            if ($host) {
                $og = Cache::remember('brandprev:og:' . $site->id, 86400, function () use ($host) {
                    try {
                        $html = (string) Http::timeout(6)->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; LevelUpGrowthPreview/1.0)'])->get('https://' . preg_replace('#^https?://#', '', rtrim($host, '/')) . '/')->body();
                        $m = fn ($re) => preg_match($re, $html, $x) ? html_entity_decode(trim($x[1]), ENT_QUOTES) : null;
                        return ['title' => $m('/<meta[^>]+property=["\']og:title["\'][^>]+content=["\']([^"\']+)/i') ?? $m('/<h1[^>]*>(.*?)<\/h1>/is'), 'image' => $m('/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)/i')];
                    } catch (\Throwable $e) { return []; }
                });
                $headline = $headline ?? $clean($og['title'] ?? null);
                $photo = $photo ?? (is_string($og['image'] ?? null) && preg_match('#^https?://#', $og['image']) ? $og['image'] : null);
            }
        }
        $industry = (string) ($biz->industry ?? $kit['industry'] ?? '');
        if (! $photo) $photo = $this->libraryPhoto($industry);
        if (! $headline) $headline = trim((string) ($biz->goal ?? '')) !== '' ? mb_substr((string) $biz->goal, 0, 80) : $name;
        return [
            'business_id' => $biz->id ?? null, 'business_name' => $name, 'industry' => $industry, 'headline' => $headline, 'eyebrow' => $eyebrow ?? ($industry !== '' ? ucwords($industry) : null),
            'photo' => $photo, 'colors' => ['primary' => $kit['primary_color'], 'secondary' => $kit['secondary_color'], 'accent' => $kit['accent_color']],
            'brand_set' => empty($kit['is_neutral']), 'logo_url' => $kit['logo_url'],
        ];
    }

    private function libraryPhoto(string $industry): ?string
    {
        $i = strtolower($industry);
        $map = ['chef' => 'catering', 'cater' => 'catering', 'bak' => 'cafe', 'coffee' => 'cafe', 'cafe' => 'cafe', 'restaurant' => 'restaurant', 'food' => 'restaurant', 'gym' => 'gym', 'fitness' => 'gym', 'pet' => 'pet_services',
            'event' => 'event_venue', 'wedding' => 'event_venue', 'design' => 'marketing_agency', 'marketing' => 'marketing_agency', 'agency' => 'marketing_agency', 'it ' => 'it_services', 'software' => 'technology', 'tech' => 'technology',
            'dental' => 'dental', 'clinic' => 'medical_clinic', 'medical' => 'medical_clinic', 'aesthetic' => 'aesthetic_clinic', 'salon' => 'beauty_salon', 'beauty' => 'beauty_salon', 'barber' => 'barbershop',
            'real estate' => 'real_estate_agency', 'realty' => 'real_estate_agency', 'property' => 'real_estate_agency', 'hotel' => 'hotel', 'resort' => 'resort', 'travel' => 'travel_agency', 'tour' => 'travel_agency',
            'law' => 'legal', 'legal' => 'legal', 'account' => 'accounting', 'construct' => 'construction', 'joinery' => 'construction', 'carpent' => 'construction', 'architect' => 'architecture', 'interior' => 'interior_design',
            'school' => 'education', 'tutor' => 'tutoring', 'course' => 'online_courses', 'child' => 'childcare', 'photo' => 'photography', 'retail' => 'retail_shop', 'shop' => 'retail_shop', 'car' => 'automotive', 'auto' => 'automotive', 'consult' => 'consulting'];
        foreach ($map as $kw => $fam) {
            if (str_contains(' ' . $i . ' ', $kw)) {
                $u = DB::table('builder_default_assets')->where('industry', $fam)->where('asset_type', 'hero')->value('url');
                if ($u) return (string) $u;
                $dir = storage_path('app/public/template-images/' . $fam);
                $files = is_dir($dir) ? glob($dir . '/*.jpg') : [];
                if ($files) return '/storage/template-images/' . $fam . '/' . basename($files[0]);
            }
        }
        return null;
    }

    /** Everything the settings screen and Sarah need about one business's brand. */
    public function profile(int $wsId, ?int $bizId): array
    {
        $biz = $this->business($wsId, $bizId);
        $row = $this->row($wsId, $biz);
        $kit = app(WorkspaceBrandKitResolver::class)->resolve($wsId, $biz->id ?? null);
        $dirs = $row ? self::json($row, 'directions_json') : [];
        return [
            'business_id' => $biz->id ?? null, 'business_name' => $kit['brand_name'], 'industry' => $kit['industry'],
            'brand_set' => empty($kit['is_neutral']),
            'colors' => ['primary' => $kit['primary_color'], 'secondary' => $kit['secondary_color'], 'accent' => $kit['accent_color']],
            'fonts' => ['heading' => $kit['heading_font'], 'body' => $kit['body_font']], 'logo_url' => $kit['logo_url'], 'tone' => $kit['tone'],
            'picks' => DesignDirections::clean((array) ($dirs['picks'] ?? [])), 'never' => DesignDirections::clean((array) ($dirs['never'] ?? [])),
            'picks_source' => $dirs['source'] ?? null,
            'recipes' => array_values(array_filter(array_map('intval', (array) ($dirs['recipes'] ?? [])))),   // DESIGN-LIBRARY-2
            'recipe_picks' => app(DesignLibraryService::class)->picks($wsId, $bizId),   // DESIGN-LIBRARY-2: public rows for the saved state
            'rules' => array_values(array_map(fn ($r) => (string) ($r['rule'] ?? ''), $row ? self::json($row, 'rules_json') : [])),
            'assets' => $row ? self::json($row, 'assets_json') : [],
            'intake_status' => $row->intake_status ?? null,
            'sources' => $row ? self::json($row, 'sources_json') : [],
            'version' => (int) ($row->version ?? 0),
            'directions' => DesignDirections::catalogue($kit['industry'] ?? null),
            'preview' => $this->previewMaterial($wsId, $biz->id ?? null),
            'businesses' => array_map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->name, 'is_default' => (bool) $b->is_default], $this->businesses($wsId)),
        ];
    }
}
