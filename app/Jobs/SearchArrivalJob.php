<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** PAGE-ONE-1: the on-page pass for a site's new URLs, then one message to the owner. */
class SearchArrivalJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $timeout = 600;
    public int $tries = 2;

    public function __construct(public int $websiteId, public array $hashes, public bool $announce = true) {}

    public function handle(\App\Core\Search\NewPages $pages): void
    {
        $pages->arrive($this->websiteId, $this->hashes, $this->announce);
    }
}
