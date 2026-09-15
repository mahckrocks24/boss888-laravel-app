<?php

namespace App\Jobs;

use App\Engines\Builder\Support\SiteThumbnail;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Re-shoots a site's card thumbnail after a deploy. Unique per site for 20 s so a burst of edits yields one render. */
class GenerateSiteThumbnailJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;
    public int $uniqueFor = 20;

    public function __construct(public int $websiteId)
    {
        $this->onQueue('tasks-low');
    }

    public function uniqueId(): string { return 'site-thumb-' . $this->websiteId; }

    public function handle(): void
    {
        SiteThumbnail::generate($this->websiteId);
    }
}
