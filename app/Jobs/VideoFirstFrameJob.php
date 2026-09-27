<?php

namespace App\Jobs;

use App\Engines\Creative\Services\ScenePlannerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/** VIDEO-2: a vertical/square video — make its first frame in that shape, then hand it to the provider to animate. */
class VideoFirstFrameJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 240;

    public function __construct(public int $jobId) { $this->onQueue('default'); }

    public function handle(ScenePlannerService $planner): void
    {
        try {
            $planner->dispatchWithFrame($this->jobId);
        } catch (\Throwable $e) {
            DB::table('creative_video_jobs')->where('id', $this->jobId)->where('status', 'framing')->update(['status' => 'failed', 'error' => mb_substr('first frame: ' . $e->getMessage(), 0, 250), 'updated_at' => now()]);
            Log::warning('[VIDEO-2] first frame job failed', ['job' => $this->jobId, 'e' => $e->getMessage()]);
        }
    }

    public function failed(\Throwable $e): void
    {
        DB::table('creative_video_jobs')->where('id', $this->jobId)->where('status', 'framing')->update(['status' => 'failed', 'error' => 'first frame timed out', 'updated_at' => now()]);
    }
}
