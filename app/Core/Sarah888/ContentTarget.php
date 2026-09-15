<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * EV-1045 (2026-09-16) — which website does content work belong to?
 *
 * The Owner's own thread on a seven-site workspace: "Let's write 5 articles" ran with no website at all, and
 * "write for chef red" still attached nothing because the matcher wanted the full site name ("Chef Red Raymundo")
 * inside the message. Publishing then refused the drafts as "not attached to a website" and asked again.
 *
 * Resolution order, all deterministic, never a guess:
 *   1. a site the owner NAMED in this message (full name, its first two words, or its subdomain label);
 *   2. the only site, when the workspace has one;
 *   3. the site the app has open (site_url / X-Lgse-Active-Site), when it belongs to this workspace;
 *   4. otherwise null — the caller must ASK before creating content work.
 */
class ContentTarget
{
    public const CONTENT_ACTIONS = ['write_article', 'generate_meta', 'generate_image_mini', 'generate_image', 'aeo_enrich', 'link_suggestions', 'insert_link', 'publish_article', 'fix_orphans'];

    /** @return array<int, array{id:int,name:string,subdomain:?string,domain:?string}> */
    public static function sites(int $wsId): array
    {
        return DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'name', 'subdomain', 'domain'])->map(fn ($w) => ['id' => (int) $w->id, 'name' => (string) $w->name, 'subdomain' => $w->subdomain, 'domain' => $w->domain])->all();
    }

    /** Sites the owner named in the text. Full name, first two words (≥ 6 chars together), or the subdomain label. */
    public static function namedIn(string $text, array $sites): array
    {
        $t = ' ' . preg_replace('/\s+/', ' ', mb_strtolower(trim($text))) . ' ';
        $t = str_replace(["'", '’'], '', $t);
        $out = [];
        foreach ($sites as $s) {
            $name = mb_strtolower(trim((string) ($s['name'] ?? '')));
            $name = str_replace(["'", '’'], '', $name);
            $cands = [];
            if (mb_strlen($name) >= 3) $cands[] = $name;
            $words = preg_split('/\s+/', $name) ?: [];
            if (count($words) >= 3) { $two = $words[0] . ' ' . $words[1]; if (mb_strlen($two) >= 6 && mb_strlen($words[0]) >= 3) $cands[] = $two; }
            foreach (['subdomain', 'domain'] as $k) {
                $h = mb_strtolower(trim((string) ($s[$k] ?? '')));
                if ($h === '') continue;
                $label = explode('.', $h)[0];
                if (mb_strlen($label) >= 5) { $cands[] = str_replace('-', ' ', $label); $cands[] = $label; }
            }
            foreach (array_unique($cands) as $c) {
                if (preg_match('/(^|[^a-z0-9])' . preg_quote($c, '/') . '([^a-z0-9]|$)/u', $t)) { $out[$s['id']] = ['site' => $s, 'len' => mb_strlen($c), 'full' => $c === $name]; break; }
            }
        }
        if ($out === []) return [];
        // "boss mac gym" names ONE of the three Boss Mac sites: a full-name match beats a prefix match, and the
        // longest match beats a shorter one, so sibling sites that share a prefix do not all light up.
        $full = array_filter($out, fn ($m) => $m['full']);
        if ($full) $out = $full;
        $max = max(array_map(fn ($m) => $m['len'], $out));
        $out = array_filter($out, fn ($m) => $m['len'] === $max);
        return array_values(array_map(fn ($m) => $m['site'], $out));
    }

    /** @return array{site:?array, sites:array, named:array, reason:string} */
    public static function resolve(int $wsId, string $message, string $uiSiteUrl = ''): array
    {
        $sites = self::sites($wsId);
        $named = self::namedIn($message, $sites);
        if (count($named) === 1) return ['site' => $named[0], 'sites' => $sites, 'named' => $named, 'reason' => 'named'];
        if (count($sites) === 1) return ['site' => $sites[0], 'sites' => $sites, 'named' => $named, 'reason' => 'only_site'];
        if ($uiSiteUrl !== '') {
            try {
                $id = (int) \App\Core\Tenancy\WebsiteScope::websiteForUrl($wsId, $uiSiteUrl);
                foreach ($sites as $s) { if ((int) $s['id'] === $id) return ['site' => $s, 'sites' => $sites, 'named' => $named, 'reason' => 'ui_site']; }
            } catch (\Throwable) {}
        }
        return ['site' => null, 'sites' => $sites, 'named' => $named, 'reason' => count($named) > 1 ? 'ambiguous' : 'none'];
    }

    /** The question Sarah asks instead of writing for nobody. */
    public static function askWhich(array $sites, array $named = []): string
    {
        $names = array_map(fn ($s) => (string) $s['name'], count($named) > 1 ? $named : $sites);
        $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' or ' . end($names) : (string) ($names[0] ?? 'your website');
        return "Before I write anything: which website should this go on? You have " . count($names) . " — " . $list . ". Tell me the name and I will start.";
    }
}
