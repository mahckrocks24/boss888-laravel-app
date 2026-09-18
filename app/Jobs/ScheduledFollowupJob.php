<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * APP888 / Sarah — user-requested one-shot timed follow-up.
 *
 * Created 2026-06-08. Backs Sarah's `schedule_followup` directive (emitted
 * from POST /api/agents/{slug}/messages). When the user asks "message me in
 * 5 minutes" Sarah includes {delay_minutes, note}; the handler dispatches
 * this job with ->delay(). At the due time the job writes a FACTUAL status
 * snapshot (no LLM → no hallucination, no credit cost) as a role='agent'
 * message (so the mobile event poller delivers it live) and fires a push
 * notification via PushDispatcherService.
 *
 * Bounded + one-shot: delay is validated to 1..1440 minutes at dispatch.
 * This is a user-DIRECTED deferred action, not autonomous behaviour.
 */
class ScheduledFollowupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Retry a couple of times if the queue hiccups, then give up quietly. */
    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        public int $workspaceId,
        public int $userId,
        public string $agentSlug,
        public string $note = '',
        // 2026-06-08 — the EXACT text to deliver at due time. When the user
        // asks for a specific message/reminder ("message me 'Hi'") Sarah puts
        // the literal words here and we send them verbatim. When empty, the
        // user wanted a status update so we fall back to the live snapshot.
        public string $message = '',
    ) {
    }

    public function handle(): void
    {
        $agent = \App\Models\Agent::where('slug', $this->agentSlug)->first();
        if (! $agent) {
            Log::warning('[ScheduledFollowup] agent not found', ['slug' => $this->agentSlug]);
            return;
        }

        // Follow the user's instruction precisely: deliver the exact message
        // they asked for when one was given; only fall back to a live status
        // snapshot when they wanted an update on ongoing work (no literal msg).
        $isLiteral = trim($this->message) !== '';
        $body = $isLiteral ? $this->message : $this->buildStatusSnapshot();

        // Persist as an agent reply. role='agent' + phase='final' matches the
        // two-phase chat write shape, so GET /agents/{slug}/messages and the
        // mobile event poller (which now reads role IN ['assistant','agent'])
        // both surface it live.
        $messageId = null;
        // Owner 2026-09-18 — a follow-up reaches the customer as Sarah, whoever was asked to remember it.
        $voice = \App\Core\Agents\SarahVoice::relay($this->agentSlug, (string) $agent->name, $body, [
            'phase'    => 'final',
            'followup' => true,
            'mode'     => $isLiteral ? 'literal' : 'status',
            'note'     => $this->note,
        ]);
        $body = $voice['content'];
        try {
            $messageId = DB::table('agent_messages')->insertGetId([
                'workspace_id'  => $this->workspaceId,
                'agent_slug'    => $voice['slug'],
                'sender'        => $voice['sender'],
                'content'       => $body,
                'role'          => 'agent',
                'metadata_json' => json_encode($voice['metadata']),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[ScheduledFollowup] persist failed: ' . $e->getMessage());
            return;
        }

        // Push to the user's device(s). Dispatcher swallows its own errors.
        try {
            app(\App\Core\Notifications\PushDispatcherService::class)->dispatchAgentReply(
                $this->userId,
                $this->workspaceId,
                $voice['slug'],
                $body,
                $voice['slug'],                 // conversation_id == agent slug (per-agent thread)
                $messageId ? (int) $messageId : null,
            );
        } catch (\Throwable $e) {
            Log::warning('[ScheduledFollowup] push failed: ' . $e->getMessage());
        }
    }

    /**
     * Deterministic, factual status snapshot — queried live from the task
     * table, never narrated by an LLM. Honest by construction: if nothing is
     * running it says so rather than inventing in-progress work.
     */
    private function buildStatusSnapshot(): string
    {
        $ws = $this->workspaceId;

        $active = DB::table('tasks')
            ->where('workspace_id', $ws)
            ->whereNotIn('status', ['completed', 'failed', 'cancelled'])
            ->orderByDesc('created_at')
            ->limit(10)
            ->get(['action', 'status']);

        $recentDone = (int) DB::table('tasks')
            ->where('workspace_id', $ws)
            ->where('status', 'completed')
            ->where('updated_at', '>=', now()->subHours(6))
            ->count();

        $note = trim($this->note);
        $intro = $note !== ''
            ? "Here's the follow-up you asked for on {$note}."
            : "Here's the follow-up you asked for.";

        if ($active->isNotEmpty()) {
            $lines = $active
                ->map(fn ($t) => '• ' . ucfirst(str_replace('_', ' ', (string) $t->action)) . ' — ' . $t->status)
                ->implode("\n");
            return $intro . "\n\nStill in progress:\n" . $lines;
        }

        if ($recentDone > 0) {
            $s = $recentDone === 1 ? '' : 's';
            return $intro . "\n\nNothing's running right now — {$recentDone} task{$s} wrapped up in the last few hours. You're all caught up.";
        }

        return $intro . "\n\nNothing's in progress at the moment — you're all caught up. Want me to kick something off?";
    }
}
