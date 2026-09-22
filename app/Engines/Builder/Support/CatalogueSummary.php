<?php

namespace App\Engines\Builder\Support;

use App\Engines\Builder\Services\CatalogueService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * CAT-2 (Owner 2026-09-22): the catalogue as a section of the app, on Basic and Advanced, named by industry.
 * THE RULE (Owner): one sidebar entry per industry WORD — two realty companies share one "Properties" entry with a
 * dropdown inside to pick which company; a realty and a travel company get "Properties" and "Packages", each its own.
 * One call gives the shell everything: each website's catalogue kinds (label, singular, count, enabled) and the
 * GROUPS (word → the websites that carry it). The vocabulary is CatalogueKinds'.
 */
class CatalogueSummary
{
    public static function forWorkspace(int $wsId): array
    {
        $sites = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->where('status', 'published') // RFC-0011 (Owner rule): drafts are not visible in the engines
            ->orderBy('created_at')->orderBy('id')->get(); // the full row: specs() reads settings_json/template from what it is handed
        if ($sites->isEmpty()) { return ['has_catalogue' => false, 'groups' => [], 'websites' => []]; }

        $counts = [];
        foreach (DB::table('catalogue_items')->where('workspace_id', $wsId)->whereNull('deleted_at')->whereIn('website_id', $sites->pluck('id'))
            ->selectRaw('website_id, kind, COUNT(*) c')->groupBy('website_id', 'kind')->get() as $row) {
            $counts[(int) $row->website_id][(string) $row->kind] = (int) $row->c;
        }
        $legacy = DB::table('property_listings')->where('workspace_id', $wsId)->whereNull('deleted_at')->whereIn('website_id', $sites->pluck('id'))
            ->selectRaw('website_id, COUNT(*) c')->groupBy('website_id')->pluck('c', 'website_id');

        $svc = app(CatalogueService::class);
        $out = [];
        foreach ($sites as $s) {
            $kinds = [];
            try { $specs = $svc->specs((int) $s->id, $s); } catch (\Throwable $e) { $specs = []; }
            foreach ($specs as $kind => $spec) {
                if (! is_array($spec)) { continue; }
                $k = (string) ($spec['kind'] ?? $kind);
                $label = trim((string) ($spec['label'] ?? $spec['plural'] ?? ucfirst($k)));
                $count = (int) ($counts[(int) $s->id][$k] ?? 0) + ($k === 'listing' ? (int) ($legacy[(int) $s->id] ?? 0) : 0);
                $enabled = array_key_exists('enabled', $spec) ? (bool) $spec['enabled'] : true;
                $kinds[] = ['kind' => $k, 'label' => $label, 'singular' => (string) ($spec['singular'] ?? $k), 'count' => $count, 'enabled' => $enabled, 'page_slug' => (string) ($spec['page_slug'] ?? $k)];
            }
            if (! $kinds) { continue; }
            $host = '';
            foreach (['custom_domain', 'domain', 'subdomain'] as $c) { $v = trim((string) ($s->$c ?? '')); if ($v !== '') { $host = preg_replace('#^https?://#', '', $v); break; } }
            $out[] = ['id' => (int) $s->id, 'name' => (string) $s->name, 'host' => $host, 'industry' => (string) ($s->template_industry ?? ''), 'status' => (string) ($s->status ?? ''), 'business_id' => $s->business_id ? (int) $s->business_id : null, 'kinds' => $kinds];
        }
        return ['has_catalogue' => $out !== [], 'groups' => self::groups($out), 'websites' => $out];
    }

    /**
     * The Owner's rule (2026-09-22, corrected): ONE sidebar entry per INDUSTRY, named by that industry's primary catalogue
     * word (Real Estate -> Properties, Travel -> Packages). Companies in the same industry share the entry (a picker inside
     * chooses the company); a site's other catalogue kinds are tabs inside the entry, never extra menu lines. Grouping by
     * kind-word made one gym spawn Programmes + Memberships + Timetable and four sites became seven lines.
     * Each group: slug (URL tail = the industry), label (sidebar word), kind (the primary kind), industry, website ids.
     */
    public static function groups(array $websites): array
    {
        $groups = [];
        foreach ($websites as $w) {
            $kinds = $w['kinds'] ?? []; if (! $kinds) { continue; }
            $primary = $kinds[0];
            $label = trim((string) ($primary['label'] ?? '')); if ($label === '') { continue; }
            $industry = trim((string) ($w['industry'] ?? ''));
            $slug = ($industry !== '' ? Str::slug($industry) : Str::slug($label)) ?: (string) $primary['kind'];
            $groups[$slug] ??= ['slug' => $slug, 'label' => $label, 'kind' => (string) $primary['kind'], 'industry' => $industry, 'websites' => []];
            if (! in_array((int) $w['id'], $groups[$slug]['websites'], true)) { $groups[$slug]['websites'][] = (int) $w['id']; }
        }
        return array_values($groups);
    }
}
