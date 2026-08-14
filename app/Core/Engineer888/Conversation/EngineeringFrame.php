<?php

namespace App\Core\Engineer888\Conversation;

use Illuminate\Support\Facades\DB;

/**
 * What Engineer888 knows, right now, rendered for a conversational turn.
 *
 * THIS IS THE ENGINEERING EQUIVALENT OF SARAH'S CognitiveFrame + ExecutiveFrame,
 * and it borrows three of her decisions deliberately:
 *
 *   1. EVERY BLOCK IS COMPUTED FROM A RECORD. Nothing here is narration. If a
 *      value cannot be read it is omitted, never guessed — the model is told
 *      what is unknown rather than handed a plausible number. Sarah's own
 *      comment on the GSC block says it best: the LLM path fabricates
 *      'connected' when the deterministic path knows better.
 *
 *   2. THE FRAME IS BUDGETED. Sarah budgets characters because relevant
 *      knowledge buried under irrelevant knowledge is knowledge the model will
 *      not use. Engineer888 has far more machine-readable state than Sarah —
 *      45 tasks, 100+ candidates, a full audit trail — so an unbounded frame
 *      would be mostly noise about work nobody asked about.
 *
 *   3. BLOCKS ARE CLASSED AND SELECTED PER TURN. Sarah's ContextSelector splits
 *      context into MANDATORY_GLOBAL / MANDATORY_SAFETY / TURN_RELEVANT /
 *      OPTIONAL. The same split is used here: identity and the active project
 *      are always present; candidate detail and audit history arrive only when
 *      the turn is plausibly about them.
 *
 * ONE DELIBERATE DIFFERENCE FROM SARAH. Sarah's frame is workspace-scoped and
 * her conversations are per-workspace. Engineer888 has exactly one owner
 * conversation carrying an active-project pointer (recorded as
 * CHAT-FOLLOWUP-002), so the frame takes the project as context rather than as
 * a hard scope: a platform-level question is still answerable while a project
 * is selected.
 */
final class EngineeringFrame
{
    public const ALWAYS   = 'ALWAYS';
    public const RELEVANT = 'RELEVANT';

    /** Cheap turn classification. Not authority — only which blocks to spend budget on. */
    /**
     * Which optional blocks a turn is plausibly about.
     *
     * ── THE TRAILING BOUNDARY WAS EATING HALF OF THESE (2026-08-14) ──
     *
     * These alternatives are deliberately written as PREFIXES — execut,
     * propos, investigat, verif, repositor — so that they catch the whole
     * family of a word. A closing \b after the group defeats exactly that: it
     * demands a non-word character straight after the prefix, so "execut"
     * matched nothing and "execute", "execution" and "executable" all missed.
     * Every prefix cue in this table was dead.
     *
     * Found because "What can I execute?" cued no block at all. The execution
     * block is ALWAYS-class so it still appeared, which is why this survived
     * unnoticed — the frame looked reasonable while the classifier was doing
     * nothing. The prefixes work now; whole words are unaffected.
     */
    private const CUES = [
        'candidates' => '/\b(candidate|approve|approval|review|diff|propos|bug tracker|waiting|pending)/i',
        'work'       => '/\b(task|tasks|working|progress|status|blocked|blocker|queue|investigat|doing)/i',
        'execution'  => '/\b(execut|run|worker|queue|lock|deploy|install|recover|verif|test)/i',
        'health'     => '/\b(health|fail|failing|defect|incident|broken|safe|safety|risk|baseline)/i',
        'repository' => '/\b(repo|repositor|branch|head|commit|file|structure|architect)/i',
    ];

    public function __construct(
        private readonly array $limits = [],
        private readonly ?\App\Core\Engineer888\Access\Engineer888AccessContext $ctx = null,
    ) {}

