<?php

namespace App\Core\Strategy;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ROUTINE-HOLD-1 (REPORT-0066 finding 2, 2026-09-30). "Fix 18 orphan pages" ran and reported "completed" on four
 * consecutive runs while the morning audit kept counting 18 - twice the fixer inserted nothing at all ("No insertable
 * internal links were found") and the digest still said "Fixed orphan pages". A routine job whose last run changed
 * nothing is a finding to raise, not tomorrow's job again. This marks the hold on the state the rules and the brief read.
 */
final class RoutineHold
{
    private const LOOKBACK_HOURS = 72;

    public static function apply(int $wsId, array $state): array
    {
        try {
            // orphan pages
            $now = (int) ($state['seo']['orphan_pages'] ?? 0);
            $last = self::lastCompleted($wsId, 'fix_orphans');
            if ($last && $now > 0) {
                $d = (json_decode((string) $last->result_json, true) ?: [])['data'] ?? [];
                $applied = (int) ($d['applied'] ?? 0); $before = (int) ($d['orphans_before'] ?? 0); $after = (int) ($d['orphans_after'] ?? 0);
                if ($applied === 0 || ($before > 0 && $after >= $before)) {
                    $when = date('D j M', strtotime((string) $last->updated_at));
                    $state['seo']['orphan_fix_hold'] = $applied === 0
                        ? "Orphan pages: the link job ran on {$when} and found no internal link it could safely insert, and the audit still counts {$now} pages without an inbound link. I'm holding that job rather than repeating it - those pages need a fresh piece of linking content or a manual link, and I'll bring that as a proposal."
                        : "Orphan pages: {$applied} link(s) were inserted on {$when} and the audit still counts {$now} pages without an inbound link, so the fixer and the audit disagree. I'm holding that job instead of repeating it until that is understood.";
                    $state['seo']['orphan_fix_hold_meta'] = ['task_id' => (int) $last->id, 'applied' => $applied, 'before' => $before, 'after' => $after, 'now' => $now];
                }
            }
            // meta descriptions
            $missing = (int) ($state['seo']['missing_meta'] ?? 0);
            $lastM = self::lastCompleted($wsId, 'generate_meta');
            if ($lastM && $missing > 0) {
                $when = date('D j M', strtotime((string) $lastM->updated_at));
                $state['seo']['meta_fix_hold'] = "Meta descriptions: that job ran on {$when} and the audit still lists {$missing} page(s) without one, so I'm holding it instead of running it again until I've seen which pages it is missing.";
                $state['seo']['meta_fix_hold_meta'] = ['task_id' => (int) $lastM->id, 'missing_now' => $missing];
            }
        } catch (\Throwable $e) {
            Log::info('[ROUTINE-HOLD-1] skipped', ['ws' => $wsId, 'e' => $e->getMessage()]);
        }
        return $state;
    }

    private static function lastCompleted(int $wsId, string $action): ?object
    {
        return DB::table('tasks')->where('workspace_id', $wsId)->where('status', 'completed')
            ->where(fn ($q) => $q->where('action', $action)->orWhere('action', 'like', '%/' . $action))
            ->where('updated_at', '>=', now()->subHours(self::LOOKBACK_HOURS))->orderByDesc('id')->first(['id', 'updated_at', 'result_json']);
    }
}
