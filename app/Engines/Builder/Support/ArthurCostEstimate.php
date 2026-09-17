<?php

namespace App\Engines\Builder\Support;

/**
 * RISK-0189 (2026-09-17) — what an "ask Arthur" request will cost, said before the owner approves it.
 *
 * The approval card and Sarah read tasks.credit_cost. The capability map prices `ask_arthur` at 0 because Arthur
 * decides the real action when he runs, so every Sarah→Arthur request showed "Uses no credits" while the ledger
 * charged 1 (EV-1056/EV-1058). This reads the request through the SAME classifier and the SAME registry Arthur
 * charges from (BuilderCapabilities::classify → PRICING) and says one of two things: a known figure, or "not known
 * yet — not free". It never invents a price: kinds whose charge is set elsewhere (image / video studio, a removal, an
 * unsupported request) are reported as unknown, and the ledger remains the only thing that debits.
 */
final class ArthurCostEstimate
{
    /** classify() kinds whose `credits` come straight from BuilderCapabilities::PRICING */
    private const PRICED_KINDS = ['edit', 'style', 'section', 'page', 'overlay', 'catalogue'];

    /**
     * @return array{known:bool, credits:int, kind:string, label:string, note:string, source:string}
     */
    public static function forRequest(string $request, ?string $industry = null): array
    {
        $c = BuilderCapabilities::classify($request, $industry);
        $kind = (string) ($c['kind'] ?? 'unsupported');
        $credits = (int) ($c['credits'] ?? 0);
        $known = in_array($kind, self::PRICED_KINDS, true) && $credits > 0;
        return [
            'known'   => $known,
            'credits' => $known ? $credits : 0,
            'kind'    => $kind,
            'label'   => (string) ($c['label'] ?? ''),
            'note'    => $known
                ? ($credits . ' credit' . ($credits === 1 ? '' : 's') . ' when Arthur applies it')
                : 'credits are set when Arthur applies it — not free',
            'source'  => 'BuilderCapabilities::classify',
        ];
    }

    /** The figure the owner is asked to approve: the estimate when the task carries a known one, else the task's own cost. */
    public static function disclosed(int $creditCost, ?array $estimate): int
    {
        if (is_array($estimate) && ! empty($estimate['known'])) return (int) ($estimate['credits'] ?? 0);
        return $creditCost;
    }

    /** The disclosure line for a task row as the card and Sarah see it. */
    public static function describe(int $creditCost, ?array $estimate): string
    {
        if (is_array($estimate) && array_key_exists('known', $estimate) && ! $estimate['known']) {
            return 'credits are set when Arthur applies it — not free';
        }
        $n = self::disclosed($creditCost, $estimate);
        if ($n <= 0) return 'no credits';
        return $n . ' credit' . ($n === 1 ? '' : 's');
    }
}
