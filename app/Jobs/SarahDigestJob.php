<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/** DIGEST-1: posts Sarah's one combined "finished work" message once the team goes quiet (see SarahDigest). */
class SarahDigestJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public int $workspaceId) {}

    public function handle(): void
    {
        $wait = app(\App\Core\Agents\SarahDigest::class)->flush($this->workspaceId);
        if ($wait > 0) {
            Cache::put('sarah-digest-armed:' . $this->workspaceId, 1, now()->addSeconds($wait + 30));
            self::dispatch($this->workspaceId)->delay(now()->addSeconds($wait));
        } else {
            Cache::forget('sarah-digest-armed:' . $this->workspaceId);
        }
    }
}
