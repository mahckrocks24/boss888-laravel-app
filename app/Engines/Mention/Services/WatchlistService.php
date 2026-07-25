<?php

namespace App\Engines\Mention\Services;

use Illuminate\Support\Facades\DB;

/**
 * WatchlistService — CRUD for brand_watchlist rows.
 *
 * A watchlist row defines a single search query the MentionScanService
 * will run on a recurring schedule. Variants are alternate spellings the
 * scanner also tries (e.g. "LevelUp", "Level Up", "levelupgrowth.io").
 * Negative keywords filter false positives (e.g. exclude pages mentioning
 * "apple pie" when tracking the Apple brand).
 *
 * Lifecycle: rows are activated by default, can be deactivated to pause
 * scanning without losing history. Deleting cascades to brand_mentions
 * + brand_mention_scan_runs via FK.
 */
class WatchlistService
{
    public const SCOPES     = ['brand', 'product', 'person', 'hashtag', 'competitor'];
    public const PRIORITIES = ['low', 'normal', 'high'];

    public function create(int $wsId, array $data, ?int $userId = null): array
    {
        $term = trim((string) ($data['term'] ?? ''));
        if ($term === '') return ['success' => false, 'error' => 'term is required'];

        $label = trim((string) ($data['label'] ?? $term));
        $scope = (string) ($data['scope'] ?? 'brand');
        if (!in_array($scope, self::SCOPES, true)) {
            return ['success' => false, 'error' => "invalid scope: $scope"];
        }
        $priority = (string) ($data['priority'] ?? 'normal');
        if (!in_array($priority, self::PRIORITIES, true)) {
            return ['success' => false, 'error' => "invalid priority: $priority"];
        }

        $now = now();
        $id = DB::table('brand_watchlist')->insertGetId([
            'workspace_id'           => $wsId,
            'label'                  => mb_substr($label, 0, 200),
            'term'                   => mb_substr($term, 0, 200),
            'variants_json'          => isset($data['variants'])
                ? json_encode($this->normalizeStringList($data['variants'])) : null,
            'negative_keywords_json' => isset($data['negative_keywords'])
                ? json_encode($this->normalizeStringList($data['negative_keywords'])) : null,
            'scope'                  => $scope,
            'priority'               => $priority,
            'is_active'              => true,
            'created_by'             => $userId,
            'metadata_json'          => isset($data['metadata'])
                ? json_encode($data['metadata']) : null,
            'created_at'             => $now,
            'updated_at'             => $now,
        ]);
        return ['success' => true, 'watchlist_id' => $id, 'data' => $this->get($wsId, $id)['data'] ?? null];
    }

    public function get(int $wsId, int $id): array
    {
        $row = DB::table('brand_watchlist')
            ->where('id', $id)->where('workspace_id', $wsId)->first();
        if (!$row) return ['success' => false, 'error' => 'watchlist row not found'];
        return ['success' => true, 'data' => $this->hydrate($row)];
    }

    public function list(int $wsId, array $opts = []): array
    {
        $q = DB::table('brand_watchlist')->where('workspace_id', $wsId);

        if (isset($opts['is_active'])) {
            $q->where('is_active', (bool) $opts['is_active']);
        }
        if (!empty($opts['scope']) && in_array($opts['scope'], self::SCOPES, true)) {
            $q->where('scope', $opts['scope']);
        }
        if (!empty($opts['q'])) {
            $needle = '%' . str_replace(['%','_'], ['\%','\_'], (string) $opts['q']) . '%';
            $q->where(function ($w) use ($needle) {
                $w->where('label', 'like', $needle)
                  ->orWhere('term', 'like', $needle);
            });
        }

        $rows = $q->orderByDesc('id')->get();
        return [
            'success' => true,
            'count'   => $rows->count(),
            'data'    => $rows->map(fn($r) => $this->hydrate($r))->toArray(),
        ];
    }

    public function update(int $wsId, int $id, array $data): array
    {
        $allowed = ['label', 'term', 'priority', 'scope'];
        $update = array_intersect_key($data, array_flip($allowed));

        if (isset($update['scope']) && !in_array($update['scope'], self::SCOPES, true)) {
            return ['success' => false, 'error' => "invalid scope"];
        }
        if (isset($update['priority']) && !in_array($update['priority'], self::PRIORITIES, true)) {
            return ['success' => false, 'error' => "invalid priority"];
        }
        if (array_key_exists('variants', $data)) {
            $update['variants_json'] = $data['variants'] === null ? null
                : json_encode($this->normalizeStringList($data['variants']));
        }
        if (array_key_exists('negative_keywords', $data)) {
            $update['negative_keywords_json'] = $data['negative_keywords'] === null ? null
                : json_encode($this->normalizeStringList($data['negative_keywords']));
        }
        if (array_key_exists('metadata', $data)) {
            $update['metadata_json'] = $data['metadata'] === null ? null
                : json_encode($data['metadata']);
        }

        if (empty($update)) return ['success' => false, 'error' => 'no editable fields supplied'];

        $update['updated_at'] = now();
        $affected = DB::table('brand_watchlist')
            ->where('id', $id)->where('workspace_id', $wsId)
            ->update($update);
        if ($affected === 0) return ['success' => false, 'error' => 'watchlist row not found'];
        return $this->get($wsId, $id);
    }

    public function activate(int $wsId, int $id): array
    {
        return $this->setActive($wsId, $id, true);
    }

    public function deactivate(int $wsId, int $id): array
    {
        return $this->setActive($wsId, $id, false);
    }

    public function delete(int $wsId, int $id): array
    {
        $affected = DB::table('brand_watchlist')
            ->where('id', $id)->where('workspace_id', $wsId)->delete();
        return ['success' => $affected > 0];
    }

    /** Workspace-scoped count of active watchlist rows. Used for plan-tier caps. */
    public function countActive(int $wsId): int
    {
        return DB::table('brand_watchlist')
            ->where('workspace_id', $wsId)
            ->where('is_active', true)
            ->count();
    }

    private function setActive(int $wsId, int $id, bool $val): array
    {
        $affected = DB::table('brand_watchlist')
            ->where('id', $id)->where('workspace_id', $wsId)
            ->update(['is_active' => $val, 'updated_at' => now()]);
        if ($affected === 0) return ['success' => false, 'error' => 'watchlist row not found'];
        return $this->get($wsId, $id);
    }

    private function hydrate($row): array
    {
        $arr = (array) $row;
        $arr['variants']          = json_decode($arr['variants_json']          ?? '[]', true) ?: [];
        $arr['negative_keywords'] = json_decode($arr['negative_keywords_json'] ?? '[]', true) ?: [];
        $arr['metadata']          = json_decode($arr['metadata_json']          ?? '{}', true) ?: [];
        unset($arr['variants_json'], $arr['negative_keywords_json'], $arr['metadata_json']);
        $arr['is_active'] = (bool) $arr['is_active'];
        return $arr;
    }

    private function normalizeStringList($input): array
    {
        if (!is_array($input)) return [];
        $out = [];
        foreach ($input as $v) {
            $s = trim((string) $v);
            if ($s !== '' && !in_array($s, $out, true)) $out[] = mb_substr($s, 0, 100);
        }
        return array_slice($out, 0, 30);
    }
}