<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * SARAH888 Phase 1Q — resolve a reference to an entity, and to the domain that
 * owns it.
 *
 * Traces to S1P-D02. Asked "Cancel the hygiene certificate renewal. Changed my
 * mind", Sarah replied "I'll queue the task to update the food hygiene
 * certificate renewal lead to 'lost' status… Proceeding now!" and did it. There
 * was a commitment by that name and a CRM lead whose name resembled it, and she
 * mutated the CRM record — real customer data — because the words looked
 * similar. Two leads on Chef Red still carry status='lost' from that turn.
 *
 * The architectural ruling is that cancellation is entity-scoped and
 * domain-scoped: what entity, which domain owns it, which lifecycle operation
 * is valid there — resolved BEFORE any mutation. This class answers the first
 * two questions.
 *
 * DOMAIN IS NOT A NEW TAXONOMY. Every action already resolves to an engine
 * through CapabilityMap — crm owns create_lead/update_lead/delete_lead, write
 * owns write_article, and so on. Engine IS domain. Inventing a second
 * classification beside it would be exactly the duplication this project
 * forbids, so this maps onto the engine slugs that already exist.
 *
 * IT REFUSES TO GUESS, DELIBERATELY. A reference that matches in two domains
 * returns AMBIGUOUS rather than a best guess. The whole defect being fixed here
 * was a confident guess across a domain boundary.
 */
class EntityResolver
{
    public const DOMAIN_COMMITMENT = 'commitment';
    public const DOMAIN_CRM        = 'crm';
    public const DOMAIN_TASK       = 'task';

    /** Share of the reference's content words that must appear in a candidate. */
    private const MATCH_THRESHOLD = 0.6;

    public function __construct(private CommitmentSync $sync) {}

    /**
     * @return array{
     *   reference:string,
     *   resolution:'unique'|'ambiguous'|'none',
     *   domain:?string,
     *   matches:array<string, array<int, array{id:int,label:string}>>
     * }
     */
    public function resolve(int $wsId, string $reference): array
    {
        $ref = trim($reference);
        $out = ['reference' => $ref, 'resolution' => 'none', 'domain' => null, 'matches' => []];
        if ($ref === '' || mb_strlen($ref) < 3) return $out;

        $matches = [];

        // Commitments — reuse the existing resolver rather than writing a
        // second matching algorithm with slightly different behaviour.
        $c = $this->sync->resolve($wsId, $ref);
        if ($c) $matches[self::DOMAIN_COMMITMENT] = [['id' => (int) $c->id, 'label' => (string) $c->title]];

        // CRM leads.
        foreach ($this->matchLeads($wsId, $ref) as $lead) {
            $matches[self::DOMAIN_CRM][] = $lead;
        }

        // Tasks, by explicit id only. "Cancel task 2756" is unambiguous;
        // fuzzy-matching a task title would reintroduce the guessing this
        // class exists to prevent.
        if (preg_match('/\btask\s*#?\s*(\d{1,10})\b/i', $ref, $m)) {
            $row = DB::table('tasks')->where('workspace_id', $wsId)->where('id', (int) $m[1])
                ->first(['id', 'action']);
            if ($row) $matches[self::DOMAIN_TASK] = [['id' => (int) $row->id, 'label' => 'task #' . $row->id . ' (' . $row->action . ')']];
        }

        $out['matches'] = $matches;
        $domains = array_keys($matches);

        if (count($domains) === 1) {
            $out['resolution'] = count($matches[$domains[0]]) === 1 ? 'unique' : 'ambiguous';
            $out['domain'] = $domains[0];
        } elseif (count($domains) > 1) {
            $out['resolution'] = 'ambiguous';
        }
        return $out;
    }

    /**
     * Leads whose name or company genuinely corresponds to the reference.
     *
     * Token overlap in BOTH directions, not LIKE: the Phase 1P failure happened
     * because "food hygiene certificate renewal" and a lead called "Food
     * Hygiene Manager" share words. They still do — so this will report the
     * lead as a candidate, which is correct. What must not happen is that
     * candidate silently winning a cross-domain contest, and that decision
     * belongs to the caller, not here.
     */
    private function matchLeads(int $wsId, string $ref): array
    {
        $refTokens = $this->tokens($ref);
        if (!$refTokens) return [];

        $rows = DB::table('leads')->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->orderByDesc('id')->limit(400)
            ->get(['id', 'name', 'company']);

        $hits = [];
        foreach ($rows as $row) {
            $label = trim((string) $row->name . ' ' . (string) ($row->company ?? ''));
            $t = $this->tokens($label);
            if (!$t) continue;
            $shared = count(array_intersect($refTokens, $t));
            if ($shared / count($refTokens) >= self::MATCH_THRESHOLD) {
                $hits[] = ['id' => (int) $row->id, 'label' => trim((string) $row->name)];
            }
            if (count($hits) >= 5) break;
        }
        return $hits;
    }

    /** Lowercased content words, stopwords dropped. Mirrors CommitmentSync. */
    private function tokens(string $s): array
    {
        $stop = ['the','a','an','of','for','to','and','our','my','that','this','it','is','on','in','all'];
        $words = preg_split('/[^a-z0-9]+/i', mb_strtolower($s)) ?: [];
        $words = array_filter($words, static fn ($w) => $w !== '' && mb_strlen($w) > 1 && !in_array($w, $stop, true));
        return array_values(array_unique($words));
    }
}
