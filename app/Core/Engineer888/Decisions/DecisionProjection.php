<?php

namespace App\Core\Engineer888\Decisions;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Access\Engineer888AccessContext;
use App\Core\Engineer888\Access\Engineer888Capability as Cap;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * What actually needs a decision from Boss, right now.
 *
 * ONE SOURCE OF TRUTH, DELIBERATELY. The Decisions page, the header counter and
 * the conversational SHOW_DECISIONS path all read from here. Before this class
 * existed those three surfaces each approximated the answer separately and
 * disagreed: the raw card table held 52 live rows, the header said 25, and a
 * per-task projection said 24. Three numbers, none of them the number of
 * decisions a human actually had to make.
 *
 * ── THE UNIT IS A LOGICAL WORK ITEM, NOT A TASK ROW ──────────────────
 *
 * The acceptance loop produced 23 separate engineering_tasks rows all asking
 * for the same Bug Tracker, each with its own VALIDATED candidate and its own
 * PENDING approval. Every one of them was legitimately "awaiting a decision" by
 * the old definition, so all 23 were offered at once and were indistinguishable
 * from one another. That is not 23 decisions. It is one decision with 22
 * earlier attempts behind it.
 *
 * ── WHY DESCRIPTION IDENTITY, AND WHY IT IS SAFE ─────────────────────
 *
 * There is no lineage column to use. engineering_tasks carries no parent id and
 * no work-item id; `session` exists but is far too coarse (43 unrelated tasks
 * share 'e888-acceptance'), and candidates.revision_of is strictly within a
 * single task — measured 2026-08-13: 19 revisions, 0 of them crossing tasks.
 *
 * So the key is the SHA-256 of the task description, scoped to the project.
 * Grouping on resemblance would be reckless; this is not resemblance. Measured
 * across the whole table on 2026-08-13, the number of description hashes
 * spanning more than one title, or more than one project, was ZERO. A shared
 * description here means the same brief was submitted again, which is exactly
 * what a repeated attempt is.
 *
 * THIS IS PRESENTATION ONLY. No row is written, merged, superseded or altered.
 * Every task, candidate and approval remains exactly as it was; earlier
 * attempts are returned under `history` so they stay reachable as evidence.
 * If a real lineage column is added later, swap key() and delete this note.
 */
final class DecisionProjection
{
    /**
     * Everything genuinely awaiting a human decision, one entry per work item.
     *
     * @return array<int,array> newest work item first
     */
    /** A candidate whose bytes nobody has judged yet. */
    public const KIND_REVIEW = 'review';

    /** An approved candidate nobody has run yet. */
    public const KIND_EXECUTE = 'execute';

