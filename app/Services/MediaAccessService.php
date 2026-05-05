<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Plan-gated access control for the unified media library.
 *
 * Reads a workspace's active subscription → plan → `media_library_limit`.
 * Conventions:
 *   -1   = unlimited (Pro, Agency)
 *    0   = no access (Free, Starter)
 *   >0   = capped count (AI Lite 50, Growth 200)
 *
 * When a workspace has no active subscription, treated as Free (0).
 *
 * "Usage" counts the workspace's own media rows (workspace_id = $wsId).
 * This mirrors the quota contract most admin panels expect: quota = what
 * you own + store. Platform-library references are NOT counted here
 * because is_public assets don't consume the workspace quota.
 */
class MediaAccessService
{
    private const CACHE_TTL_SECONDS = 60;

    /** Cached limit lookup keyed by workspace. */
    public function getLimit(int $wsId): int
    {
        return (int) Cache::remember(
            "media_access_limit:{$wsId}",
            self::CACHE_TTL_SECONDS,
            fn() => $this->resolveLimit($wsId)
        );
    }

    /**
     * Count of workspace-owned media rows. Excludes platform/public assets
     * because those don't eat the workspace quota.
     */
    public function getUsage(int $wsId): int
    {
        return (int) DB::table('media')
            ->where('workspace_id', $wsId)
            ->where(function ($q) {
                $q->whereNull('is_public')->orWhere('is_public', 0);
            })
            ->count();
    }

    /** True when the plan grants ANY media library access (limit != 0). */
    public function canAccess(int $wsId): bool
    {
        return $this->getLimit($wsId) !== 0;
    }

    /** True when current usage is under the workspace's cap. Unlimited → always true. */
    public function isWithinLimit(int $wsId): bool
    {
        $limit = $this->getLimit($wsId);
        if ($limit === -1) return true;
        if ($limit === 0)  return false;
        return $this->getUsage($wsId) < $limit;
    }

    /**
     * One-call state object for UI banners / upload gates.
     * Returns limit, usage, remaining, can_access, within_limit, plan_slug.
     */
    public function snapshot(int $wsId): array
    {
        $limit = $this->getLimit($wsId);
        $usage = $this->getUsage($wsId);
        $remaining = $limit === -1 ? null : max(0, $limit - $usage);
        return [
            'workspace_id'  => $wsId,
            'plan_slug'     => $this->resolvePlanSlug($wsId),
            'limit'         => $limit,
            'usage'         => $usage,
            'remaining'     => $remaining,
            'unlimited'     => $limit === -1,
            'can_access'    => $limit !== 0,
            'within_limit'  => $limit === -1 ? true : ($limit === 0 ? false : $usage < $limit),
        ];
    }

    /**
     * Drop the cached limit for a workspace — call after plan upgrade /
     * downgrade so the gate reflects the new tier immediately.
     */
    public function forgetCache(int $wsId): void
    {
        Cache::forget("media_access_limit:{$wsId}");
    }

    // ── internals ────────────────────────────────────────────────────

    private function resolveLimit(int $wsId): int
    {
        $plan = $this->resolvePlan($wsId);
        if (!$plan) return 0; // no active subscription → treat as Free
        return (int) ($plan->media_library_limit ?? 0);
    }

    private function resolvePlanSlug(int $wsId): ?string
    {
        $plan = $this->resolvePlan($wsId);
        return $plan ? ($plan->slug ?? null) : null;
    }

    /**
     * Most-recent active subscription → plan row. Returns null if none.
     */
    private function resolvePlan(int $wsId)
    {
        $sub = DB::table('subscriptions')
            ->where('workspace_id', $wsId)
            ->whereIn('status', ['active', 'trialing'])
            ->orderByDesc('id')
            ->first();
        if (!$sub) return null;

        return DB::table('plans')->where('id', $sub->plan_id)->first();
    }
}
