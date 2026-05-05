<?php

namespace App\Engines\Creative\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * BlueprintRetrieverService — Creative888 Blueprint Retrieval
 *
 * Ported from WP class-lucreative-blueprint-retriever.php (Phase 2C).
 *
 * Fetches recent stored blueprints from the creative_blueprints table.
 * Matching priority:
 *   1. Same layout AND same subject_type (best match)
 *   2. Same subject_type only
 *   3. Most recent (fallback)
 *
 * NEVER throws — returns empty array on any failure.
 */
class BlueprintRetrieverService
{
    const TABLE = 'creative_blueprints';

    /**
     * Fetch the most relevant recent blueprints for a given analysis context.
     *
     * @param  int   $workspaceId  Workspace scope.
     * @param  array $analysis     Analysis data (used for matching: layout, dominant_subject).
     * @param  int   $limit        Max blueprints to return (default 3, max 10).
     * @return array Decoded blueprint objects with computed scores. Empty on failure.
     */
    public function getRelevant(int $workspaceId, array $analysis, int $limit = 3): array
    {
        try {
            if (!DB::getSchemaBuilder()->hasTable(self::TABLE)) {
                return [];
            }

            $limit  = max(1, min(10, $limit));
            $layout = strtolower(trim($analysis['layout'] ?? ''));
            $subj   = strtolower(trim($analysis['dominant_subject'] ?? ''));

            $columns = ['id', 'blueprint_json', 'score', 'usage_count', 'last_used_at', 'external_score', 'external_count', 'created_at'];

            // Best match: layout + subject
            $rows = [];
            if (!empty($layout) && $layout !== 'unknown' && !empty($subj) && $subj !== 'general') {
                $rows = DB::table(self::TABLE)
                    ->where('workspace_id', $workspaceId)
                    ->where('source_type', '!=', 'ba888')
                    ->where('layout', $layout)
                    ->where('subject_type', $subj)
                    ->orderByDesc('score')
                    ->orderByDesc('created_at')
                    ->limit($limit)
                    ->get($columns)
                    ->toArray();
            }

            // Fallback: subject only
            if (empty($rows) && !empty($subj) && $subj !== 'general') {
                $rows = DB::table(self::TABLE)
                    ->where('workspace_id', $workspaceId)
                    ->where('source_type', '!=', 'ba888')
                    ->where('subject_type', $subj)
                    ->orderByDesc('score')
                    ->orderByDesc('created_at')
                    ->limit($limit)
                    ->get($columns)
                    ->toArray();
            }

            // Fallback: most recent
            if (empty($rows)) {
                $rows = DB::table(self::TABLE)
                    ->where('workspace_id', $workspaceId)
                    ->where('source_type', '!=', 'ba888')
                    ->orderByDesc('score')
                    ->orderByDesc('created_at')
                    ->limit($limit)
                    ->get($columns)
                    ->toArray();
            }

            if (empty($rows)) {
                return [];
            }

            // Decode, noise-filter, score, and sort
            $scoreEngine = app(ScoreEngineService::class);
            $blueprints  = [];
            $nowTs       = time();

            foreach ($rows as $row) {
                $row = (array) $row;
                $bp = json_decode($row['blueprint_json'], true);
                if (!is_array($bp) || empty($bp)) {
                    continue;
                }

                $bp['_id']          = (int) $row['id'];
                $bp['_score']       = (float) $row['score'];
                $bp['_usage_count'] = (int) $row['usage_count'];
                $bp['_last_used']   = (string) ($row['last_used_at'] ?? '');

                // Noise filter: exclude zero-use blueprints older than 7 days
                if ($bp['_usage_count'] === 0) {
                    $createdTs = !empty($row['created_at']) ? strtotime($row['created_at']) : 0;
                    if ($createdTs > 0 && ($nowTs - $createdTs) >= 604800) {
                        continue;
                    }
                }

                // Compute dynamic runtime score
                $bp['_external_score'] = (float) ($row['external_score'] ?? 0.0);
                $bp['_external_count'] = (int) ($row['external_count'] ?? 0);
                $bp['_computed_score'] = $scoreEngine->computeScore(
                    $bp['_usage_count'],
                    $bp['_last_used'],
                    $bp['_external_score'],
                    $bp['_external_count']
                );

                $blueprints[] = $bp;
            }

            // Sort by computed score descending
            if (count($blueprints) > 1) {
                usort($blueprints, fn ($a, $b) => $b['_computed_score'] <=> $a['_computed_score']);
            }

            return $blueprints;

        } catch (\Throwable $e) {
            Log::warning('[CREATIVE888 BlueprintRetriever] Exception: ' . $e->getMessage());
            return [];
        }
    }
}
