<?php

namespace App\Core\Sarah888;

use App\Connectors\RuntimeClient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 — RUNTIME-NATIVE SHADOW TURN.
 *
 * The architecture the boundary work exists to reach:
 *
 *   owner turn -> Laravel auth/tenancy -> STRUCTURED authoritative context
 *              -> Runtime reasons -> emits a ToolIntent if it needs one
 *              -> ToolIntentGateway (governance + REAL execution)
 *              -> ToolResult -> Runtime interprets REAL evidence
 *              -> Sarah speaks
 *
 * WHAT MAKES THIS DIFFERENT FROM THE LEGACY PATH.
 * Legacy composes a 24k-34k character prose system prompt (measured p50 28,950) and
 * asks the model to rediscover the workspace inside it. This sends compact structured
 * truth and lets the model reason over it. Facts keep name/value/unit/period;
 * capabilities keep provider and readiness; nothing is narrated twice.
 *
 * SAFETY.
 * Shadow only. Legacy Sarah is untouched. Mutating capabilities are never executed —
 * the gateway returns WOULD_* instead. Reads DO execute, because the point of the
 * shadow is that Runtime reasons over real vendor and workspace evidence rather than
 * over prose about it.
 *
 * CONVERSATION ISOLATION.
 * Recent conversation is read by workspace_id AND conversation_id. The legacy route
 * reads the last 20 messages by workspace + agent_slug with NO conversation filter,
 * so two different Sarah conversations in one workspace contaminate each other. This
 * path does not inherit that.
 */
final class ShadowTurn
{
    /** How much recent conversation to carry. Turns, not characters. */
    private const HISTORY_TURNS = 8;

    /**
     * Shadow conversation is written under its OWN agent slug, and this is not cosmetic.
     *
     * MEASURED 2026-08-14, and it was customer-visible. `remember()` wrote shadow turns as
     * `agent_slug = 'sarah'`, and `GET /agents/{slug}/messages` selects on
     * `workspace_id + agent_slug` with no conversation filter and no shadow exclusion. In
     * ws 2 — Chef Red, a LIVE CUSTOMER — **100 of the 100 rows that endpoint returns were
     * shadow test turns**: "Push the homepage builder page live", "Send the summer campaign
     * email to our list now", each attributed to the customer as if they had typed it.
     *
     * A shadow must be invisible to the owner of the workspace it runs in. Writing under a
     * distinct slug makes that structural: the SPA's query can never return these rows,
     * because it asks for 'sarah'. `history()` is unaffected — it reads by workspace and
     * conversation_id, not by slug — so the shadow keeps its own memory.
     *
     * The alternative fixes were worse: excluding shadow rows in the route means editing
     * `agents-01.php` (3,608 lines, production, another session's territory), and a
     * dedicated table means a migration for something a slug already separates.
     */
    private const SHADOW_SLUG = 'sarah-shadow';

    public function __construct(
        private RuntimeClient $runtime,
        private CapabilityManifest $manifest,
        private ToolIntentGateway $gateway,
        private ExecutiveFacts $facts,
    ) {}

