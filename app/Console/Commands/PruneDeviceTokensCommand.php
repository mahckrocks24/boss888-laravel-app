<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * b20 (2026-07-24) — sweep unreachable push registrations.
 *
 * WHY THIS EXISTS
 * ───────────────
 * A customer logged out of the companion app and kept receiving notifications.
 * The cause was accumulation: Expo tokens were pruned ONLY when Expo replied
 * 'DeviceNotRegistered' (app uninstalled), which a logout never triggers. Every
 * re-login registered a fresh token without retiring the old one — two physical
 * phones had produced SEVEN live registrations, and the stale ones kept getting
 * delivered to.
 *
 * That accumulation is also why the problem appeared to be fixed and then came
 * back on its own: clearing the token the app currently held stopped the noise
 * until the next login minted a new one and the older rows kept firing.
 *
 * Binding registrations to a session (device_tokens.session_id) stops new
 * strays being created. This sweeps what falls through anyway:
 *
 *   1. bound to a session that is revoked or expired  → owner signed out
 *   2. no session binding and not seen in --days      → pre-b20 leftovers
 *
 * Neither is recoverable state: the app re-registers on next launch after login.
 */
class PruneDeviceTokensCommand extends Command
{
    protected $signature = 'sarah:prune-device-tokens
        {--days=45 : age limit for unbound (pre-b20) registrations}
        {--dry : report what would be removed without deleting}';

    protected $description = 'Remove push registrations whose sign-in has ended (prevents stale-token accumulation)';

    public function handle(): int
    {
        $dry  = (bool) $this->option('dry');
        $days = max(1, (int) $this->option('days'));

        // 1) Bound to a session that is no longer valid.
        $signedOut = DB::table('device_tokens')
            ->join('sessions', 'sessions.id', '=', 'device_tokens.session_id')
            ->where(function ($q) {
                $q->whereNotNull('sessions.revoked_at')
                  ->orWhere('sessions.expires_at', '<=', now());
            })
            ->pluck('device_tokens.id')
            ->all();

        // 2) Never bound to a session and long dormant.
        $orphans = DB::table('device_tokens')
            ->whereNull('session_id')
            ->where(function ($q) use ($days) {
                $q->where('last_seen_at', '<', now()->subDays($days))
                  ->orWhereNull('last_seen_at');
            })
            ->pluck('id')
            ->all();

        $ids = array_values(array_unique(array_merge($signedOut, $orphans)));

        if (empty($ids)) {
            $this->info('[prune-device-tokens] nothing to remove.');
            return self::SUCCESS;
        }

        $this->line(sprintf(
            '  signed-out registrations: %d   unbound/dormant (>%dd): %d',
            count($signedOut), $days, count($orphans)
        ));

        if ($dry) {
            $this->info('[prune-device-tokens] ' . count($ids) . ' would be removed [DRY]');
            return self::SUCCESS;
        }

        $removed = DB::table('device_tokens')->whereIn('id', $ids)->delete();

        Log::info('[prune-device-tokens] removed unreachable push registrations', [
            'removed' => $removed,
            'signed_out' => count($signedOut),
            'orphans' => count($orphans),
        ]);

        $this->info("[prune-device-tokens] removed {$removed} registration(s).");
        return self::SUCCESS;
    }
}
