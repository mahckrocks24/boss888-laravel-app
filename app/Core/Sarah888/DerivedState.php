<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * SARAH888 — deterministic derived state.
 *
 * Sarah could persist a commitment and could not answer "how many are there".
 * The record was in the prompt, so the model counted the lines it could see —
 * which is a different number from the truth as soon as the frame truncates,
 * and the frame truncates at 101 live commitments. Asked for the earliest
 * deadline it compared date strings by eye. Asked who carries the most work it
 * estimated. Every one of those is arithmetic, and arithmetic done by
 * generation is arithmetic that is sometimes wrong with no signal that it was.
 *
 * So the numbers are computed here, in SQL, from the authoritative tables, and
 * handed to the model as facts it is told not to recompute.
 *
 * WHAT THIS IS NOT. It is not a second memory. It stores nothing and caches
 * nothing across requests: every value is derived at read time from
 * `sarah_commitments`, `tasks`, `approvals` and `projects`, which remain the
 * only sources of truth. Delete this class and no fact is lost — the same
 * numbers are still computable from the same rows. That property is the whole
 * design: a derived layer that could disagree with its source is a second
 * source of truth wearing a disguise.
 *
 * It computes dates, counts, orderings, aggregations and rankings. It never
 * invents a business fact, and no model output is admitted into it.
 *
 * Every result carries its provenance — the domain it came from, the row ids
 * it was computed over, the rule used, and when. A number without provenance
 * cannot be audited, and an executive record that cannot be audited is not an
 * executive record.
 */
class DerivedState
{
    public const CALC_VERSION = 'ds-v1';

    /**
     * The typed registry. A query that is not named here cannot be run, which
     * is what stops this from drifting into a general-purpose query surface
     * over customer data.
     */
    public const QUERIES = [
        'active_commitment_count',
        'cancelled_commitment_count',
        'superseded_commitment_count',
        'overdue_commitment_count',
        'due_soon_commitment_count',
        'next_deadline',
        'earliest_deadline',
        'commitments_by_owner',
        'current_owner',
        'previous_owner',
        'active_projects',
        'pending_approvals',
        'open_tasks',
        'failed_tasks',
        'blocked_tasks',
        'current_risks',
    
        'websites',
    ];

    /** Within this many days a deadline counts as "due soon". */
    public const DUE_SOON_DAYS = 7;

    public function __construct(private TemporalAnchor $time) {}

    /**
     * Run one typed query.
     *
     * @throws \InvalidArgumentException when the key is not in the registry
     */
    public function query(string $key, int $wsId, array $args = []): array
    {
        if (!in_array($key, self::QUERIES, true)) {
            throw new \InvalidArgumentException("Unknown derived query [$key]");
        }
        return match ($key) {
            'active_commitment_count'     => $this->commitmentCount($wsId, 'active'),
            'cancelled_commitment_count'  => $this->commitmentCount($wsId, 'cancelled'),
            'superseded_commitment_count' => $this->commitmentCount($wsId, 'superseded'),
            'overdue_commitment_count'    => $this->overdue($wsId),
            'due_soon_commitment_count'   => $this->dueSoon($wsId),
            'earliest_deadline'           => $this->earliestDeadline($wsId),
            'next_deadline'               => $this->nextDeadline($wsId),
            'commitments_by_owner'        => $this->byOwner($wsId),
            'current_owner'               => $this->currentOwner($wsId, (string) ($args['subject'] ?? '')),
            'previous_owner'              => $this->previousOwner($wsId, (string) ($args['subject'] ?? '')),
            'active_projects'             => $this->activeProjects($wsId),
            'pending_approvals'           => $this->pendingApprovals($wsId),
            'open_tasks'                  => $this->taskCount($wsId, ['pending', 'awaiting_approval'], 'open_tasks'),
            'failed_tasks'                => $this->taskCount($wsId, ['failed'], 'failed_tasks'),
            'blocked_tasks'               => $this->taskCount($wsId, ['blocked'], 'blocked_tasks'),
            'current_risks'               => $this->risks($wsId),
            'websites'                    => $this->websites($wsId),
        };
    }

    // ── commitments ────────────────────────────────────────────────────────

