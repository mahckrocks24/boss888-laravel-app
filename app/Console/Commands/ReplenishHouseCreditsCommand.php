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

            if ($credits) {
                DB::table('credits')->where('workspace_id', $ws->id)->update([
                    'balance' => $allowance,
                    'reserved_balance' => 0,
                    'updated_at' => now(),
                ]);
            } else {
                DB::table('credits')->insert([
                    'workspace_id' => $ws->id,
                    'balance' => $allowance,
                    'reserved_balance' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            // Log the transaction
            DB::table('credit_transactions')->insert([
                'workspace_id' => $ws->id,
                'type' => 'credit',
                'amount' => $allowance,
                'reference_type' => 'house_account_replenish',
                'reference_id' => null,
                'metadata_json' => json_encode([
                    'previous_balance' => $oldBalance,
                    'new_balance' => $allowance,
                    'monthly_allowance' => $allowance,
                    'replenish_date' => now()->toDateString(),
                ]),
                'created_at' => now(),
            ]);

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