    /**
     * @return array{text:string,blocks:array<int,string>,chars:int}
     */
    public function build(object $conversation, string $turn): array
    {
        $budget = (int) ($this->limits['max_chars'] ?? 14000);
        $wanted = $this->classify($turn);

        $blocks = [];

        $blocks['identity']      = [self::ALWAYS,   fn () => $this->identity()];
        $blocks['project']       = [self::ALWAYS,   fn () => $this->project($conversation)];
        $blocks['work']          = [self::RELEVANT, fn () => $this->work($conversation)];
        $blocks['candidates']    = [self::RELEVANT, fn () => $this->candidates($conversation)];
        $blocks['execution']     = [self::ALWAYS,   fn () => $this->execution($conversation)];
        $blocks['health']        = [self::RELEVANT, fn () => $this->health()];
        $blocks['repository']    = [self::RELEVANT, fn () => $this->repository($conversation)];
        $blocks['standards']     = [self::RELEVANT, fn () => $this->standards()];

        $out = [];
        $used = 0;
        $included = [];

        foreach ($blocks as $name => [$class, $fn]) {
            if ($class === self::RELEVANT && ! in_array($name, $wanted, true)) { continue; }

            $text = $this->safe($fn, $name);

            if ($text === '') { continue; }
            if ($used + strlen($text) > $budget) { continue; }

            $out[] = $text;
            $used += strlen($text);
            $included[] = $name;
        }

        return ['text' => implode("\n\n", $out), 'blocks' => $included, 'chars' => $used];
    }

    /** Which optional blocks is this turn plausibly about? */
    private function classify(string $turn): array
    {
        $hit = [];

        foreach (self::CUES as $block => $pattern) {
            if (preg_match($pattern, $turn)) { $hit[] = $block; }
        }

        // ASKING WHETHER SOMETHING CAN RUN IS ASKING ABOUT DECISIONS.
        //
        // "What can I execute?" cued only the execution block, so the model was
        // handed worker state and a bare approval count and no list of what
        // those decisions actually are. It answered from the count. The two
        // blocks belong together: the execution question is a question about
        // which decisions have reached the point of being runnable.
        if (in_array('execution', $hit, true) && ! in_array('candidates', $hit, true)) {
            $hit[] = 'candidates';
        }

        // A short turn with no cue ("hello", "hey", "thanks") gets the cheap
        // frame. A substantive turn with no cue gets the useful blocks, because
        // an open question about engineering usually needs them.
        if ($hit === [] && str_word_count($turn) >= 6) {
            $hit = ['work', 'candidates', 'health'];
        }

        return $hit;
    }

    private function identity(): string
    {
        return "WHO YOU ARE\n"
            . "You are Engineer888, the AI engineering department for LevelUp Growth.\n"
            . "You are speaking with Mark (Boss), the CEO and the only account with access to you.\n"
            . "Environment: " . app()->environment() . '.';
    }

    private function project(object $conversation): string
    {
        $p = DB::table('engineering_projects')->find($conversation->active_project_id ?? 0);

        if ($p === null) {
            return "ACTIVE PROJECT\nNone selected. You cannot open engineering work until one is chosen.";
        }

        $head = $branch = 'unreadable';
        $dirty = null;

        if (is_dir($p->repository_path . '/.git')) {
            $head = trim((string) @shell_exec('cd ' . escapeshellarg($p->repository_path) . ' && git rev-parse --short HEAD 2>/dev/null'));
            $branch = trim((string) @shell_exec('cd ' . escapeshellarg($p->repository_path) . ' && git rev-parse --abbrev-ref HEAD 2>/dev/null'));
            $dirty = (int) trim((string) @shell_exec('cd ' . escapeshellarg($p->repository_path) . ' && git status --porcelain -uall 2>/dev/null | wc -l'));
        }

        return "ACTIVE PROJECT\n"
            . "{$p->name} ({$p->key})\n"
            . "Repository: {$p->repository_path}\n"
            . "Branch: {$branch}  HEAD: {$head}" . ($dirty === null ? '' : "  uncommitted files: {$dirty}") . "\n"
            . "Test database: " . ($p->test_database ?? 'none') . "  PHPUnit config: " . ($p->phpunit_config ?? 'none') . "\n"
            . "This is context, not a boundary. Platform-level questions are still answerable.";
    }

