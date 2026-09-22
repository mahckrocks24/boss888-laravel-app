<?php

namespace App\Core\Business;

use App\Core\Memory\WorkspaceMemoryService;
use Illuminate\Support\Facades\DB;

/**
 * Memory segregated per business (RFC-0011 U1): keys `biz:{id}:{key}` belong to one business; unprefixed keys are
 * PORTFOLIO (workspace) level and, while a workspace has one business, are that business's. Reads can be segregated
 * (one business, falling back to the portfolio) or combined (every business's value, keyed by name).
 * Wraps WorkspaceMemoryService — never bypasses it for writes.
 */
class BusinessMemory
{
    public function __construct(private WorkspaceMemoryService $memory, private BusinessProfileResolver $resolver) {}

    public static function key(?int $businessId, string $key): string
    {
        return $businessId ? config('business.memory_prefix', 'biz:') . $businessId . ':' . $key : $key;
    }

    /** One business's value; falls back to the portfolio value for the DEFAULT business only. */
    public function get(int $wsId, ?int $businessId, string $key): mixed
    {
        if ($businessId) {
            $v = self::unwrap($this->memory->get($wsId, self::key($businessId, $key)));
            if ($v !== null) { return $v; }
            $b = $this->resolver->find($wsId, $businessId);
            if (! $b || ! $b->is_default) { return null; }
        }
        return self::unwrap($this->memory->get($wsId, $key));
    }

    public function set(int $wsId, ?int $businessId, string $key, mixed $value, ?int $ttl = null): void
    {
        $this->memory->set($wsId, self::key($businessId, $key), $value, $ttl);
    }

    public function forget(int $wsId, ?int $businessId, string $key): void
    {
        $this->memory->forget($wsId, self::key($businessId, $key));
    }

    /** Every business's value for a key, keyed by business name (the default's may come from the portfolio level). */
    public function combined(int $wsId, string $key): array
    {
        $out = [];
        foreach ($this->resolver->forWorkspace($wsId) as $b) {
            $v = $this->get($wsId, (int) $b->id, $key);
            if ($v !== null) { $out[$b->name] = $v; }
        }
        return $out;
    }

    /** All keys of one business (prefix stripped), plus the portfolio keys for the default business. */
    public function segregated(int $wsId, int $businessId): array
    {
        $prefix = self::key($businessId, '');
        $rows = DB::table('workspace_memory')->where('workspace_id', $wsId)->where('key', 'like', str_replace(['%', '_'], ['\\%', '\\_'], $prefix) . '%')->get(['key', 'value_json']);
        $out = [];
        foreach ($rows as $r) { $out[substr($r->key, strlen($prefix))] = self::unwrap($r->value_json); }
        $b = $this->resolver->find($wsId, $businessId);
        if ($b && $b->is_default) {
            $portfolio = DB::table('workspace_memory')->where('workspace_id', $wsId)->where('key', 'not like', config('business.memory_prefix', 'biz:') . '%')->get(['key', 'value_json']);
            foreach ($portfolio as $r) { $out[$r->key] = $out[$r->key] ?? self::unwrap($r->value_json); }
        }
        return $out;
    }

    /** value_json holds either a bare JSON scalar/array (legacy writes) or {"value": …} (WorkspaceMemoryService::set). */
    public static function unwrap(mixed $raw): mixed
    {
        $v = $raw;
        if (is_string($raw)) { $d = json_decode($raw, true); $v = ($d === null && trim($raw) !== 'null') ? $raw : $d; } // an already-decoded string stays a string
        if (is_array($v) && array_keys($v) === ['value']) { return $v['value']; }
        return $v;
    }
}
