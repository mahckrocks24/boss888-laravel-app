<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 — THE CUTOVER ADAPTER.
 *
 * One workspace's chat turn, answered by the Runtime-native path instead of the legacy
 * route body, and returned in EXACTLY the shape the existing SPA already understands.
 *
 * WHY AN ADAPTER RATHER THAN A BRANCH INSIDE THE ROUTE.
 * `agents-01.php` is 3,608 lines of production chat handling and belongs to no one session.
 * Every line added there is a line that can break a live customer's Sarah. This keeps the
 * route change to a single guarded block whose whole body is "ask the selector, and if it
 * says so, delegate and return" — reviewable in ten seconds — while everything that could
 * go wrong lives here, in Sarah888's own file, under Sarah888's own tests.
 *
 * THE CONTRACT IT MUST HONOUR. Legacy is asynchronous: the POST returns, and the SPA polls
 * `agent_messages` for a row tagged `phase=final` carrying the turn's correlation. A reply
 * that does not write that row is invisible no matter how good it is. So this writes the
 * same row, under the same slug, with the same correlation envelope — the ONLY difference
 * being that the text came from Runtime reasoning over typed Laravel evidence.
 *
 * FAILURE IS NOT SILENT. If the Runtime-native path throws, this returns null and the
 * caller falls straight through to the legacy body. A cutover that can strand a customer
 * mid-conversation is not reversible in any sense that matters, and the owner's message is
 * already persisted by the time we are called.
 */
final class RuntimeNativeTurn
{
    public function __construct(
        private PathSelector $selector,
        private ShadowTurn $turn,
    ) {}