    public function current(Engineer888AccessContext $ctx, ?int $projectId = null): array
    {
        // ONE POLICY, ONE CALL. checkContext() already requires the canonical
        // account, the canonical email, an active status, platform-admin, a
        // live grant carrying this capability, and a human credential type.
        // Re-deriving any of that here would be a second implementation of the
        // rule, and a second implementation is the thing that eventually
        // disagrees. This projection reads no owner-scoped table, so there is
        // nothing further to scope.
        if (! (new Engineer888Access())->allowsContext($ctx, Cap::REVIEW_CANDIDATE)) {
            return [];
        }

        // Undecided candidates: VALIDATED, not superseded, and carrying no
        // decision other than a PENDING request-for-approval. A PENDING row is
        // the ASKING, not the answering — joining it as a decision is the defect
        // that once made every task that reached REQUEST_APPROVAL unapprovable.
        $rows = DB::table('engineering_candidates as c')
            ->join('engineering_tasks as t', 't.id', '=', 'c.task_id')
            ->join('engineering_projects as p', 'p.id', '=', 't.project_id')
            ->leftJoin('engineering_candidate_approvals as a', function ($j) {
                $j->on('a.candidate_id', '=', 'c.id')
                  ->whereNull('a.revoked_at')->whereNull('a.superseded_at')
                  ->where('a.state', '!=', 'PENDING');
            })
            ->whereNull('c.superseded_at')
            ->where('c.status', 'VALIDATED')
            ->whereNull('a.id')
            ->whereNotIn('t.status', ['completed', 'failed'])
            ->when($projectId !== null, fn ($q) => $q->where('t.project_id', $projectId))
            // The newest candidate on a task; older ones are that task's history.
            ->whereRaw('c.id = (select max(c2.id) from engineering_candidates c2
                                 where c2.task_id = c.task_id
                                   and c2.superseded_at is null
                                   and c2.status = ?)', ['VALIDATED'])
            ->orderByDesc('c.id')
            ->get([
                'c.id as candidate_id', 'c.uuid as candidate_uuid', 'c.file_count',
                'c.confidence', 'c.provider', 'c.model', 'c.created_at as candidate_at',
                't.id as task_id', 't.uuid as task_uuid', 't.title', 't.description',
                't.status as task_status', 't.current_stage', 't.session',
                'p.id as project_id', 'p.name as project_name',
            ]);

        $items = [];

        foreach ($rows as $r) {
            $key = $this->key($r);

            if (! isset($items[$key])) {
                // First row wins because the query is ordered newest-first, so
                // the current decision is the most recent attempt at this work.
                $items[$key] = [
                    'work_key'       => $key,
                    'kind'           => self::KIND_REVIEW,
                    'title'          => $r->title,
                    'project_id'     => (int) $r->project_id,
                    'project'        => $r->project_name,
                    'task_uuid'      => $r->task_uuid,
                    'candidate_uuid' => $r->candidate_uuid,
                    'file_count'     => $r->file_count === null ? null : (int) $r->file_count,
                    'confidence'     => $r->confidence,
                    'stage'          => $r->current_stage,
                    'decided_at'     => null,
                    'raised_at'      => $r->candidate_at,
                    'attempts'       => 0,
                    'history'        => [],
                ];
            }

            $items[$key]['attempts']++;

            // Everything after the first is an earlier attempt at the same work.
            if ($items[$key]['candidate_uuid'] !== $r->candidate_uuid) {
                $items[$key]['history'][] = [
                    'task_uuid'      => $r->task_uuid,
                    'candidate_uuid' => $r->candidate_uuid,
                    'raised_at'      => $r->candidate_at,
                ];
            }
        }

        // ── APPROVED, AND STILL WAITING ──────────────────────────────
        //
        // A decision is not finished when it is approved. Somebody approved
        // these bytes and nothing has run them, so the next move is still
        // Boss's — and until this block existed the projection said there was
        // nothing waiting while two live execute_task cards sat unpressed.
        //
        // Found 2026-08-13, immediately after two candidates were approved in
        // the browser: awaiting-review went to 2 and the two approved items
        // vanished from every surface rather than moving to the next state.
        $approved = DB::table('engineering_candidate_approvals as a')
            ->join('engineering_candidates as c', 'c.id', '=', 'a.candidate_id')
            ->join('engineering_tasks as t', 't.id', '=', 'a.task_id')
            ->join('engineering_projects as p', 'p.id', '=', 't.project_id')
            ->whereNotNull('a.approved_at')
            ->whereNull('a.revoked_at')
            ->whereNull('a.superseded_at')
            ->whereNull('c.superseded_at')
            ->whereNotIn('t.status', ['completed', 'failed'])
            ->when($projectId !== null, fn ($q) => $q->where('t.project_id', $projectId))
            ->orderByDesc('a.id')
            ->get([
                'c.uuid as candidate_uuid', 'c.file_count', 'c.confidence',
                'a.approved_at', 't.uuid as task_uuid', 't.title', 't.description',
                't.current_stage', 'p.id as project_id', 'p.name as project_name',
            ]);

        foreach ($approved as $r) {
            // Keyed apart from the review entry: the same work can legitimately
            // have one candidate approved and awaiting execution while a later
            // one awaits review. Two different decisions, two different asks.
            $key = 'exec:' . $this->key($r);

            if (isset($items[$key])) { continue; }

            $items[$key] = [
                'work_key'       => $key,
                'kind'           => self::KIND_EXECUTE,
                'title'          => $r->title,
                'project_id'     => (int) $r->project_id,
                'project'        => $r->project_name,
                'task_uuid'      => $r->task_uuid,
                'candidate_uuid' => $r->candidate_uuid,
                'file_count'     => $r->file_count === null ? null : (int) $r->file_count,
                'confidence'     => $r->confidence,
                'stage'          => $r->current_stage,
                'decided_at'     => $r->approved_at,
                'raised_at'      => $r->approved_at,
                'attempts'       => 1,
                'history'        => [],
            ];
        }

        return array_values($items);
    }

