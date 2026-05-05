<?php

namespace App\Core\TaskSystem;

use App\Models\Task;
use App\Core\EngineKernel\CapabilityMapService;
use App\Core\Billing\CreditService;
use App\Core\Audit\AuditLogService;
use App\Core\Memory\WorkspaceMemoryService;
use App\Connectors\ConnectorResolver;
use App\Services\ParameterResolverService;
use App\Services\IdempotencyService;
use App\Services\ConnectorCircuitBreakerService;
use App\Services\TaskProgressService;
use App\Services\QueueControlService;
use App\Services\ExecutionRateLimiterService;
use App\Core\PlanGating\PlanGatingService;
use Illuminate\Support\Facades\Log;

class Orchestrator
{
    private int $maxStepsPerTask = 5;
    private int $maxRetries = 2;

    public function __construct(
        private CapabilityMapService $capabilityMap,
        private CreditService $creditService,
        private TaskService $taskService,
        private AuditLogService $auditLog,
        private WorkspaceMemoryService $memory,
        private ConnectorResolver $connectorResolver,
        private ParameterResolverService $parameterResolver,
        private IdempotencyService $idempotency,
        private ConnectorCircuitBreakerService $circuitBreaker,
        private TaskProgressService $progress,
        private QueueControlService $queueControl,
        private ExecutionRateLimiterService $rateLimiter,
        private PlanGatingService $planGating,
    ) {}