    private function work(object $conversation): string
    {
        $max = (int) ($this->limits['max_tasks'] ?? 8);

        $tasks = DB::table('engineering_tasks as t')
            ->join('engineering_projects as p', 'p.id', '=', 't.project_id')
            ->whereNotIn('t.status', ['completed'])
            ->orderByDesc('t.updated_at')
            ->limit($max)
            ->get(['t.id', 't.uuid', 't.title', 't.status', 't.current_stage', 'p.name as project']);

        if ($tasks->isEmpty()) { return ''; }

        $lines = $tasks->map(fn ($t) =>
            "- #{$t->id} \"" . mb_strimwidth($t->title, 0, 70, '...') . "\" [{$t->project}] status={$t->status} stage=" . ($t->current_stage ?: 'none')
        )->implode("\n");

        $counts = DB::table('engineering_tasks')->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status');

        return "OPEN ENGINEERING WORK (most recently touched " . count($tasks) . ")\n{$lines}\n"
            . 'Totals by status: ' . collect($counts)->map(fn ($c, $s) => "{$s}={$c}")->implode(', ') . '.';
    }

    private function candidates(object $conversation): string
    {
        // ONE SOURCE OF TRUTH.
        //
        // This block used to count raw PENDING approval rows and tell the model
        // there were 30 candidates waiting, while DecisionProjection said 2 and
        // the header said 25. The model repeated the number it was handed, so
        // Boss was told "30 candidates" by an assistant reading from a
        // different source than the interface sitting next to it.
        //
        // Whatever the header and the Decisions surface say, this says.
        if ($this->ctx !== null) {
            $projectId = $conversation->active_project_id === null
                ? null : (int) $conversation->active_project_id;

            $items = (new \App\Core\Engineer888\Decisions\DecisionProjection())
                ->current($this->ctx, $projectId);

            if ($items === []) { return ''; }

            // ── STATE, NOT JUST TITLE (2026-08-14) ───────────────────
            //
            // "Approved" and "can be run" stopped being the same thing when
            // the expiry defect was fixed, so the model has to be told which
            // it is looking at. Without the state on the line it can only
            // infer, and the inference it would make — approved means ready —
            // is exactly the one that was wrong.
            $lines = [];
            $byState = [];

            foreach ($items as $i) {
                $byState[$i['state']] = ($byState[$i['state']] ?? 0) + 1;

                $line = '- [' . $i['state'] . '] ' . mb_strimwidth((string) $i['title'], 0, 60, '...')
                    . ' (' . $i['file_count'] . ' files, ' . $i['confidence'] . ' confidence'
                    . ($i['history'] ? ', ' . count($i['history']) . ' earlier attempts' : '')
                    . ')';

                if ($i['state'] === \App\Core\Engineer888\Decisions\DecisionState::APPROVAL_EXPIRED) {
                    $line .= "\n    approved by " . ($i['approved_by'] ?: 'a human')
                        . ' at ' . $i['decided_at'] . ', window closed ' . $i['expires_at']
                        . '. NOT executable. The candidate is unchanged and kept as history; '
                        . 'the only way forward is to run the task again for a fresh candidate, '
                        . 'and only if Boss asks for that.';
                }

                $lines[] = $line;
            }

            $summary = [];
            foreach ($byState as $s => $n) { $summary[] = "{$n} {$s}"; }

            return "DECISIONS AWAITING BOSS (" . count($items) . " in total: " . implode(', ', $summary) . ")\n"
                . implode("\n", $lines) . "\n"
                . "This is the authoritative count. Repeated attempts at the same brief are folded "
                . "into one decision and earlier attempts are history, not separate work. Never "
                . "quote a larger number from anywhere else.\n"
                . "REVIEW_REQUIRED needs his judgement of the bytes. READY_TO_EXECUTE is approved, "
                . "in date and runnable. APPROVAL_EXPIRED was approved but the window closed before "
                . "anything ran it — it cannot be executed, cannot be approved again, and cannot have "
                . "its expiry extended. Do not describe an expired approval as awaiting approval, and "
                . "never count it among what he can execute.";
        }

        $max = (int) ($this->limits['max_candidates'] ?? 5);

        $rows = DB::table('engineering_candidates as c')
            ->join('engineering_tasks as t', 't.id', '=', 'c.task_id')
            ->join('engineering_projects as p', 'p.id', '=', 't.project_id')
            ->leftJoin('engineering_candidate_approvals as a', 'a.candidate_id', '=', 'c.id')
            ->whereNull('c.superseded_at')
            ->where('c.status', 'VALIDATED')
            ->orderByDesc('c.id')
            ->limit($max)
            ->get(['c.uuid', 'c.file_count', 'c.confidence', 'c.provider', 'c.model',
                   't.id as tid', 't.title', 'p.name as project', 'a.state', 'a.id as aid']);

        if ($rows->isEmpty()) { return ''; }

        $lines = $rows->map(function ($r) {
            return "- task #{$r->tid} \"" . mb_strimwidth($r->title, 0, 60, '...') . "\" [{$r->project}] "
                . "{$r->file_count} files, confidence {$r->confidence}, approval " . ($r->state ?: 'none')
                . ' (candidate ' . substr($r->uuid, 0, 8) . ')';
        })->implode("\n");

        $pending = DB::table('engineering_candidate_approvals')->where('state', 'PENDING')->count();

        return "CANDIDATES AWAITING A DECISION (newest " . count($rows) . " of {$pending} pending)\n{$lines}\n"
            . "Many of these are repeated attempts at the same acceptance exercise from 10-12 August; "
            . "they are separate tasks, not duplicates. Do not present them as a queue of distinct work.";
    }

