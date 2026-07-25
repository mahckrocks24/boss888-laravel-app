<?php

namespace App\Engines\Web\Services;

use App\Connectors\RuntimeClient;
use App\Core\Billing\CreditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 2026-05-24 — Single chokepoint for every external-web touch.
 *
 * Every agent web action (fetch URL, search the web) goes through one
 * of these two methods. Each call: capability gate → daily-cap → audit
 * log insert → runtime call → audit log update → credit commit/release.
 *
 * Architecture rule (hands-vs-brain):
 *   - Laravel HERE: gates, audits, charges, surfaces the activity log.
 *   - Runtime: performs the HTTP fetch + HTML parse + SERP scrape.
 *
 * Transparency:
 *   Every row in `agent_web_activity` is the single source of truth.
 *   `GET /api/web/activity` exposes this read-only to the FE so the
 *   user can see exactly what every agent has done online.
 */
class WebActivityService
{
    public const DAILY_CAP_FETCH  = 50;
    public const DAILY_CAP_SEARCH = 25;

    public const COST_FETCH  = 1;
    public const COST_SEARCH = 2;

    // LAUNCH SCOPE (2026-07-20) — removed social/email specialists (leo, maya,
    // chris, marcus, zara, tyler, zoe, jordan, kai, vera) are no longer allowed to
    // call the web scanner. Retained launch agents + special-purpose personas only.
    public const ALLOWED_AGENTS = [
        'dmm', 'sarah', 'james', 'alex', 'diana', 'ryan', 'sofia',
        'priya', 'nora', 'elena', 'max',
        // Special-purpose personas
        'arthur', 'bella', 'assistant',
    ];

    public function __construct(
        private RuntimeClient $runtime,
        private CreditService $credits,
    ) {}

    /**
     * Fetch a single URL via the runtime's /internal/scanner endpoint.
     * Returns the structured page (title, meta, headings, body, links).
     */
    public function fetch(int $wsId, string $agentSlug, ?int $userId, string $url, ?int $taskId = null): array
    {
        return $this->execute($wsId, $agentSlug, $userId, $taskId, 'fetch', $url, self::COST_FETCH, self::DAILY_CAP_FETCH, function () use ($url, $wsId, $agentSlug) {
            $resp = $this->runtime->post('/internal/scanner', [
                'url'          => $url,
                'workspace_id' => $wsId,
                'agent_slug'   => $agentSlug,
            ], 15);
            if (!$resp->successful()) {
                throw new \RuntimeException('scanner HTTP ' . $resp->status() . ': ' . mb_substr($resp->body(), 0, 200));
            }
            return $resp->json() ?? [];
        });
    }

    /**
     * Search the web via runtime /internal/agent-search. Backend is
     * DuckDuckGo HTML scrape by default; swappable via WEB_SEARCH_BACKEND
     * env on the runtime side.
     */
    public function search(int $wsId, string $agentSlug, ?int $userId, string $query, ?int $taskId = null): array
    {
        return $this->execute($wsId, $agentSlug, $userId, $taskId, 'search', $query, self::COST_SEARCH, self::DAILY_CAP_SEARCH, function () use ($query, $wsId, $agentSlug) {
            $resp = $this->runtime->post('/internal/agent-search', [
                'query'        => $query,
                'workspace_id' => $wsId,
                'agent_slug'   => $agentSlug,
            ], 15);
            if (!$resp->successful()) {
                throw new \RuntimeException('search HTTP ' . $resp->status() . ': ' . mb_substr($resp->body(), 0, 200));
            }
            return $resp->json() ?? [];
        });
    }

