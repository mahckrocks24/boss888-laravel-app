<?php

namespace App\Core\Business;

use App\Core\Memory\WorkspaceMemoryService;
use App\Models\Business;
use Illuminate\Support\Facades\DB;

/**
 * Which BUSINESS a Sarah turn is about (RFC-0011 U3). Resolved BEFORE the prompt is composed.
 *
 *  single     the workspace has one business (or the feature is off for it): never ask, never mention the machinery
 *  named      the message names a business, one of its aliases, or one of its websites
 *  sticky     nothing named, but the thread has an active business (set on a named turn; TTL config)
 *  portfolio  "all my businesses", "across …", comparisons — answer grouped
 *  ambiguous  several businesses, nothing named, no sticky, and the message is about the owner's business — ONE question
 *  default    several businesses, nothing named, no sticky, but the message is not about the business at all — the default
 *
 * The SEO-selected site is a hint only (it never answers the question by itself).
 */
class BusinessContext
{
    public const STICKY_KEY = 'sarah:active_business';

    private const PORTFOLIO = '/\b(all (of )?(my|our|the) (businesses|companies|brands|ventures)|across (all|the|my|our) (businesses|brands|companies)|(every|each) (business|brand|company)|compare (my|the|our|all) (businesses|brands|companies)|both (of my )?businesses|all of them|the whole portfolio|my portfolio)\b/i';
    private const REFERENTIAL = '/\b(my|our)\b|\b(the business|prices?|pricing|rates?|customers?|clients?|leads?|enquir|the website|the site|the blog|the articles?|the services?|the brand|the campaign|the posts?|rankings?|traffic|how (is|are) (it|things|we|everything)|status|report|revenue|bookings?|sales|the calendar|appointments?)\b/i';

    public function __construct(private BusinessProfileResolver $resolver, private WorkspaceMemoryService $memory) {}

    public function resolve(int $wsId, string $message, ?string $uiSiteUrl = null): array
    {
        $all = $this->resolver->forWorkspace($wsId);
        $default = $this->resolver->default($wsId);
        $base = ['multi' => false, 'mode' => 'single', 'business' => $default, 'business_id' => $default?->id, 'businesses' => $all, 'named' => [], 'source' => 'single', 'ask' => null, 'hint' => null];
        if (! $this->resolver->isMulti($wsId)) { return $base; }

        $base['multi'] = true;
        $msg = ' ' . preg_replace('/\s+/', ' ', mb_strtolower($message)) . ' ';
        $sites = $this->sitesByBusiness($wsId);

        // 1. named — business name, aliases, website names/hosts
        $named = [];
        foreach ($all as $b) {
            foreach ($this->handlesFor($b, $sites[$b->id] ?? []) as $h) {
                if (mb_strlen($h) >= 4 && preg_match('/(?<![\p{L}\p{N}])' . preg_quote($h, '/') . '(?![\p{L}\p{N}])/u', $msg)) { $named[$b->id] = $b; break; }
            }
        }
        $named = array_values($named);

        // 2. portfolio
        if (preg_match(self::PORTFOLIO, $message) || count($named) > 1) {
            $this->clearSticky($wsId);
            return array_merge($base, ['mode' => 'portfolio', 'business' => null, 'business_id' => null, 'named' => $named, 'source' => count($named) > 1 ? 'several named' : 'portfolio words']);
        }
        if (count($named) === 1) {
            $this->setSticky($wsId, (int) $named[0]->id);
            return array_merge($base, ['mode' => 'named', 'business' => $named[0], 'business_id' => $named[0]->id, 'named' => $named, 'source' => 'named']);
        }

        // 3. sticky
        $sticky = $this->sticky($wsId, $all);
        if ($sticky) { return array_merge($base, ['mode' => 'sticky', 'business' => $sticky, 'business_id' => $sticky->id, 'source' => 'sticky']); }

        // 4. the SEO-selected site is a hint, never an answer
        $hint = null;
        if ($uiSiteUrl) { $host = strtolower((string) parse_url(str_starts_with($uiSiteUrl, 'http') ? $uiSiteUrl : 'https://' . $uiSiteUrl, PHP_URL_HOST)); foreach ($sites as $bid => $list) { foreach ($list as $s) { if ($host && in_array($host, $s['hosts'], true)) { $hint = $bid; } } } }

        // 5. about the business, nobody named → ask; otherwise the default, silently
        if (preg_match(self::REFERENTIAL, $message)) {
            return array_merge($base, ['mode' => 'ambiguous', 'business' => null, 'business_id' => null, 'source' => 'referential, nothing named', 'ask' => self::askWhich($all, $hint ? $this->resolver->find($wsId, $hint) : null), 'hint' => $hint]);
        }
        return array_merge($base, ['mode' => 'default', 'source' => 'not about the business', 'hint' => $hint]);
    }

