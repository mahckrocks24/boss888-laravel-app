<?php

namespace App\Core\TaskSystem;

use App\Models\Task;
use App\Models\Approval;
use App\Core\Audit\AuditLogService;
use App\Core\Notifications\NotificationService;
use App\Core\EngineKernel\CapabilityMapService;

class TaskService
{
    public function __construct(
        private TaskDispatcher $dispatcher,
        private AuditLogService $auditLog,
        private NotificationService $notifications,
        private CapabilityMapService $capabilityMap,
        private TaskCategoryService $categoryService,
    ) {}

    /**
     * Create a task. Approval enforcement is MANDATORY.
     *
     * Approval modes (from CapabilityMap):
     *   auto      → dispatch immediately
     *   review    → block until human approves
     *   protected → ALWAYS require approval, no bypass
     */
    public function create(int $workspaceId, array $data): Task
    {
        $action = $data['action'];
        $engine = $data['engine'] ?? $this->resolveEngineSlug($action);

        // ── SARAH888 S1J-N01 — STAMP THE CORRELATION ENVELOPE ───────────────
        // Every agent_messages row has carried an execution_id since Phase 1A
        // and no task ever did: 565 of 565 tasks on Chef Red in 24h had
        // created_via and none had execution_id. That silently disabled two
        // things — ActionLedger::forConversation could not attribute any task
        // to the conversation that caused it, so the Phase 1J self-report guard
        // read a permanently-empty ledger and treated every denial as truthful;
        // and ActionLedger's `this_turn` flag, added in Phase 1D, has never
        // been true.
        //
        // Stamped here rather than at the ~18 call sites that each build their
        // own payload, for the same reason the Phase 1E cost gate lives here:
        // this is the one funnel they all pass through, and a per-caller
        // convention is one every future caller can forget. The union operator
        // is deliberate — an explicit value from the caller always wins, and an
        // unset context stamps nothing, which keeps queue workers and scheduled
        // jobs behaving exactly as they do now.
        $__corrStamp = app(\App\Core\Sarah888\CorrelationContext::class)->payloadStamp();
        if ($__corrStamp) {
            $__p = is_array($data['payload'] ?? null) ? $data['payload'] : [];
            $data['payload'] = $__p + $__corrStamp;
        }

        // LAUNCH SCOPE (2026-07-20) — creation-time choke point (memory-compat +
        // routing safety net). NO source — a promoted/remembered behavior, a saved
        // goal or strategy replay, a task template, a runtime proposal, or a direct
        // caller — may CREATE a task for a launch-removed engine/action, nor route a
        // task to a removed agent. Removed work is refused at creation, so it is
        // never queued, assigned, or shown (not merely refused at execution). The
        // retained blog-article-share passes because its payload/source carry the
        // article-share context marker that LaunchScopePolicy explicitly allows.
        $lsParams  = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        if (\App\Core\LaunchScope\LaunchScopePolicy::isRemoved($engine, $action, $lsParams, $data)) {
            \Illuminate\Support\Facades\Log::warning('[LaunchScope] task creation refused', [
                'workspace_id' => $workspaceId, 'engine' => $engine, 'action' => $action,
                'source' => $data['source'] ?? null,
            ]);
            throw new \RuntimeException("LAUNCH_SCOPE: task creation refused for removed capability {$engine}/{$action}");
        }

        // ── SARAH888 S1D-N01 — REFUSE AN UNMAPPED ACTION AT CREATION ────────
        // Sarah invents action names. Measured on Chef Red in 24h:
        //   enhance_seo 61, confirm_cancellation 23, check_progress 22,
        //   follow_up 21 — 127 tasks, none of those actions registered
        //   anywhere in the codebase.
        // They were accepted here, queued, dispatched, and only died at
        // execution with "No capability mapped for action: X". That burns a
        // worker slot, pollutes the failure count the owner is shown, and
        // teaches Sarah nothing, because the refusal never reaches her turn.
        // Refuse at the same choke point that already refuses removed
        // capabilities, so the caller finds out immediately.
        // ── SARAH888 S1L-D05 — FORBIDDEN TIER AT THE FUNNEL ─────────────────
        // Above 'protected': protected means a human must say yes, forbidden
        // means there is no yes. None of these action names are registered
        // today, so this blocks nothing that currently works — it is the
        // forward net, so that registering `approve_invoice` tomorrow does not
        // silently make payment approval executable. Refused at the same choke
        // point that already refuses removed and unmapped capabilities.
        // ── SARAH888 S1L-D05 (residual) — A REFUSED TURN CREATES NOTHING ────
        // Live verification of the first 1N.1 build returned "I can't approve
        // or release payments… ✅ Queued 1 tasks (Elena: 1)." The refusal was
        // correct and the write happened anyway, because the queued action was
        // `create_lead` — the forbidden operation lived only in the owner's
        // request and never reached the action column, so neither the
        // action-name net above nor the reply-time guard could stop it.
        // A refusal contradicted by a side effect is worse than no refusal.
        // ── SARAH888 S1P-D02 — CANCELLATION IS DOMAIN-SCOPED ────────────────
        // "Cancel the hygiene certificate renewal" mutated a CRM lead to
        // status='lost' because a lead's name resembled a commitment's name.
        // Two leads on Chef Red still carry that status. Engine IS domain, so
        // the check is simply: does this engine own the entity that was
        // actually resolved? Nothing resolved, or resolved in two domains,
        // means nothing may be written — a confident cross-domain guess is the
        // entire defect.
        $__cancel = app(\App\Core\Sarah888\CancellationPolicy::class);
        if (!$__cancel->permits($engine)) {
            \Illuminate\Support\Facades\Log::warning('[Sarah888] task creation refused — cancellation domain boundary', [
                'workspace_id' => $workspaceId, 'engine' => $engine, 'action' => $action,
                'reference' => $__cancel->reference(), 'resolution' => $__cancel->resolution(),
                'resolved_domain' => $__cancel->domain(),
            ]);
            throw new \RuntimeException('CANCELLATION_DOMAIN: this turn cancels "'
                . $__cancel->reference() . '" (' . $__cancel->resolution()
                . '); a ' . $engine . ' mutation is not in scope');
        }

        // ── SARAH888 S1Q-N01 — GOVERNANCE PRECEDES EXECUTION ELIGIBILITY ────
        // The earlier build created the task and then tried to cancel it. Work
        // that completed inside the turn could not be withdrawn, so the control
        // was a race rather than a boundary. The decision now happens BEFORE
        // the row exists: no executable task, no queue dispatch, no credit
        // reserved, nothing for TurnWork to reverse.
        //
        // permitsCreation() rather than turnIsForbidden(): a turn that named a
        // REFUSE_OUTRIGHT capability creates nothing at all, EXCEPT where the
        // owner authorised a fallback in the same breath ("send the email, or
        // if you can't, draft a post") — that substitute is genuinely what they
        // asked for.
        $__authority = app(\App\Core\Sarah888\ActionAuthority::class);
        if (!$__authority->permitsCreation()) {
            \Illuminate\Support\Facades\Log::warning('[Sarah888] task creation refused — turn requested a REFUSE_OUTRIGHT capability', [
                'workspace_id' => $workspaceId, 'action' => $action, 'engine' => $engine,
                'capabilities' => $__authority->turnOperations(),
            ]);
            throw new \RuntimeException('REFUSE_OUTRIGHT: this turn requested '
                . implode(', ', $__authority->turnOperations())
                . ' — no executable work may be created for it');
        }

        if (app(\App\Core\Sarah888\ActionAuthority::class)->isForbiddenAction($action)) {
            \Illuminate\Support\Facades\Log::warning('[Sarah888] task creation refused — forbidden action tier', [
                'workspace_id' => $workspaceId, 'engine' => $engine, 'action' => $action,
                'source' => $data['source'] ?? null,
            ]);
            throw new \RuntimeException("FORBIDDEN_ACTION: '{$action}' may never be executed, with or without approval");
        }

        if ($this->capabilityMap->resolve($action) === null) {
            \Illuminate\Support\Facades\Log::warning('[Sarah888] task creation refused — unmapped action', [
                'workspace_id' => $workspaceId, 'engine' => $engine, 'action' => $action,
                'source' => $data['source'] ?? null,
            ]);
            throw new \RuntimeException("UNMAPPED_ACTION: no capability is registered for action '{$action}'");
        }
        // Never route a task to a removed agent (strip; the action itself is allowed).
        if (!empty($data['assigned_agents']) && is_array($data['assigned_agents'])) {
            $data['assigned_agents'] = array_values(array_filter(
                $data['assigned_agents'],
                fn($a) => !(is_string($a) && \App\Core\LaunchScope\LaunchScopePolicy::isRemovedAgent($a))
            ));
        }

        // 2026-05-27 — derive task category from (engine, action). Single
        // source of truth for the taxonomy. Manual override still possible
        // via $data['category']. Validation: invalid categories fall back to
        // the derived value so the column never holds garbage.
        $category = $data['category'] ?? null;
        if (!$category || !$this->categoryService->isValid($category)) {
            $category = $this->categoryService->for($engine, $action);
        }

        // Resolve approval mode from capability map (kept as a safety net /
        // narrower override; category is now the primary authority).
        $approvalMode = $this->capabilityMap->getApprovalMode($action);
        $creditCost = $data['credit_cost'] ?? $this->capabilityMap->getCreditCost($action);

        // 2026-05-27 Phase 4 — category is the primary authority for default
        // approval routing. Per the masterplan's 7-category taxonomy:
        //   - publish   → HARD require approval (going-live safety rail)
        //   - create    → require approval (generative output for the brand)
        //   - crm       → require approval (touches relationships)
        //   - campaign  → require approval (multi-step marketing)
        //   - research  → auto-run (read-only, low risk)
        //   - optimize  → auto-run (incremental tweaks)
        //   - operations → auto-run (system housekeeping)
        //
        // CapabilityMap remains a safety net: if an action is explicitly
        // marked 'protected' (the strictest setting), that still wins so
        // category=research with cap=protected still requires approval.
        // Explicit requires_approval on the caller side also wins.
        $categoryDefault = match ($category) {
            'publish'                              => 'protected',
            'create', 'crm', 'campaign'            => 'review',
            'research', 'optimize', 'operations'   => 'auto',
            default                                => 'review',
        };
        // Pick the MORE restrictive of category-default and cap-map-mode.
        // Strictness: protected > review > auto.
        $strictness = ['auto' => 0, 'review' => 1, 'protected' => 2];
        $effectiveMode = ($strictness[$approvalMode] ?? 1) > ($strictness[$categoryDefault] ?? 0)
            ? $approvalMode
            : $categoryDefault;

        $requiresApproval = match ($effectiveMode) {
            'auto'      => array_key_exists('requires_approval', $data) ? (bool) $data['requires_approval'] : false,
            'review'    => empty($data['auto_approve']) ? ($data['requires_approval'] ?? true) : false,
            'protected' => true,
            default     => true,
        };

        // ── SARAH888 PHASE 1E SLICE 1E.2 — COST GATE ────────────────────────
        // F1-D07: asking "how many published articles do we have?" queued four
        // paid image generations and spent 8 credits. The mechanism is directly
        // above — callers pass auto_approve => true for anything non-destructive,
        // which turns 'review' into no approval. Approval was therefore decided
        // by DESTRUCTIVENESS and never by COST.
        //
        // Slice 1E.1 gated the chat route's create_tasks loop and that was not
        // enough: three paid creation paths exist and the one that caused the
        // original failure (WriteService::fillMissingImages, via the router)
        // never passes through it. This is the funnel all five callers share.
        //
        // It can only RAISE the bar — an approval already required stands, and
        // free work is untouched. It fails CLOSED: no turn context means no
        // authorization, so a queue worker or scheduled job cannot spend on a
        // user's behalf just by not knowing about this.
        if (!$requiresApproval && $creditCost > 0) {
            try {
                $ctx = app(\App\Core\Sarah888\SpendContext::class);
                if (!$ctx->isAuthorized()) {
                    $requiresApproval = true;
                    $ctx->recordHeld($action, (int) $creditCost);
                    \Illuminate\Support\Facades\Log::info('[Sarah888] spend gated at creation', [
                        'workspace_id' => $workspaceId, 'action' => $action,
                        'credit_cost' => $creditCost,
                        'classification' => $ctx->turn()['classification'] ?? '?',
                        'reason' => $ctx->turn()['reason'] ?? '?',
                    ]);
                }
            } catch (\Throwable $e) {
                // Unresolvable context is not permission to spend.
                $requiresApproval = true;
                \Illuminate\Support\Facades\Log::warning('[Sarah888] spend context unavailable — held the spend', [
                    'workspace_id' => $workspaceId, 'action' => $action, 'error' => $e->getMessage(),
                ]);
            }
        }
        // Publish stays a HARD override regardless of any caller hint — EXCEPT
        // when the caller has already captured an explicit user confirmation for
        // an ARTICLE publish (2026-07-23, Boss decision). Sarah's chat path runs a
        // two-turn confirm and the affirmative is matched server-side from the
        // user's own message, so requiring a second Review-Queue click asked for
        // the same consent twice and left confirmed articles sitting unpublished.
        // Scope is deliberately narrow: `publish_article` only. Website and page
        // publishes, and every other publish-category action, stay hard-gated.
        if ($category === 'publish') {
            $requiresApproval = ! (! empty($data['user_confirmed']) && $action === 'publish_article');
        }

        // ── SARAH888 CERT-A-D02 — AN UNCOMMISSIONED TURN CREATES NO
        //    EXECUTABLE WORK ────────────────────────────────────────────────
        // Certification Pass A queued 18 tasks against invented premises —
        // a distributor summit, a Reykjavik deal, a 2.4m facility — and every
        // one reached status=completed before the reversal guard ran, because
        // create_lead is fast and costs nothing. Reversal cannot win that race.
        //
        // SpendPolicy classified this turn when the request arrived:
        // 'question' and 'statement' mean the owner asked for something and
        // commissioned nothing. Mutating work from such a turn is held in an
        // approval state rather than executed, so the owner still sees what was
        // proposed and nothing touches their data until they say so.
        //
        // THE RESEARCH EXEMPTION IS GONE.
        // It existed so Sarah could look something up in order to answer the
        // question she was asked, and it was correct when written. It is not
        // any more: the execution-attributed battery lost 8 of 30 read-only
        // scenarios to free research lookups spawned from questions like
        // "what is our earliest deadline?".
        //
        // The capability that justified the exception has been replaced by a
        // better one. DerivedState, TemporalAnchor and CognitiveFrame answer
        // earliest deadline, next deadline, counts, overdue, ownership and
        // current state deterministically, and they are already in the frame
        // before the model reads the turn. Sarah no longer needs to spawn a
        // task to answer a question about her own workspace.
        //
        // READ_ONLY means zero task rows. No category is exempt from that.
        // 2026-08-08 — THIS GATE NOW REFUSES CREATION. It used to set
        // requires_approval = true and let the row be written.
        //
        // Measured on the forensic tenant: of 139 replies that asked the owner
        // "shall I proceed?", 111 (80%) had already created tasks in the same
        // execution_id. The prompts driving them were questions — "what is the
        // earliest deadline on record?", "who is carrying the most active
        // commitments?" — and they produced create_lead and write_article rows.
        // A question was writing to CRM.
        //
        // Holding the row was never sufficient. A persisted task has an
        // idempotency key, a batch id, a credit cost and a queue position; it
        // appears in counts, in the approval queue, and in every "what is
        // outstanding" answer Sarah gives. The owner asked a question and their
        // workspace changed. requires_approval only decides whether the row
        // RUNS — the check lives in markRunning(), long after this point — so
        // it is a downstream defense, and the invariant it was standing in for
        // is a creation-time one:
        //
        //   READ_ONLY / ASK_FIRST  =  zero task rows, zero queue entries,
        //                             zero credit reservations, zero mutations
        //                             until a later explicit authorization.
        //
        // So this refuses, exactly as the REFUSE_OUTRIGHT gate above does, and
        // for the same reason: the only reliable way to guarantee a turn
        // changed nothing is for it to write nothing.
        //
        // `!$requiresApproval` was also dropped from the condition. It meant an
        // uncommissioned turn escaped this gate entirely whenever approval was
        // already required for some other reason — the exact case where the row
        // is most expensive to have created.
        //
        // Research stays exempt: Sarah must be able to look something up in
        // order to answer the question she was asked.
        // AN APPROVED PROPOSAL IS THE COMMISSION.
        // Consent recorded on a proposal was given in an earlier turn, so the
        // wording of the turn that redeems it says nothing about authority.
        // Without this, a bare "Yes." classified as a statement and the
        // platform refused work the owner had just explicitly approved,
        // while "Yes, go ahead." went through — the same decision, decided
        // by phrasing. Keyed on the receipt, like the ASK_FIRST gate above.
        $__turn = app(\App\Core\Sarah888\SpendContext::class)->turn();
        if (empty($data['authorized_by_proposal'])
            && !($__turn['authorized'] ?? false)
            && in_array($__turn['classification'] ?? 'none', ['question', 'statement'], true)
            // RESEARCH IS EXEMPT ONLY WHILE IT IS FREE.
            // The exemption exists so Sarah can look something up in order to
            // answer the question she was asked. It assumed research meant a
            // free lookup. It does not: the 100-scenario battery lost 7 of 30
            // read-only cases to deep_audit, a research action costing 3 credits
            // and requiring approval. Asking "what is our earliest deadline?"
            // was spending the owner's money. Free lookups still run; paid
            // research from an uncommissioned turn does not.
            ) {   // NO RESEARCH EXEMPTION — see below
            \Illuminate\Support\Facades\Log::warning('[Sarah888] UNCOMMISSIONED_TURN — task creation REFUSED', [
                'workspace_id' => $workspaceId, 'action' => $action, 'category' => $category,
                'classification' => $__turn['classification'] ?? null,
            ]);
            throw new \App\Core\TaskSystem\Exceptions\TaskCreationNotAuthorized(
                reason: 'UNCOMMISSIONED_TURN',
                action: $action,
                context: [
                    'workspace_id'       => $workspaceId,
                    'capability'         => $category,
                    'intent_class'       => $__turn['classification'] ?? null,
                    'authorization_state'=> ($__turn['authorized'] ?? false) ? 'authorized' : 'unauthorized',
                    'governance_policy'  => 'creation_gated',
                    'execution_id'       => $__turn['execution_id'] ?? null,
                    'conversation_id'    => $__turn['conversation_id'] ?? null,
                    'source_message_id'  => $__turn['source_message_id'] ?? null,
                ],
                message: "UNCOMMISSIONED_TURN: this turn was classified as a "
                    . ($__turn['classification'] ?? 'non-directive')
                    . " and did not authorize execution, so no work may be created for '{$action}'. "
                    . "Propose it to the owner and create it after they say yes."
            );
        }

        // ── AN UNSPECIFIED DELEGATION COMMISSIONS NOTHING ──────────────────
        // "Just handle it." / "Make it happen." / "Whatever you think is best."
        // Eight of ten adversarial scenarios created real tasks from turns that
        // named no deliverable at all. There was nothing to authorise, so the
        // work was invented rather than delegated — the owner cannot approve a
        // request they never made.
        //
        // Sarah should ask what is meant. She must not decide for them.
        if (($data['source'] ?? '') === 'agent' && empty($data['authorized_by_proposal'])) {
            $__vt = app(\App\Core\Sarah888\SpendContext::class)->turn();
            if (($__vt['classification'] ?? 'none') !== 'none'
                && array_key_exists('specifies_action', $__vt)
                && $__vt['specifies_action'] === false) {

                \Illuminate\Support\Facades\Log::warning('[Sarah888] UNSPECIFIED_DELEGATION — refused', [
                    'workspace_id' => $workspaceId, 'action' => $action,
                    'classification' => $__vt['classification'] ?? null,
                ]);
                throw new \App\Core\TaskSystem\Exceptions\TaskCreationNotAuthorized(
                    reason: 'UNSPECIFIED_DELEGATION',
                    action: $action,
                    context: ['workspace_id' => $workspaceId, 'capability' => $category,
                              'governance_policy' => 'creation_gated',
                              'authorization_state' => 'no_action_named'],
                    message: "UNSPECIFIED_DELEGATION: that turn did not name anything to do, so "
                           . "no work was created. Ask the owner what they want rather than choosing for them."
                );
            }
        }
        // ── ASK_FIRST CREATES NOTHING ──────────────────────────────────────
        // Approval used to be recorded ON the row: the task was created with
        // requires_approval = 1 and the check happened later, in markRunning().
        // Measured before this gate, "Create the winter campaign." produced a
        // task row carrying a 5-credit cost and an approval row beside it,
        // before the owner had said anything. A held row is still a row — it
        // has an idempotency key, a queue position and a cost, and it appears
        // in every "what is outstanding" answer Sarah gives.
        //
        // Work needing consent is therefore PROPOSED, not created. The proposal
        // records the exact payload and ProactiveStrategyEngine builds the task
        // at the moment of approval — the lifecycle that engine already runs.
        //
        // SCOPE. Applying this to everything approval-flagged would have turned
        // routine work into a permission request: crm.create_lead is
        // approval-flagged by default, so Sarah would have stopped acting and
        // started asking, for most of what she does. The invariant is about
        // consequence, not paperwork, so the gate is limited to actions that
        // spend money, publish, or reach outside the workspace — and every
        // other approval-flagged action keeps its existing behaviour.
        //
        // Background callers are excluded too. An absent turn context means
        // there was no conversation — a queue worker, the daily cycle,
        // fill_missing_images — and there is no owner present to ask. Their
        // spend is governed by the Phase-2 gate, not by this one.
        if ($requiresApproval
            && empty($data['authorized_by_proposal'])
            && ($data['source'] ?? '') === 'agent'
            && $this->askFirstApplies($action, $category, (int) ($data['credit_cost'] ?? 0))) {

            $__t = app(\App\Core\Sarah888\SpendContext::class)->turn();
            $__hasTurn = ($__t['classification'] ?? 'none') !== 'none';

            if ($__hasTurn) {
                $__corr = [];
                try {
                    $__c = app(\App\Core\Sarah888\CorrelationContext::class);
                    $__corr = ['conversation_id' => $__c->conversationId(),
                               'execution_id'    => $__c->executionId()];
                } catch (\Throwable) { /* proposal still records the action */ }

                $__p = app(\App\Core\Sarah888\ChatActionProposal::class)->propose(
                    $workspaceId,
                    array_merge($data, ['engine' => $engine, 'action' => $action,
                        'category' => $category, 'credit_cost' => $data['credit_cost'] ?? 0]),
                    $__corr
                );

                \Illuminate\Support\Facades\Log::info('[Sarah888] ASK_FIRST — proposed, not created', [
                    'workspace_id' => $workspaceId, 'action' => $action,
                    'proposal_id' => $__p['proposal_id'], 'approval_id' => $__p['approval_id'],
                ]);

                throw new \App\Core\TaskSystem\Exceptions\TaskCreationNotAuthorized(
                    reason: 'ASK_FIRST_PROPOSED',
                    action: $action,
                    context: array_merge([
                        'workspace_id' => $workspaceId, 'capability' => $category,
                        'proposal_id' => $__p['proposal_id'], 'approval_id' => $__p['approval_id'],
                        'credit_cost' => $data['credit_cost'] ?? 0,
                        'governance_policy' => 'creation_gated',
                        'authorization_state' => 'awaiting_owner',
                    ], $__corr),
                    message: "ASK_FIRST_PROPOSED: '{$action}' needs your approval, so nothing has been "
                           . "created or charged. It is waiting as proposal #{$__p['proposal_id']}."
                );
            }
        }

        // Generate idempotency key
        // FIX 2026-04-11: JSON_SORT_KEYS is not a real PHP constant. Use ksort
        // for deterministic hashing (top-level keys only, sufficient for the
        // shallow payloads that engine actions produce).
        $payload = $data['payload'] ?? [];
        $payloadForHash = $payload;
        if (is_array($payloadForHash)) ksort($payloadForHash);
        $idemKey = $data['idempotency_key'] ?? hash('sha256',
            "{$workspaceId}:{$action}:" . json_encode($payloadForHash));

        // v1.4.4 (2026-05-30) — batched approvals. Caller (Sarah's chat loop
        // in routes/api.php) generates one batch_id per (action) group when
        // create_tasks contains multiple entries of the same action. We
        // persist it on the task and use it to fold approvals: only the
        // FIRST task of a (workspace_id, batch_id, action) trio creates an
        // approval row; subsequent tasks just inherit by virtue of sharing
        // the batch_id. The approval+reject endpoints cascade across all
        // sibling tasks via batch_id.
        $batchId = isset($data['batch_id']) ? (string) $data['batch_id'] : null;

        // 2026-07-15 — idempotent guard (dup-as-failure fix). idempotency_key
        // is globally UNIQUE; a repeat (e.g. Sarah emitting two identical tasks
        // in one turn) previously threw a 1062 duplicate-key error that the
        // chat loop logged as "Task creation failed" and reported to the user
        // as a failure. Return the EXISTING task instead — that is the whole
        // point of an idempotency key (repeat request -> same result).
        $existingIdemTask = Task::where('idempotency_key', $idemKey)->first();
        if ($existingIdemTask) {
            return $existingIdemTask;
        }

        $task = Task::create([
            'workspace_id' => $workspaceId,
            'parent_task_id' => $data['parent_task_id'] ?? null,
            'batch_id' => $batchId,
            'engine' => $engine,
            'action' => $action,
            'category' => $category,
            'payload_json' => $payload ?: null,
            'source' => $data['source'] ?? 'manual',
            'assigned_agents_json' => $data['assigned_agents'] ?? null,
            'priority' => $data['priority'] ?? 'normal',
            'requires_approval' => $requiresApproval,
            'credit_cost' => $creditCost,
            'idempotency_key' => $idemKey,
        ]);

        if ($requiresApproval) {
            $task->update(['approval_status' => 'pending']);

            // Batch-fold: if another pending approval already exists for
            // this workspace + batch_id + action, do NOT create a new one.
            // The user will see ONE row in Command Center for the whole
            // batch; on Approve/Reject the cascade in ApprovalController
            // updates every sibling task by batch_id.
            $existingBatchApproval = null;
            if ($batchId) {
                $existingBatchApproval = Approval::where('workspace_id', $workspaceId)
                    ->where('batch_id', $batchId)
                    ->where('action', $action)
                    ->where('status', 'pending')
                    ->first();
            }

            if (!$existingBatchApproval) {
                // INFRA888 Phase 1D — persist the REQUESTER so the approval
                // layer can enforce separation of duties. Without this the
                // requester is unknown and protected capabilities fail closed.
                $requestedBy = $data['user_id'] ?? ($data['created_by'] ?? null);
                $requesterType = !empty($data['agent_id']) ? 'agent' : ($requestedBy ? 'user' : 'system');

                Approval::create([
                    'workspace_id'         => $workspaceId,
                    'task_id'              => $task->id,
                    'batch_id'             => $batchId,
                    'engine'               => $engine,
                    'action'               => $action,
                    'status'               => 'pending',
                    'requested_by'         => $requestedBy,
                    'requester_actor_type' => $requesterType,
                    'capability_key'       => $engine . '.' . $action,
                    'approval_policy_json' => \App\Core\Governance\ApprovalPolicyRegistry::forCapability(
                        $engine, $action, $approvalMode ?? 'review'
                    ),
                ]);

                $this->notifications->send($workspaceId, 'task', 'task.approval_required', [
                    'task_id'        => $task->id,
                    'engine'         => $task->engine,
                    'action'         => $task->action,
                    'approval_mode'  => $approvalMode,
                    'batch_id'       => $batchId,
                ]);
            }
            // else: batch already has a pending approval — this task just
            // inherits, no new notification (would spam the user with N).
        } else {
            // Auto mode — dispatch immediately
            $this->dispatcher->dispatch($task);
        }

        // 2026-05-27 Phase 5 — include category in audit metadata so post-hoc
        // analytics can filter the audit trail by category without parsing
        // payload JSON. Also surfaces the effective approval mode (which may
        // differ from raw cap-map mode when category overrides applied).
        $this->auditLog->log($workspaceId, null, 'task.created', 'Task', $task->id, [
            'engine'            => $task->engine,
            'action'            => $task->action,
            'category'          => $task->category,
            'source'            => $task->source,
            'approval_mode'     => $approvalMode,
            'effective_mode'    => $effectiveMode,
            'requires_approval' => $requiresApproval,
        ]);

        return $task;
    }