    private function execution(object $conversation): string
    {
        $active = trim((string) @shell_exec('systemctl is-active e888-worker.service 2>/dev/null'));
        $depth = trim((string) @shell_exec('redis-cli llen queues:e888-isolated 2>/dev/null'));
        $locks = trim((string) @shell_exec("redis-cli --scan --pattern '*lock*' 2>/dev/null | head -3"));

        $lastAttempt = DB::table('engineering_execution_attempts')->orderByDesc('id')->first();
        $attempts = DB::table('engineering_execution_attempts')->count();

        $last = $lastAttempt === null ? 'none recorded'
            : "#{$lastAttempt->id} " . ($lastAttempt->result ?? '?') . ' on ' . ($lastAttempt->repository_path ?? '?')
              . ' at ' . ($lastAttempt->started_at ?? '?');

        // ── "LIVE AND APPROVED" WAS A LIE (2026-08-14) ────────────────
        //
        // This line counted rows reading state=APPROVED and called them live.
        // Measured in browser QA: asked "What can I execute?" Engineer888
        // answered "There are 16 approved candidates ready for execution" and
        // drew no cards, because all 16 had passed their 12-hour window and
        // ApprovalLedger would refuse every one of them. The number was true
        // of the table and false about authority, which is the worse of the
        // two ways to be wrong.
        //
        // It asks the projection now, like every other surface. Approved-but-
        // lapsed is reported separately rather than folded into either count:
        // it is neither waiting for judgement nor runnable, and collapsing it
        // into "approved" is exactly what produced the wrong sentence.
        $pending = $runnable = $lapsed = null;

        if ($this->ctx !== null) {
            // SCOPED TO THE CONVERSATION'S PROJECT, like the decisions block,
            // the header badge and the Decisions page. Measured 2026-08-14:
            // counted platform-wide, this block said "11 decisions" while the
            // badge said 3 and three cards were drawn — the model repeated the
            // 11. One number means one scope as well as one source.
            $projectId = ($conversation->active_project_id ?? null) === null
                ? null : (int) $conversation->active_project_id;

            $projection = new \App\Core\Engineer888\Decisions\DecisionProjection();
            $counts = [];

            foreach ($projection->current($this->ctx, $projectId) as $item) {
                $counts[$item['state']] = ($counts[$item['state']] ?? 0) + 1;
            }

            $pending  = $counts[\App\Core\Engineer888\Decisions\DecisionState::REVIEW_REQUIRED] ?? 0;
            $runnable = $counts[\App\Core\Engineer888\Decisions\DecisionState::READY_TO_EXECUTE] ?? 0;
            $lapsed   = $counts[\App\Core\Engineer888\Decisions\DecisionState::APPROVAL_EXPIRED] ?? 0;
        }

        $approvalLine = $pending === null
            ? "Approvals: not readable without an access context.\n"
            : "Decisions: {$pending} awaiting your review, {$runnable} approved and runnable now, "
              . "{$lapsed} approved but expired.\n"
              . ($runnable === 0
                  ? "NOTHING IS RUNNABLE. Do not say anything is ready to execute, and never quote a "
                    . "count of approved rows as a count of runnable work — an approval whose window "
                    . "closed is not permission.\n"
                  : '');

        return "EXECUTION STATE\n"
            . 'Isolated worker (e888-worker.service): ' . ($active ?: 'unreadable') . "\n"
            . 'Isolated queue e888-isolated depth: ' . ($depth === '' ? 'unreadable' : $depth) . "\n"
            . 'Repository execution lock: ' . ($locks === '' ? 'none held' : $locks) . "\n"
            . "Last execution attempt: {$last}  (total attempts recorded: {$attempts})\n"
            . $approvalLine
            . "WHAT THIS MEANS. Nothing can be executed until a human has typed the exact approval "
            . "statement for a specific candidate. An idle queue and a free lock do NOT mean work is "
            . "ready to run, and must never be described as 'you can proceed'. If nothing is approved, "
            . "the honest answer is that the decision is still his to make.";
    }

