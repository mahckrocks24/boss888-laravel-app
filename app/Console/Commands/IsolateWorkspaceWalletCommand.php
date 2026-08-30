<?php

namespace App\Console\Commands;

use App\Core\Billing\CreditService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * P1-U1 (2026-08-30, REPORT-0023 UX-001 — Owner disposition): give a workspace its OWN credit wallet.
 *
 *   php artisan workspace:isolate-wallet {workspace} [--seed=50] [--reason=qa_isolation]
 *
 * What it does, in one transaction:
 *   1. refuses to touch a house account, a workspace that already owns its pool, or one whose pool has
 *      pending reservations originating from this workspace (they would commit against the old pool);
 *   2. sets workspaces.billing_workspace_id = the workspace's own id;
 *   3. creates the wallet row at 0 (the same way CreditService::credit() creates wallets);
 *   4. seeds --seed credits THROUGH CreditService::credit() so the ledger carries a `credit` row with
 *      reference_type `adjustment/{reason}`, the previous pool and the actor in metadata_json.
 * It never writes credits.balance directly and never touches the old pool's balance or history — the
 * commits this workspace made against the old pool stay attributed to it (per-workspace usage) and remain
 * the old pool's spend (nothing is reimbursed; see EV-0887).
 */
class IsolateWorkspaceWalletCommand extends Command
{
    protected $signature = 'workspace:isolate-wallet {workspace : workspace id} {--seed=50 : credits to grant through the ledger} {--reason=qa_isolation : reference reason} {--dry-run : report only}';
    protected $description = 'Re-parent a workspace to its own credit pool and seed credits through the ledger (no raw balance writes)';

    public function handle(CreditService $credits): int
    {
        $wsId   = (int) $this->argument('workspace');
        $seed   = max(0, (int) $this->option('seed'));
        $reason = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $this->option('reason'))) ?: 'qa_isolation';
        $dry    = (bool) $this->option('dry-run');

        $ws = DB::table('workspaces')->where('id', $wsId)->first(['id', 'name', 'created_by', 'billing_workspace_id', 'is_house_account']);
        if (! $ws) { $this->error("Workspace {$wsId} not found."); return self::FAILURE; }
        if ((bool) $ws->is_house_account) { $this->error("Workspace {$wsId} is a house account — not a candidate."); return self::FAILURE; }

        $oldPool = (int) ($ws->billing_workspace_id ?: $wsId);
        if ($oldPool === $wsId && DB::table('credits')->where('workspace_id', $wsId)->exists()) {
            $this->info("Workspace {$wsId} already owns its wallet — nothing to do.");
            return self::SUCCESS;
        }

        $pending = (int) DB::table('credit_transactions')->where('workspace_id', $wsId)
            ->where('type', 'reserve')->where('reservation_status', 'pending')->count();
        if ($pending > 0) {
            $this->error("Workspace {$wsId} has {$pending} pending reservation(s) against pool {$oldPool}; let them commit/release first.");
            return self::FAILURE;
        }

        $spentOnOldPool = (int) DB::table('credit_transactions')->where('workspace_id', $wsId)->where('type', 'commit')->sum('amount');
        $this->line(sprintf('%s (#%d) · current pool #%d · committed against it so far: %d credits · seed: %d (%s)%s',
            $ws->name, $wsId, $oldPool, $spentOnOldPool, $seed, $reason, $dry ? ' · DRY RUN' : ''));
        if ($dry) { return self::SUCCESS; }

        DB::transaction(function () use ($wsId, $oldPool, $seed, $reason, $credits, $spentOnOldPool) {
            DB::table('workspaces')->where('id', $wsId)->update(['billing_workspace_id' => $wsId, 'updated_at' => now()]);
            if (! DB::table('credits')->where('workspace_id', $wsId)->exists()) {
                DB::table('credits')->insert(['workspace_id' => $wsId, 'balance' => 0, 'reserved_balance' => 0, 'created_at' => now(), 'updated_at' => now()]);
            }
            if ($seed > 0) {
                $credits->credit($wsId, $seed, 'adjustment/' . $reason, null, [
                    'previous_pool_workspace_id' => $oldPool,
                    'committed_against_previous_pool' => $spentOnOldPool,
                    'actor' => 'artisan workspace:isolate-wallet',
                ]);
            }
        });

        $bal = $credits->getBalance($wsId);
        $this->info(sprintf('Done. Workspace %d now pools to itself · balance %d · available %d.', $wsId, $bal['balance'] ?? 0, $bal['available'] ?? 0));
        return self::SUCCESS;
    }
}