    /**
     * Does an action need consent BEFORE it exists, rather than approval before
     * it runs?
     *
     * Consequence, not paperwork. Three things qualify:
     *   1. it spends credits — money leaves the workspace
     *   2. it publishes — the output becomes visible outside the workspace
     *   3. it is a capability ActionAuthority already types as consequential —
     *      destructive, broadcast, security, billing or workspace membership
     *
     * The third reuses the governance registry rather than restating it, so a
     * capability added there is covered here automatically and the two cannot
     * drift apart. Everything else — routine CRM and content work — keeps its
     * existing behaviour and still honours requires_approval before running.
     */
    private function askFirstApplies(string $action, string $category, int $creditCost): bool
    {
        if ($creditCost > 0)        return true;
        if ($category === 'publish') return true;

        foreach (\App\Core\Sarah888\ActionAuthority::CAPABILITIES as $key => $_) {
            [$domain] = explode('.', $key, 2);
            if (in_array($domain, ['destructive', 'messaging', 'security', 'billing', 'workspace'], true)
                && str_contains($key, $action)) {
                return true;
            }
        }
        return false;
    }
    public function find(int $taskId): ?Task
    {
        return Task::find($taskId);
    }

    public function listForWorkspace(int $workspaceId, array $filters = []): \Illuminate\Database\Eloquent\Collection
    {
        $query = Task::where('workspace_id', $workspaceId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (isset($filters['engine'])) {
            $query->where('engine', $filters['engine']);
        }

        return $query->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->get();
    }

    public function markRunning(Task $task): void
    {
        // ENFORCEMENT: verify approval before running
        if ($task->requires_approval && $task->approval_status !== 'approved') {
            throw new \RuntimeException(
                "Task {$task->id} requires approval (current: {$task->approval_status}). Cannot run."
            );
        }

        $task->update(['status' => 'running', 'started_at' => now()]);
    }

    public function markCompleted(Task $task, ?array $result = null): void
    {
        $task->update([
            'status' => 'completed',
            'result_json' => $result,
            'completed_at' => now(),
            // 2026-06-08 — finalize progress_message on completion. It was set to
            // "Executing step N of M" at dispatch and never cleared, so terminal
            // tasks kept reading as in-progress (root of the phantom "pending
            // tasks" issue where Sarah narrated finished work as current).
            'progress_message' => 'Completed: ' . str_replace('_', ' ', (string) $task->action),
        ]);

        $this->notifications->send($task->workspace_id, 'task', 'task.completed', [
            'task_id' => $task->id,
            'action' => $task->action,
        ]);
    }

    public function markFailed(Task $task, string $error, bool $terminal = false): void
    {
        $task->increment('retry_count');
        $maxRetries = 4;

        // H2: a terminal (non-retryable) failure must NOT consume the generic
        // retry budget — the caller already classified it as permanent. Fail now.
        if (! $terminal && $task->retry_count < $maxRetries) {
            $task->update(['status' => 'queued']);
            $this->dispatcher->dispatch($task);
        } else {
            $task->update([
                'status' => 'failed',
                'error_text' => $error,
                'completed_at' => now(),
                // 2026-06-08 — finalize progress_message (see markCompleted).
                'progress_message' => 'Failed: ' . str_replace('_', ' ', (string) $task->action),
            ]);
            $this->notifications->send($task->workspace_id, 'task', 'task.failed', [
                'task_id' => $task->id,
                'error' => $error,
            ]);
        }
    }

    private function resolveEngineSlug(string $action): string
    {
        $cap = $this->capabilityMap->resolve($action);
        return $cap['engine'] ?? 'unknown';
    }

    /**
     * Requeue a task with optional delay (for throttling/retry).
     */
    public function requeue(Task $task, int $delaySeconds = 5): void
    {
        $task->update(['status' => 'queued']);
        $this->dispatcher->dispatchWithDelay($task, $delaySeconds);
    }
}
