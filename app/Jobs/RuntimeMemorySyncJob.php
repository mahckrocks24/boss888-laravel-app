<?php

namespace App\Jobs;

use App\Core\OwnerModel\RuntimeMemorySync;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** RFC-0023 P6: one debounced push of a workspace's owner memory to the runtime. */
class RuntimeMemorySyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(public int $wsId) {}

    public function handle(RuntimeMemorySync $sync): void
    {
        $sync->push($this->wsId);
    }
}