    /** The question Sarah asks instead of guessing. */
    public static function askWhich(array $businesses, ?Business $hint = null): string
    {
        $names = array_values(array_map(fn ($b) => (string) $b->name, $businesses));
        $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' or ' . end($names) : (string) ($names[0] ?? 'your business');
        $h = $hint ? " If you mean {$hint->name}, just say so." : '';
        return "Quick check before I answer — which business do you mean: {$list}? Say the name, or \"all of them\" and I'll cover each.{$h}";
    }

    /** The prompt block for a multi-business workspace: PORTFOLIO roster, ACTIVE business profile, HARD RULE. */
    public static function promptBlock(array $ctx, BusinessProfileResolver $resolver, int $wsId): string
    {
        if (empty($ctx['multi'])) { return ''; }
        $all = $ctx['businesses'] ?? [];
        $out = "- YOUR OWNER'S BUSINESSES (" . count($all) . " — this owner runs SEVERAL businesses in this workspace; keep them apart):\n";
        foreach (array_slice($all, 0, 12) as $b) {
            $p = $resolver->profile($wsId, (int) $b->id);
            $out .= '    • ' . $b->name . ($p['industry'] ? ' — ' . $p['industry'] : '') . ($p['location'] ? ' (' . $p['location'] . ')' : '') . ($b->is_default ? ' [default]' : '') . "\n";
        }
        if (count($all) > 12) { $out .= '    • … and ' . (count($all) - 12) . " more\n"; }
        $mode = $ctx['mode'] ?? 'single';
        if (in_array($mode, ['named', 'sticky', 'default'], true) && ! empty($ctx['business'])) {
            $b = $ctx['business']; $p = $resolver->profile($wsId, (int) $b->id);
            $out .= "- ACTIVE BUSINESS FOR THIS TURN: {$b->name}" . ($mode === 'sticky' ? ' (the business this conversation has been about; say its name in your first sentence so a wrong assumption is easy to correct)' : ($mode === 'default' ? ' (the default — the message did not concern a business)' : ' (the owner named it)')) . "\n";
            foreach (['industry' => 'Industry', 'location' => 'Location', 'tone' => 'Tone', 'target_audience' => 'Target audience', 'differentiators' => 'Differentiators'] as $k => $label) { if (! empty($p[$k])) { $out .= "    {$label}: " . (is_array($p[$k]) ? implode(', ', $p[$k]) : $p[$k]) . "\n"; } }
            // The model read a bare "Pricing:" label as a price list it did not have (one probe of two): say what the line is.
            if (! empty($p['pricing_anchor'])) { $out .= "    PRICES {$b->name} CHARGES (ground truth — when the owner asks about prices, rates, fees or cost, answer from this line; never say you have no record of them): " . $p['pricing_anchor'] . "\n"; }
            if (! empty($p['services'])) { $out .= '    Services: ' . implode(', ', array_slice((array) $p['services'], 0, 12)) . "\n"; }
            $out .= "  HARD RULE — ONE BUSINESS: answer for {$b->name} only. Never carry another business's name, domain, services, prices, tone or audience into this answer unless the owner names it.\n";
        } elseif ($mode === 'portfolio') {
            $out .= "- THIS TURN COVERS ALL BUSINESSES: answer grouped by business name, one short section each, never blending one business's facts into another's.\n";
            foreach (array_slice($all, 0, 8) as $b) { $p = $resolver->profile($wsId, (int) $b->id); $out .= '    ' . $b->name . ': ' . implode(' · ', array_filter([$p['industry'] ?? null, $p['location'] ?? null, ! empty($p['services']) ? implode(', ', array_slice((array) $p['services'], 0, 6)) : null, $p['pricing_anchor'] ?? null])) . "\n"; }
        }
        $out .= "  HARD RULE — WHICH BUSINESS: when a message says 'my business', 'my prices', 'my customers' or the like WITHOUT naming one of these businesses and none is active, do NOT guess — ask ONE short question naming the businesses. When a business IS named, use it and say its name back in your first sentence.\n";
        return $out;
    }

