<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Wave 1 — R5 (2026-05-17). Per the AI Assistant Operating Rules, SEO
 * assistant chat history must be retained for 90 days and no longer.
 *
 * Runs daily. Hard-deletes seo_assistant_messages rows older than 90
 * days. Idempotent and safe to re-run.
 */
class SeoPurgeChatHistoryCommand extends Command
{
    protected $signature   = 'seo:purge-chat-history {--days=90 : Retention window in days}';
    protected $description = 'Hard-delete SEO assistant chat messages older than the retention window (default 90 days).';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 7) {
            $this->error("Refusing to purge with retention < 7 days. Requested: $days");
            return 1;
        }

        $cutoff = now()->subDays($days);
        $deleted = DB::table('seo_assistant_messages')
            ->where('created_at', '<', $cutoff)
            ->delete();

        $this->info("Deleted {$deleted} chat messages older than {$cutoff->toDateTimeString()} ({$days} days)");
        return 0;
    }
}