    public function execute(Task $task): void
    {
        $reservationRef = null;

        try {
            // ── 0. Idempotency check ─────────────────────────────────────
            $idemKey = $this->idempotency->ensureKey($task);

            $duplicate = $this->idempotency->checkDuplicate($idemKey);
            if ($duplicate) {
                $task->update([
                    'status' => 'completed',
                    'result_json' => $duplicate['result'],
                    'completed_at' => now(),
                    'progress_message' => 'Duplicate — returned cached result',
                ]);
                $this->progress->recordEvent($task->id, 'idempotent_skip', 'completed',
                    message: 'Duplicate execution skipped');
                return;
            }

            if (! $this->idempotency->acquireLock($idemKey)) {
                $task->update(['status' => 'blocked', 'progress_message' => 'Execution lock held by another worker']);
                $this->progress->recordEvent($task->id, 'lock_blocked', 'blocked',
                    message: 'Could not acquire execution lock');
                return;
            }

            // ── 1. Plan gating check ──────────────────────────────────
            $planCheck = $this->planGating->check($task->workspace_id, $task->action);
            if (! $planCheck['allowed']) {
                $task->update(['status' => 'failed', 'progress_message' => $planCheck['reason']]);
                $this->progress->recordEvent($task->id, 'plan_gated', 'failed',
                    message: $planCheck['reason']);
                $this->idempotency->releaseLock($idemKey);
                return;
            }

            // ── 2. Resolve capability ────────────────────────────────────
            $capability = $this->capabilityMap->resolve($task->action);
            if (! $capability) {
                throw new \RuntimeException("No capability mapped for action: {$task->action}");
            }

            $connectorName = $capability['connector'];

            // ── 2. Circuit breaker check ─────────────────────────────────
            if ($connectorName && ! $this->circuitBreaker->isAvailable($connectorName)) {
                $task->update(['status' => 'degraded', 'progress_message' => "Connector {$connectorName} circuit open"]);
                $this->progress->recordEvent($task->id, 'circuit_open', 'degraded',
                    connector: $connectorName, message: 'Circuit breaker open — execution blocked');
                $this->idempotency->releaseLock($idemKey);
                return;
            }

            // ── 3. Rate limit check ──────────────────────────────────────
            $agentSlug = $this->extractAgentSlug($task);
            $rateCheck = $this->rateLimiter->check($task->workspace_id, $agentSlug, $connectorName ?? '');
            if (! $rateCheck['allowed']) {
                $task->update(['status' => 'blocked', 'progress_message' => $rateCheck['reason']]);
                $this->progress->recordEvent($task->id, 'rate_limited', 'blocked',
                    message: $rateCheck['reason']);
                $this->idempotency->releaseLock($idemKey);
                return;
            }

            // ── 4. Workspace concurrency check ───────────────────────────
            if (! $this->queueControl->canWorkspaceExecute($task->workspace_id)) {
                $task->update(['status' => 'queued', 'progress_message' => 'Workspace at concurrency cap — requeued']);
                $this->progress->recordEvent($task->id, 'throttled', 'queued',
                    message: 'Workspace concurrency cap reached');
                $this->idempotency->releaseLock($idemKey);
                // Re-dispatch with delay
                $this->taskService->requeue($task, 10);
                return;
            }

            // ── 5. Parameter resolution ──────────────────────────────────
            $payload = $task->payload_json ?? [];
            $resolution = $this->parameterResolver->resolve($task->workspace_id, $task->action, $payload);

            if (! $resolution['resolved']) {
                throw new \RuntimeException('Missing required parameters: ' . implode(', ', $resolution['missing']));
            }

            $validatedParams = $resolution['params'];

            // ── 6. Mark running ──────────────────────────────────────────
            $this->taskService->markRunning($task);
            $task->update(['execution_started_at' => now()]);
            $this->progress->recordEvent($task->id, 'execution_started', 'running',
                action: $task->action, message: 'Execution started');

            // ── 7. Credit reservation (reserve upfront for all steps) ────
            $creditCost = $capability['credit_cost'] ?? $task->credit_cost;
            if ($creditCost > 0) {
                $reservation = $this->creditService->reserveCredits(
                    $task->workspace_id, $creditCost, 'Task', $task->id, "task_{$task->id}"
                );
                $reservationRef = $reservation->reservation_reference;
                $this->progress->recordEvent($task->id, 'credits_reserved', 'running',
                    message: "Reserved {$creditCost} credits (ref: {$reservationRef})");
            }

            // ── 8. Execute steps ─────────────────────────────────────────
            $steps = $this->resolveSteps($task, $validatedParams);
            $totalSteps = min(count($steps), $this->maxStepsPerTask);
            $task->update(['total_steps' => $totalSteps]);

            $results = [];
            foreach ($steps as $i => $step) {
                if ($i >= $this->maxStepsPerTask) break;

                $this->progress->updateProgress($task, $i + 1, $totalSteps,
                    "Executing step " . ($i + 1) . " of {$totalSteps}: {$step['action']}");

                $stepResult = $this->executeStep($task, $step['action'], $step['params'], $capability, $i);
                $results[] = $stepResult;

                if (! $stepResult['success']) {
                    throw new \RuntimeException("Step " . ($i + 1) . " ({$step['action']}) failed: {$stepResult['message']}");
                }
            }

            // ── 9. Commit credits ────────────────────────────────────────
            if ($reservationRef && $reservationRef !== 'zero_cost') {
                $this->creditService->commitReservedCredits($reservationRef);
                $this->progress->recordEvent($task->id, 'credits_committed', 'running',
                    message: "Credits committed (ref: {$reservationRef})");
            }

            // ── 10. Finalize ─────────────────────────────────────────────
            $finalResult = count($results) === 1 ? $results[0] : [
                'success' => true,
                'data' => $results,
                'message' => 'All ' . count($results) . ' steps completed',
            ];

            $task->update(['execution_finished_at' => now()]);
            $this->taskService->markCompleted($task, $finalResult);
            $this->progress->recordEvent($task->id, 'execution_completed', 'completed',
                message: 'Task completed successfully');

            // Record rate limit usage
            $this->rateLimiter->record($task->workspace_id, $agentSlug, $connectorName ?? '');

            // ── 11. Audit ────────────────────────────────────────────────
            $this->auditLog->log($task->workspace_id, null, 'task.executed', 'Task', $task->id,
                $this->sanitizeAuditPayload([
                    'idempotency_key' => $idemKey,
                    'action' => $task->action,
                    'connector' => $connectorName,
                    'steps' => count($results),
                    'credit_cost' => $creditCost,
                    'reservation_ref' => $reservationRef,
                    'verification' => 'passed',
                ]));

            $this->idempotency->releaseLock($idemKey);

            if ($connectorName) {
                $this->circuitBreaker->recordSuccess($connectorName);
            }

        } catch (\Throwable $e) {
            Log::error("Orchestrator failed for task {$task->id}", [
                'action' => $task->action,
                'error' => $e->getMessage(),
            ]);

            // Release reserved credits on failure
            if ($reservationRef && $reservationRef !== 'zero_cost') {
                $this->creditService->releaseReservedCredits($reservationRef);
                $this->progress->recordEvent($task->id, 'credits_released', 'failed',
                    message: "Credits released on failure (ref: {$reservationRef})");
            }

            if (isset($connectorName) && $connectorName) {
                $this->circuitBreaker->recordFailure($connectorName);
            }

            $this->progress->recordEvent($task->id, 'execution_failed', 'failed',
                message: $e->getMessage());

            $this->auditLog->log($task->workspace_id, null, 'task.execution_failed', 'Task', $task->id,
                $this->sanitizeAuditPayload([
                    'idempotency_key' => $idemKey ?? null,
                    'action' => $task->action,
                    'connector' => $connectorName ?? null,
                    'error' => $e->getMessage(),
                    'reservation_ref' => $reservationRef,
                ]));

            $this->taskService->markFailed($task, $e->getMessage());

            if (isset($idemKey)) {
                $this->idempotency->releaseLock($idemKey);
            }
        }
    }

