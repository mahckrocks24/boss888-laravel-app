<?php

namespace App\Engines\Builder\Support;

use App\Core\Billing\CreditService;
use Illuminate\Support\Facades\Log;

/**
 * ONE PRICE LIST FOR EVERY EDITOR ACTION (Owner, 2026-09-15: "make sure all actions inside editor will charge credit
 * accordingly"). Chat and panel go through the same table; a change that did not happen is never charged; an action
 * the workspace cannot afford is refused before anything is written.
 */
final class EditorCredits
{
    public static function price(string $action): int
    {
        $p = BuilderCapabilities::pricing();
        return (int) ($p[$action] ?? 0);
    }

    /** True when the workspace can pay for the action (or it is free). */
    public static function canAfford(int $wsId, string $action): bool
    {
        $cost = self::price($action);
        return $cost === 0 || app(CreditService::class)->hasBalance($wsId, $cost);
    }

    /** The customer-facing refusal, one sentence. */
    public static function refusal(string $action): string
    {
        $cost = self::price($action);
        return 'Not enough credits for this change (' . $cost . ' credit' . ($cost === 1 ? '' : 's') . ' needed). Add credits under Billing to continue.';
    }

    /** Charge after the change happened. Returns the amount charged (0 for free actions). */
    public static function charge(int $wsId, string $action, ?int $websiteId = null, array $meta = []): int
    {
        $cost = self::price($action);
        if ($cost <= 0) return 0;
        try {
            app(CreditService::class)->debit($wsId, $cost, 'builder_' . $action, $websiteId, $meta + ['action' => $action]);
        } catch (\Throwable $e) {
            Log::warning('[EditorCredits] debit failed', ['workspace' => $wsId, 'action' => $action, 'error' => $e->getMessage()]);
            return 0;
        }
        return $cost;
    }

    /** "… · 1 credit" appended to a customer-facing message. */
    public static function suffix(int $cost): string
    {
        return $cost > 0 ? ' · ' . $cost . ' credit' . ($cost === 1 ? '' : 's') : '';
    }
}
