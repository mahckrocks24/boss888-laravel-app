<?php

namespace App\Core\OwnerModel;

use App\Connectors\RuntimeClient;
use App\Core\Awareness\BusinessFactsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0023 P6 ("runtime always", Owner 2026-10-02, closes DEC-0073 D3): Laravel stays the system of record for what Sarah
 * knows about an owner; the runtime always holds a current copy. Every change to the Owner Model (facts, confirmations,
 * dismissals, erasure), every repair episode and every business-facts recompute queues a push of one compact object -
 * `sarah_memory` - into the runtime's workspace memory (POST /internal/workspace-memory), where every prompt builder on the
 * runtime renders it (v2.37.18 owner-memory-format.js). The business profile fields are merged the same way. Erasure pushes
 * an emptied object with erased_at, so the owner's right to erase (D5) reaches the runtime within seconds.
 * Debounced per workspace (DEBOUNCE_SECONDS); nothing here blocks a chat turn.
 */
class RuntimeMemorySync
{
    public const VERSION = 1;
    public const DEBOUNCE_SECONDS = 45;
    public const FIELD = 'sarah_memory';

    public function __construct(private OwnerModelService $model, private BusinessFactsService $business) {}

    public static function enabled(): bool
    {
        return file_exists(storage_path('app/memory1.on')) && (string) env('RUNTIME_URL', '') !== '';
    }

    /** Queue a push for this workspace (debounced). `$now` pushes immediately (erasure). */
    public static function queue(int $wsId, bool $now = false): void
    {
        try {
            if (! self::enabled()) return;
            if ($now) { app(self::class)->push($wsId); return; }
            if (Cache::add('rtmemsync:' . $wsId, 1, self::DEBOUNCE_SECONDS)) \App\Jobs\RuntimeMemorySyncJob::dispatch($wsId)->delay(now()->addSeconds(20));
        } catch (\Throwable $e) { Log::info('[RT-MEMORY] queue skipped: ' . $e->getMessage()); }
    }

    /** The object the runtime renders. Public so the parity test and /api/memory/me can show exactly what was sent. */
    public function payload(int $wsId): array
    {
        $facts = DB::table('owner_model_facts')->where('workspace_id', $wsId)->where('status', 'confirmed')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->orderByDesc('last_confirmed_at')->orderByDesc('id')->limit(60)->get();
        $f = fn ($r) => ['v' => (string) $r->value, 'src' => (string) $r->source, 'date' => substr((string) ($r->last_confirmed_at ?: $r->first_seen_at ?: $r->created_at), 0, 10)];
        $owner = ['identity' => [], 'goals' => [], 'preferences' => [], 'interests' => [], 'wants' => [], 'behaviour' => []];
        $debts = [];
        foreach ($facts as $r) {
            if ($r->group === 'relationship') { if (str_starts_with((string) $r->key, 'debt_')) $debts[] = ['text' => (string) $r->value, 'since' => substr((string) $r->created_at, 0, 10)]; continue; }
            if (isset($owner[$r->group]) && is_array($owner[$r->group]) && $r->group !== 'behaviour') $owner[$r->group][] = $f($r);
        }
        foreach ($owner as $g => $rows) if (is_array($rows) && $g !== 'behaviour') $owner[$g] = array_slice($rows, 0, 12);
        try { $owner['behaviour'] = array_map('strval', $this->model->behaviour($wsId)); } catch (\Throwable) { $owner['behaviour'] = []; }

        $corr = [];
        try { foreach (DB::table('experience_owner_feedback')->where('workspace_id', $wsId)->where('created_at', '>=', now()->subDays(180))->orderByDesc('id')->limit(8)->get(['feedback_type', 'value', 'created_at']) as $fb) $corr[] = ['text' => trim(mb_substr((string) $fb->value, 0, 160)) . ' (' . strtolower(str_replace('_', ' ', (string) $fb->feedback_type)) . ')', 'date' => substr((string) $fb->created_at, 0, 10)]; } catch (\Throwable) {}

        $lessons = [];
        try { foreach (DB::table('outcome_ledger')->where('workspace_id', $wsId)->whereNotNull('lesson')->where('delivered_at', '>=', now()->subDays(90))->orderByDesc('delivered_at')->limit(8)->get(['kind', 'lesson', 'delivered_at']) as $l) $lessons[] = ['date' => substr((string) $l->delivered_at, 0, 10), 'kind' => (string) $l->kind, 'text' => mb_substr((string) $l->lesson, 0, 200)]; } catch (\Throwable) {}

        $openRepair = false; $cooldown = null;
        try { $openRepair = DB::table('repair_log')->where('workspace_id', $wsId)->where('status', 'open')->exists(); $s = json_decode((string) DB::table('workspaces')->where('id', $wsId)->value('settings_json'), true) ?: []; $cooldown = $s['sarah_cooldown_until'] ?? null; } catch (\Throwable) {}

        $erased = DB::table('memory_events')->where('workspace_id', $wsId)->where('kind', 'erased')->orderByDesc('id')->value('created_at');
        $anyLive = $facts->isNotEmpty() || $corr || $lessons;
        return [
            'version' => self::VERSION, 'synced_at' => now()->toIso8601String(), 'workspace_id' => $wsId,
            'erased_at' => ($erased && ! $anyLive) ? substr((string) $erased, 0, 19) : null,
            'owner' => $owner, 'corrections' => $corr, 'debts' => $debts, 'lessons' => $lessons,
            'open_repair' => $openRepair, 'cooldown_until' => $cooldown && strtotime((string) $cooldown) > time() ? $cooldown : null,
        ];
    }