    /**
     * @return array{reply:string, trace:array, timings:array, tool:?array}
     */
    public function run(int $wsId, string $conversationId, string $message,
                        array $opts = []): array
    {
        $T = [];
        $mark = function (string $k, float $from) use (&$T) {
            $T[$k] = (int) round((microtime(true) - $from) * 1000);
        };

        // ── 1. structured authoritative context ──────────────────────────
        $t = microtime(true);
        $context = $this->context($wsId, $conversationId, $message, $opts);
        $mark('laravel_context_ms', $t);

        // ── 2. Runtime reasoning pass 1 ──────────────────────────────────
        $t = microtime(true);
        $pass1 = $this->reason($wsId, $context, $message);
        $mark('runtime_pass1_ms', $t);

        $trace = [
            'context_bytes' => strlen(json_encode($context)),
            'capabilities_offered' => count($context['capabilities']),
            'pass1_model' => $pass1['model'] ?? null,
            'pass1_provider' => $pass1['provider'] ?? null,
            'pass1_fallback' => $this->fallbackNote($pass1),
            'pass1_prompt_chars' => $pass1['prompt_chars'] ?? null,
            'pass1_tokens_in'    => $pass1['tokens_in'] ?? null,
            'pass1_tokens_out'   => $pass1['tokens_out'] ?? null,
            'pass1_tokens_reasoning' => $pass1['tokens_reasoning'] ?? null,
            'pass1_raw'   => $pass1['parsed'] ?? null,
            // What the context is actually MADE of. H2/H3/H4 cannot be tested
            // against a single aggregate byte count.
            'context_parts' => [
                'facts'        => strlen(json_encode($context['facts'])),
                'capabilities' => strlen(json_encode($context['capabilities'])),
                'experience'   => strlen((string) $context['experience']),
                'commitments'  => strlen((string) $context['what_you_have_committed_to']),
                'conversation' => strlen(json_encode($context['conversation'])),
                'approval'     => strlen(json_encode($context['what_is_waiting_on_you'])),
            ],
            'context_counts' => [
                'facts'        => count($context['facts']),
                'capabilities' => count($context['capabilities']),
                'conversation' => count($context['conversation']),
            ],
            // Which arm of an Experience888 A/B this turn belongs to. Recorded on EVERY
            // turn, not just experimental ones, so a causal claim can never be made from
            // a run whose arm was not written down.
            'experience_included' => !($opts['without_experience'] ?? false),
        ];

        $reply = trim((string) ($pass1['parsed']['reply'] ?? ''));
        $rawIntent = $pass1['parsed']['tool_intent'] ?? null;
        $toolEnvelope = null;

        // ── 3-5. tool intent -> governance -> real execution ─────────────
        if (is_array($rawIntent) && ($rawIntent['capability_id'] ?? '') !== '') {
            $t = microtime(true);
            $built = ToolIntent::fromRuntime($rawIntent, $wsId, [
                'conversation_id' => $conversationId,
                // The owner's own words, so the gateway can tell a parameter they STATED
                // from one the model carried over. Without this a paid call runs on a guess.
                'owner_message'   => $message,
            ]);
            $mark('intent_parse_ms', $t);

            if ($built['intent'] === null) {
                $trace['intent_error'] = $built['error'];
            } else {
                $trace['tool_intent'] = $built['intent']->toArray();

                $t = microtime(true);
                // `live` is set ONLY by the cutover adapter, when this workspace has been
                // deliberately enrolled on the Runtime-native path. It does NOT unlock
                // mutations: the gateway settles those at governance either way and nothing
                // executes. What it changes is that a metered read now BILLS the workspace,
                // which is correct once this is the owner's real Sarah rather than our
                // validation of her — and the status vocabulary becomes the real one
                // (TOOL_REQUIRES_APPROVAL) instead of the simulated WOULD_* form.
                $live   = (bool) ($opts['live'] ?? false);
                $result = $this->gateway->handle($built['intent'], !$live);
                $mark('gateway_and_execution_ms', $t);

                $toolEnvelope = $result->toRuntime();
                $trace['tool_result'] = [
                    'status' => $result->status, 'executed' => $result->executed(),
                    'simulated' => $result->simulated(), 'provenance' => $result->provenance,
                    'latency_ms' => $result->latencyMs,
                ];

                // ── 6. Runtime interprets REAL evidence ──────────────────
                $t = microtime(true);
                $pass2 = $this->interpret($wsId, $context, $message, $toolEnvelope);
                $mark('runtime_pass2_ms', $t);
                $trace['pass2_model']    = $pass2['model'] ?? null;
                $trace['pass2_provider'] = $pass2['provider'] ?? null;
                $trace['pass2_fallback'] = $this->fallbackNote($pass2);
                $trace['pass2_prompt_chars'] = $pass2['prompt_chars'] ?? null;
                $trace['pass2_tokens_in']    = $pass2['tokens_in'] ?? null;
                $trace['pass2_tokens_out']   = $pass2['tokens_out'] ?? null;
                $trace['pass2_tokens_reasoning'] = $pass2['tokens_reasoning'] ?? null;
                $trace['tool_result_chars']  = strlen(json_encode($toolEnvelope));

                $reply = trim((string) ($pass2['parsed']['reply'] ?? $reply));
            }
        }

        $T['total_ms'] = array_sum($T);

        if ($reply === '') {
            $reply = "I couldn't put an answer together for that one.";
            $trace['degraded'] = true;
        }

        Log::info('[Sarah888] shadow turn', [
            'ws' => $wsId, 'conversation_id' => $conversationId,
            'timings' => $T, 'capability' => $trace['tool_intent']['capability_id'] ?? null,
            'tool_status' => $trace['tool_result']['status'] ?? null,
        ]);

        $this->remember($wsId, $conversationId, $message, $reply);

        return ['reply' => $reply, 'trace' => $trace, 'timings' => $T, 'tool' => $toolEnvelope];
    }

