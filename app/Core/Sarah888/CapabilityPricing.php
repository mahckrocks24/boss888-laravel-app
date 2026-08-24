<?php

namespace App\Core\Sarah888;

use App\Core\Intelligence\ToolCostCalculatorService;

/**
 * SARAH888 — WHAT A CAPABILITY COSTS, ACCORDING TO THE PLATFORM.
 *
 * The Runtime-native gateway calls connectors directly, which is the whole point of the
 * boundary and why a SERP takes 2.3s instead of 40s — but it therefore never passes the
 * task pipeline that charges credits. Measured 2026-08-14: a real DataForSEO query executed
 * and `credits.balance` and `credit_transactions` were both unchanged. A vendor query was
 * purchased and nobody was billed.
 *
 * PRICES ARE NOT INVENTED HERE. `ToolCostCalculatorService` is the platform's own registry,
 * backed by `engine_intelligence` tool blueprints. Its published prices match the product's
 * documented references exactly: serp_analysis 1, deep_audit 3, ai_report 2.
 *
 * THE FALLBACK IS DELIBERATELY REFUSED, and this is the important decision.
 * `costForTool()` falls back to a flat per-ENGINE figure when no blueprint exists — seo 2,
 * write 3, and so on. That heuristic exists to ESTIMATE a sequence of tasks during planning,
 * where every task does real work. Applied to this gateway it would charge:
 *
 *     list_articles   3 credits   for SELECT ... FROM articles
 *     gsc_performance 2 credits   for reading an already-synced local table
 *     get_lead        2 credits   for reading one row
 *
 * Billing a customer to read their own database is not a price the product has ever set —
 * it would be this class inventing pricing, which is precisely what must not happen. So a
 * capability with no blueprint is UNPRICED, and an unpriced local read is free. If the
 * business later decides one of these should cost something, the fix is to add a blueprint,
 * where prices are supposed to live, not a constant in Sarah's code.
 *
 * NOTE ON `observed_max_credits`. The manifest publishes the MAXIMUM a task of this action
 * has ever historically cost. That is a sound input to a RISK decision — "this can get
 * expensive" — and the wrong number to charge, because it bills every caller the worst case
 * ever recorded (serp_analysis: canonical 1, observed max 5). Approval keeps the observed
 * max; charging uses the canonical price.
 */
final class CapabilityPricing
{
    public function __construct(private ToolCostCalculatorService $costs) {}

    /**
     * @return array{credits:int, source:string, priced:bool}
     *         `source` is 'blueprint' when the platform has set a price, 'unpriced'
     *         otherwise. It is recorded on the ToolResult so a billing question can be
     *         answered from the turn rather than re-derived months later.
     */
    public function quote(string $capabilityId, ?string $engine): array
    {
        $engine = trim((string) $engine);
        if ($engine === '') {
            return ['credits' => 0, 'source' => 'unpriced', 'priced' => false];
        }

        // Only a real blueprint price counts. See the class docblock for why the
        // per-engine estimation fallback must not be used to bill anyone.
        if ($this->costs->sourceForTool($engine, $capabilityId) !== 'blueprint') {
            return ['credits' => 0, 'source' => 'unpriced', 'priced' => false];
        }

        $credits = (int) $this->costs->costForTool($engine, $capabilityId);
        if ($credits <= 0) {
            // A blueprint that explicitly says zero is a deliberate free capability —
            // every CRM operation is priced this way. Recorded as such, not as unpriced.
            return ['credits' => 0, 'source' => 'blueprint_free', 'priced' => true];
        }

        return ['credits' => $credits, 'source' => 'blueprint', 'priced' => true];
    }

    /** Does executing this capability cost the workspace anything? */
    public function isChargeable(string $capabilityId, ?string $engine): bool
    {
        return $this->quote($capabilityId, $engine)['credits'] > 0;
    }
}