    /**
     * Execute a single step with idempotent check, retry, and verification.
     */
    private function executeStep(Task $task, string $action, array $params, array $capability, int $stepIndex): array
    {
        $connectorName = $capability['connector'];
        $connectorAction = $capability['action'];

        // Idempotent step check
        $stepHash = $this->idempotency->generateStepHash($task->id, $action, $stepIndex);
        $cached = $this->idempotency->checkStepCompleted($stepHash);
        if ($cached) {
            $this->progress->recordEvent($task->id, 'step_idempotent_skip', null,
                step: $stepIndex, action: $action, message: 'Step already completed — cached result used');
            return $cached;
        }

        $attempt = 0;
        $lastError = null;

        while ($attempt <= $this->maxRetries) {
            $attempt++;

            try {
                // Execute
                if ($connectorName) {
                    $connector = $this->connectorResolver->resolve($connectorName);
                    $result = $connector->execute($connectorAction, $params);
                } else {
                    $result = $this->executeInternalAction($task, $action, $params);
                }

                $this->progress->recordEvent($task->id, 'step_executed', null,
                    step: $stepIndex, connector: $connectorName, action: $action,
                    message: $result['success'] ? 'Step executed' : 'Step execution returned failure',
                    data: ['attempt' => $attempt]);

                if (! $result['success']) {
                    $lastError = $result['message'];
                    if ($attempt <= $this->maxRetries) {
                        usleep($attempt * 500_000);
                    }
                    continue;
                }

                // ── Verification ─────────────────────────────────────────
                if ($connectorName) {
                    $task->update(['status' => 'verifying']);

                    $verification = $connector->verifyResult($connectorAction, $params, $result);

                    $this->progress->recordEvent($task->id, 'step_verified', null,
                        step: $stepIndex, connector: $connectorName, action: $action,
                        message: $verification['verified'] ? 'Verification passed' : 'Verification FAILED: ' . $verification['message']);

                    if (! $verification['verified']) {
                        $result = [
                            'success' => false,
                            'data' => $verification['data'] ?? [],
                            'message' => 'Verification failed: ' . $verification['message'],
                        ];
                        $lastError = $result['message'];
                        if ($attempt <= $this->maxRetries) {
                            usleep($attempt * 500_000);
                        }
                        continue;
                    }

                    // Merge verified data
                    $result['data'] = $verification['data'];

                    $task->update(['status' => 'running']);
                }

                // Cache step result for idempotent replay
                $this->idempotency->recordStepResult($stepHash, $result);

                return $result;

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                $this->progress->recordEvent($task->id, 'step_error', null,
                    step: $stepIndex, action: $action,
                    message: "Attempt {$attempt} error: {$lastError}");

                if ($attempt <= $this->maxRetries) {
                    usleep($attempt * 500_000);
                }
            }
        }

        return ['success' => false, 'data' => [], 'message' => "Failed after {$attempt} attempts: {$lastError}"];
    }