    /** null when the declared provider answered; otherwise what replaced it, and why. */
    private function fallbackNote(array $pass): ?array
    {
        if (empty($pass['fallback_used'])) return null;
        return [
            'requested' => $pass['requested_model'] ?? null,
            'actual'    => $pass['model'] ?? null,
            'reason'    => $pass['fallback_reason'] ?? null,
        ];
    }

    /**
     * Persist the shadow turn so the NEXT turn has something to refer to.
     *
     * Tagged `shadow: true` and carried on its own conversation_id, so shadow
     * conversation never blends into the legacy record and can be identified or
     * removed wholesale. This writes conversation history only — no task, proposal,
     * approval or business state.
     */
    private function remember(int $wsId, string $conversationId, string $owner, string $reply): void
    {
        try {
            $meta = json_encode(['conversation_id' => $conversationId, 'shadow' => true]);
            $now  = now();
            DB::table('agent_messages')->insert([
                ['workspace_id' => $wsId, 'agent_slug' => self::SHADOW_SLUG, 'sender' => 'Owner',
                 'role' => 'user', 'content' => $owner, 'metadata_json' => $meta,
                 'created_at' => $now, 'updated_at' => $now],
                ['workspace_id' => $wsId, 'agent_slug' => self::SHADOW_SLUG, 'sender' => 'Sarah',
                 'role' => 'agent', 'content' => $reply, 'metadata_json' => $meta,
                 'created_at' => $now, 'updated_at' => $now],
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] shadow turn could not be persisted: ' . $e->getMessage(),
                ['ws' => $wsId]);
        }
    }