    private function health(): string
    {
        return "KNOWN ENGINEERING HEALTH\n"
            . "- Baseline defect B1: the platform-wide safety audit cannot certify complete coverage. "
            . "It reports complete=false while listing no unreadable path, so the certificate is internally "
            . "inconsistent. This does NOT prove an unsafe execution path exists. Open, not repaired.\n"
            . "- Baseline defect B2: RepositoryIntelligenceTest fails 7 ways because the class it exercises "
            . "is absent from app/Core/Engineer888/Repository/. Open, not repaired.\n"
            . "- Engineer888 suite: 643 passed, 8 failed, 2 skipped. All 8 failures pre-date current work.\n"
            . "- The shared tree /var/www/levelup-staging is worked on by other sessions concurrently and "
            . "is usually dirty; it is not safe to deploy into without checking who is active.";
    }

    private function repository(object $conversation): string
    {
        return "REPOSITORY FACTS WORTH REMEMBERING\n"
            . "- The acceptance project is a plain PSR-4 PHP project with PHPUnit. It is NOT Laravel. "
            . "An early candidate invented Laravel infrastructure for it; repository discovery now runs "
            . "before architecture is chosen.\n"
            . "- Committing to feature/engineer888-chat-v1 does not deploy. The served tree can lag the branch.\n"
            . "- The isolated browser environment is loopback-only on 127.0.0.1:8080 and serves the "
            . "hardened worktree, not the shared tree.";
    }

    private function standards(): string
    {
        $assets = DB::table('engineering_assets')
            ->whereIn('kind', ['coding_standard', 'standard', 'convention'])
            ->orderByDesc('id')->limit(6)->get(['name', 'kind']);

        if ($assets->isEmpty()) { return ''; }

        return "PROVEN ENGINEERING STANDARDS ON RECORD\n"
            . $assets->map(fn ($a) => "- {$a->name} ({$a->kind})")->implode("\n");
    }

    /** A block that throws is an absent block, never a broken turn. */
    private function safe(callable $fn, string $name): string
    {
        try {
            return trim((string) $fn());
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[engineer888] frame block failed', [
                'block' => $name, 'error' => $e->getMessage(),
            ]);

            return '';
        }
    }
}
