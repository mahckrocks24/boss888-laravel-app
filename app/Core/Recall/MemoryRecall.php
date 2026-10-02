<?php

namespace App\Core\Recall;

use App\Core\OwnerModel\OwnerModelService;
use App\Core\Sarah888\MemoryHorizon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RFC-0023 P4: for the current message, the few earlier moments outside the visible window that are most relevant - from the
 * whole history of this workspace, dated and attributed, so Sarah can say "you asked me on 15 Sep ...". MySQL full-text
 * (natural language mode) ranked with a mild recency tie-break; tenancy-scoped by the workspace column, logged to
 * memory_events as `recall`. Kill switch storage/app/recall1.on. Embeddings are a reserved column, not a dependency.
 */
class MemoryRecall
{
    public const DEFAULT_N = 8;
    public const MIN_QUERY_TERMS = 2;
    private const STOP = ['the', 'and', 'for', 'with', 'this', 'that', 'you', 'your', 'please', 'can', 'could', 'would', 'make', 'want', 'need', 'one', 'two', 'what', 'when', 'where', 'which', 'how', 'did', 'does', 'have', 'has', 'had', 'was', 'were', 'are', 'from', 'about', 'into', 'just', 'also', 'like', 'tell', 'remind', 'back', 'again', 'sarah', 'ang', 'mga', 'yung', 'naman', 'lang'];

    public function __construct(private OwnerModelService $model) {}

    public static function enabled(): bool { return file_exists(storage_path('app/recall1.on')); }

    /** @return array<int,string> dated lines, newest-irrelevant excluded, empty when nothing scores */
    public function recall(int $wsId, ?int $bizId, string $message, int $n = self::DEFAULT_N): array
    {
        if (! self::enabled()) return [];
        $t0 = microtime(true);
        $query = $this->query($message);
        if ($query === null) return [];
        try {
            // the visible window is already in the prompt: recall only reaches beyond it
            $visible = DB::table('agent_messages')->where('workspace_id', $wsId)->orderByDesc('id')->limit(MemoryHorizon::VISIBLE_WINDOW)->pluck('id')->map(fn ($i) => 'msg:' . $i)->all();
            $rows = DB::table('memory_chunks')->select('id', 'source', 'ref', 'said_by', 'text', 'said_at', 'business_id')
                ->selectRaw('MATCH(text) AGAINST (? IN NATURAL LANGUAGE MODE) AS score', [$query])
                ->where('workspace_id', $wsId)
                ->when($bizId, fn ($q) => $q->where(fn ($w) => $w->where('business_id', $bizId)->orWhereNull('business_id')))
                ->when($visible, fn ($q) => $q->whereNotIn('ref', $visible))
                ->whereRaw('MATCH(text) AGAINST (? IN NATURAL LANGUAGE MODE) > 0', [$query])
                ->orderByDesc('score')->orderByDesc('said_at')->limit($n * 3)->get();
            if ($rows->isEmpty()) { $this->model->event($wsId, 'recall', ['hits' => 0, 'terms' => $query, 'ms' => (int) ((microtime(true) - $t0) * 1000)]); return []; }
            // mild recency: a hit from this month beats an equal hit from three months ago; dedupe near-identical texts
            $max = (float) $rows->max('score');
            $scored = $rows->map(function ($r) use ($max) {
                $age = max(0, (time() - strtotime((string) $r->said_at)) / 86400);
                // what the owner said, told or confirmed outranks what Sarah answered at equal relevance
                $boost = match (true) { $r->source === 'fact' || $r->source === 'journal' => 1.3, $r->said_by === 'owner' => 1.25, $r->source === 'outcome' => 1.0, default => 0.9 };
                $r->rank = ($max > 0 ? $r->score / $max : 0) * (1 - min(0.3, $age / 365)) * $boost;
                return $r;
            })->sortByDesc('rank')->values();
            $out = []; $seen = [];
            foreach ($scored as $r) {
                $key = mb_substr(mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string) $r->text)), 0, 80);
                if (isset($seen[$key])) continue; $seen[$key] = true;
                $who = match ($r->said_by) { 'owner' => 'the owner said', 'sarah' => 'you answered', default => 'on record' };
                if ($r->source === 'journal' || $r->source === 'fact') $who = 'the owner told you';
                $out[] = '  - ' . substr((string) $r->said_at, 0, 10) . ', ' . $who . ': "' . mb_substr((string) $r->text, 0, 220) . (mb_strlen((string) $r->text) > 220 ? '…' : '') . '"';
                if (count($out) >= $n) break;
            }
            $this->model->event($wsId, 'recall', ['hits' => count($out), 'candidates' => $rows->count(), 'terms' => $query, 'ms' => (int) ((microtime(true) - $t0) * 1000)], array_sum(array_map('mb_strlen', $out)));
            return $out;
        } catch (\Throwable $e) { Log::warning('[RECALL] failed: ' . $e->getMessage(), ['ws' => $wsId]); return []; }
    }

    /** The message reduced to its content words (3+ letters, not stop words); null when there is nothing to search for. */
    public function query(string $message): ?string
    {
        $w = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($message), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $w = array_values(array_unique(array_filter($w, fn ($x) => mb_strlen($x) >= 3 && ! in_array($x, self::STOP, true))));
        if (count($w) < self::MIN_QUERY_TERMS) return null;
        return implode(' ', array_slice($w, 0, 24));
    }
}