    /**
     * Compact structured truth. Every value keeps its provenance; nothing is narrated
     * twice; no fact is restated in prose alongside its typed form.
     */
    public function context(int $wsId, string $conversationId, string $message,
                            array $opts = []): array
    {
        $facts = [];
        foreach ($this->facts->all($wsId) as $f) {
            $facts[] = ['name' => $f['name'], 'value' => $f['value'],
                        'unit' => $f['unit'], 'period' => $f['period']];
        }

        $caps = [];
        foreach ($this->manifest->availableFor($wsId) as $c) {
            $caps[] = [
                'capability_id' => $c['capability_id'],
                'operation'     => $c['operation_type'],
                'provider'      => $c['provider'],
                'required_parameters' => $c['required_parameters'],
            ];
            if (!empty($c['purpose'])) $caps[count($caps) - 1]['purpose'] = $c['purpose'];
        }

        // WHAT EXISTS BUT IS NOT CONFIGURED HERE, and why.
        //
        // Offering only what is available made the manifest go quiet exactly where it
        // should speak. Measured 2026-08-14 on ws 990100, which has no Search Console
        // connection: asked "What is Search Console telling us?", Runtime could not see
        // that GSC exists-but-is-unconnected, so it substituted `serp_analysis` and billed
        // a live DataForSEO lookup for the literal phrase "Search Console".
        //
        // A capability that exists and is not set up is a FACT the owner needs — "Search
        // Console isn't connected for this workspace" is the correct answer, and it can
        // only be given if the reasoning layer is told. Silence invites a substitute.
        //
        // Ids and reasons only: this is not an offer, and nothing here may be emitted as
        // a tool_intent.
        $unavailable = [];
        foreach ($this->manifest->forWorkspace($wsId) as $c) {
            if ($c['workspace_available'] !== false) continue;
            $why = $c['missing_configuration'] ?? [];
            $unavailable[] = [
                'capability_id' => $c['capability_id'],
                'why' => is_array($why) && $why !== [] ? implode('; ', $why) : 'not configured',
            ];
        }

        // `without_experience` is the CONTROL ARM of the Experience888 causal test, and
        // exists only for that. It withholds advisory evidence and nothing else: the
        // facts, the capability list and governance are byte-identical in both arms, so
        // any difference in the answer is attributable to experience alone. It must
        // never change what is AUTHORISED — authorisation lives in ToolIntentGateway,
        // which never sees this flag.
        $experience = '';
        if (!($opts['without_experience'] ?? false)) {
            try {
                $experience = (string) app(\App\Core\Experience888\ExperienceRetriever::class)
                    ->forTurn($wsId, $message, 900);
            } catch (\Throwable $e) { $experience = ''; }
        }

        // ── THE EXECUTIVE COMMITMENT RECORD ─────────────────────────────────
        // Legacy injects this into every turn (route line 1294). The cutover adapter
        // returns long before that, so a Runtime-native workspace answered commitment
        // questions with no record at all. Measured on ws 990100, which holds 157 live
        // commitments:
        //   "How many open commitments do we have?" -> "237" (it reached for `get_queue`
        //   and reported TASK-QUEUE counts as commitments — confidently wrong, with a
        //   real TOOL_SUCCEEDED behind it).
        //   "What did we agree about <title of commitment #35982>?" -> "I don't have any
        //   agreement about that on record" — about a commitment that is active and on
        //   record, which is verbatim the failure this record exists to prevent.
        //
        // The turn text is passed so the store's own relevance tiering can force-keep a
        // commitment the owner just named. That is the platform's filter, not a word-match
        // of mine: an earlier attempt to hand-roll relevance filtering broke Experience888's
        // standing-feedback contract and was rolled back.
        $commitments = '';
        try {
            $commitments = (string) app(\App\Core\Sarah888\CommitmentStore::class)
                ->renderForPrompt($wsId, 60, $message);
        } catch (\Throwable $e) {
            // Losing the record degrades an answer; failing the turn loses it entirely.
            $commitments = '';
        }

        return [
            'workspace_id'  => $wsId,
            'facts'         => $facts,
            'capabilities'  => $caps,
            'not_configured_here' => $unavailable,
            // Statement-shaped, like `what_is_waiting_on_you` and for the same reason: a
            // context key that reads like a noun gets emitted as a CAPABILITY_ID and comes
            // back TOOL_UNAVAILABLE. `commitments` would have been exactly that mistake.
            //
            // Kept SEPARATE from `what_is_waiting_on_you` deliberately. Commitments are
            // promises on the record; those are work queues. Conflating them is what
            // produced "237".
            'what_you_have_committed_to' => $commitments,
            // THREE DIFFERENT THINGS THAT ALL SOUND LIKE "PENDING APPROVAL".
            //
            // The transcript's T005 defect. `ExecutiveFacts` exposes both
            // `tasks_awaiting_approval` (9 — the status enum) and
            // `tasks_awaiting_owner_approval` (223 — the flag), names that differ by one
            // word for numbers that differ by 214. Measured 2026-08-14 on ws 990100: asked
            // the same question twice, the shadow answered "223 tasks, NO proposals
            // pending" and then "223 tasks plus 24 proposals". The 24 was in the context
            // BOTH times. Legacy answered "9", which is real but is not the number the
            // owner is asking about, and then contradicted itself in the same sentence.
            //
            // Disambiguating in the key names makes the distinction structural instead of
            // a parsing exercise the model has to get right every time.
            // KEY NAME IS LOAD-BEARING. This was `approval_state`, and on 2026-08-14 turn
            // 22 of the Phase H session Runtime emitted `approval_state` as a CAPABILITY_ID
            // — a context key mistaken for a tool — got TOOL_UNAVAILABLE, and told the
            // owner "I don't have a way to pull approval requirements from this workspace".
            // A false capability refusal, with the answer sitting in the very block whose
            // name it had misread.
            //
            // Context keys must not read like capability ids. `capabilities` is the only
            // list of things that can be called; everything else is phrased as a statement
            // about the workspace, not as a noun a model could plausibly invoke.
            'what_is_waiting_on_you' => [
                'tasks_awaiting_YOUR_approval' => (int) DB::table('tasks')
                    ->where('workspace_id', $wsId)->where('requires_approval', 1)
                    ->whereNotIn('status', ['completed', 'failed', 'cancelled'])->count(),
                'proposals_awaiting_YOUR_decision' => (int) DB::table('strategy_proposals')
                    ->where('workspace_id', $wsId)->where('status', 'pending_approval')->count(),
                'tasks_blocked_needing_attention' => (int) DB::table('tasks')
                    ->where('workspace_id', $wsId)->where('status', 'blocked')->count(),
                'note' => 'These are SEPARATE queues. Never add them together, and never '
                        . 'report one as if it were the other.',
            ],
            'experience'    => $experience,
            'conversation'  => $this->history($wsId, $conversationId),
        ];
    }

