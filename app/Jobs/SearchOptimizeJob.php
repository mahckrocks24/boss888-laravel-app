<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** PAGE-ONE-1 S3: one approved fix from the monthly Search tune-up. */
class SearchOptimizeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public int $itemId) {}

    public function handle(\App\Core\Search\Performance $perf): void
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('search-opt:' . $this->itemId, 600);
        if (! $lock->get()) return;
        try { $perf->optimize($this->itemId); } finally { $lock->release(); }
    }
}
