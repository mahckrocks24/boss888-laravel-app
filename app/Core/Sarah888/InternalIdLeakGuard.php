<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Owner rule 2026-09-18 — internal row numbers are never spoken to a customer.
 *
 * Sarah had learned to cite articles, approvals and tasks by their database ids ("#1054", "request #12579",
 * "(#32621)") because ids are verifiable and titles are not. The customer has no surface that shows those numbers:
 * to them it is noise, and it exposes the platform's internals. This guard runs last on every Sarah reply and turns
 * each "#<n>" that names a row of THIS workspace into what the customer can see — an article's title in quotes, "in
 * your review queue" for an approval, a task's own description — and drops a "#<n>" that names nothing here (an
 * invented number is not a fact either). Ids stay in logs and in the UI's data attributes; only prose is touched.
 */
final class InternalIdLeakGuard
{
    private const LEAD = '(?:article|articles|draft|drafts|piece|post|request|requests|approval|task|tasks|job|jobs|item|ticket|id|ids|no\.|number)';

    /** @return array{reply:string, rewritten:array} */
    public static function apply(string $reply, int $wsId): array
    {
        if ($wsId <= 0 || ! preg_match('/#\d+/', $reply)) return ['reply' => $reply, 'rewritten' => []];
        $rewritten = [];
        $rx = '/(\(\s*)?(?:\b(' . self::LEAD . ')\s*)?(\(\s*)?#(\d+)(\s*\))?(\s*["“][^"”]{1,120}["”])?/iu';
        $out = preg_replace_callback($rx, function ($m) use ($wsId, &$rewritten) {
            $openBefore = ($m[1] ?? '') !== '' || ($m[3] ?? '') !== '';
            $closeAfter = ($m[5] ?? '') !== '';
            $paren = $openBefore && $closeAfter;
            $lead = strtolower(trim($m[2] ?? ''));
            $leadWord = $lead === '' ? '' : preg_replace('/s$/', '', $lead);
            $id = (int) $m[4];
            $quoted = isset($m[6]) && trim($m[6]) !== '' ? trim($m[6]) : null;
            $q = fn (string $t) => '"' . mb_strimwidth($t, 0, 60, '…') . '"';

            $art = DB::table('articles')->where('workspace_id', $wsId)->where('id', $id)->whereNull('deleted_at')->first(['title']);
            if ($art && in_array($leadWord, ['', 'article', 'draft', 'piece', 'post', 'id', 'number', 'no.', 'item'], true)) {
                $rewritten[] = ['id' => $id, 'as' => 'article'];
                $title = $quoted ?: $q((string) $art->title);
                return in_array($leadWord, ['article', 'draft', 'piece', 'post'], true) && ! $paren ? $leadWord . ' ' . $title : $title;
            }
            $appr = DB::table('approvals')->where('workspace_id', $wsId)->where('id', $id)->first(['status']);
            if ($appr && in_array($leadWord, ['', 'request', 'approval', 'item', 'ticket', 'id', 'number', 'no.'], true)) {
                $rewritten[] = ['id' => $id, 'as' => 'approval'];
                if ($appr->status !== 'pending') return $paren ? '' : 'that request';
                return $paren ? 'in your review queue' : 'the request in your review queue';
            }
            $task = DB::table('tasks')->where('workspace_id', $wsId)->where('id', $id)->first(['payload_json']);
            if ($task) {
                $p = json_decode((string) ($task->payload_json ?? ''), true) ?: [];
                $title = trim((string) ($p['title'] ?? $p['request'] ?? $p['description'] ?? $p['command'] ?? ''));
                $rewritten[] = ['id' => $id, 'as' => 'task'];
                if ($title === '') return $paren ? '' : ($leadWord !== '' ? 'that ' . $leadWord : 'that job');
                return ($leadWord !== '' ? $leadWord . ' ' : '') . $q($title);   // "request (#N)" → request "…"; "(#N)" → "…"
            }
            // names nothing in this workspace: not a fact — drop it (a quoted title, if any, is kept)
            $rewritten[] = ['id' => $id, 'as' => 'stripped'];
            return $quoted ?: '';
        }, $reply) ?? $reply;
        // tidy what the removals leave behind
        $out = preg_replace('/\(\s*\)/', '', (string) $out);
        $out = preg_replace('/\s{2,}/', ' ', (string) $out);
        $out = preg_replace('/\s+([.,;:!?])/', '$1', (string) $out);
        $out = preg_replace('/\b(the|a|an)\s+the request in your review queue/i', 'the request in your review queue', (string) $out);
        $out = preg_replace('/\b(the|a|an)\s+that (request|job)\b/i', 'that $2', (string) $out);
        if ($rewritten !== []) {
            Log::info('[Sarah888] internal ids kept out of the reply', ['ws' => $wsId, 'rewritten' => $rewritten]);
        }
        return ['reply' => trim((string) $out), 'rewritten' => $rewritten];
    }
}
