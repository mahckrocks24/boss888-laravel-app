<?php

namespace App\Core\Business;

use App\Models\Business;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The one place a business profile is read (RFC-0011 U1).
 *
 * profile(wsId, businessId|null) merges, in this order: the business row → its `biz:{id}:*` memory facts → the
 * workspace's portfolio memory facts → the workspaces.* mirror (last fallback). With the kill-switch off, or for a
 * workspace that has no businesses yet, the answer is the default business = the workspaces.* values = today.
 * Request-scoped cache; at most two queries per workspace per request.
 */
class BusinessProfileResolver
{
    private array $cache = [];

    public static function enabledFor(int $wsId): bool
    {
        if (config('business.profiles')) { return true; }
        return in_array($wsId, (array) config('business.qa_workspaces', []), true);
    }

    /** All businesses of a workspace, default first, then sort_order, then id. */
    public function forWorkspace(int $wsId): array
    {
        if (isset($this->cache['ws'][$wsId])) { return $this->cache['ws'][$wsId]; }
        if (! Schema::hasTable('businesses')) { return $this->cache['ws'][$wsId] = []; }
        $rows = Business::where('workspace_id', $wsId)->orderByDesc('is_default')->orderBy('sort_order')->orderBy('id')->get()->all();
        return $this->cache['ws'][$wsId] = $rows;
    }

    public function default(int $wsId): ?Business
    {
        foreach ($this->forWorkspace($wsId) as $b) { if ($b->is_default) { return $b; } }
        return $this->forWorkspace($wsId)[0] ?? null;
    }

    public function find(int $wsId, ?int $businessId): ?Business
    {
        if (! $businessId) { return $this->default($wsId); }
        foreach ($this->forWorkspace($wsId) as $b) { if ((int) $b->id === (int) $businessId) { return $b; } }
        return $this->default($wsId);
    }

    /** The business a website belongs to (its business_id, else the workspace default). */
    public function forWebsite(int $websiteId): ?Business
    {
        if (! Schema::hasTable('businesses')) { return null; }
        $w = DB::table('websites')->where('id', $websiteId)->first(['workspace_id', 'business_id']);
        if (! $w) { return null; }
        return $this->find((int) $w->workspace_id, (int) ($w->business_id ?? 0) ?: null);
    }

    /** True when the workspace holds more than one business and the feature is on for it. */
    public function isMulti(int $wsId): bool
    {
        return self::enabledFor($wsId) && count($this->forWorkspace($wsId)) > 1;
    }

    /**
     * The merged profile as an array: name, industry, services (array), goal, location, brand_color, logo_url, tone,
     * target_audience, differentiators, pricing_anchor, domain, business_id, is_default, source.
     */
    public function profile(int $wsId, ?int $businessId = null): array
    {
        $enabled = self::enabledFor($wsId);
        $b = $enabled ? $this->find($wsId, $businessId) : $this->default($wsId);
        $ws = $this->workspaceRow($wsId);
        $portfolio = $this->memoryFacts($wsId, null);
        $own = $b && $enabled ? $this->memoryFacts($wsId, (int) $b->id) : [];

        $out = [
            'business_id' => $b?->id, 'is_default' => (bool) ($b?->is_default ?? true), 'source' => $b ? ($enabled ? 'business' : 'default-mirror') : 'workspace',
            'name' => $this->first($own['business_name'] ?? null, $portfolio['business_name'] ?? null, $b?->name, $ws->business_name ?? null, $ws->name ?? null),
            'industry' => $this->first($own['industry'] ?? null, $portfolio['industry'] ?? null, $b?->industry, $ws->industry ?? null),
            'services' => $this->services($own['services'] ?? null, $portfolio['services'] ?? null, $b?->services_json, $ws->services_json ?? null),
            'goal' => $this->first($b?->goal, $ws->goal ?? null),
            'location' => $this->first($own['location'] ?? null, $portfolio['location'] ?? null, $b?->location, $ws->location ?? null),
            'brand_color' => $this->first($b?->brand_color, $ws->brand_color ?? null),
            'logo_url' => $this->first($b?->logo_url, $ws->logo_url ?? null),
            'tone' => $this->first($own['tone'] ?? null, $portfolio['tone'] ?? null, $b?->tone),
            'target_audience' => $this->first($own['target_audience'] ?? null, $portfolio['target_audience'] ?? null, $b?->target_audience),
            'differentiators' => $this->first($own['differentiators'] ?? null, $portfolio['differentiators'] ?? null, $b?->differentiators),
            'pricing_anchor' => $this->first($own['pricing_anchor'] ?? null, $portfolio['pricing_anchor'] ?? null, $b?->pricing_anchor),
            'domain' => $this->first($own['domain'] ?? null, $portfolio['domain'] ?? null),
        ];
        // With several businesses ON, a non-default business must not inherit the default's memory facts by accident:
        // portfolio facts are the DEFAULT business's facts while N = 1; for another business only its own facts count.
        if ($enabled && $b && ! $b->is_default) {
            foreach (['tone', 'target_audience', 'differentiators', 'pricing_anchor', 'domain'] as $k) { $out[$k] = $this->first($own[$k] ?? null, $b->{$k} ?? null); }
            $out['name'] = $this->first($own['business_name'] ?? null, $b->name);
            $out['industry'] = $this->first($own['industry'] ?? null, $b->industry);
            $out['services'] = $this->services($own['services'] ?? null, $b->services_json);
            $out['location'] = $this->first($own['location'] ?? null, $b->location);
            $out['goal'] = $b->goal; $out['brand_color'] = $b->brand_color; $out['logo_url'] = $b->logo_url;
        }
        return $out;
    }

