<?php

namespace App\Jobs;

use App\Core\Brand\BrandIntakeService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BRAND-B1 (RFC-0017 5d): runs after the owner's message in Sarah's thread. It waits for Sarah's own reply to that
 * message first, so her brand question or summary card always comes after her answer, never in the middle.
 */
class BrandIntakeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 14;
    public int $timeout = 240;

    public function __construct(public int $wsId, public int $userMessageId) { $this->onQueue('default'); }

    public function handle(BrandIntakeService $svc): void
    {
        $answered = DB::table('agent_messages')->where('workspace_id', $this->wsId)->where('agent_slug', 'sarah')->where('role', 'agent')
            ->where('id', '>', $this->userMessageId)
            ->where(function ($q) { $q->whereNull('metadata_json')->orWhereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.phase')), '') <> 'ack'"); })
            ->exists();
        $newer = DB::table('agent_messages')->where('workspace_id', $this->wsId)->where('role', 'user')->where('id', '>', $this->userMessageId)->exists();
        if (! $answered && ! $newer && $this->attempts() < $this->tries) { $this->release(10); return; }
        try {
            $out = $svc->handle($this->wsId, $this->userMessageId);
            if ($out !== 'nothing') Log::info('[BRAND-B1] intake', ['ws' => $this->wsId, 'msg' => $this->userMessageId, 'result' => $out]);
        } catch (\Throwable $e) {
            Log::warning('[BRAND-B1] intake failed', ['ws' => $this->wsId, 'msg' => $this->userMessageId, 'e' => $e->getMessage()]);
        }
    }
}
