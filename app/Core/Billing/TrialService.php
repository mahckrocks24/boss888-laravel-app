<?php

namespace App\Core\Billing;

use App\Models\Plan;
use App\Models\Subscription;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TrialService — 3-day / 50-credit trial system
 *
 * Rules (from Master Context):
 *   - Trial activates on FIRST website creation (not on signup)
 *   - No credit card required to start trial
 *   - Duration: 3 days from activation
 *   - Credits: 50 trial credits (separate pool from regular credits)
 *   - Trial gives Growth-level access for 3 days
 *   - On expiry: workspace drops to Free tier, trial credits zeroed
 *   - One trial per workspace — cannot re-activate
 */
class TrialService
{
    private const TRIAL_DAYS    = 3;
    private const TRIAL_CREDITS = 50;
    private const TRIAL_PLAN    = 'growth';  // plan slug that trial mimics

    public function __construct(
        private CreditService $credits,
    ) {}

    // ═══════════════════════════════════════════════════════════
    // ACTIVATION
    // ═══════════════════════════════════════════════════════════

    /**
     * Activate the trial for a workspace.
     * Called by BuilderService::createWebsite() on first website creation.
     * Idempotent — silently returns if trial already activated.
     */
    public function activateTrial(int $wsId): array
    {
        $ws = Workspace::find($wsId);
        if (!$ws) {
            return ['activated' => false, 'reason' => 'workspace_not_found'];
        }

        // Already has a trial (started or expired)
        if ($ws->trial_started_at) {
            return ['activated' => false, 'reason' => 'already_trialed'];
        }

        // Already on a paid plan — no trial needed
        if ($this->isOnPaidPlan($wsId)) {
            return ['activated' => false, 'reason' => 'already_subscribed'];
        }

        DB::beginTransaction();
        try {
            // Mark trial start on workspace.
            // 2026-05-12 sprint 2: also set the new columns introduced in the
            // 2026_05_16_000002_add_trial_cols_to_workspaces migration.
            $ws->trial_started_at = now();
            $ws->trial_credits    = self::TRIAL_CREDITS;
            if (\Illuminate\Support\Facades\Schema::hasColumn('workspaces', 'is_trial')) {
                $ws->is_trial = true;
            }
            if (\Illuminate\Support\Facades\Schema::hasColumn('workspaces', 'trial_expires_at')) {
                $ws->trial_expires_at = now()->addDays(self::TRIAL_DAYS);
            }
            $ws->save();

            // Create a trial subscription at Growth level
            $growthPlan = Plan::where('slug', self::TRIAL_PLAN)->first();
            if ($growthPlan) {
                // Cancel any existing free subscription
                Subscription::where('workspace_id', $wsId)
                    ->where('status', 'active')
                    ->update(['status' => 'cancelled', 'ends_at' => now()]);

                // Create trial subscription
                Subscription::create([
                    'workspace_id' => $wsId,
                    'plan_id'      => $growthPlan->id,
                    'provider'     => 'trial',
                    'status'       => 'trialing',
                    'starts_at'    => now(),
                    'ends_at'      => now()->addDays(self::TRIAL_DAYS),
                ]);
            }

            // Credit the trial credits into the workspace balance
            $this->credits->credit(
                $wsId,
                self::TRIAL_CREDITS,
                'trial_activation',
                'Trial activation — ' . self::TRIAL_CREDITS . ' credits for ' . self::TRIAL_DAYS . ' days'
            );

            DB::commit();

            Log::info("Trial activated for workspace {$wsId}", [
                'trial_credits' => self::TRIAL_CREDITS,
                'expires_at'    => now()->addDays(self::TRIAL_DAYS)->toDateTimeString(),
            ]);

            return [
                'activated'    => true,
                'trial_credits'=> self::TRIAL_CREDITS,
                'days'         => self::TRIAL_DAYS,
                'expires_at'   => now()->addDays(self::TRIAL_DAYS)->toISOString(),
                'plan_during_trial' => self::TRIAL_PLAN,
            ];

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Trial activation failed for workspace {$wsId}", ['error' => $e->getMessage()]);
            return ['activated' => false, 'reason' => 'error', 'error' => $e->getMessage()];
        }
    }

    // ═══════════════════════════════════════════════════════════
    // STATUS CHECKS
    // ═══════════════════════════════════════════════════════════

    public function isInTrial(int $wsId): bool
    {
        $ws = Workspace::find($wsId);
        if (!$ws || !$ws->trial_started_at) return false;

        return now()->lt(
            \Carbon\Carbon::parse($ws->trial_started_at)->addDays(self::TRIAL_DAYS)
        );
    }

    public function isTrialExpired(int $wsId): bool
    {
        $ws = Workspace::find($wsId);
        if (!$ws || !$ws->trial_started_at) return false;

        return now()->gte(
            \Carbon\Carbon::parse($ws->trial_started_at)->addDays(self::TRIAL_DAYS)
        );
    }