    /** How many decisions a human actually has to make. */
    public function count(Engineer888AccessContext $ctx, ?int $projectId = null): int
    {
        return count($this->current($ctx, $projectId));
    }

    /**
     * The one work item a phrase refers to, or null.
     *
     * The MODEL may pass a hint; it may not pass an identity. Resolution happens
     * here, against the projection this class already computed, so a hint that
     * matches nothing yields nothing rather than an arbitrary object. Nothing
     * the model writes can name a card, a fingerprint or an approval statement.
     */
    public function resolve(Engineer888AccessContext $ctx, ?string $hint, ?int $projectId = null): ?array
    {
        $items = $this->current($ctx, $projectId);

        if ($items === []) { return null; }

        $hint = trim((string) $hint);

        if ($hint === '') { return $items[0]; }   // "show me the decision" => the current one

        $needle = $this->normalise($hint);
        $best = null;
        $bestScore = 0;

        foreach ($items as $item) {
            $title = mb_strtolower($item['title']);
            $score = 0;

            if ($title === $needle) { $score = 100; }
            elseif ($needle !== '' && str_contains($title, $needle)) { $score = 60; }
            else {
                // Word overlap. Measured 2026-08-13: "Bug Tracker" matched on
                // the substring path but "the bug tracker" did not, because the
                // article broke the substring and the only surviving word was
                // "tracker" - one hit, under the floor. Boss says "the Bug
                // Tracker", not "Bug Tracker", so the phrasing that failed is
                // the phrasing a human actually uses. Determiners are stripped
                // by normalise() and the length filter is >2 so "bug" counts.
                foreach ($this->terms($needle) as $w) {
                    if (str_contains($title, $w)) { $score += 15; }
                }
            }

            if ($score > $bestScore) { $bestScore = $score; $best = $item; }
        }

        // A weak match is no match. Showing the wrong decision is worse than
        // saying which ones exist.
        return $bestScore >= 20 ? $best : null;
    }

    /**
     * Strip the words a human says but a title does not carry.
     *
     * "show me the bug tracker candidate" and "the Bug Tracker" must both reach
     * the same work item as "Bug Tracker" does.
     */
    private function normalise(string $hint): string
    {
        $s = mb_strtolower(trim($hint));
        $s = preg_replace('/\b(show|open|review|display|paste|just|me|the|that|this|it|please|current|latest)\b/', ' ', $s);
        $s = preg_replace('/\b(candidate|decision|approval|task|one|item)\b/', ' ', $s);

        return trim(preg_replace('/\s+/', ' ', (string) $s));
    }

    /** @return array<int,string> meaningful words from a normalised hint */
    private function terms(string $needle): array
    {
        return array_values(array_filter(
            preg_split('/\W+/', $needle) ?: [],
            fn ($w) => mb_strlen($w) > 2
        ));
    }

    /**
     * The presentation-only logical-work key.
     *
     * Project plus description identity. See the class note for why this cannot
     * merge unrelated work, and for what to replace it with if a real lineage
     * column ever lands.
     */
    private function key(object $row): string
    {
        return $row->project_id . ':' . hash('sha256', (string) $row->description);
    }
}
