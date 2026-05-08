<?php

namespace App\Engines\Builder\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * BuilderSnapshotService — undo/restore for page-level sections_json edits.
 *
 * Writes to the canvas_states table (page_id + sections_json + reason
 * columns added by 2026_05_08_193000 migration). Coexists with the
 * existing ManualEditService usage of canvas_states (which keys on
 * asset_id, not page_id).
 *
 * Used by BuilderService::updatePage() (auto-snapshot before+after every
 * mutation) and BuilderSnapshotController (restore + history endpoints).
 *
 * Pruning: the bootstrap/app.php scheduler runs pruneOldSnapshots(30)
 * weekly so canvas_states doesn't grow unbounded.
 */
class BuilderSnapshotService
{
    /**
     * Capture the current sections_json of a page into canvas_states.
     * Returns the new state ID. Throws if the page doesn't exist.
     */
    public function snapshot(int $pageId, string $reason = 'manual'): int
    {
        $page = DB::table('pages')->where('id', $pageId)->first();
        if (! $page) {
            throw new \RuntimeException("Page {$pageId} not found");
        }

        // Resolve workspace_id via the website join (canvas_states needs it).
        $wsId = (int) DB::table('websites')->where('id', $page->website_id)->value('workspace_id');

        return (int) DB::table('canvas_states')->insertGetId([
            'workspace_id'  => $wsId,
            'page_id'       => $pageId,
            'sections_json' => $page->sections_json,
            'reason'        => $reason,
            'state_json'    => '{}', // ManualEdit's NOT NULL column — unused for Builder snapshots.
            'status'        => 'draft',
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    /**
     * Restore a page to a previous sections_json snapshot.
     * Auto-snapshots the current state first (reason='pre_restore') so the
     * restore itself is undoable.
     */
    public function restore(int $pageId, int $stateId): void
    {
        $state = DB::table('canvas_states')
            ->where('id', $stateId)
            ->where('page_id', $pageId)
            ->first();
        if (! $state) {
            throw new \RuntimeException("Snapshot {$stateId} for page {$pageId} not found");
        }

        // Snapshot the current state before overwriting it.
        $this->snapshot($pageId, 'pre_restore');

        DB::table('pages')->where('id', $pageId)->update([
            'sections_json' => $state->sections_json,
            'updated_at'    => now(),
        ]);

        // Bust the published-site cache for any subdomain pointing at this website.
        $page = DB::table('pages')->where('id', $pageId)->first();
        if ($page) {
            $website = DB::table('websites')->where('id', $page->website_id)->first();
            if ($website && $website->subdomain) {
                $sub = explode('.', (string) $website->subdomain)[0];
                Cache::forget("published_site:{$sub}");
                Cache::forget("published_site:{$sub}:home");
                Cache::forget("published_site:{$sub}:" . ($page->slug ?? 'home'));
            }
        }
    }

    /**
     * Most-recent snapshots for a page (newest first).
     */
    public function history(int $pageId, int $limit = 20): array
    {
        return DB::table('canvas_states')
            ->where('page_id', $pageId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'reason', 'created_at'])
            ->toArray();
    }

    /**
     * Delete page-level snapshots older than $keepDays. Returns deleted count.
     * ManualEdit canvas states (page_id IS NULL) are NOT touched.
     */
    public function pruneOldSnapshots(int $keepDays = 30): int
    {
        return (int) DB::table('canvas_states')
            ->whereNotNull('page_id')
            ->where('created_at', '<', now()->subDays($keepDays))
            ->delete();
    }
}