    /**
     * Execute an internal (non-connector) action for a queued task.
     *
     * PATCH v1.0.2 — Option C: Orchestrator is now the single async execution
     * authority for ALL engine actions, including LLM-powered ones.
     *
     * GUARDRAILS enforced here:
     *   1. No pipeline duplication — credits, gating, audit are already handled
     *      by execute() before this method is called. This method ONLY dispatches.
     *   2. Strictly whitelisted map — action → [ServiceClass, methodName].
     *      No dynamic method resolution. User-controlled strings never reach call_user_func.
     *   3. Non-array return values (bool, int, Eloquent model) are normalised
     *      into the standard ['success', 'data', 'message'] shape before returning.
     *   4. All exceptions caught, normalised to failure result — worker never crashes.
     *   5. Result is JSON-safe by construction (Eloquent models cast via ->toArray()).
     *
     * EES (EngineExecutionService) remains the synchronous/manual execution path.
     * Orchestrator owns async/agent execution. The two paths do NOT overlap.
     *
     * To add a new action: add one entry to $dispatchMap below.
     */
    private function executeInternalAction(Task $task, string $action, array $params): array
    {
        $wsId = $task->workspace_id;

        // ── Whitelisted dispatch map ──────────────────────────────────────────
        // Key:   "{engine}/{action}" — engine-scoped to prevent cross-engine collisions.
        // Value: Closure that executes the action and ALWAYS returns array.
        //
        // Return normalisation rules:
        //   bool   → ['success' => $value, 'data' => []]
        //   int    → ['entity_id' => $value, 'data' => []]
        //   Model  → $model->toArray()
        //   array  → passed through directly
        // ─────────────────────────────────────────────────────────────────────
        $dispatchMap = [

            // ── CRM ──────────────────────────────────────────────────────────
            'crm/create_lead'      => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->createLead($wsId, $params)->toArray(),
            'crm/update_lead'      => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->updateLead($params['lead_id'], $params, $params['user_id'] ?? null)->toArray(),
            'crm/score_lead'       => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->scoreLead($params['lead_id'], $params['score'] ?? null)->toArray(),
            'crm/assign_lead'      => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->assignLead($params['lead_id'], $params['assigned_to'] ?? null, $params['user_id'] ?? null)->toArray(),
            'crm/import_leads'     => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->importLeads($wsId, $params['rows'] ?? [], $params['user_id'] ?? null),
            'crm/create_contact'   => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->createContact($wsId, $params)->toArray(),
            'crm/create_deal'      => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->createDeal($wsId, $params)->toArray(),
            'crm/update_deal_stage'=> fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->updateDealStage($params['deal_id'], $params['stage'], $params['user_id'] ?? null)->toArray(),
            'crm/log_activity'     => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->logActivity($wsId, $params)->toArray(),
            'crm/add_note'         => fn() => app(\App\Engines\CRM\Services\CrmService::class)
                                        ->addNote($wsId, $params['entity_type'], $params['entity_id'], $params['body'], $params['user_id'] ?? null)->toArray(),

            // ── SEO ───────────────────────────────────────────────────────────
            'seo/serp_analysis'    => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->serpAnalysis($wsId, $params),
            'seo/ai_report'        => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->aiReport($wsId, $params),
            'seo/deep_audit'       => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->deepAudit($wsId, $params),
            'seo/improve_draft'    => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->improveDraft($wsId, $params),
            'seo/write_article'    => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->writeArticle($wsId, $params),
            'seo/link_suggestions' => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->generateLinkSuggestions($wsId, $params),
            'seo/insert_link'      => fn() => ['inserted' => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->insertLink($wsId, $params['link_id'] ?? 0)],
            'seo/dismiss_link'     => fn() => ['dismissed' => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->dismissLink($wsId, $params['link_id'] ?? 0)],
            'seo/check_outbound'   => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->checkOutbound($wsId, $params),
            'seo/autonomous_goal'  => fn() => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->createGoal($wsId, $params),
            'seo/pause_goal'       => fn() => ['paused' => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->pauseGoal($wsId, $params['goal_id'] ?? 0)],
            'seo/resume_goal'      => fn() => ['resumed' => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->resumeGoal($wsId, $params['goal_id'] ?? 0)],
            'seo/add_keyword'      => fn() => ['entity_id' => app(\App\Engines\SEO\Services\SeoService::class)
                                        ->addKeyword($wsId, $params)],

            // ── Write / Content ───────────────────────────────────────────────
            'write/create_article'      => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->createArticle($wsId, $params),
            'write/write_article'       => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->createArticle($wsId, $params),
            'write/improve_draft'       => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->improveDraft($wsId, $params),
            'write/generate_outline'    => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->generateOutline($wsId, $params),
            'write/generate_headlines'  => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->generateHeadlines($wsId, $params),
            'write/generate_meta'       => fn() => app(\App\Engines\Write\Services\WriteService::class)
                                            ->generateMeta($wsId, $params),

            // ── Builder ───────────────────────────────────────────────────────
            'builder/create_website'    => fn() => app(\App\Engines\Builder\Services\BuilderService::class)
                                            ->createWebsite($wsId, $params),
            'builder/generate_page'     => fn() => app(\App\Engines\Builder\Services\BuilderService::class)
                                            ->createPage($params['website_id'], $params),
            'builder/wizard_generate'   => fn() => app(\App\Engines\Builder\Services\BuilderService::class)
                                            ->wizardGenerate($wsId, $params),
            'builder/publish_website'   => fn() => app(\App\Engines\Builder\Services\BuilderService::class)
                                            ->publishWebsite($params['website_id']),

            // ── Marketing ─────────────────────────────────────────────────────
            'marketing/create_campaign'   => fn() => app(\App\Engines\Marketing\Services\MarketingService::class)
                                              ->createCampaign($wsId, $params),
            'marketing/schedule_campaign' => fn() => app(\App\Engines\Marketing\Services\MarketingService::class)
                                              ->scheduleCampaign($params['campaign_id'] ?? 0, $params['scheduled_at'] ?? ''),
            'marketing/create_automation' => fn() => ['entity_id' => app(\App\Engines\Marketing\Services\MarketingService::class)
                                              ->createAutomation($wsId, $params)],

            // ── Social ────────────────────────────────────────────────────────
            'social/social_create_post'   => fn() => app(\App\Engines\Social\Services\SocialService::class)
                                              ->createPost($wsId, $params),
            'social/create_post'          => fn() => app(\App\Engines\Social\Services\SocialService::class)
                                              ->createPost($wsId, $params),
            'social/social_schedule_post' => fn() => (function() use ($params) {
                                                app(\App\Engines\Social\Services\SocialService::class)
                                                    ->schedulePost($params['post_id'], $params['scheduled_at']);
                                                return ['scheduled' => true, 'post_id' => $params['post_id']];
                                              })(),
            'social/social_publish_post'  => fn() => app(\App\Engines\Social\Services\SocialService::class)
                                              ->publishPost($params['post_id']),

            // ── Calendar ──────────────────────────────────────────────────────
            'calendar/create_event' => fn() => ['entity_id' => app(\App\Engines\Calendar\Services\CalendarService::class)
                                        ->createEvent($wsId, $params)],

            // ── BeforeAfter ───────────────────────────────────────────────────
            'beforeafter/ba_transform'   => fn() => app(\App\Engines\BeforeAfter\Services\BeforeAfterService::class)
                                             ->createDesign($wsId, $params),
            'beforeafter/create_design'  => fn() => app(\App\Engines\BeforeAfter\Services\BeforeAfterService::class)
                                             ->createDesign($wsId, $params),

            // ── ManualEdit ────────────────────────────────────────────────────
            'manualedit/create_canvas'   => fn() => app(\App\Engines\ManualEdit\Services\ManualEditService::class)
                                             ->createCanvas($wsId, $params),

            // ── Traffic Defense ───────────────────────────────────────────────
            'traffic/create_rule'        => fn() => ['entity_id' => app(\App\Engines\TrafficDefense\Services\TrafficDefenseService::class)
                                             ->createRule($wsId, $params)],
        ];

        // ── Lookup ────────────────────────────────────────────────────────────
        $key = "{$task->engine}/{$action}";
        $handler = $dispatchMap[$key] ?? null;

        if ($handler === null) {
            Log::warning("Orchestrator::executeInternalAction — no handler for [{$key}]", [
                'task_id' => $task->id, 'engine' => $task->engine, 'action' => $action,
            ]);
            return [
                'success' => false,
                'data'    => [],
                'message' => "No async handler registered for action [{$key}]. " .
                             "Add an entry to Orchestrator::\$dispatchMap to enable async execution.",
            ];
        }

        // ── Execute with normalisation and error containment ──────────────────
        try {
            $raw = $handler();

            // Normalise: ensure result is always a JSON-safe array
            if ($raw instanceof \Illuminate\Database\Eloquent\Model) {
                $raw = $raw->toArray();
            } elseif (is_bool($raw)) {
                $raw = ['result' => $raw];
            } elseif (is_int($raw) || is_string($raw)) {
                $raw = ['entity_id' => $raw];
            } elseif (! is_array($raw)) {
                $raw = ['result' => (string) $raw];
            }

            return [
                'success' => true,
                'data'    => $raw,
                'message' => "Action [{$key}] completed",
            ];

        } catch (\Throwable $e) {
            // Catch everything — do not crash the queue worker.
            // The Orchestrator's outer execute() will catch this and mark the task failed.
            Log::error("Orchestrator::executeInternalAction failed [{$key}]", [
                'task_id' => $task->id,
                'error'   => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
            throw $e; // Re-throw so Orchestrator marks task failed and releases credits
        }
    }

    private function resolveSteps(Task $task, array $params): array
    {
        if (isset($params['steps']) && is_array($params['steps'])) {
            return array_map(fn ($s) => [
                'action' => $s['action'] ?? $task->action,
                'params' => $s['params'] ?? [],
            ], $params['steps']);
        }

        return [['action' => $task->action, 'params' => $params]];
    }

    private function extractAgentSlug(Task $task): ?string
    {
        if ($task->source !== 'agent') return null;
        $agents = $task->assigned_agents_json ?? [];
        return $agents[0] ?? null;
    }

    /**
     * Sanitize payload for audit — strip secrets.
     */
    private function sanitizeAuditPayload(array $data): array
    {
        $sensitiveKeys = ['api_key', 'token', 'secret', 'password', 'authorization', 'credential'];

        array_walk_recursive($data, function (&$value, $key) use ($sensitiveKeys) {
            foreach ($sensitiveKeys as $sensitive) {
                if (stripos($key, $sensitive) !== false) {
                    $value = '***REDACTED***';
                }
            }
        });

        return $data;
    }
}
