<?php

namespace App\Core\Intelligence;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * WorkspaceKnowledgeBase — cross-agent shared memory.
 *
 * When James completes an SEO audit, the result lands here. When Priya
 * is later asked to write content, she sees James's audit summary in her
 * system prompt. Each entry expires (default 30 days) so old findings
 * don't pollute future prompts.
 *
 * Patched 2026-05-10 (Phase 2H).
 */
class WorkspaceKnowledgeBase
{
    public function store(
        int $wsId,
        string $sourceAgent,
        string $type,
        string $title,
        array $data,
        ?int $expiresInDays = 30
    ): int {
        return DB::table('workspace_knowledge')->insertGetId([
            'workspace_id'    => $wsId,
            'source_agent'    => $sourceAgent,
            'knowledge_type'  => $type,
            'title'           => mb_substr($title, 0, 500),
            'data'            => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'relevance_score' => 1.000,
            'expires_at'      => $expiresInDays ? now()->addDays($expiresInDays) : null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    /**
     * Returns the N most relevant un-expired knowledge entries for a
     * workspace. The `forAgent` parameter is reserved for future scoring
     * (boost entries from agents who have collaborated previously) and
     * currently has no effect on ordering.
     */
    public function getRelevant(int $wsId, string $forAgent = '', int $limit = 5): array
    {
        $rows = DB::table('workspace_knowledge')
            ->where('workspace_id', $wsId)
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('relevance_score')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        return $rows->map(function ($r) {
            $data = is_string($r->data) ? (json_decode($r->data, true) ?: $r->data) : $r->data;
            return [
                'id'        => (int)$r->id,
                'from'      => $r->source_agent,
                'type'      => $r->knowledge_type,
                'title'     => $r->title,
                'data'      => $data,
                'age'       => $r->created_at ? Carbon::parse($r->created_at)->diffForHumans() : null,
                'expires_at'=> $r->expires_at,
            ];
        })->all();
    }

    /**
     * Render a system-prompt block summarising shared knowledge. Empty
     * string when there's nothing to surface — caller can concatenate
     * unconditionally.
     */
    public function buildContextBlock(int $wsId, string $forAgent = '', int $limit = 5): string
    {
        $rows = $this->getRelevant($wsId, $forAgent, $limit);
        if (empty($rows)) return '';

        $lines = ['SHARED WORKSPACE KNOWLEDGE — entries from your teammates (acknowledge if relevant):'];
        foreach ($rows as $k) {
            $dataPreview = is_array($k['data'])
                ? json_encode($k['data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : (string)$k['data'];
            if (strlen($dataPreview) > 300) {
                $dataPreview = substr($dataPreview, 0, 300) . '... (truncated)';
            }
            $lines[] = "- [{$k['from']}] {$k['title']} ({$k['age']}): {$dataPreview}";
        }
        $lines[] = '';
        return implode("\n", $lines);
    }

    /**
     * Hard-delete expired entries. Wired to the daily scheduler in a
     * follow-up; for now callers run it explicitly when needed.
     */
    public function purgeExpired(): int
    {
        return DB::table('workspace_knowledge')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();
    }
}
