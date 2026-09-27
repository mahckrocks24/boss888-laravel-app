<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** PAGE-ONE-1 S2: quality gate, in-body images, on-page pass, publish or hold — after the writer has saved the draft. */
class PageOneFinishJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 900;
    public int $tries = 1;

    public function __construct(public int $articleId) {}

    public function handle(\App\Core\Search\PageOne $pageOne): void
    {
        $lock = \Illuminate\Support\Facades\Cache::lock('page-one-finish:' . $this->articleId, 900);
        if (! $lock->get()) return;
        try { $pageOne->finish($this->articleId); } finally { $lock->release(); }
    }
}