    /** Push sarah_memory and the business profile for one workspace. Returns what happened (for logs and the command). */
    public function push(int $wsId, bool $force = false): array
    {
        if (! self::enabled()) return ['ok' => false, 'why' => 'disabled'];
        $rt = app(RuntimeClient::class);
        if (! $rt->isConfigured()) return ['ok' => false, 'why' => 'runtime not configured'];
        $out = ['ok' => false, 'ws' => $wsId];
        try {
            $payload = $this->payload($wsId);
            // P6b: unchanged memory is not re-sent (the 10-minute facts refresh touched every workspace: 1,070 pushes in one hour)
            $__h = md5(json_encode(array_diff_key($payload, ['synced_at' => 1]) + ['_biz' => $this->business->facts($wsId)]));
            if (! $force && \Illuminate\Support\Facades\Cache::get('rtmemsync:hash:' . $wsId) === $__h) return ['ok' => true, 'ws' => $wsId, 'skipped' => 'unchanged'];
            $r = $rt->post('/internal/workspace-memory', ['wsId' => $wsId, 'field' => self::FIELD, 'value' => $payload], 15);
            $out['memory_http'] = $r->status();
            $out['ok'] = $r->successful() && (bool) (($r->json() ?? [])['ok'] ?? false);
            // business profile: merge our four facts into whatever the runtime already holds (meetings fill the rest)
            try {
                $facts = $this->business->facts($wsId);
                $cur = ($r->json() ?? [])['memory']['business_profile'] ?? [];
                $cur = is_array($cur) ? $cur : [];
                $merge = array_filter(['name' => $facts['business_name'] ?? null, 'industry' => $facts['industry'] ?? null, 'location' => $facts['location'] ?? null, 'website' => $facts['domain'] ?? null]);
                if ($merge) { $r2 = $rt->post('/internal/workspace-memory', ['wsId' => $wsId, 'field' => 'business_profile', 'value' => $merge + $cur], 15); $out['profile_http'] = $r2->status(); }
            } catch (\Throwable $e) { $out['profile_error'] = mb_substr($e->getMessage(), 0, 120); }
            $size = mb_strlen(json_encode($payload, JSON_UNESCAPED_UNICODE));
            $this->model->event($wsId, 'runtime_synced', ['ok' => $out['ok'], 'http' => $out['memory_http'] ?? null, 'facts' => count($payload['owner']['goals']) + count($payload['owner']['preferences']) + count($payload['owner']['identity']) + count($payload['owner']['interests']) + count($payload['owner']['wants']), 'debts' => count($payload['debts']), 'lessons' => count($payload['lessons']), 'erased' => (bool) $payload['erased_at']], $size);
            if (! $out['ok']) Log::warning('[RT-MEMORY] push not accepted', $out);
            else \Illuminate\Support\Facades\Cache::put('rtmemsync:hash:' . $wsId, $__h, now()->addDays(7));
        } catch (\Throwable $e) {
            $out['error'] = mb_substr($e->getMessage(), 0, 200);
            Log::warning('[RT-MEMORY] push failed', $out);
            $this->model->event($wsId, 'runtime_sync_failed', ['error' => $out['error']]);
        }
        return $out;
    }

    /** Every workspace whose memory changed since `$since` (null = every workspace with a confirmed fact or a lesson). */
    public function pushChanged(?\DateTimeInterface $since = null, ?int $only = null): array
    {
        $ids = collect();
        if ($only) $ids = collect([$only]);
        else {
            $ids = $ids->merge(DB::table('owner_model_facts')->when($since, fn ($q) => $q->where('updated_at', '>=', $since))->distinct()->pluck('workspace_id'));
            $ids = $ids->merge(DB::table('outcome_ledger')->whereNotNull('lesson')->when($since, fn ($q) => $q->where('updated_at', '>=', $since))->distinct()->pluck('workspace_id'));
            $ids = $ids->merge(DB::table('repair_log')->when($since, fn ($q) => $q->where('updated_at', '>=', $since))->distinct()->pluck('workspace_id'));
        }
        $res = ['workspaces' => 0, 'ok' => 0, 'failed' => 0];
        foreach ($ids->unique()->values() as $ws) { $res['workspaces']++; $r = $this->push((int) $ws); $r['ok'] ? $res['ok']++ : $res['failed']++; }
        return $res;
    }
}
