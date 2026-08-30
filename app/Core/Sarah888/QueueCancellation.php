<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * QUEUE-CANCEL (2026-08-30, Owner): the owner can tell Sarah to cancel what is waiting in the queue.
 *
 * Deterministic, two turns. Turn 1 ("cancel the queued audits", "clear everything waiting", "delete the pending tasks
 * older than two weeks") → Sarah names exactly what would be cancelled, in business words, with the consequences, and
 * asks for a yes. Turn 2 ("yes") → it is done and reported. Nothing is charged or refunded: items waiting for an OK have
 * never reserved credits (verified 2026-08-30: 0 reservations across 140 waiting items on Chef Red). Items that DO
 * carry a live reservation are skipped and named, never silently cancelled.
 */
class QueueCancellation
{
    public const TTL_MIN = 15;

    public static function key(int $wsId): string { return 'sarah:cancelq:ws' . $wsId; }

    /** Business names for actions. */
    private const LABELS = [
        'deep_audit' => ['site audit', 'site audits'], 'write_article' => ['article', 'articles'],
        'improve_draft' => ['draft improvement', 'draft improvements'], 'generate_meta' => ['meta description', 'meta descriptions'],
        'link_suggestions' => ['internal-link suggestion', 'internal-link suggestions'], 'competitor_gaps' => ['competitor check', 'competitor checks'],
        'publish_article' => ['article publish', 'article publishes'], 'generate_image' => ['image', 'images'],
        'generate_image_mini' => ['featured image', 'featured images'], 'delete_lead' => ['lead deletion', 'lead deletions'],
        'social_publish_post' => ['social post', 'social posts'], 'aeo_enrich' => ['answer-engine enrichment', 'answer-engine enrichments'],
    ];

    /** Does this message ask to cancel queued / waiting work? */
    public static function asks(string $text): bool
    {
        $t = mb_strtolower($text);
        return (bool) (preg_match('/\b(cancel|delete|clear|remove|drop|scrap|bin|kill|get rid of|wipe|purge|dismiss|reject)\b/', $t)
            && preg_match('/\b(queue|queued|pending|waiting|backlog|approvals?|awaiting|in the queue|those tasks|these tasks|the tasks|all( the)? tasks|stuck)\b/', $t));
    }

    /** Is this a yes to a pending cancellation offer? */
    public static function confirms(string $text): bool
    {
        return (bool) preg_match('/^\s*(yes|yeah|yep|yup|ok|okay|sure|go ahead|do it|confirm(ed)?|please do|cancel them|proceed|approved?)\b/i', trim($text));
    }

    public static function declines(string $text): bool
    {
        return (bool) preg_match('/^\s*(no|nope|don\'?t|do not|stop|leave (it|them)|keep them|never ?mind|cancel that)\b/i', trim($text));
    }

    /**
     * The waiting items this message refers to.
     * @return array{filter:string, tasks:\Illuminate\Support\Collection}
     */
    public function scope(int $wsId, string $text): array
    {
        $t = mb_strtolower($text);
        $q = DB::table('tasks')->where('workspace_id', $wsId)
            ->whereIn('status', ['pending', 'queued'])
            ->where(function ($w) { $w->where('approval_status', 'pending')->orWhereNull('approval_status')->orWhere('requires_approval', 1); })
            ->where('status', 'pending');
        $filter = 'everything waiting';
        $kinds = [];
        foreach ([
            '/\baudits?\b/' => 'deep_audit', '/\barticles?\b|\bdrafts?\b|\bposts?\b/' => ['write_article', 'improve_draft', 'publish_article'],
            '/\bleads?\b/' => 'delete_lead', '/\blinks?\b/' => 'link_suggestions', '/\bmetas?\b|\bmeta descriptions?\b/' => 'generate_meta',
            '/\bimages?\b|\bpictures?\b/' => ['generate_image', 'generate_image_mini'], '/\bcompetitors?\b/' => 'competitor_gaps', '/\bsocial\b/' => 'social_publish_post',
        ] as $rx => $acts) { if (preg_match($rx, $t)) { foreach ((array) $acts as $a) $kinds[] = $a; } }
        if ($kinds && !preg_match('/\b(all|everything|whole|entire)\b/', $t)) { $q->whereIn('action', $kinds); $filter = 'the waiting ' . implode(' / ', array_unique(array_map(fn ($a) => self::LABELS[$a][1] ?? str_replace('_', ' ', $a), $kinds))); }
        if (preg_match('/older than (\d+|a|an|one|two|three|four|five|six|seven|ten)\s*(day|week|month)s?/', $t, $m)) {
            $n = ['a' => 1, 'an' => 1, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5, 'six' => 6, 'seven' => 7, 'ten' => 10][$m[1]] ?? (int) $m[1];
            $q->where('created_at', '<', now()->sub($m[2], $n)); $filter .= " older than {$n} {$m[2]}" . ($n === 1 ? '' : 's');
        }
        $tasks = $q->orderBy('id')->get(['id', 'engine', 'action', 'credit_cost', 'created_at']);
        return ['filter' => $filter, 'tasks' => $tasks];
    }

    /** What the owner is told before saying yes. */
    public function describe(array $scope): string
    {
        $tasks = $scope['tasks']; $n = $tasks->count();
        if ($n === 0) return "Nothing is waiting in the queue" . ($scope['filter'] !== 'everything waiting' ? " for {$scope['filter']}" : '') . " — there's nothing to cancel.";
        $byAction = [];
        foreach ($tasks as $t) { $byAction[$t->action] = ($byAction[$t->action] ?? 0) + 1; }
        arsort($byAction);
        $parts = [];
        foreach ($byAction as $a => $c) { $l = self::LABELS[$a] ?? [str_replace('_', ' ', $a), str_replace('_', ' ', $a) . 's']; $parts[] = $c . ' ' . ($c === 1 ? $l[0] : $l[1]); }
        $credits = (int) $tasks->sum('credit_cost');
        $oldest = $tasks->min('created_at');
        $days = $oldest ? now()->diffInDays(\Carbon\Carbon::parse($oldest)) : 0;
        $list = count($parts) > 1 ? implode(', ', array_slice($parts, 0, -1)) . ' and ' . end($parts) : $parts[0];
        return "Cancelling {$scope['filter']} would remove {$n} item" . ($n === 1 ? '' : 's') . " that " . ($n === 1 ? 'has' : 'have') . " been waiting for your OK"
            . ($days > 1 ? " (the oldest for {$days} days)" : '') . ": {$list}.\n\n"
            . "What this means: none of that work will happen — " . ($this->mentions($byAction, ['write_article', 'improve_draft']) ? 'no new articles or draft improvements, ' : '')
            . ($this->mentions($byAction, ['deep_audit']) ? 'no site audits, ' : '') . ($this->mentions($byAction, ['delete_lead']) ? 'the leads stay in your CRM, ' : '')
            . "and anything you still want has to be asked for again. It cannot be undone. "
            . ($credits > 0 ? "None of it has been charged, so cancelling costs nothing and refunds nothing (it stops {$credits} credit" . ($credits === 1 ? '' : 's') . " from ever being spent)." : "No credits are involved.")
            . "\n\nSay **yes** to cancel " . ($n === 1 ? 'it' : 'them') . ", or **no** to leave " . ($n === 1 ? 'it' : 'them') . " waiting.";
    }

    private function mentions(array $byAction, array $acts): bool { foreach ($acts as $a) if (!empty($byAction[$a])) return true; return false; }

    public function remember(int $wsId, array $scope, string $ownerText): void
    {
        Cache::put(self::key($wsId), ['ids' => $scope['tasks']->pluck('id')->map(fn ($i) => (int) $i)->all(), 'filter' => $scope['filter'], 'asked_at' => time(), 'owner_text' => $ownerText], now()->addMinutes(self::TTL_MIN));
    }

    public function pending(int $wsId): ?array { $v = Cache::get(self::key($wsId)); return is_array($v) ? $v : null; }

    public function forget(int $wsId): void { Cache::forget(self::key($wsId)); }

    /**
     * Cancel the remembered items. Approvals → rejected (with the owner's reason); tasks → cancelled. Items that carry a
     * live credit reservation are skipped and reported.
     * @return array{cancelled:int, skipped:int, skipped_ids:array<int,int>, already:int}
     */
    public function execute(int $wsId, array $taskIds, ?int $userId, string $reason = 'Cancelled by the owner from chat'): array
    {
        $cancelled = 0; $skipped = []; $already = 0;
        $rows = DB::table('tasks')->where('workspace_id', $wsId)->whereIn('id', $taskIds)->get(['id', 'status', 'approval_status']);
        foreach ($rows as $t) {
            if ($t->status !== 'pending') { $already++; continue; }
            $reserved = DB::table('credit_transactions')->where('workspace_id', $wsId)->where('reference_id', (int) $t->id)
                ->where('reference_type', 'like', '%task%')->whereIn('reservation_status', ['reserved', 'held', 'pending'])->exists();
            if ($reserved) { $skipped[] = (int) $t->id; continue; }
            try {
                DB::transaction(function () use ($wsId, $t, $userId, $reason) {
                    DB::table('approvals')->where('workspace_id', $wsId)->where('task_id', $t->id)->where('status', 'pending')->update([
                        'status' => 'rejected', 'decision_by' => $userId, 'decision_note' => $reason, 'decided_at' => now(), 'updated_at' => now(),
                    ]);
                    DB::table('tasks')->where('id', $t->id)->update([
                        'status' => 'cancelled', 'approval_status' => 'rejected', 'error_text' => $reason, 'cancelled_at' => now(), 'updated_at' => now(),
                    ]);
                });
                $cancelled++;
            } catch (\Throwable $e) {
                $skipped[] = (int) $t->id;
                Log::warning('[Sarah888] queue cancel failed for a task', ['ws' => $wsId, 'task' => $t->id, 'error' => $e->getMessage()]);
            }
        }
        Log::info('[Sarah888] QUEUE-CANCEL executed', ['ws' => $wsId, 'user' => $userId, 'cancelled' => $cancelled, 'skipped' => count($skipped), 'already' => $already]);
        return ['cancelled' => $cancelled, 'skipped' => count($skipped), 'skipped_ids' => $skipped, 'already' => $already];
    }

    public function report(array $res): string
    {
        $n = $res['cancelled'];
        $s = "Done — I've cancelled {$n} item" . ($n === 1 ? '' : 's') . " that " . ($n === 1 ? 'was' : 'were') . " waiting for your OK. Nothing was charged.";
        if ($res['already'] > 0) $s .= " {$res['already']} had already moved on (run, failed or cancelled) so " . ($res['already'] === 1 ? 'it was' : 'they were') . " left alone.";
        if ($res['skipped'] > 0) $s .= " {$res['skipped']} " . ($res['skipped'] === 1 ? 'item holds' : 'items hold') . " reserved credits, so I left " . ($res['skipped'] === 1 ? 'it' : 'them') . " — tell me if you want those released too.";
        return $s . " Your queue is clear of the rest; anything you still want, just ask me again.";
    }
}
