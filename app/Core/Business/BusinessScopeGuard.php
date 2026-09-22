<?php

namespace App\Core\Business;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Post-generation guard (RFC-0011 U3): in a single-business turn (named / sticky / default) inside a multi-business
 * workspace, the reply must not carry another business's name or domain. Offending SENTENCES are struck (never the
 * whole reply); a reply that loses everything becomes the clarifying question. Portfolio turns are untouched.
 */
class BusinessScopeGuard
{
    /** @return array{reply: string, struck: string[]} */
    public static function apply(string $reply, array $ctx, int $wsId): array
    {
        if (empty($ctx['multi']) || ! in_array($ctx['mode'] ?? '', ['named', 'sticky', 'default'], true) || empty($ctx['business'])) { return ['reply' => $reply, 'struck' => []]; }
        $active = $ctx['business'];
        $others = [];
        foreach ($ctx['businesses'] ?? [] as $b) {
            if ((int) $b->id === (int) $active->id) { continue; }
            $handles = [trim((string) $b->name)];
            foreach ((array) ($b->aliases_json ?? []) as $a) { if (trim((string) $a) !== '') { $handles[] = trim((string) $a); } }
            foreach (DB::table('websites')->where('workspace_id', $wsId)->where('business_id', $b->id)->whereNull('deleted_at')->get(['subdomain', 'custom_domain', 'domain']) as $w) {
                foreach (['custom_domain', 'domain', 'subdomain'] as $c) { $v = strtolower(trim((string) ($w->$c ?? ''))); if ($v !== '') { $handles[] = preg_replace('#^https?://#', '', $v); } }
            }
            $others[(string) $b->name] = array_values(array_unique(array_filter($handles, fn ($h) => mb_strlen($h) >= 4)));
        }
        if (! $others) { return ['reply' => $reply, 'struck' => []]; }

        $struck = []; $kept = [];
        foreach (preg_split('/(?<=[.!?])\s+|\n+/u', $reply) as $sentence) {
            $hit = null;
            foreach ($others as $name => $handles) {
                foreach ($handles as $h) {
                    if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($h, '/') . '(?![\p{L}\p{N}])/iu', $sentence)) { $hit = $name; break 2; }
                }
            }
            if ($hit !== null) { $struck[] = $hit . ': ' . mb_substr(trim($sentence), 0, 120); } else { $kept[] = $sentence; }
        }
        if (! $struck) { return ['reply' => $reply, 'struck' => []]; }

        Log::warning('[Business] scope guard struck ' . count($struck) . ' sentence(s)', ['ws' => $wsId, 'active' => $active->name, 'mode' => $ctx['mode'], 'struck' => array_slice($struck, 0, 5)]);
        $out = trim(implode(' ', array_filter(array_map('trim', $kept))));
        if ($out === '') { $out = BusinessContext::askWhich($ctx['businesses'] ?? [], $active); }
        return ['reply' => $out, 'struck' => $struck];
    }
}
