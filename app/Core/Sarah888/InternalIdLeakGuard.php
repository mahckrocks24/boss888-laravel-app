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
    private const LEAD = '(?:article|articles|draft|drafts|piece|post|posts|request|requests|approval|approvals|task|tasks|job|jobs|item|items|ticket|tickets|lead|leads|proposal|proposals|id|ids|no\.|number)';

    /**
     * H1 (2026-09-25 certification): the same ids written as ordinary prose.
     *
     * This guard assumed every internal id arrives as "#N", so it returned at the door for
     * any reply without a hash. Sarah does not always write one. Measured live, all three
     * reached the customer untouched: "the newest being lead 1554", "a pending write task
     * (ID 32916)", "three tasks tied to proposal 11543". Normalising the bare form into the
     * hash form lets the resolution below handle both without a second copy of it.
     *
     * Deliberately narrower than LEAD: "number" and "no." head ordinary counts and are left
     * alone unless they carry a hash.
     */
    private const BARE_LEAD = '(?:article|articles|draft|drafts|piece|post|posts|request|requests|approval|approvals|task|tasks|job|jobs|item|items|ticket|tickets|lead|leads|proposal|proposals|id|ids)';

    /** @return array{reply:string, rewritten:array} */
    public static function apply(string $reply, int $wsId): array
    {
        if ($wsId <= 0) return ['reply' => $reply, 'rewritten' => []];
        // H1: "task 32916" -> "task #32916" so one resolution path serves both forms.
        $reply = (string) (preg_replace('/\b(' . self::BARE_LEAD . ')\s+(\d{1,6})\b/iu', '$1 #$2', $reply) ?? $reply);
        if (! preg_match('/#\d+/', $reply)) return ['reply' => $reply, 'rewritten' => []];
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
            if (in_array($leadWord, ['', 'lead', 'id', 'item'], true)) {
                $ld = DB::table('leads')->where('workspace_id', $wsId)->where('id', $id)->whereNull('deleted_at')->first(['name', 'email']);
                if ($ld) {
                    $rewritten[] = ['id' => $id, 'as' => 'lead'];
                    $nm = trim((string) ($ld->name ?? '')) ?: trim((string) ($ld->email ?? ''));
                    if ($nm === '') return $paren ? '' : 'that lead';
                    return ($leadWord === 'lead' && ! $paren) ? 'lead ' . $q($nm) : $q($nm);
                }
            }
            if (in_array($leadWord, ['', 'proposal', 'request', 'item', 'id'], true)) {
                $pr = DB::table('strategy_proposals')->where('workspace_id', $wsId)->where('id', $id)->first(['title']);
                if ($pr) {
                    $rewritten[] = ['id' => $id, 'as' => 'proposal'];
                    $ttl = trim((string) ($pr->title ?? ''));
                    if ($ttl === '' || strcasecmp($ttl, 'Untitled action') === 0) return $paren ? '' : 'that plan';
                    return $paren ? $q($ttl) : 'the plan ' . $q($ttl);
                }
            }
            $task = DB::table('tasks')->where('workspace_id', $wsId)->where('id', $id)->first(['payload_json']);
            if ($task) {
                $p = json_decode((string) ($task->payload_json ?? ''), true) ?: [];
                $title = trim((string) ($p['title'] ?? $p['request'] ?? $p['description'] ?? $p['command'] ?? ''));
                $rewritten[] = ['id' => $id, 'as' => 'task'];
                if ($title === '') return $paren ? '' : ($leadWord !== '' ? 'that ' . $leadWord : 'that job');
                return ($leadWord !== '' && ! $paren ? $leadWord . ' ' : '') . $q($title);   // H1b   // "request (#N)" → request "…"; "(#N)" → "…"
            }
            // names nothing in this workspace: not a fact — drop it (a quoted title, if any, is kept)
            $rewritten[] = ['id' => $id, 'as' => 'stripped'];
            // H1b: dropping the id must not drop the noun with it — "the newest being lead 1554"
            // became "the newest being at status new" when the lead turned out to be soft-deleted.
            if ($quoted) return $quoted;
            if ($leadWord !== '' && ! $paren && ! in_array($leadWord, ['id', 'no.', 'number'], true)) return 'that ' . $leadWord;
            return '';
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