    /**
     * Cancelled and superseded rows are excluded from "live" by status, not by
     * absence of a successor. A superseded row is still a true historical
     * record; it is simply not the current one, and counting it would report
     * the same obligation twice.
     */
    private function commitmentCount(int $wsId, string $status): array
    {
        $ids = $this->ids(DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)->where('status', $status));

        return $this->time->fact(
            key: $status === 'active' ? 'active_commitment_count' : "{$status}_commitment_count",
            value: count($ids), wsId: $wsId, domain: 'sarah_commitments', sourceIds: $ids,
            rule: "count(sarah_commitments where workspace_id=$wsId and status='$status')",
        );
    }

    private function overdue(int $wsId): array
    {
        $today = $this->time->now($wsId)['local']->startOfDay()->format('Y-m-d');
        $rows = DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)->where('status', 'active')
            ->whereNotNull('deadline')->whereDate('deadline', '<', $today)
            ->orderBy('deadline')->get(['id', 'title', 'deadline', 'owner']);

        return $this->time->fact(
            key: 'overdue_commitment_count', value: $rows->count(), wsId: $wsId,
            domain: 'sarah_commitments', sourceIds: $rows->pluck('id')->map('intval')->all(),
            rule: "count(active commitments with deadline < $today workspace-local)",
            extra: ['items' => $rows->map(fn ($r) => [
                'id' => (int) $r->id, 'title' => $r->title, 'deadline' => $r->deadline,
                'owner' => $r->owner, 'days_overdue' => abs((int) $this->time->daysUntil($r->deadline, $wsId)),
            ])->all()],
        );
    }

    private function dueSoon(int $wsId): array
    {
        $local = $this->time->now($wsId)['local']->startOfDay();
        $rows = DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)->where('status', 'active')->whereNotNull('deadline')
            ->whereDate('deadline', '>=', $local->format('Y-m-d'))
            ->whereDate('deadline', '<=', $local->addDays(self::DUE_SOON_DAYS)->format('Y-m-d'))
            ->orderBy('deadline')->get(['id', 'title', 'deadline', 'owner']);

        return $this->time->fact(
            key: 'due_soon_commitment_count', value: $rows->count(), wsId: $wsId,
            domain: 'sarah_commitments', sourceIds: $rows->pluck('id')->map('intval')->all(),
            rule: 'count(active commitments with deadline within ' . self::DUE_SOON_DAYS . ' days)',
            extra: ['items' => $rows->all()],
        );
    }

    private function earliestDeadline(int $wsId): array
    {
        $row = DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)->where('status', 'active')->whereNotNull('deadline')
            ->orderBy('deadline')->orderBy('id')      // id breaks ties deterministically
            ->first(['id', 'title', 'deadline', 'owner']);

        return $this->time->fact(
            key: 'earliest_deadline',
            value: $row?->deadline, wsId: $wsId, domain: 'sarah_commitments',
            sourceIds: $row ? [(int) $row->id] : [],
            rule: 'min(deadline) over active commitments, ties broken by lowest id',
            extra: $row ? [
                'title' => $row->title, 'owner' => $row->owner,
                'days_until' => $this->time->daysUntil($row->deadline, $wsId),
            ] : ['title' => null, 'owner' => null, 'days_until' => null],
        );
    }

    /**
     * The next deadline is not the earliest one.
     *
     * They are the same number only while nothing is overdue. On the forensic
     * tenant the earliest active deadline is nine days in the PAST, so aliasing
     * "what's next" to min(deadline) answered a question about the future with
     * a date that had already gone by. Asked "which deadline is first?" an
     * owner means the next thing coming at them; asked "what's the earliest"
     * they mean the extreme of the set. Both are legitimate and they are
     * different queries, so they get different implementations.
     */
    private function nextDeadline(int $wsId): array
    {
        $today = $this->time->now($wsId)['local']->startOfDay()->format('Y-m-d');
        $row = DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)->where('status', 'active')->whereNotNull('deadline')
            ->whereDate('deadline', '>=', $today)
            ->orderBy('deadline')->orderBy('id')
            ->first(['id', 'title', 'deadline', 'owner']);

        return $this->time->fact(
            key: 'next_deadline',
            value: $row?->deadline, wsId: $wsId, domain: 'sarah_commitments',
            sourceIds: $row ? [(int) $row->id] : [],
            rule: "min(deadline) over active commitments with deadline >= $today (workspace-local), ties by lowest id",
            extra: $row ? [
                'title' => $row->title, 'owner' => $row->owner,
                'days_until' => $this->time->daysUntil($row->deadline, $wsId),
            ] : ['title' => null, 'owner' => null, 'days_until' => null],
        );
    }
    /** Ranked, so "who carries the most" is answered by position not by eye. */
    private function byOwner(int $wsId): array
    {
        $rows = DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)->where('status', 'active')
            ->whereNotNull('owner')->where('owner', '!=', '')
            ->select('owner', DB::raw('COUNT(*) as n'))
            ->groupBy('owner')->orderByDesc('n')->orderBy('owner')  // name breaks ties
            ->get();

        $ids = $this->ids(DB::table('sarah_commitments')
            ->where('workspace_id', $wsId)->where('status', 'active')
            ->whereNotNull('owner')->where('owner', '!=', ''));

        return $this->time->fact(
            key: 'commitments_by_owner',
            value: $rows->mapWithKeys(fn ($r) => [$r->owner => (int) $r->n])->all(),
            wsId: $wsId, domain: 'sarah_commitments', sourceIds: $ids,
            rule: 'count(active commitments) grouped by owner, ordered desc then owner asc',
            extra: ['top' => $rows->first()?->owner, 'top_count' => (int) ($rows->first()->n ?? 0)],
        );
    }

    /**
     * Ownership questions are asked about a thing ("who owns the rebuild"), so
     * the subject is matched against the title. The newest matching row wins,
     * because a reassignment supersedes rather than mutates.
     */
    private function currentOwner(int $wsId, string $subject): array
    {
        $row = $this->findBySubject($wsId, $subject, activeOnly: true);
        return $this->time->fact(
            key: 'current_owner', value: $row?->owner, wsId: $wsId,
            domain: 'sarah_commitments', sourceIds: $row ? [(int) $row->id] : [],
            rule: "current owner of the newest active commitment matching " . json_encode($subject),
            extra: ['subject' => $subject, 'matched_title' => $row?->title],
        );
    }

    /**
     * The previous owner is read off the supersession chain, never guessed. If
     * the chain does not record a different owner, the answer is null — "I
     * don't have that" is a correct answer and a fabricated name is not.
     */
    private function previousOwner(int $wsId, string $subject): array
    {
        $cur = $this->findBySubject($wsId, $subject, activeOnly: true);
        $prev = null; $chain = [];

        $node = $cur;
        $guard = 0;
        while ($node && $node->supersedes_id && $guard++ < 20) {
            $node = DB::table('sarah_commitments')->where('workspace_id', $wsId)
                ->where('id', $node->supersedes_id)
                ->first(['id', 'title', 'owner', 'supersedes_id']);
            if (!$node) break;
            $chain[] = (int) $node->id;
            if ($node->owner && $cur->owner && $node->owner !== $cur->owner) { $prev = $node->owner; break; }
        }

        return $this->time->fact(
            key: 'previous_owner', value: $prev, wsId: $wsId, domain: 'sarah_commitments',
            sourceIds: array_merge($cur ? [(int) $cur->id] : [], $chain),
            rule: 'walk supersedes_id from the current row until the owner differs',
            extra: ['subject' => $subject, 'current_owner' => $cur?->owner, 'chain' => $chain],
        );
    }

    private function findBySubject(int $wsId, string $subject, bool $activeOnly): ?object
    {
        $subject = trim($subject);
        if (mb_strlen($subject) < 3) return null;
        $q = DB::table('sarah_commitments')->where('workspace_id', $wsId)
            ->where('title', 'like', '%' . $subject . '%');
        if ($activeOnly) $q->where('status', 'active');
        return $q->orderByDesc('id')->first(['id', 'title', 'owner', 'supersedes_id', 'deadline']);
    }

    // ── other authoritative domains ────────────────────────────────────────

    private function activeProjects(int $wsId): array
    {
        $rows = DB::table('projects')->where('workspace_id', $wsId)
            ->where('status', 'active')->whereNull('deleted_at')->whereNull('archived_at')
            ->orderBy('id')->get(['id', 'name', 'planned_end_at']);

        return $this->time->fact(
            key: 'active_projects', value: $rows->count(), wsId: $wsId, domain: 'projects',
            sourceIds: $rows->pluck('id')->map('intval')->all(),
            rule: "count(projects where status='active' and not archived and not soft-deleted)",
            extra: ['items' => $rows->all()],
        );
    }

    private function pendingApprovals(int $wsId): array
    {
        $ids = $this->ids(DB::table('approvals')->where('workspace_id', $wsId)->where('status', 'pending'));
        return $this->time->fact(
            key: 'pending_approvals', value: count($ids), wsId: $wsId, domain: 'approvals',
            sourceIds: $ids, rule: "count(approvals where status='pending')",
        );
    }

    private function taskCount(int $wsId, array $statuses, string $key): array
    {
        $ids = $this->ids(DB::table('tasks')->where('workspace_id', $wsId)->whereIn('status', $statuses));
        return $this->time->fact(
            key: $key, value: count($ids), wsId: $wsId, domain: 'tasks', sourceIds: $ids,
            rule: 'count(tasks where status in [' . implode(',', $statuses) . '])',
        );
    }

    /** Risk is overdue work plus work that is blocked — both already computed. */
    private function risks(int $wsId): array
    {
        $over = $this->overdue($wsId);
        $blocked = $this->taskCount($wsId, ['blocked'], 'blocked_tasks');
        $failed  = $this->taskCount($wsId, ['failed'], 'failed_tasks');

        return $this->time->fact(
            key: 'current_risks',
            value: ['overdue_commitments' => $over['value'],
                    'blocked_tasks' => $blocked['value'], 'failed_tasks' => $failed['value']],
            wsId: $wsId, domain: 'sarah_commitments+tasks',
            sourceIds: array_merge($over['source_ids'], $blocked['source_ids'], $failed['source_ids']),
            rule: 'overdue active commitments, plus tasks in blocked or failed',
            extra: ['worst_overdue' => $over['items'][0] ?? null],
        );
    }

    /** @return int[] */
    private function ids($q): array
    {
        return $q->orderBy('id')->pluck('id')->map('intval')->all();
    }

    // ── prompt rendering ───────────────────────────────────────────────────

    /**
     * The block Sarah actually reads. Deliberately compact: these are the
     * numbers, not the records. The commitment block carries the records, and
     * duplicating them here would spend the budget twice to say one thing.
     */
    /**
     * The websites this business owns — by NAME.
     *
     * Chef Red, 2026-09-01. The owner asked "how many websites do we have now?" and got back
     * "You have 2 websites in this workspace." He then asked "Aren't you across all things happening in
     * this account?" and she answered, accurately, "I don't have visibility into the full context."
     *
     * She was right. Until now this class had no concept of a website at all — not one mention in 414
     * lines. Every fact she could state about the customer's estate was a count someone else had already
     * reduced to a number, so she could never say WHICH site, how old it was, or whether anyone had
     * written anything for it. A portfolio was added to WorkspaceStateGatherer earlier the same day, but
     * that feeds the proactive engine and the meeting engine; the CHAT reads this class, and this class
     * was never told. Half a fix reaches half the product.
     *
     * A workspace holds many websites (INC-0006), so the answer is a list, not a tally.
     */
    private function websites(int $wsId): array
    {
        $sites = DB::table('websites')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'name', 'status', 'template_industry', 'subdomain', 'custom_domain']);

        // One grouped query, not one per site: this renders on every turn.
        $articles = DB::table('articles')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->whereNotNull('website_id')
            ->selectRaw('website_id, COUNT(*) c')
            ->groupBy('website_id')
            ->pluck('c', 'website_id');

        $items = $sites->map(fn ($s) => [
            'id'       => (int) $s->id,
            'name'     => trim((string) ($s->name ?? '')) ?: ('website #' . $s->id),
            'status'   => (string) ($s->status ?? ''),
            'host'     => (string) ($s->custom_domain ?: $s->subdomain ?: ''),
            'articles' => (int) ($articles[$s->id] ?? 0),
        ])->values()->all();

        return ['key' => 'websites', 'value' => count($items), 'items' => $items];
    }

    public function render(int $wsId): string
    {
        try {
            $active   = $this->query('active_commitment_count', $wsId);
            $overdue  = $this->query('overdue_commitment_count', $wsId);
            $soon     = $this->query('due_soon_commitment_count', $wsId);
            $earliest = $this->query('earliest_deadline', $wsId);
            $owners   = $this->query('commitments_by_owner', $wsId);
            $cancel   = $this->query('cancelled_commitment_count', $wsId);
            $projects = $this->query('active_projects', $wsId);
            $approv   = $this->query('pending_approvals', $wsId);
            $open     = $this->query('open_tasks', $wsId);
            $blocked  = $this->query('blocked_tasks', $wsId);
            $sites    = $this->query('websites', $wsId);
        } catch (\Throwable) {
            return '';
        }

        $s  = "DERIVED STATE (computed from the workspace record just now — exact, not estimated).\n";
        $s .= "  Active commitments : {$active['value']}"
            . ($cancel['value'] ? "   (plus {$cancel['value']} cancelled)" : '') . "\n";
        $s .= "  Overdue            : {$overdue['value']}\n";
        $s .= "  Due within " . self::DUE_SOON_DAYS . " days : {$soon['value']}\n";

        $next = $this->query('next_deadline', $wsId);
        if ($next['value']) {
            $dn = $next['days_until'];
            $s .= "  Next deadline      : {$next['value']} (" . ($dn === 0 ? 'today' : "in $dn days") . ") — \"{$next['title']}\""
                . ($next['owner'] ? " [{$next['owner']}]" : '') . "\n";
        }

        if ($earliest['value']) {
            $d = $earliest['days_until'];
            $when = $d === null ? '' : ($d < 0 ? abs($d) . ' days ago' : ($d === 0 ? 'today' : "in $d days"));
            $s .= "  Earliest deadline  : {$earliest['value']} ($when) — \"{$earliest['title']}\""
                . ($earliest['owner'] ? " [{$earliest['owner']}]" : '') . "\n";
        }
        if (!empty($overdue['items'])) {
            $w = $overdue['items'][0];
            $s .= "  Most overdue       : \"{$w['title']}\" — {$w['days_overdue']} days late"
                . ($w['owner'] ? " [{$w['owner']}]" : '') . "\n";
        }
        if ($owners['value']) {
            $pairs = [];
            foreach (array_slice($owners['value'], 0, 6, true) as $o => $n) $pairs[] = "$o: $n";
            $s .= "  By owner           : " . implode(', ', $pairs)
                . (count($owners['value']) > 6 ? ', …' : '') . "\n";
            $s .= "  Carries the most   : {$owners['top']} ({$owners['top_count']})\n";
        }
        // FOUR NUMBERS ON ONE LINE IS AN INVITATION TO MISREAD.
        // Asked "how many pending approvals are there?", Sarah answered 146 —
        // the OPEN TASKS figure sitting two columns to the right on the same
        // line. The approvals number was present and correct; the layout put a
        // different number close enough to the label to be picked up instead.
        // A wrong count delivered confidently is indistinguishable to the owner
        // from a hallucination, and it was caused here by formatting rather
        // than by anything the model got wrong.
        $s .= "  Active projects    : {$projects['value']}\n";
        $s .= "  Pending approvals  : {$approv['value']}\n";
        $s .= "  Open tasks         : {$open['value']}\n";
        $s .= "  Blocked tasks      : {$blocked['value']}\n";
        // The estate, by name. A business that owns two websites is owed their names, and a site with
        // nothing written for it is the single most actionable fact she can hold about it.
        if ($sites['value'] > 0) {
            $s .= "  Websites (" . $sites['value'] . ")     :\n";
            foreach ($sites['items'] as $w) {
                $s .= "      • \"{$w['name']}\""
                    . ($w['host'] !== '' ? " ({$w['host']})" : '')
                    . " — {$w['status']}, "
                    . ($w['articles'] > 0 ? "{$w['articles']} article" . ($w['articles'] === 1 ? '' : 's')
                                          : 'NO content written yet')
                    . "\n";
            }
        } else {
            $s .= "  Websites           : none built yet\n";
        }
        $s .= "  These numbers are computed. Use them exactly as given; do not\n"
            . "  recount them from the lists below, which may be abridged.\n"
            . "  Name a website when you talk about it. Never answer a question\n"
            . "  about the customer's websites with only a count.\n\n";

        return $s;
    }
}