    private function handlesFor(Business $b, array $sites): array
    {
        $h = [mb_strtolower(trim((string) $b->name))];
        // the distinctive part of the name: its words minus those another business in the workspace also uses
        $others = [];
        foreach ($this->resolver->forWorkspace((int) $b->workspace_id) as $o) { if ((int) $o->id !== (int) $b->id) { foreach (preg_split('/\s+/', mb_strtolower(trim((string) $o->name))) as $w) { $others[$w] = true; } } }
        $own = array_values(array_filter(preg_split('/\s+/', mb_strtolower(trim((string) $b->name))), fn ($w) => $w !== '' && empty($others[$w])));
        if ($own && count($own) < count(preg_split('/\s+/', trim((string) $b->name)))) { $h[] = implode(' ', $own); }
        foreach ((array) ($b->aliases_json ?? []) as $a) { $a = mb_strtolower(trim((string) $a)); if ($a !== '') { $h[] = $a; } }
        foreach ($sites as $s) { $h[] = mb_strtolower($s['name']); foreach ($s['hosts'] as $host) { $h[] = $host; $h[] = preg_replace('/\.levelupgrowth\.io$/', '', $host); } }
        return array_values(array_unique(array_filter($h)));
    }

    private function sitesByBusiness(int $wsId): array
    {
        $out = [];
        foreach (DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['id', 'name', 'subdomain', 'custom_domain', 'domain', 'business_id']) as $w) {
            $hosts = [];
            foreach (['custom_domain', 'domain', 'subdomain'] as $c) { $v = strtolower(trim((string) ($w->$c ?? ''))); if ($v !== '') { $hosts[] = preg_replace('#^https?://#', '', $v); } }
            $out[(int) ($w->business_id ?? 0)][] = ['id' => $w->id, 'name' => trim((string) $w->name), 'hosts' => array_values(array_unique($hosts))];
        }
        return $out;
    }

    private function sticky(int $wsId, array $all): ?Business
    {
        $v = BusinessMemory::unwrap($this->memory->get($wsId, self::STICKY_KEY));
        if (! is_array($v) || empty($v['id'])) { return null; }
        $ttl = (int) config('business.sticky_ttl_seconds', 21600);
        if (! empty($v['at']) && (time() - (int) $v['at']) > $ttl) { return null; }
        foreach ($all as $b) { if ((int) $b->id === (int) $v['id']) { return $b; } }
        return null;
    }

    public function setSticky(int $wsId, int $businessId): void
    {
        $this->memory->set($wsId, self::STICKY_KEY, ['id' => $businessId, 'at' => time()], (int) config('business.sticky_ttl_seconds', 21600));
    }

    public function clearSticky(int $wsId): void
    {
        $this->memory->forget($wsId, self::STICKY_KEY);
    }
}
