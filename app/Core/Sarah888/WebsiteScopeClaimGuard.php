<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * RISK-0141 (2026-09-07, DEC-0040). In a two-site workspace Sarah told the owner a draft was "scoped to the Launch QA
 * Cakes website only" while the task and the article it produced carried no website_id at all (task 32229 / article
 * 1022, EV-0917). The execution-target capability itself is held by DEC-0029; what must never happen meanwhile is a
 * claim of website scope that the record does not back.
 *
 * Rule: if a created task carries website provenance (payload_json.website_id > 0) Sarah may name that site; if none
 * does, any scoping phrase that names a site of this workspace is rewritten to workspace level and one sentence says
 * the binding is not recorded yet. Nothing is fabricated, nothing is bound, only the words change.
 */
final class WebsiteScopeClaimGuard
{
    public const NOTE = "One note: this is saved at workspace level — it isn't attached to a specific website in the record yet. Tell me which site it belongs on before it's published.";

    public static function apply(string $reply, int $wsId, array $createdTaskIds): string
    {
        if ($reply === '' || $wsId <= 0 || empty($createdTaskIds)) return $reply;

        $names = [];
        foreach (DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->get(['id', 'name']) as $s) {
            $n = trim((string) $s->name);
            if (mb_strlen($n) >= 3) $names[(int) $s->id] = $n;
        }
        if (!$names) return $reply;

        $bound = self::boundWebsiteIds($createdTaskIds);
        $changed = false;
        foreach ($names as $id => $n) {
            if (isset($bound[$id])) continue;                       // provenance recorded → the claim is backed
            $q = preg_quote($n, '/');
            // Only SCOPING phrases count: "scoped to the X website only", "for the X website only", "on X only",
            // "specifically for X". A plain mention of the business name is not a scope claim.
            $patterns = [
                '/\b(?:scoped|limited|restricted|specifically|specific|targeted)\s+(?:to|for|at)\s+(?:the\s+)?' . $q . '(?:\s+(?:website|site))?(?:\s+only)?/iu',
                '/\b(?:for|on|to)\s+(?:the\s+)?' . $q . '(?:\s+(?:website|site))?\s+only\b/iu',
                '/\b(?:for|on|to)\s+(?:the\s+)?' . $q . '\s+(?:website|site)\b/iu',
            ];
            foreach ($patterns as $p) {
                $new = preg_replace($p, 'for your workspace (not yet attached to ' . $n . ')', $reply);
                if ($new !== null && $new !== $reply) { $reply = $new; $changed = true; }
            }
        }
        if ($changed && stripos($reply, self::NOTE) === false) {
            $reply = rtrim($reply) . "\n\n" . self::NOTE;
        }
        return $reply;
    }

    /** Website ids the created tasks carry as provenance (payload_json.website_id > 0). */
    private static function boundWebsiteIds(array $ids): array
    {
        $out = [];
        foreach (DB::table('tasks')->whereIn('id', $ids)->get(['id', 'payload_json']) as $t) {
            $p = is_string($t->payload_json) ? (json_decode($t->payload_json, true) ?: []) : (array) $t->payload_json;
            $w = (int) ($p['website_id'] ?? 0);
            if ($w > 0) $out[$w] = true;
        }
        return $out;
    }
}