    /**
     * Shared pipeline: gate → log insert → runtime call → log update.
     * Returns the structured payload to the caller (engine service).
     */
    private function execute(int $wsId, string $agentSlug, ?int $userId, ?int $taskId, string $action, string $target, int $cost, int $dailyCap, \Closure $runtimeCall): array
    {
        $t0 = microtime(true);

        // Capability gate
        if (!in_array($agentSlug, self::ALLOWED_AGENTS, true)) {
            return $this->logBlocked($wsId, $agentSlug, $userId, $taskId, $action, $target, "agent '{$agentSlug}' not in ALLOWED_AGENTS");
        }

        // Daily cap
        $today = DB::table('agent_web_activity')
            ->where('workspace_id', $wsId)
            ->where('action', $action)
            ->where('status', 'ok')
            ->where('created_at', '>=', now()->startOfDay())
            ->count();
        if ($today >= $dailyCap) {
            return $this->logBlocked($wsId, $agentSlug, $userId, $taskId, $action, $target, "daily cap reached ({$today}/{$dailyCap})", 'capped');
        }

        // Credit gate + reserve
        if (!$this->credits->hasBalance($wsId, $cost)) {
            return $this->logBlocked($wsId, $agentSlug, $userId, $taskId, $action, $target, "insufficient credits (need {$cost})");
        }
        $reservationRef = $this->credits->reserve($wsId, $cost, "web.{$action}");

        // Audit row — pending. 2026-05-27 — task_id links this row to the
        // research task that triggered it, so the task drawer can show every
        // URL the agent touched during its execution.
        $rowId = DB::table('agent_web_activity')->insertGetId([
            'workspace_id'    => $wsId,
            'agent_slug'      => $agentSlug,
            'task_id'         => $taskId,
            'user_id'         => $userId,
            'action'          => $action,
            'url_or_query'    => mb_substr($target, 0, 2048),
            'status'          => 'pending',
            'cost_credits'    => $cost,
            'reservation_ref' => $reservationRef,
            'fetched_at'      => now(),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        try {
            $resp = $runtimeCall();
            $duration = (int) round((microtime(true) - $t0) * 1000);

            if (empty($resp['success']) && empty($resp['result'])) {
                $err = (string) ($resp['error'] ?? 'runtime call returned no data');
                $this->markFailed($rowId, $reservationRef, $wsId, $err, $duration);
                return ['success' => false, 'error' => $err, 'activity_id' => $rowId];
            }

            $payload = is_array($resp) ? $resp : [];

            // Extract title from whichever shape the runtime returned.
            //   scanner  : og_data.title | blueprint.brand_name | first h1
            //   search   : query (for the activity row) + results[]
            $title = '';
            if (!empty($payload['og_data']['title'])) {
                $title = (string) $payload['og_data']['title'];
            } elseif (!empty($payload['blueprint']['brand_name'])) {
                $title = (string) $payload['blueprint']['brand_name'];
            } elseif (!empty($payload['headings'][0]['text'])) {
                $title = (string) $payload['headings'][0]['text'];
            } elseif (!empty($payload['query'])) {
                $title = 'search: ' . $payload['query'];
            }

            // Build a useful body representation for the audit log preview.
            //   scanner  : headings + ctas + og description
            //   search   : list of result titles + snippets
            $bodyParts = [];
            if (!empty($payload['og_data']['description'])) $bodyParts[] = (string) $payload['og_data']['description'];
            foreach (($payload['headings'] ?? []) as $h) {
                if (is_array($h) && !empty($h['text'])) $bodyParts[] = '[' . ($h['level'] ?? 'h') . '] ' . $h['text'];
            }
            foreach (($payload['ctas'] ?? []) as $cta) {
                if (is_string($cta)) $bodyParts[] = 'CTA: ' . $cta;
            }
            foreach (($payload['results'] ?? []) as $r) {
                if (is_array($r)) {
                    $bodyParts[] = ($r['title'] ?? '') . ' — ' . ($r['snippet'] ?? '') . ' (' . ($r['url'] ?? '') . ')';
                }
            }
            $body    = implode("\n", $bodyParts);
            $preview = mb_substr($body, 0, 500);
            $length  = mb_strlen($body);
            $hash    = $length > 0 ? hash('sha256', $body) : null;

            DB::table('agent_web_activity')->where('id', $rowId)->update([
                'status'             => 'ok',
                'title'              => $title !== '' ? mb_substr($title, 0, 512) : null,
                'response_preview'   => $preview,
                'content_length'     => $length,
                'content_hash'       => $hash,
                'duration_ms'        => $duration,
                'response_data_json' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'updated_at'         => now(),
            ]);

            $this->credits->commit($wsId, $reservationRef, $cost);

            // 2026-05-27 — research audit trail. Persists to the default
            // log channel with a [research] tag so `grep '\[research\]'` in
            // laravel.log gives a per-task chronological replay. The DB row
            // in agent_web_activity is the primary audit record; the log
            // line is for compliance / incident-response convenience.
            Log::info('[research] web.' . $action . ' ok', [
                'workspace_id' => $wsId,
                'agent_slug'   => $agentSlug,
                'task_id'      => $taskId,
                'activity_id'  => $rowId,
                'target'       => mb_substr($target, 0, 256),
                'title'        => mb_substr((string) $title, 0, 200),
                'duration_ms'  => $duration,
                'cost_credits' => $cost,
                'bytes'        => $length,
            ]);

            return [
                'success'     => true,
                'activity_id' => $rowId,
                'duration_ms' => $duration,
                'cost'        => $cost,
                'data'        => $payload,
            ];
        } catch (\Throwable $e) {
            $duration = (int) round((microtime(true) - $t0) * 1000);
            $this->markFailed($rowId, $reservationRef, $wsId, $e->getMessage(), $duration);
            Log::warning('[WebActivity] runtime call threw', [
                'workspace_id' => $wsId, 'agent' => $agentSlug, 'action' => $action,
                'error' => $e->getMessage(),
            ]);
            return ['success' => false, 'error' => $e->getMessage(), 'activity_id' => $rowId];
        }
    }

    private function logBlocked(int $wsId, string $agentSlug, ?int $userId, ?int $taskId, string $action, string $target, string $reason, string $status = 'blocked'): array
    {
        DB::table('agent_web_activity')->insert([
            'workspace_id' => $wsId,
            'agent_slug'   => $agentSlug,
            'task_id'      => $taskId,
            'user_id'      => $userId,
            'action'       => $action,
            'url_or_query' => mb_substr($target, 0, 2048),
            'status'       => $status,
            'error'        => $reason,
            'cost_credits' => 0,
            'fetched_at'   => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        return ['success' => false, 'error' => $reason, 'status' => $status];
    }

    private function markFailed(int $rowId, ?string $reservationRef, int $wsId, string $error, int $duration): void
    {
        DB::table('agent_web_activity')->where('id', $rowId)->update([
            'status'      => 'error',
            'error'       => mb_substr($error, 0, 1000),
            'duration_ms' => $duration,
            'updated_at'  => now(),
        ]);
        if ($reservationRef) {
            try { $this->credits->release($wsId, $reservationRef); } catch (\Throwable $e) {}
        }
    }

    /**
     * Activity rows linked to a specific research task. Backs the "Web
     * Activity" drawer tab for category=research tasks. Returns a compact
     * shape suitable for direct rendering — url, title, status, duration,
     * preview snippet.
     */
    public function forTask(int $taskId, int $limit = 50): array
    {
        return DB::table('agent_web_activity')
            ->where('task_id', $taskId)
            ->orderBy('id')
            ->limit($limit)
            ->get([
                'id', 'agent_slug', 'action', 'url_or_query', 'title',
                'status', 'duration_ms', 'cost_credits', 'content_length',
                'response_preview', 'error', 'fetched_at',
            ])
            ->map(fn($r) => (array) $r)
            ->toArray();
    }

    /**
     * Transparency view — list activity for a workspace, filterable by
     * agent + date + task. Backs `GET /api/web/activity`.
     */
    public function listActivity(int $wsId, ?string $agentSlug = null, ?string $since = null, int $limit = 100, ?int $taskId = null): array
    {
        $q = DB::table('agent_web_activity')->where('workspace_id', $wsId);
        if ($agentSlug) $q->where('agent_slug', $agentSlug);
        if ($since)     $q->where('created_at', '>=', $since);
        if ($taskId)    $q->where('task_id', $taskId);

        return $q->orderByDesc('id')
            ->limit(min($limit, 500))
            ->get([
                'id', 'agent_slug', 'user_id', 'action', 'url_or_query',
                'status', 'title', 'response_preview', 'content_length',
                'duration_ms', 'cost_credits', 'error', 'created_at',
            ])
            ->toArray();
    }
}
