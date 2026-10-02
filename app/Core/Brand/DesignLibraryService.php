<?php

namespace App\Core\Brand;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * DESIGN-LIBRARY-2 (Owner 2026-10-01). The searchable library of reference design recipes and a business's picks (up to
 * three). What leaves this class for a customer is the PUBLIC face only (title, industry, archetype, format, mood, our
 * thumbnail); the recipe and the prompt template are Sarah's private method (SECRET-1) and never appear in a response.
 */
final class DesignLibraryService
{
    public const MAX_PICKS = 5;   // Owner 2026-10-01: "get users to choose 5"
    private const STYLE_NAMES = ['D1' => 'Editorial luxury', 'D2' => 'Clean minimal', 'D3' => 'Bold colour block', 'D4' => 'Warm organic', 'D5' => 'Authentic photo-first', 'D6' => 'Soft cinematic', 'D7' => 'Neon night', 'D8' => 'Layered collage', 'D9' => 'Playful retro', 'D10' => 'Technical blueprint'];
    private const ARCHETYPE_LABELS = ['statement over scene' => 'Statement over a photo', 'product hero' => 'Product hero', 'offer banner' => 'Offer or promo', 'quote card' => 'Quote card', 'before-after' => 'Before and after', 'listing card' => 'Listing card', 'checklist/tips' => 'Tips and checklists', 'testimonial' => 'Testimonial', 'announcement' => 'Announcement', 'event poster' => 'Event poster', 'menu/price card' => 'Menu or price card', 'team/portrait' => 'Team or portrait', 'lifestyle photo' => 'Lifestyle photo', 'editorial carousel cover' => 'Carousel cover', 'other' => 'Other'];

    public function __construct(private BrandProfileService $profiles) {}

