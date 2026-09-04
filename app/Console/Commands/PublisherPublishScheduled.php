<?php

namespace App\Console\Commands;

use App\Engines\Publisher\Services\DeskService;
use Illuminate\Console\Command;

/**
 * PUBLISHER888 Unit 1 — publishes site-bound articles whose scheduled_at is due.
 * Grace window: only rows scheduled within the last 24 h are flipped; older 'scheduled'
 * rows (the stranded pre-desk ones) are counted and left alone.
 */
class PublisherPublishScheduled extends Command
{
    protected $signature = 'publisher:publish-scheduled {--grace=24 : hours}';
    protected $description = 'Publish desk-scheduled stories that are due';

    public function handle(DeskService $desk): int
    {
        $r = $desk->publishDue((int) $this->option('grace'));
        \Illuminate\Support\Facades\Cache::put('desk:publish-scheduled:last_run', now()->toIso8601String(), 86400); // Unit 2 heartbeat, read by /api/desk/health
        if ($r['published']) \Illuminate\Support\Facades\Log::info('desk.publish_scheduled', $r);
        $this->line(json_encode(['published' => $r['published'], 'stale_skipped' => $r['stale_skipped']]));
        return self::SUCCESS;
    }
}
