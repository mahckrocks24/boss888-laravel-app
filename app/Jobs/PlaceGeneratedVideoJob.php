<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * STUDIO → ARTHUR (2026-09-14). A customer asked Arthur for a video; the studio (creative/generate_video, MiniMax)
 * is rendering it and `video:finalize-pending` drives the asset to completed every minute. This job only WATCHES
 * that asset and, once it is complete, asks Arthur to place the clip on the home page. It never talks to the
 * provider and never charges anything: the kernel charged 8 credits at kickoff and refunds on failure.
 */
class PlaceGeneratedVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 30 checks, 30 s apart ≈ 15 minutes, then it gives up and says so in the record. */
    public int $tries = 30;
    public int $timeout = 120;

    public function __construct(public int $wsId, public int $websiteId, public int $assetId, public string $prompt = '') {}

    public function handle(): void
    {
        if ($this->assetId <= 0 && $this->prompt !== '') {
            // Approval flow: the asset is created only after someone approves. Adopt it by prompt once it exists.
            $found = DB::table('assets')->where('workspace_id', $this->wsId)->where('type', 'video')->where('prompt', $this->prompt)
                ->whereNull('deleted_at')->orderByDesc('id')->value('id');
            if (! $found) {
                if ($this->attempts() >= $this->tries) { $this->notePending('never_approved'); return; }
                $this->release(60);
                return;
            }
            $this->assetId = (int) $found;
            try {
                $tv = json_decode((string) (DB::table('websites')->where('id', $this->websiteId)->value('template_variables') ?: '{}'), true) ?: [];
                if (isset($tv['pending_video']) && is_array($tv['pending_video'])) { $tv['pending_video']['asset_id'] = $this->assetId; $tv['pending_video']['status'] = 'rendering'; DB::table('websites')->where('id', $this->websiteId)->update(['template_variables' => json_encode($tv)]); }
            } catch (\Throwable $e) {}
        }
        $asset = DB::table('assets')->where('id', $this->assetId)->where('workspace_id', $this->wsId)->first();
        if (! $asset) {
            Log::warning('[Arthur] video job: asset missing', ['website' => $this->websiteId, 'asset' => $this->assetId]);
            $this->notePending('missing');
            return;
        }
        if ($asset->status === 'completed' && ! empty($asset->url)) {
            $r = app(\App\Engines\Builder\Services\ArthurService::class)->placeVideoOnSite($this->wsId, $this->websiteId, (string) $asset->url, 'Watch our story');
            Log::info('[Arthur] video job placed', ['website' => $this->websiteId, 'asset' => $this->assetId, 'ok' => (bool) ($r['success'] ?? false), 'error' => $r['error'] ?? null]);
            if (empty($r['success'])) { $this->notePending('place_failed:' . (string) ($r['error'] ?? '')); }
            return;
        }
        if (in_array((string) $asset->status, ['failed', 'cancelled', 'error'], true)) {
            Log::warning('[Arthur] video job: generation failed', ['website' => $this->websiteId, 'asset' => $this->assetId, 'status' => $asset->status]);
            $this->notePending('failed');
            return;
        }
        if ($this->attempts() >= $this->tries) {
            Log::warning('[Arthur] video job: gave up waiting', ['website' => $this->websiteId, 'asset' => $this->assetId, 'status' => $asset->status]);
            $this->notePending('timeout');
            return;
        }
        $this->release(30);
    }

    private function notePending(string $status): void
    {
        try {
            $tv = json_decode((string) (DB::table('websites')->where('id', $this->websiteId)->value('template_variables') ?: '{}'), true) ?: [];
            if (isset($tv['pending_video']) && is_array($tv['pending_video'])) {
                $tv['pending_video']['status'] = $status;
                $tv['pending_video']['noted_at'] = now()->toIso8601String();
                DB::table('websites')->where('id', $this->websiteId)->update(['template_variables' => json_encode($tv), 'updated_at' => now()]);
            }
        } catch (\Throwable $e) {
            Log::warning('[Arthur] video job: could not note status: ' . $e->getMessage());
        }
    }
}
