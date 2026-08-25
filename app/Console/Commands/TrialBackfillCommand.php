<?php

namespace App\Console\Commands;

use App\Core\Billing\TrialService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill trials for website-owning workspaces whose first-site trial silently
 * failed before the EV-0725 credit-wallet fix (CreditService::credit firstOrFail on a
 * non-existent wallet -> activateTrial rolled back -> trial_started_at NULL).
 *
 * DRY-RUN by default (read-only). Pass --execute to apply. Uses the authoritative,
 * now-fixed TrialService::activateTrial, which is idempotent (already-trialed
 * workspaces are skipped) and paid-plan-aware (a subscribed workspace is left alone).
 * Scoped to website-owning workspaces with trial_started_at NULL — exactly the
 * EV-0727 backfill set.
 */
class TrialBackfillCommand extends Command
{
    protected $signature = 'boss888:trial-backfill {--execute : Actually activate trials (default is a read-only dry-run)}';

    protected $description = 'Backfill trials for website-owning workspaces whose first-site trial silently failed (EV-0725). Dry-run by default.';

    public function handle(TrialService $trials): int
    {
        $execute = (bool) $this->option('execute');

        $targets = DB::table('workspaces as w')
            ->join('websites as s', 's.workspace_id', '=', 'w.id')
            ->whereNull('w.trial_started_at')
            ->distinct()
            ->orderBy('w.id')
            ->pluck('w.id')
            ->all();

        if (empty($targets)) {
            $this->info('No website-owning workspaces need a trial backfill.');
            return self::SUCCESS;
        }

        $this->line(($execute ? 'EXECUTING' : 'DRY-RUN (read-only)') . ' trial backfill for '
            . count($targets) . ' workspace(s): ' . implode(', ', $targets));

        $activated = 0; $skipped = 0; $failed = 0;
        foreach ($targets as $wsId) {
            $hasWallet = DB::table('credits')->where('workspace_id', (int) $wsId)->exists();

            if (! $execute) {
                $this->line("  ws {$wsId}: would activateTrial (credits wallet "
                    . ($hasWallet ? 'exists' : 'MISSING -> would be created') . ')');
                continue;
            }

            $res = $trials->activateTrial((int) $wsId);
            if ($res['activated'] ?? false) {
                $activated++;
                $this->info("  ws {$wsId}: ACTIVATED ({$res['trial_credits']} credits, {$res['days']}d)");
            } elseif (in_array($res['reason'] ?? '', ['already_trialed', 'already_subscribed'], true)) {
                $skipped++;
                $this->line("  ws {$wsId}: skipped ({$res['reason']})");
            } else {
                $failed++;
                $this->error("  ws {$wsId}: FAILED — " . ($res['reason'] ?? '?') . ' ' . ($res['error'] ?? ''));
            }
        }

        if ($execute) {
            $this->line("Done. activated={$activated} skipped={$skipped} failed={$failed}");
            return $failed > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->line('Dry-run complete. Re-run with --execute to apply (idempotent; already-trialed/subscribed workspaces are skipped).');
        return self::SUCCESS;
    }
}