    /** @return array{items:array, total:int, page:int, per_page:int, facets:array} */
    public function search(array $f, ?string $homeIndustry = null): array
    {
        $q = DB::table('design_recipes')->where('active', true);
        $terms = preg_split('/\s+/', strtolower(trim((string) ($f['q'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        // any word may match; rows that match more of the words come first
        $terms = array_slice($terms, 0, 6); $rank = []; $bind = [];
        if ($terms) { $q->where(function ($w) use ($terms) { foreach ($terms as $t) { $like = '%' . addcslashes(mb_substr($t, 0, 40), '%_') . '%'; $w->orWhere('tags', 'like', $like)->orWhere('title', 'like', $like); } }); foreach ($terms as $t) { $rank[] = '(CASE WHEN tags LIKE ? OR title LIKE ? THEN 1 ELSE 0 END)'; $like = '%' . addcslashes(mb_substr($t, 0, 40), '%_') . '%'; $bind[] = $like; $bind[] = $like; } }
        if (! empty($f['industry']))  $q->where('industry', (string) $f['industry']);
        if (! empty($f['archetype'])) $q->where('archetype', (string) $f['archetype']);
        if (! empty($f['format']))    $q->where('format', (string) $f['format']);
        if (! empty($f['style']))     $q->where('direction_fit', 'like', '%' . addcslashes(strtoupper((string) $f['style']), '%_') . ' %');
        if (isset($f['people']) && $f['people'] !== '' && $f['people'] !== null) $q->where('has_people', (bool) (int) $f['people']);
        $total = (clone $q)->count();
        $page = max(1, (int) ($f['page'] ?? 1)); $per = min(60, max(12, (int) ($f['per_page'] ?? 24)));
        if ($rank) $q->orderByRaw('(' . implode(' + ', $rank) . ') DESC', $bind);
        if ($homeIndustry) $q->orderByRaw('CASE WHEN industry = ? THEN 0 ELSE 1 END', [$homeIndustry]);
        $rows = $q->orderBy('sort')->offset(($page - 1) * $per)->limit($per)->get();
        return ['items' => array_map(fn ($r) => $this->publicRow($r), $rows->all()), 'total' => $total, 'page' => $page, 'per_page' => $per, 'facets' => $this->facets()];
    }

    public function facets(): array
    {
        $base = fn () => DB::table('design_recipes')->where('active', true);
        $ind = $base()->selectRaw('industry, count(*) n')->groupBy('industry')->orderBy('industry')->get()->map(fn ($r) => ['value' => $r->industry, 'label' => $r->industry, 'count' => (int) $r->n])->all();
        $arch = $base()->selectRaw('archetype, count(*) n')->groupBy('archetype')->orderByDesc('n')->get()->map(fn ($r) => ['value' => $r->archetype, 'label' => self::ARCHETYPE_LABELS[$r->archetype] ?? ucfirst($r->archetype), 'count' => (int) $r->n])->all();
        $fmt = $base()->selectRaw('format, count(*) n')->groupBy('format')->get()->map(fn ($r) => ['value' => $r->format, 'label' => ['square' => 'Square', 'portrait_4_5' => 'Portrait', 'story_9_16' => 'Story'][$r->format] ?? $r->format, 'count' => (int) $r->n])->all();
        $styles = [];
        foreach (self::STYLE_NAMES as $code => $name) { $n = $base()->where('direction_fit', 'like', '%' . $code . ' %')->count(); if ($n) $styles[] = ['value' => $code, 'label' => $name, 'count' => $n]; }
        return ['industry' => $ind, 'archetype' => $arch, 'format' => $fmt, 'style' => $styles, 'people' => [['value' => '1', 'label' => 'With people', 'count' => $base()->where('has_people', true)->count()], ['value' => '0', 'label' => 'No people', 'count' => $base()->where('has_people', false)->count()]]];
    }

    public function get(int $id): ?array
    {
        $r = DB::table('design_recipes')->where('id', $id)->where('active', true)->first();
        return $r ? $this->publicRow($r, true) : null;
    }

    /** The public face of a recipe: never the recipe or the prompt (SECRET-1). */
    public function publicRow(object $r, bool $detail = false): array
    {
        $recipe = json_decode((string) $r->recipe_json, true) ?: [];
        $colour = json_decode((string) $r->colour_json, true) ?: [];
        $fit = json_decode((string) $r->direction_fit, true) ?: [];
        $out = [
            'id' => (int) $r->id, 'title' => $r->title, 'industry' => $r->industry, 'archetype' => $r->archetype,
            'archetype_label' => self::ARCHETYPE_LABELS[$r->archetype] ?? ucfirst((string) $r->archetype), 'format' => $r->format, 'mood' => $r->mood,
            'styles' => array_values(array_filter(array_map(fn ($d) => preg_match('/^(D\d+)/', (string) $d, $m) ? (self::STYLE_NAMES[$m[1]] ?? null) : null, $fit))),
            'colours' => ['ground' => $colour['ground'] ?? null, 'text' => $colour['text'] ?? null, 'accent' => $colour['accent'] ?? null],
            'has_people' => (bool) $r->has_people,
            'thumb' => $r->thumb_path ? Storage::disk('public')->url($r->thumb_path) . '?v=' . strtotime((string) ($r->updated_at ?? 'now')) : null,
        ];
        if ($detail) {
            $out['description'] = trim(implode(' ', array_filter([
                (string) (($recipe['layout'] ?? [])['structure'] ?? ''), (string) (($recipe['imagery'] ?? [])['treatment'] ?? ''),
                ! empty(($recipe['typography'] ?? [])['headline']['family_feel']) ? 'Headline in a ' . $recipe['typography']['headline']['family_feel'] . '.' : '',
            ])));
            $out['why'] = array_values((array) ($recipe['what_makes_it_work'] ?? []));
            $out['asks'] = array_values(array_map(fn ($v) => (string) ($v['controls'] ?? ''), (array) ($recipe['variables'] ?? [])));   // what Sarah will ask for, in words
        }
        return $out;
    }

    /** The business's picked recipes (public rows) in order. */
    public function picks(int $wsId, ?int $bizId): array
    {
        $row = $this->profiles->row($wsId, $this->profiles->business($wsId, $bizId), false);
        $dirs = $row ? BrandProfileService::json($row, 'directions_json') : [];
        $ids = array_values(array_filter(array_map('intval', (array) ($dirs['recipes'] ?? []))));
        if (! $ids) return [];
        $rows = DB::table('design_recipes')->whereIn('id', $ids)->where('active', true)->get()->keyBy('id');
        return array_values(array_filter(array_map(fn ($id) => isset($rows[$id]) ? $this->publicRow($rows[$id]) : null, $ids)));
    }

    /** Save up to three picks; the classic direction picks are derived from the recipes' style fits so every existing consumer keeps working. */
    public function setPicks(int $wsId, ?int $bizId, array $ids, string $source = 'library'): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $ids = array_slice($ids, 0, self::MAX_PICKS);
        if (! $ids) return ['success' => false, 'error' => 'Pick at least one design you like.'];
        $rows = DB::table('design_recipes')->whereIn('id', $ids)->where('active', true)->get()->keyBy('id');
        $ids = array_values(array_filter($ids, fn ($id) => isset($rows[$id])));
        if (! $ids) return ['success' => false, 'error' => 'Those designs are not available.'];
        $codes = [];
        foreach ($ids as $id) foreach ((array) (json_decode((string) $rows[$id]->direction_fit, true) ?: []) as $d) { if (preg_match('/^(D\d+)/', (string) $d, $m) && ! in_array($m[1], $codes, true)) $codes[] = $m[1]; }
        $codes = array_slice($codes, 0, BrandProfileService::MAX_PICKS);
        $biz = $this->profiles->business($wsId, $bizId);
        $row = $this->profiles->row($wsId, $biz, true);
        $prev = BrandProfileService::json($row, 'directions_json');
        $this->profiles->write($row, [
            'directions_json' => json_encode(['recipes' => $ids, 'picks' => $codes ?: ($prev['picks'] ?? []), 'never' => (array) ($prev['never'] ?? []), 'chosen_at' => now()->toIso8601String(), 'source' => $source]),
            'intake_status' => 'confirmed', 'confirmed_at' => $row->confirmed_at ?? now(),
        ], $source, 'design library picks chosen');
        try { $__om = app(\App\Core\OwnerModel\OwnerModelService::class); $__om->observe($wsId, $biz->id ?? null, 'look_picked', 'recipes:' . implode(',', $ids), ['source' => $source]); $__om->upsert($wsId, $biz->id ?? null, 'preferences', 'visual_looks', 'Chose these design looks for posts: ' . implode(', ', array_map(fn ($id) => (string) $rows[$id]->title, $ids)) . '.', 'stated', 0.95, 'library_picks', 'confirmed'); } catch (\Throwable) {}   // RFC-0023 P1
        return ['success' => true, 'business_id' => $biz->id ?? null, 'recipes' => array_map(fn ($id) => $this->publicRow($rows[$id]), $ids), 'names' => array_map(fn ($id) => (string) $rows[$id]->title, $ids), 'picks' => $codes];
    }

    /** Sarah's shortlist for the intake card: the business's industry first, then close industries, spread across archetypes. */
    public function shortlist(?string $industry, int $n = 8): array
    {
        $base = DB::table('design_recipes')->where('active', true);
        $rows = $industry ? (clone $base)->where('industry', $industry)->orderBy('sort')->get()->all() : (clone $base)->inRandomOrder()->limit($n * 4)->get()->all();   // no industry: a spread across the whole library
        if (count($rows) < $n) $rows = array_merge($rows, (clone $base)->when($industry, fn ($q) => $q->where('industry', '!=', $industry))->inRandomOrder()->limit($n * 2)->get()->all());
        $out = []; $seen = [];
        foreach ([0, 1] as $pass) foreach ($rows as $r) { if (count($out) >= $n) break 2; if (in_array($r->id, array_column($out, 'id'), true)) continue; if ($pass === 0 && isset($seen[$r->archetype])) continue; $seen[$r->archetype] = 1; $out[] = $this->publicRow($r); }
        return $out;
    }
}