    /**
     * The Workspace model as ONE business sees it: the profile columns (business_name, industry, services_json, goal,
     * location, brand_color, logo_url) carry that business's row. Switch off, or the default business → the columns
     * as stored (identical to Workspace::find). The instance is a VIEW: saving it is refused (Workspace::booted).
     */
    public function workspaceFor(int $wsId, ?int $businessId = null): ?\App\Models\Workspace
    {
        $ws = \App\Models\Workspace::find($wsId);
        if (! $ws) { return null; }
        $b = $this->overlayBusiness($wsId, $businessId);
        if (! $b) { return $ws; }
        $ws->setRawAttributes(array_merge($ws->getAttributes(), $this->overlayColumns($b)), true);
        $ws->isBusinessView = true;
        return $ws;
    }

    /** The same view for a website: its business, else the workspace default. */
    public function workspaceForWebsite(int $websiteId): ?\App\Models\Workspace
    {
        $w = DB::table('websites')->where('id', $websiteId)->first(['workspace_id', 'business_id']);
        return $w ? $this->workspaceFor((int) $w->workspace_id, (int) ($w->business_id ?? 0) ?: null) : null;
    }

    /** The `workspaces` row (stdClass, as DB::table readers use it) with the business's profile columns overlaid. */
    public function workspaceRowFor(int $wsId, ?int $businessId = null, array $columns = ['*']): ?object
    {
        $row = DB::table('workspaces')->where('id', $wsId)->first($columns);
        if (! $row) { return null; }
        $b = $this->overlayBusiness($wsId, $businessId);
        if (! $b) { return $row; }
        foreach ($this->overlayColumns($b) as $col => $val) { if ($columns === ['*'] || in_array($col, $columns, true) || property_exists($row, $col)) { $row->$col = $val; } }
        return $row;
    }

    public function workspaceRowForWebsite(int $websiteId, array $columns = ['*']): ?object
    {
        $w = DB::table('websites')->where('id', $websiteId)->first(['workspace_id', 'business_id']);
        return $w ? $this->workspaceRowFor((int) $w->workspace_id, (int) ($w->business_id ?? 0) ?: null, $columns) : null;
    }

    /** The business whose columns overlay the workspace — only a NON-default business with the switch on; otherwise none (= the columns). */
    private function overlayBusiness(int $wsId, ?int $businessId): ?Business
    {
        if (! self::enabledFor($wsId) || ! $businessId) { return null; }
        $b = $this->find($wsId, $businessId);
        return ($b && ! $b->is_default) ? $b : null;
    }

    private function overlayColumns(Business $b): array
    {
        return [
            'business_name' => $b->name, 'industry' => $b->industry,
            'services_json' => is_array($b->services_json) ? json_encode($b->services_json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : $b->services_json,
            'goal' => $b->goal, 'location' => $b->location, 'brand_color' => $b->brand_color, 'logo_url' => $b->logo_url,
        ];
    }

    public function forget(int $wsId): void { unset($this->cache['ws'][$wsId], $this->cache['row'][$wsId], $this->cache['mem'][$wsId]); }

    private function workspaceRow(int $wsId): ?object
    {
        return $this->cache['row'][$wsId] ??= DB::table('workspaces')->where('id', $wsId)->first(['id', 'name', 'business_name', 'industry', 'services_json', 'goal', 'location', 'brand_color', 'logo_url']);
    }

    /** Memory facts for the portfolio (businessId null → unprefixed keys) or one business (biz:{id}:key). */
    private function memoryFacts(int $wsId, ?int $businessId): array
    {
        $k = $businessId ?? 0;
        if (isset($this->cache['mem'][$wsId][$k])) { return $this->cache['mem'][$wsId][$k]; }
        $prefix = $businessId ? config('business.memory_prefix', 'biz:') . $businessId . ':' : '';
        $keys = array_map(fn ($f) => $prefix . $f, Business::MEMORY_FACTS);
        $rows = DB::table('workspace_memory')->where('workspace_id', $wsId)->whereIn('key', $keys)->get(['key', 'value_json']);
        $out = [];
        foreach ($rows as $r) {
            $key = $prefix ? substr($r->key, strlen($prefix)) : $r->key;
            $out[$key] = BusinessMemory::unwrap($r->value_json);
        }
        return $this->cache['mem'][$wsId][$k] = $out;
    }

    private function first(...$vals)
    {
        foreach ($vals as $v) { if (is_array($v)) { if ($v) { return $v; } continue; } if ($v !== null && trim((string) $v) !== '') { return is_string($v) ? trim($v) : $v; } }
        return null;
    }

    private function services(...$vals): array
    {
        foreach ($vals as $v) {
            if (is_array($v) && $v) { return array_values($v); }
            if (is_string($v) && trim($v) !== '') {
                $d = json_decode($v, true);
                if (is_array($d) && $d) { return array_values($d); }
                return array_values(array_filter(array_map('trim', preg_split('/[,;]\s*/', $v))));
            }
        }
        return [];
    }
}
