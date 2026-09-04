<?php

namespace App\Engines\Publisher\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PUBLISHER888 Unit 2 — append-only audit trail for every mutating desk action.
 * Context (who/where) is set once per request by DeskContext; services call record().
 * Never throws: an audit failure is logged, never blocks the business action.
 */
class DeskAudit
{
    /** Request-scoped context. Static so the middleware and every service resolved later share it without a container binding. */
    private static array $ctx = [];

    public function setContext(array $ctx): void { self::$ctx = $ctx; }
    public function context(): array { return self::$ctx; }
    public function requestId(): string { return (string) (self::$ctx['request_id'] ?? ''); }

    public static function newRequestId(?string $incoming): string
    {
        $incoming = trim((string) $incoming);
        return preg_match('/^[A-Za-z0-9_\-]{8,36}$/', $incoming) ? $incoming : (string) Str::uuid();
    }

    /** @param array|object|null $before  @param array|object|null $after */
    public function record(string $action, ?string $entityType = null, ?int $entityId = null, ?string $summary = null, $before = null, $after = null): void
    {
        try {
            if (empty(self::$ctx['website_id'])) return;
            DB::table('desk_audit_log')->insert([
                'workspace_id' => (int) (self::$ctx['workspace_id'] ?? 0), 'website_id' => (int) self::$ctx['website_id'],
                'user_id' => self::$ctx['user_id'] ?? null, 'role' => self::$ctx['role'] ?? null,
                'action' => mb_substr($action, 0, 64), 'entity_type' => $entityType ? mb_substr($entityType, 0, 32) : null, 'entity_id' => $entityId,
                'summary' => $summary !== null ? mb_substr($summary, 0, 255) : null,
                'before_json' => $before === null ? null : json_encode(self::slim($before), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'after_json' => $after === null ? null : json_encode(self::slim($after), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR),
                'ip' => self::$ctx['ip'] ?? null, 'user_agent' => isset(self::$ctx['user_agent']) ? mb_substr((string) self::$ctx['user_agent'], 0, 255) : null,
                'request_id' => self::$ctx['request_id'] ?? null, 'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('desk.audit.failed', ['action' => $action, 'error' => $e->getMessage(), 'request_id' => self::$ctx['request_id'] ?? null]);
        }
    }

    /** Keep audit rows small: drop bodies, cap strings, never store secrets. */
    private static function slim($v): array
    {
        $a = is_object($v) ? (array) $v : (array) $v;
        $out = [];
        foreach ($a as $k => $val) {
            if (in_array($k, ['content', 'description', 'password', 'token', 'refresh_token', 'brief_json', 'sections_json', 'metadata_json'], true)) { $out[$k] = is_string($val) ? '[' . strlen($val) . ' chars]' : '[omitted]'; continue; }
            if (is_string($val)) $out[$k] = mb_substr($val, 0, 200);
            elseif (is_scalar($val) || $val === null) $out[$k] = $val;
            elseif (is_array($val)) $out[$k] = array_slice($val, 0, 20);
        }
        return $out;
    }

    public function list(int $websiteId, array $f = []): array
    {
        $q = DB::table('desk_audit_log as a')->leftJoin('users as u', 'u.id', '=', 'a.user_id')->where('a.website_id', $websiteId);
        if (!empty($f['entity_type'])) $q->where('a.entity_type', $f['entity_type']);
        if (!empty($f['entity_id'])) $q->where('a.entity_id', (int) $f['entity_id']);
        if (!empty($f['user_id'])) $q->where('a.user_id', (int) $f['user_id']);
        if (!empty($f['action'])) $q->where('a.action', 'like', $f['action'] . '%');
        $total = (clone $q)->count(); $limit = max(1, min(200, (int) ($f['limit'] ?? 50))); $offset = max(0, (int) ($f['offset'] ?? 0));
        $rows = $q->orderByDesc('a.id')->offset($offset)->limit($limit)->get(['a.id', 'a.user_id', 'u.name as user_name', 'u.email as user_email', 'a.role', 'a.action', 'a.entity_type', 'a.entity_id', 'a.summary', 'a.before_json', 'a.after_json', 'a.ip', 'a.request_id', 'a.created_at']);
        return ['success' => true, 'items' => $rows->map(fn ($r) => ['id' => (int) $r->id, 'user' => ['id' => $r->user_id, 'name' => $r->user_name, 'email' => $r->user_email], 'role' => $r->role, 'action' => $r->action, 'entity_type' => $r->entity_type, 'entity_id' => $r->entity_id, 'summary' => $r->summary,
            'before' => is_string($r->before_json) ? json_decode($r->before_json, true) : null, 'after' => is_string($r->after_json) ? json_decode($r->after_json, true) : null, 'ip' => $r->ip, 'request_id' => $r->request_id, 'created_at' => $r->created_at])->all(),
            'total' => $total, 'offset' => $offset, 'limit' => $limit, 'has_more' => $offset + $rows->count() < $total];
    }
}
