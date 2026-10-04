<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReplenishHouseCreditsCommand extends Command
{
    protected $signature = 'credits:replenish-house {--force : Run even if not the 1st of the month}';
    protected $description = 'Replenish credits for house account workspaces to their monthly allowance';

    public function handle(): int
    {
        if (!$this->option('force') && now()->day !== 1) {
            $this->info('Credit replenish runs on the 1st of each month. Use --force to run now.');
            return 0;
        }

        $houseAccounts = DB::table('workspaces')
            ->where('is_house_account', true)
            ->where('credits_auto_replenish', true)
            ->where('monthly_credit_allowance', '>', 0)
            ->get();

        if ($houseAccounts->isEmpty()) {
            $this->info('No house accounts with auto-replenish enabled.');
            return 0;
        }

        foreach ($houseAccounts as $ws) {
            $credits = DB::table('credits')->where('workspace_id', $ws->id)->first();
            $oldBalance = $credits->balance ?? 0;
            $allowance = $ws->monthly_credit_allowance;

            // CREDIT-CERT-1: set through the wallet; the ledger records the real difference (was the full allowance as a credit)
            app(\App\Core\Billing\CreditService::class)->setBalance((int) $ws->id, (int) $allowance, 'house_account_replenish', ['monthly_allowance' => (int) $allowance, 'replenish_date' => now()->toDateString()]);

            $this->line("  ✓ WS {$ws->id} ({$ws->name}): {$oldBalance} → {$allowance} credits");
            Log::info("House account credit replenish", [
                'workspace_id' => $ws->id,
                'old_balance' => $oldBalance,
                'new_balance' => $allowance,
            ]);
        }

        $this->info("Done. {$houseAccounts->count()} house account(s) replenished.");
        return 0;
    }
}
