<?php

namespace App\Core\Growth;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * NEEDS-YOU-1 (Owner 2026-09-27): decisions waiting for the owner live in their own place — the Review tab in the
 * companion app and the "Needs your OK" pull-down on the web — not as buttons under whatever Sarah said last. Once a day
 * the owner gets one reminder (a push that opens Review), and the app shows it once a day as a pop-up.
 */
final class NeedsYou
{
    /** @return array{count:int, approvals:int, campaigns:int, changes:int} */
    public function count(int $wsId): array
    {
        $approvals = (int) DB::table('approvals')->where('workspace_id', $wsId)->where('status', 'pending')->count();
        $campaigns = 0;
        try { $campaigns = (int) DB::table('marketing_campaigns')->where('workspace_id', $wsId)->where('status', 'idea')->whereNull('deleted_at')->where('created_at', '>=', now()->subDays(14))->count(); } catch (\Throwable $e) {}
        $changes = 0;
        try { $changes = (int) DB::table('campaign_changes')->where('workspace_id', $wsId)->where('status', 'proposed')->count(); } catch (\Throwable $e) {}
        $posts = 0;   // POST-TIMELINE-1: drafted posts waiting for Post it
        try { $posts = (int) DB::table('social_posts')->where('workspace_id', $wsId)->where('status', 'draft')->whereNull('deleted_at')->whereNull('preview_dismissed_at')->where('created_at', '>=', now()->subDays(7))->count(); } catch (\Throwable $e) {}
        return ['count' => $approvals + $campaigns + $changes + $posts, 'approvals' => $approvals, 'campaigns' => $campaigns, 'changes' => $changes, 'posts' => $posts];
    }

    public static function line(array $n): string
    {
        $parts = [];
        if (! empty($n['posts'])) $parts[] = $n['posts'] . ' post' . ($n['posts'] === 1 ? '' : 's') . ' ready to go out';
        if ($n['campaigns']) $parts[] = $n['campaigns'] . ' campaign idea' . ($n['campaigns'] === 1 ? '' : 's');
        if ($n['changes']) $parts[] = $n['changes'] . ' campaign update' . ($n['changes'] === 1 ? '' : 's');
        if ($n['approvals']) $parts[] = $n['approvals'] . ' approval' . ($n['approvals'] === 1 ? '' : 's');
        $list = count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts) : ($parts[0] ?? '');
        return $list . ' waiting for your OK. Tap to open Review.';
    }

    /** Hourly: one reminder per workspace per local day, at 10:00 local, only when something is waiting. */
    public function remind(?int $onlyWs = null, bool $force = false): int
    {
        $sent = 0;
        $q = DB::table('workspaces');
        if ($onlyWs) $q->where('id', $onlyWs);
        foreach ($q->get(['id', 'created_by', 'timezone']) as $ws) {
            try {
                $tz = (string) ($ws->timezone ?: 'UTC'); try { new \DateTimeZone($tz); } catch (\Throwable $e) { $tz = 'UTC'; }
                $local = Carbon::now($tz);
                if (! $force && $local->hour !== 10) continue;
                $n = $this->count((int) $ws->id);
                if ($n['count'] < 1 || ! $ws->created_by) continue;
                if (! $force && ! Cache::add('needs-you-reminded:' . $ws->id . ':' . $local->toDateString(), 1, now()->addHours(30))) continue;
                app(\App\Core\Notifications\PushDispatcherService::class)->dispatchAgentReply((int) $ws->created_by, (int) $ws->id, 'sarah', self::line($n), '', null, ['screen' => 'review', 'kind' => 'needs_you']);
                $sent++;
            } catch (\Throwable $e) { Log::info('[NEEDS-YOU-1] reminder failed', ['ws' => $ws->id, 'e' => $e->getMessage()]); }
        }
        return $sent;
    }
}
