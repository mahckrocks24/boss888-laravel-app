<?php

namespace App\Jobs;

use App\Core\OwnerModel\OwnerModelExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0023 P1: after the owner's message in Sarah's thread, the stated lane runs (rules + one small model call) and
 * Sarah echoes what she kept in one short line AFTER her own reply. Off the chat critical path; waits for her reply
 * like BrandIntakeJob does. Kill switch: storage/app/memory1.on absent = no extraction, no echo.
 */
class OwnerModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 12;
    public int $timeout = 120;

    public function __construct(public int $wsId, public int $userMessageId, public ?int $bizId = null) { $this->onQueue('default'); }

    public function handle(OwnerModelExtractor $extractor): void
    {
        if (! file_exists(storage_path('app/memory1.on'))) return;
        $msg = DB::table('agent_messages')->where('id', $this->userMessageId)->where('workspace_id', $this->wsId)->where('role', 'user')->first();
        if (! $msg) return;
        $answered = DB::table('agent_messages')->where('workspace_id', $this->wsId)->where('agent_slug', 'sarah')->where('role', 'agent')->where('id', '>', $this->userMessageId)
            ->where(function ($q) { $q->whereNull('metadata_json')->orWhereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.phase')), '') <> 'ack'"); })->exists();
        $newer = DB::table('agent_messages')->where('workspace_id', $this->wsId)->where('role', 'user')->where('id', '>', $this->userMessageId)->exists();
        if (! $answered && ! $newer && $this->attempts() < $this->tries) { $this->release(10); return; }
        try {
            if (! $this->bizId) { try { $__bc = app(\App\Core\Business\BusinessContext::class)->resolve($this->wsId, (string) $msg->content, ''); $this->bizId = (int) ($__bc['business_id'] ?? 0) ?: null; } catch (\Throwable) {} }
            $prev = (string) (DB::table('agent_messages')->where('workspace_id', $this->wsId)->where('agent_slug', 'sarah')->where('role', 'agent')->where('id', '<', $this->userMessageId)->orderByDesc('id')->value('content') ?? '');
            $written = $extractor->run($this->wsId, $this->bizId, $this->userMessageId, (string) $msg->content, $prev);
            if (! $written) return;
            // one echo per turn, only when something durable was kept or a guess was made; never during a repair cool-down (P3 hook)
            $line = OwnerModelExtractor::echoLine($written);
            if ($line === null) return;
            $recent = DB::table('agent_messages')->where('workspace_id', $this->wsId)->where('role', 'agent')->where('created_at', '>=', now()->subMinutes(10))
                ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.notification_type')) = 'owner_fact'")->exists();
            if ($recent) return;
            app(\App\Core\Agents\AgentMessageService::class)->postAsAgent($this->wsId, 'sarah', $line, ['notification_type' => 'owner_fact', 'card' => ['type' => 'owner_fact', 'fact_ids' => array_values(array_map(fn ($w) => (int) $w['id'], $written)), 'business_id' => $this->bizId]]);
            Log::info('[OWNER-MODEL] kept', ['ws' => $this->wsId, 'msg' => $this->userMessageId, 'n' => count($written)]);
        } catch (\Throwable $e) {
            Log::warning('[OWNER-MODEL] job failed', ['ws' => $this->wsId, 'msg' => $this->userMessageId, 'e' => $e->getMessage()]);
        }
    }
}