    /**
     * Recent turns for THIS conversation only.
     *
     * The legacy route filters `audit_logs` by workspace + agent_slug and takes the
     * last 20 messages with no conversation filter, so a second Sarah conversation in
     * the same workspace silently becomes "memory" of the first.
     */
    private function history(int $wsId, string $conversationId): array
    {
        $rows = DB::table('agent_messages')
            ->where('workspace_id', $wsId)
            ->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.conversation_id')) = ?",
                [$conversationId])
            ->orderByDesc('id')->limit(self::HISTORY_TURNS * 2)
            ->get(['role', 'content'])->reverse()->values();

        return $rows->map(fn ($r) => [
            'role' => $r->role === 'user' ? 'owner' : 'sarah',
            'text' => mb_substr((string) $r->content, 0, 600),
        ])->all();
    }

    /** Pass 1: answer, or ask Laravel to run exactly one capability. */
    private function reason(int $wsId, array $context, string $message): array
    {
        $system = "You are Sarah, the Digital Marketing Manager for this workspace. "
            . "You are talking to the business owner.\n\n"
            . "You are given STRUCTURED TRUTH as JSON: typed facts (each with its own unit "
            . "and period), the capabilities Laravel can execute for you, current approval "
            . "state, evidenced experience from this workspace, and this conversation's "
            . "recent turns.\n\n"
            . "RULES.\n"
            . "- Never invent a number. If a fact is not in `facts`, you do not know it.\n"
            . "- You cannot run anything yourself. Laravel executes capabilities for you.\n"
            . "- If answering needs live or external data, emit ONE tool_intent using a "
            . "capability_id from `capabilities` and its required_parameters. Never invent "
            . "a capability that is not listed.\n"
            . "- Otherwise answer directly from the facts.\n"
            . "- `capabilities` is the ONLY authority on what you can do here. NEVER "
            . "say you cannot do something, or that something is not configured, "
            . "without first checking that list. If a capability exists that would "
            . "answer the question — including questions about what you CAN do, such "
            . "as email_readiness — emit it instead of guessing.\n"
            . "- Never say you will do something later, and never say you have done "
            . "something. Nothing has run yet on this turn.\n"
            . "- Never OFFER to look something up. If a listed capability answers the "
            . "question, emit the tool_intent now. 'I can check X if you want' is only "
            . "correct when X would CHANGE something and needs their approval.\n"
            . "- `not_configured_here` lists capabilities this platform HAS but this "
            . "workspace has not set up, with the reason. If the question is about one of "
            . "them, say plainly that it is not connected here and why — do NOT substitute "
            . "a different capability to produce an answer anyway. Never emit a "
            . "tool_intent for anything in that list.\n"
            . "- `what_you_have_committed_to` is the durable record of promises made in "
            . "this workspace, and it outranks your recollection of the conversation. "
            . "Answer commitment questions from it and from nothing else — it is NOT a "
            . "work queue, so never answer it with task or approval counts, and never add "
            . "it to them. It states its own live total; use that number, not a sum you "
            . "compute. If something is not in it, say you have no record of it — do not "
            . "assert it never happened.\n"
            . "- Talk like a sharp colleague: short, direct, no bullet lists unless asked.\n\n"
            . "Reply with JSON only:\n"
            . '{"reply":"<your answer, or empty if you are emitting a tool_intent>",'
            . '"tool_intent":{"capability_id":"...","parameters":{...},"reason":"..."}|null}';

        return $this->call($system, json_encode($context, JSON_UNESCAPED_SLASHES)
            . "\n\nOWNER: " . $message, $wsId, 1200);
    }

    /** Pass 2: the answer, grounded in what Laravel actually returned. */
    private function interpret(int $wsId, array $context, string $message, array $tool): array
    {
        $system = "You are Sarah, the Digital Marketing Manager, talking to the owner.\n\n"
            . "Laravel ran (or declined to run) the capability you asked for. Answer the "
            . "owner from the TOOL RESULT below.\n\n"
            . "THIS TURN'S OUTCOME — binding:\n"
            . $this->statusDirective((string) ($tool['status'] ?? ''), (bool) ($tool['executed'] ?? false))
            . "\nThat outcome describes YOUR OWN tool call, not the subject the owner asked "
            . "about. Never report it to them: no 'the check ran successfully', no 'it was "
            . "refused because parameters were missing'. Measured 2026-08-14 — asked "
            . "\"did that SERP analysis actually run?\", Sarah answered \"it was refused "
            . "because keyword and location were missing\", describing her own failed call "
            . "as though it were the history the owner asked for. Use the result; do not "
            . "narrate it. If it did not give you what you need, ask the owner for what is "
            . "missing in their terms.\n"
            . "Use the numbers in `data` exactly. Search Console position is an AVERAGE "
            . "position, not a live SERP rank — never conflate them.\n"
            . "Answer directly from the result. Talk like a sharp colleague. Short. "
            . "No bullet lists unless asked.\n\n"
            . 'Reply with JSON only: {"reply":"<your answer>"}';

        // Only what interpretation needs. Pass 1 already reasoned over the full
        // context; re-sending it made pass 2 the slowest stage in the turn.
        $payload = [
            'owner_message' => $message,
            'tool_result'   => $this->trimForInterpretation($tool),
            'conversation'  => array_slice($context['conversation'], -4),
        ];

        return $this->call($system, json_encode($payload, JSON_UNESCAPED_SLASHES), $wsId, 600);
    }

    /**
     * The ONE binding rule for the outcome that actually happened.
     *
     * Laravel owns what its own governance statuses mean. Listing all six and asking the
     * model to work out which applies is the same antipattern as narrating a fact it can
     * already see typed: measured 2026-08-14, 72% of every generation was reasoning
     * tokens, and latency is ms = 1244 + 7.5 * tokens_out (R2 0.974). Deciding this in
     * PHP — where it is deterministic — removes deliberation the model should never have
     * been doing, and removes any chance it picks the wrong rule.
     *
     * The executed=false guard stays unconditional: it is the invariant that stops a
     * simulated or refused turn being described in the past tense.
     */
    private function statusDirective(string $status, bool $executed): string
    {
        $rule = match (true) {
            $status === ToolResult::SUCCEEDED =>
                "It really ran. You may describe the data as fact.",
            in_array($status, [ToolResult::REQUIRES_APPROVAL, ToolResult::WOULD_REQUIRE_APPROVAL], true) =>
                "NOTHING RAN. You can do it, but it needs their approval first.",
            in_array($status, [ToolResult::MISCONFIGURED, ToolResult::WOULD_BE_MISCONFIGURED], true) =>
                "NOTHING RAN. Say plainly what is not connected for this workspace.",
            in_array($status, [ToolResult::UNAVAILABLE, ToolResult::WOULD_BE_UNAVAILABLE], true) =>
                "You do not have that capability here. Say so about THAT capability only — "
                . "do not generalise it into a claim about anything else.",
            $status === ToolResult::FAILED =>
                "It was attempted and it failed. Say so.",
            in_array($status, [ToolResult::REFUSED, ToolResult::WOULD_BE_REFUSED], true) =>
                "NOTHING RAN. It was refused — say what was missing.",
            $status === ToolResult::WOULD_BE_PERMITTED =>
                "It was NOT run this turn.",
            default => "Nothing may be described as done.",
        };

        return "- {$status}: {$rule}\n"
            . ($executed ? '' :
               "- Nothing executed this turn: do NOT use the past tense about it, do NOT "
               . "promise to do it later, and do NOT say 'hold on'.\n");
    }

    /**
     * Keep the evidence, drop the bulk.
     *
     * Ranking data is the positions, URLs and domains — not the marketing copy in each
     * snippet. Truncating snippets keeps the result verifiable while removing the
     * tokens that made interpretation slow.
     */
    private function trimForInterpretation(array $tool): array
    {
        $d = $tool['data'] ?? null;
        if (!is_array($d)) return $tool;

        if (isset($d['top_results']) && is_array($d['top_results'])) {
            $d['top_results'] = array_map(fn ($r) => [
                'position' => $r['position'] ?? null,
                'domain'   => $r['domain'] ?? null,
                'title'    => mb_substr((string) ($r['title'] ?? ''), 0, 90),
            ], array_slice($d['top_results'], 0, 10));
        }
        foreach (['top_queries', 'top_pages'] as $k) {
            if (isset($d[$k]) && is_array($d[$k])) $d[$k] = array_slice($d[$k], 0, 10);
        }
        unset($d['serp_html'], $d['raw']);

        $tool['data'] = $d;
        return $tool;
    }

    /**
     * One runtime call, with the provider metadata the shadow needs to report.
     *
     * WHICH BRAIN ANSWERED IS PART OF THE EVIDENCE.
     * Runtime `/health` declares deepseek-v4-flash/pro, but a shadow SERP turn came back
     * from `gpt-4o-mini` on 2026-08-13 — the runtime's own OpenAI fallback (it returns
     * requested_provider / actual_provider / fallback_used / fallback_reason; see
     * RuntimeClient D-01/D-02). Reading only `model` recorded the fallback as if it were
     * the declared model, which is the same class of error as reporting a tool result
     * without its provenance: a cutover measurement of "which brain, at what latency"
     * would silently be measuring a different provider.
     */
    private function call(string $system, string $user, int $wsId, int $maxTokens): array
    {
        try {
            $r = $this->runtime->chatJson($system, $user,
                ['workspace_id' => $wsId, 'agent_slug' => 'sarah'], $maxTokens);
        } catch (\Throwable $e) {
            return ['parsed' => null, 'model' => null, 'error' => $e->getMessage()];
        }

        $parsed = is_array($r['parsed'] ?? null) ? $r['parsed'] : null;
        if ($parsed === null) {
            $txt = (string) ($r['text'] ?? '');
            if (preg_match('/\{.*\}/s', $txt, $m)) $parsed = json_decode($m[0], true);
        }

        $raw      = is_array($r['raw'] ?? null) ? $r['raw'] : [];
        $fallback = (bool) ($raw['fallback_used'] ?? $raw['fallback'] ?? false);
        $usage    = is_array($raw['usage'] ?? null) ? $raw['usage'] : [];

        return [
            'parsed' => is_array($parsed) ? $parsed : null,
            'model'  => $raw['actual_model'] ?? $raw['model'] ?? null,
            'provider'          => $raw['actual_provider'] ?? null,
            'requested_model'   => $raw['requested_model'] ?? null,
            'fallback_used'     => $fallback,
            'fallback_reason'   => $fallback
                ? mb_substr((string) ($raw['fallback_reason'] ?? ''), 0, 200) : null,
            // Latency forensics need the SHAPE of each call, not just its duration:
            // a pass that is slow because it generated 900 tokens is a different
            // problem from one that is slow because it was sent 40KB of prompt.
            'prompt_chars' => strlen($system) + strlen($user),
            'tokens_in'    => $usage['prompt_tokens'] ?? $usage['input_tokens'] ?? null,
            'tokens_out'   => $usage['completion_tokens'] ?? $usage['output_tokens'] ?? null,
            // DeepSeek bills reasoning AS output (RuntimeClient:1255), and measured
            // 2026-08-14 latency is ms = 1244 + 7.52 * tokens_out (R2 0.974). The
            // reasoning share IS the latency budget, so it is measured, not inferred
            // from how long the visible reply happens to be.
            'tokens_reasoning' => $usage['reasoning_tokens'] ?? null,
            'usage'  => $raw['usage'] ?? null,
        ];
    }
}