    public function hasHadTrial(int $wsId): bool
    {
        $ws = Workspace::find($wsId);
        return $ws && $ws->trial_started_at !== null;
    }

    /**
     * Get full trial status for a workspace.
     * Used by GET /workspace/trial-status.
     */
    public function getTrialStatus(int $wsId): array
    {
        $ws = Workspace::find($wsId);

        if (!$ws || !$ws->trial_started_at) {
            return [
                'has_trial'     => false,
                'active'        => false,
                'expired'       => false,
                'eligible'      => !$this->isOnPaidPlan($wsId),
                'trial_credits' => 0,
                'days_remaining'=> 0,
                'expires_at'    => null,
                'trigger'       => 'Create your first website to start your 3-day free trial',
            ];
        }

        $startedAt  = \Carbon\Carbon::parse($ws->trial_started_at);
        $expiresAt  = $startedAt->copy()->addDays(self::TRIAL_DAYS);
        $active     = $this->isInTrial($wsId);
        $expired    = !$active;
        $daysLeft   = $active ? (int) now()->diffInDays($expiresAt, false) : 0;

        $balance = $this->credits->getBalance($wsId);

        return [
            'has_trial'      => true,
            'active'         => $active,
            'expired'        => $expired,
            'eligible'       => false,
            'trial_credits'  => (int) ($ws->trial_credits ?? self::TRIAL_CREDITS),
            'credits_used'   => max(0, (int)($ws->trial_credits ?? 0) - ($balance['available'] ?? 0)),
            'credits_remaining' => $balance['available'] ?? 0,
            'days_remaining' => max(0, $daysLeft),
            'started_at'     => $startedAt->toISOString(),
            'expires_at'     => $expiresAt->toISOString(),
            'plan_during_trial' => self::TRIAL_PLAN,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // EXPIRY
    // ═══════════════════════════════════════════════════════════

    /**
     * Expire the trial for a workspace.
     * Called by the daily cron when trial_started_at + 3 days < now().
     * Downgrades to Free tier. Zeros trial credits.
     */
    public function expireTrial(int $wsId): array
    {
        $ws = Workspace::find($wsId);
        if (!$ws) {
            return ['expired' => false, 'reason' => 'workspace_not_found'];
        }

        DB::beginTransaction();
        try {
            // Cancel the trial subscription
            Subscription::where('workspace_id', $wsId)
                ->where('status', 'trialing')
                ->update(['status' => 'expired', 'ends_at' => now()]);

            // Create Free plan subscription
            $freePlan = Plan::where('slug', 'free')->first();
            if ($freePlan) {
                Subscription::create([
                    'workspace_id' => $wsId,
                    'plan_id'      => $freePlan->id,
                    'provider'     => 'system',
                    'status'       => 'active',
                    'starts_at'    => now(),
                ]);
            }

            // Zero out trial credit balance (don't debit — just reset)
            DB::table('credits')
                ->where('workspace_id', $wsId)
                ->update(['balance' => 0, 'reserved_balance' => 0, 'updated_at' => now()]);

            // MEDIUM-03 FIX: Bust any cached credit balance so the UI reflects 0 immediately
            try {
                \Illuminate\Support\Facades\Cache::forget("credits:{$wsId}");
                \Illuminate\Support\Facades\Cache::forget("workspace:{$wsId}:credits");
                \Illuminate\Support\Facades\Cache::forget("lu:credits:{$wsId}");
            } catch (\Throwable) {}

            DB::commit();

            Log::info("Trial expired for workspace {$wsId}");

            return ['expired' => true, 'downgraded_to' => 'free'];

        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error("Trial expiry failed for workspace {$wsId}", ['error' => $e->getMessage()]);
            return ['expired' => false, 'reason' => 'error'];
        }
    }

    /**
     * Process all expired trials.
     * Called by the daily cron — sarah:proactive --type=daily triggers this too.
     * Returns count of trials expired.
     */
    public function processExpiredTrials(): int
    {
        $cutoff = now()->subDays(self::TRIAL_DAYS);

        // Find workspaces with active trial subscriptions that have passed expiry
        $expired = Workspace::whereNotNull('trial_started_at')
            ->where('trial_started_at', '<', $cutoff)
            ->whereHas('subscription', function ($q) {
                $q->where('status', 'trialing');
            })
            ->get();

        $count = 0;
        foreach ($expired as $ws) {
            $result = $this->expireTrial($ws->id);
            if ($result['expired'] ?? false) {
                $count++;
            }
        }

        if ($count > 0) {
            Log::info("Processed {$count} expired trial(s)");
        }

        return $count;
    }

    // ═══════════════════════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════════════════════

    private function isOnPaidPlan(int $wsId): bool
    {
        return Subscription::where('workspace_id', $wsId)
            ->whereIn('status', ['active', 'trialing'])
            ->whereHas('plan', function ($q) {
                $q->where('price', '>', 0);
            })
            ->exists();
    }
}
