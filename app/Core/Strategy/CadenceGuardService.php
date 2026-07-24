<?php

namespace App\Core\Strategy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 2026-05-24 FIX 46 — Cadence enforcement.
 *
 * Hard rule layer (not intelligence): given a workspace's chosen tier,
 * checks whether a new task of type X would exceed the tier's weekly
 * or monthly cadence cap. Returns allow/deny + reason.
 *
 * Called from task-creation paths BEFORE inserting into tasks table.
 * Lives in Laravel because it's a deterministic rule, not synthesis.
 *
 * Cadences derived from StrategyTierService (single source of truth).
 */
class CadenceGuardService
{
    /**
     * Check if an action of $actionType can be queued this period.
     * Returns ['allowed' => bool, 'reason' => string, 'current' => int, 'cap' => int].
     */
    public function check(int $wsId, string $actionType): array
    {
        $active = StrategyTierService::getActiveStrategy($wsId);
        $cadence = $active['cadence'];

        // Map action type to cadence field + window
        $mapping = [
            'write_article'        => ['articles_per_month', 'month'],
            'social_create_post'   => ['standalone_socials_per_month', 'month'],
            'generate_video'       => ['videos_per_month', 'month'],
            'send_email'           => ['emails_per_month', 'month'],
            'deep_audit'           => ['audits_per_month', 'month'],
            'strategy_meeting'     => ['strategy_meetings_per_month', 'month'],
            'retargeting_refresh' => ['retargeting_campaigns_per_month', 'month'],
        ];

        if (!isset($mapping[$actionType])) {
            return ['allowed' => true, 'reason' => 'no cap defined for this action', 'current' => 0, 'cap' => 0];
        }
        [$cadenceField, $window] = $mapping[$actionType];

        $cap = (int) ($cadence[$cadenceField] ?? 0);
        if ($cap === 0) {
            return ['allowed' => false, 'reason' => "Tier {$active['tier_name']} doesn't include {$actionType}", 'current' => 0, 'cap' => 0];
        }

        $current = $this->countCurrent($wsId, $actionType, $window);

        // 2026-05-25 FIX B — credit-first rule. Tier caps are ADVISORY.
        // If the user has credits, the only hard limit is credit balance
        // (enforced separately by CreditService at reservation time).
        // CadenceGuard returns a warning so Sarah can narrate it in chat
        // ("you're past your tier cap, X credits required"), but does NOT
        // block execution. User priority on credit spend, per the rule.
        if ($current >= $cap) {
            return [
                'allowed' => true,
                'reason'  => '',
                'warning' => "exceeds_tier_cap:{$actionType} {$current}/{$cap} on {$active['tier_name']} tier",
                'current' => $current,
                'cap'     => $cap,
                'over_cap_by' => $current - $cap + 1,
            ];
        }

        return [
            'allowed' => true,
            'reason'  => '',
            'warning' => null,
            'current' => $current,
            'cap'     => $cap,
        ];
    }

    /**
     * Count current period's usage for an action type. Used by check()
     * and by morning brief for "you have N slots remaining this month".
     */
    public function countCurrent(int $wsId, string $actionType, string $window = 'month'): int
    {
        $cutoff = $window === 'month' ? now()->startOfMonth() : now()->startOfWeek();

        try {
            return (int) DB::table('tasks')
                ->where('workspace_id', $wsId)
                ->where('action', $actionType)
                ->where('created_at', '>=', $cutoff)
                ->whereNotIn('status', ['failed', 'cancelled'])
                ->count();
        } catch (\Throwable $e) {
            Log::warning('[Cadence] countCurrent failed: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * Returns the full remaining cadence picture for the morning brief.
     */
    public function remainingForMonth(int $wsId): array
    {
        $active = StrategyTierService::getActiveStrategy($wsId);
        $cadence = $active['cadence'];

        $out = [];
        foreach ([
            'write_article'      => 'articles_per_month',
            'social_create_post' => 'standalone_socials_per_month',
            'generate_video'     => 'videos_per_month',
            'send_email'         => 'emails_per_month',
        ] as $action => $field) {
            $cap = (int) ($cadence[$field] ?? 0);
            $current = $this->countCurrent($wsId, $action, 'month');
            $out[$action] = [
                'cap'         => $cap,
                'used'        => $current,
                'remaining'   => max(0, $cap - $current),
                'utilization' => $cap > 0 ? round(($current / $cap) * 100) : 0,
            ];
        }
        return $out;
    }
}
