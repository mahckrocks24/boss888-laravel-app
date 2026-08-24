<?php

namespace App\Engines\Ads\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * AdAuditService — append-only record of every consequential ad-platform action.
 *
 * WHY THIS IS NOT OPTIONAL
 * "Who approved this creative, and when?" is the first question asked when an
 * advertiser's ad turns out to be inappropriate, and "who widened the eligible
 * plans?" is the first question asked when a paying customer sees an ad on
 * their site. Neither is answerable without this table.
 *
 * Auditing must never break the action it audits — a failure here is logged and
 * swallowed. That is the one place in this engine where failing open is correct:
 * losing an audit line is bad, but blocking a legitimate creative approval
 * because the audit write failed is worse.
 */
final class AdAuditService
{
    public function record(
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        mixed $before = null,
        mixed $after = null,
        ?int $actorId = null,
        ?string $actorLabel = null,
    ): void {
        try {
            DB::table('ad_audit_log')->insert([
                'actor_id'     => $actorId,
                'actor_label'  => $actorLabel ?? ($actorId === null ? 'system' : null),
                'action'       => $action,
                'subject_type' => $subjectType,
                'subject_id'   => $subjectId,
                'before'       => $before === null ? null : json_encode($before, JSON_UNESCAPED_SLASHES),
                'after'        => $after === null ? null : json_encode($after, JSON_UNESCAPED_SLASHES),
                'occurred_at'  => now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('ADS888 audit write failed', [
                'action' => $action,
                'error'  => $e->getMessage(),
            ]);
        }
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 50, ?string $action = null): array
    {
        $rows = DB::table('ad_audit_log')
            ->when($action !== null, fn ($q) => $q->where('action', $action))
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        return array_map(static fn ($r) => (array) $r, $rows->all());
    }
}