    /**
     * @param  array $corr   the route's correlation envelope, so the reply is bound to the
     *                       question that caused it — the F1 defect where a late reply
     *                       attached itself to whatever was on screen.
     * @return array|null    response payload, or null to let legacy handle this turn
     */
    public function handle(int $wsId, string $agentSlug, string $agentName,
                           string $content, array $corr): ?array
    {
        $decision = $this->selector->decide($wsId);
        if ($decision['path'] !== PathSelector::PATH_RUNTIME_NATIVE) {
            return null;                       // not enrolled: legacy answers, unchanged
        }

        // One conversation per SPA thread. The route's correlation already carries it; a
        // per-workspace fallback keeps continuity rather than starting fresh every turn.
        $conversationId = (string) ($corr['conversation_id'] ?? "ws{$wsId}-default");

        // ── SPEND AUTHORISATION FOR THIS TURN ───────────────────────────────
        // Legacy assesses every turn (SpendPolicy::assessTurn) and publishes the verdict
        // so every paid path can see it. My delegation returns before that line, so the
        // Runtime-native path ran with NO SpendContext — `isAuthorized()` false — and every
        // metered read therefore answered "that needs your approval". Observed live: the
        // owner asked for a SERP and got TOOL_REQUIRES_APPROVAL for a read they had just
        // explicitly requested.
        //
        // The platform's own policy decides, exactly as legacy calls it. Failure is treated
        // as unauthorised, which is the same fail-closed posture legacy takes.
        try {
            $spend = app(SpendPolicy::class)->assessTurn($content);
            app(SpendContext::class)->setTurn($spend, $wsId);
        } catch (\Throwable $e) {
            app(SpendContext::class)->setTurn(
                ['authorized' => false, 'reason' => 'assessment failed', 'classification' => 'unknown'],
                $wsId);
            Log::warning('[Sarah888] turn assessment failed — treating as unauthorised',
                ['ws' => $wsId, 'error' => $e->getMessage()]);
        }

        // ── EXPERIENCE888 OWNER-FEEDBACK CAPTURE ────────────────────────────
        // Also below the delegation point in legacy. Without it a Runtime-native workspace
        // stops learning from its owner — corrections and standing preferences would simply
        // stop being recorded, silently, on the path that is supposed to be better. Phase D
        // proved that evidence causally changes Sarah's answers, so losing the capture is a
        // real regression, not a missing nicety.
        //
        // The enrolment and split gates are the platform's (ExperienceEligibility), not
        // reimplemented here: a live customer workspace capturing owner feedback while its
        // operational history stays excluded is a deliberate product decision.
        try {
            $ofc = app(\App\Core\Experience888\OwnerFeedbackClassifier::class);
            $eligible = app(\App\Core\Experience888\ExperienceEligibility::class)
                ->isFeedbackEnabled($wsId);
            if ($eligible && trim($content) !== '' && $ofc->isFeedback($content)) {
                $ofc->record($wsId, $ofc->deriveSubject($content), $content, [
                    'source_message_id' => $corr['user_message_id'] ?? null,
                    'conversation_id'   => $conversationId,
                ]);
                Log::info('[Experience888] owner feedback captured (runtime-native)',
                    ['ws' => $wsId]);
            }
        } catch (\Throwable $e) {
            // Learning is advisory; never let it break the reply.
            Log::warning('[Experience888] owner feedback capture failed',
                ['ws' => $wsId, 'error' => $e->getMessage()]);
        }

        // ── COMMITMENT SYNC ─────────────────────────────────────────────────
        // The write half of the commitment record. `ShadowTurn` now reads the record into
        // context, but without this nothing new ever enters it on the Runtime-native path:
        // the workspace would answer perfectly about promises made before cutover and be
        // permanently deaf to every one made after it.
        //
        // Runs BEFORE the reasoning, matching legacy, so a commitment stated in this very
        // message is on the record by the time Sarah answers about it. Legacy's own note
        // applies unchanged: losing a commitment is recoverable, losing the reply is not.
        try {
            app(CommitmentSync::class)->syncFromMessage($wsId, $content, $corr);
        } catch (\Throwable $e) {
            Log::error('[Sarah888] commitment sync failed', ['ws' => $wsId, 'error' => $e->getMessage()]);
        }

        // ── THE PER-MESSAGE CHAT METER ──────────────────────────────────────
        // Legacy charges for the act of chatting: 0.1 cr effective, batched as 1 credit
        // every tenth message (CreditService::meterChat). My delegation returns before the
        // legacy handler reaches that call, so without this a Runtime-native workspace
        // would chat for free — the same class of gap as the tool-metering one, and found
        // the same way: by asking what stopped happening rather than what started.
        //
        // Metered BEFORE the reasoning runs, matching legacy's order, so a workspace that
        // cannot pay does not first consume Runtime inference. And metered exactly once:
        // legacy never executes for a turn this adapter answers.
        $meter = app(\App\Core\Billing\CreditService::class)->meterChat($wsId, 'agent_message');
        if (!($meter['sufficient'] ?? true)) {
            // The owner's message is already saved by the route above. Same words legacy
            // uses, because a billing state is not the moment to invent new copy.
            $text = "I can't reply just yet — this workspace is out of credits. "
                  . "Your message is saved, so top up and I'll pick straight up from here.";
            $this->persist($wsId, $agentSlug, $agentName, $text,
                array_merge($corr, ['phase' => 'final', 'error' => true,
                                    'reason' => 'insufficient_credits', 'runtime_native' => true]));
            return [
                'success' => false,
                'error'   => "This workspace is out of credits, so {$agentName} can't reply right now. "
                           . "Your message has been saved — add credits and she'll continue from where you left off.",
                'reason'        => 'insufficient_credits',
                'message_saved' => true,
                'chat_counter'  => $meter['counter'] ?? 0,
                'runtime_native' => true,
            ];
        }

        $started = microtime(true);
        try {
            $result = $this->turn->run($wsId, $conversationId, $content, ['live' => true]);
        } catch (\Throwable $e) {
            // Fall through to legacy rather than leaving the owner with nothing.
            Log::error('[Sarah888] runtime-native turn failed; falling back to legacy', [
                'ws' => $wsId, 'error' => $e->getMessage(),
            ]);
            return null;
        }

        $reply = trim((string) ($result['reply'] ?? ''));
        if ($reply === '') {
            Log::warning('[Sarah888] runtime-native produced no reply; falling back to legacy',
                ['ws' => $wsId]);
            return null;
        }

        $trace = $result['trace'] ?? [];
        $meta  = array_merge($corr, [
            'phase' => 'final',
            // Marked so a turn answered by the new path is identifiable in the data
            // without inferring it from prose — the same reason ToolResult carries
            // provenance. Observability of a cutover is not optional.
            'runtime_native'  => true,
            'capability'      => $trace['tool_intent']['capability_id'] ?? null,
            'tool_status'     => $trace['tool_result']['status'] ?? null,
            'provenance'      => $trace['tool_result']['provenance'] ?? null,
            'provider'        => $trace['pass1_provider'] ?? null,
            'fallback_used'   => (bool) ($trace['pass1_fallback'] ?? $trace['pass2_fallback'] ?? false),
            'total_ms'        => $result['timings']['total_ms'] ?? null,
            'stamped_at'      => now()->toIso8601String(),
        ]);

        if (!$this->persist($wsId, $agentSlug, $agentName, $reply, $meta)) {
            return null;
        }

        Log::info('[Sarah888] runtime-native turn served', [
            'ws' => $wsId, 'conversation_id' => $conversationId,
            'capability' => $meta['capability'], 'tool_status' => $meta['tool_status'],
            'ms' => (int) round((microtime(true) - $started) * 1000),
        ]);

        return [
            'sent'       => true,
            'reply'      => $reply,
            'agent_name' => $agentName,
            'runtime_native' => true,
            // Same shape legacy returns, so the SPA's counter badge keeps working.
            'chat_meter' => [
                'counter'        => $meter['counter'] ?? 0,
                'debited'        => $meter['debited'] ?? false,
                'threshold'      => 10,
                'effective_cost' => '0.1 cr',
            ],
        ];
    }

    /** The phase=final row the SPA polls for. Returns false if it could not be written. */
    private function persist(int $wsId, string $slug, string $name, string $text, array $meta): bool
    {
        try {
            DB::table('agent_messages')->insert([
                'workspace_id'  => $wsId,
                'agent_slug'    => $slug,     // the OWNER-visible slug: this is her real reply
                'sender'        => $name,
                'content'       => $text,
                'role'          => 'agent',
                'metadata_json' => json_encode($meta),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]);
            return true;
        } catch (\Throwable $e) {
            Log::error('[Sarah888] could not persist runtime-native reply', [
                'ws' => $wsId, 'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
