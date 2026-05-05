<?php

namespace App\Core\Orchestration;

use App\Core\Intelligence\GlobalKnowledgeService;
use App\Core\Intelligence\AgentExperienceService;
use App\Core\Intelligence\EngineIntelligenceService;
use App\Connectors\DeepSeekConnector;
use App\Models\Agent;
use App\Models\Workspace;
use App\Models\Meeting;
use App\Models\MeetingMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Agent Meeting Engine — real war room meetings.
 *
 * This is NOT simulation. This is NOT prompt templating.
 *
 * Each agent ACTUALLY THINKS with:
 *   - Their unique personality and role
 *   - Their real experience (tasks done, industries handled, success rate)
 *   - Their engine intelligence (what tools work, what doesn't, effectiveness data)
 *   - Their workspace memories (what they know about THIS client)
 *   - Global knowledge relevant to their specialty
 *   - What other agents just said (so they can agree, disagree, build on it)
 *
 * Meeting flow (like a real war room):
 *   Round 1 — OPENING: Sarah states the goal, sets context
 *   Round 2 — CONTRIBUTIONS: Each specialist gives their expert take (PARALLEL LLM calls)
 *   Round 3 — DEBATE: Agents respond to each other (challenge, support, build on)
 *   Round 4 — SYNTHESIS: Sarah weighs all inputs, creates the plan
 *   Round 5 — PRESENTATION: Sarah presents plan to user for approval
 *
 * The plan emerges FROM the discussion. Not before it.
 */
class AgentMeetingEngine
{
    // HARD LIMITS — meeting STOPS if exceeded
    private const MAX_MEETING_ROUNDS = 4;     // opening + contributions + debate + synthesis
    private const MAX_AGENTS_PER_MEETING = 5; // Sarah + 4 specialists max
    private const MAX_TOKENS_PER_AGENT = 200; // per round
    private const MAX_TOKENS_TOTAL = 1500;    // entire meeting budget

    public function __construct(
        private DeepSeekConnector $llm,
        private GlobalKnowledgeService $globalKnowledge,
        private AgentExperienceService $agentExperience,
        private EngineIntelligenceService $engineIntel,
        private \App\Connectors\RuntimeClient $runtime,
        private \App\Core\Billing\CreditService $creditService,
        private \App\Core\PlanGating\PlanGatingService $planGating,
    ) {}

    /**
     * Start a strategy meeting. Returns meeting ID.
     * Frontend polls for new messages as they come in.
     */
    public function startMeeting(int $wsId, int $userId, string $goal, array $agentSlugs = []): array
    {
        $workspace = Workspace::findOrFail($wsId);

        // If no agents specified, Sarah selects the team
        if (empty($agentSlugs)) {
            $agentSlugs = $this->selectTeam($goal);
        }

        // Ensure Sarah is always in the meeting
        if (!in_array('sarah', $agentSlugs)) {
            array_unshift($agentSlugs, 'sarah');
        }

        // ENFORCE: plan-based agent gating — filter out agents the workspace doesn't have access to
        $agentSlugs = array_filter($agentSlugs, function ($slug) use ($wsId) {
            $check = $this->planGating->canUseAgent($wsId, $slug);
            if (!$check['allowed']) {
                \Illuminate\Support\Facades\Log::info("[Meeting] Agent '{$slug}' excluded — " . ($check['reason'] ?? 'not on workspace team'));
            }
            return $check['allowed'];
        });
        $agentSlugs = array_values($agentSlugs); // re-index

        // If no agents passed gating (shouldn't happen — Sarah always passes), add Sarah
        if (empty($agentSlugs)) {
            $agentSlugs = ['sarah'];
        }

        // ENFORCE: max agents per meeting
        $agentSlugs = array_slice($agentSlugs, 0, self::MAX_AGENTS_PER_MEETING);

        // ── CREDIT GATE (Phase 3 fix — Gap 1) ────────────────────────────
        // Meetings consume real LLM tokens (~8 credits per max meeting).
        // estimateMeetingCost() already computes the exact cost per agent
        // roster. Reserve credits upfront; commit on completion, release on
        // failure/cancellation. Without this, every meeting was a free ride.
        $estimate = $this->estimateMeetingCost($agentSlugs);
        $creditCost = (int) ($estimate['total_credits'] ?? 0);
        $reservationRef = null;

        if ($creditCost > 0) {
            if (! $this->creditService->hasBalance($wsId, $creditCost)) {
                return [
                    'error' => "Insufficient credits. Meeting requires {$creditCost} credits.",
                    'credit_cost' => $creditCost,
                    'breakdown' => $estimate['breakdown'] ?? [],
                ];
            }
            $reservationRef = $this->creditService->reserve($wsId, $creditCost, "meeting_strategy");
        }

        // Create meeting record with token + credit tracking
        $meeting = Meeting::create([
            'workspace_id' => $wsId,
            'created_by' => $userId,
            'title' => "Strategy: " . substr($goal, 0, 100),
            'type' => 'strategy',
            'status' => 'active',
            'total_credits_used' => 0,
            'metadata_json' => json_encode([
                'goal' => $goal,
                'agents' => $agentSlugs,
                'phase' => 'opening',
                'rounds_completed' => 0,
                'tokens_used' => 0,
                'token_budget' => self::MAX_TOKENS_TOTAL,
                'max_rounds' => self::MAX_MEETING_ROUNDS,
                // Phase 3: credit tracking fields
                'credit_cost' => $creditCost,
                'reservation_ref' => $reservationRef,
            ]),
        ]);

        // Add participants
        foreach ($agentSlugs as $slug) {
            $agent = Agent::where('slug', $slug)->first();
            if ($agent) {
                DB::table('meeting_participants')->insert([
                    'meeting_id' => $meeting->id,
                    'participant_type' => 'agent',
                    'participant_id' => $agent->id,
                    'joined_at' => now(),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        // Add user as participant
        DB::table('meeting_participants')->insert([
            'meeting_id' => $meeting->id,
            'participant_type' => 'user',
            'participant_id' => $userId,
            'joined_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Start the meeting — Round 1: Sarah opens
        $this->runOpening($meeting, $workspace, $goal, $agentSlugs);

        return [
            'meeting_id' => $meeting->id,
            'status' => 'active',
            'agents' => $agentSlugs,
            'phase' => 'opening',
        ];
    }

    /**
     * Advance the meeting to the next round.
     * Called by frontend or automatically after each round.
     */
    public function advanceMeeting(int $meetingId): array
    {
        $meeting = Meeting::findOrFail($meetingId);
        $meta = json_decode($meeting->metadata_json, true);
        $phase = $meta['phase'] ?? 'opening';
        $goal = $meta['goal'] ?? '';
        $agentSlugs = $meta['agents'] ?? [];
        $workspace = Workspace::find($meeting->workspace_id);
        $tokensUsed = $meta['tokens_used'] ?? 0;
        $roundsCompleted = $meta['rounds_completed'] ?? 0;

        // ENFORCE: max rounds
        if ($roundsCompleted >= self::MAX_MEETING_ROUNDS) {
            $this->completeMeeting($meeting);
            return ['meeting_id' => $meetingId, 'phase' => 'complete', 'reason' => 'max_rounds_reached', 'tokens_used' => $tokensUsed];
        }

        // ENFORCE: token budget
        if ($tokensUsed >= self::MAX_TOKENS_TOTAL) {
            $this->completeMeeting($meeting);
            return ['meeting_id' => $meetingId, 'phase' => 'complete', 'reason' => 'token_budget_exhausted', 'tokens_used' => $tokensUsed];
        }

        $nextPhase = match ($phase) {
            'opening' => 'contributions',
            'contributions' => 'debate',
            'debate' => 'synthesis',
            'synthesis' => 'complete',
            default => 'complete',
        };

        // Execute the next phase
        match ($nextPhase) {
            'contributions' => $this->runContributions($meeting, $workspace, $goal, $agentSlugs),
            'debate' => $this->runDebate($meeting, $workspace, $goal, $agentSlugs),
            'synthesis' => $this->runSynthesis($meeting, $workspace, $goal, $agentSlugs),
            'complete' => $this->completeMeeting($meeting),
        };

        // Update meeting phase
        $meta['phase'] = $nextPhase;
        $meta['rounds_completed'] = ($meta['rounds_completed'] ?? 0) + 1;
        $meeting->update(['metadata_json' => json_encode($meta)]);

        return [
            'meeting_id' => $meetingId,
            'phase' => $nextPhase,
            'rounds_completed' => $meta['rounds_completed'],
        ];
    }

    /**
     * Run the entire meeting automatically (all rounds).
     * For when user wants the full meeting without stepping through.
     */
    public function runFullMeeting(int $wsId, int $userId, string $goal): array
    {
        $start = $this->startMeeting($wsId, $userId, $goal);
        $meetingId = $start['meeting_id'];

        // Advance through all phases
        $this->advanceMeeting($meetingId); // → contributions
        $this->advanceMeeting($meetingId); // → debate
        $this->advanceMeeting($meetingId); // → synthesis

        return $this->getMeetingTranscript($meetingId);
    }

    /**
     * Get full meeting transcript with all messages.
     */
    public function getMeetingTranscript(int $meetingId): array
    {
        $meeting = Meeting::findOrFail($meetingId);
        $messages = MeetingMessage::where('meeting_id', $meetingId)
            ->orderBy('created_at')
            ->get();

        $meta = json_decode($meeting->metadata_json, true);

        return [
            'meeting' => $meeting,
            'messages' => $messages->map(function ($msg) {
                $agent = $msg->sender_type === 'agent' ? Agent::find($msg->sender_id) : null;
                return [
                    'id' => $msg->id,
                    'sender_type' => $msg->sender_type,
                    'sender_name' => $agent?->name ?? 'User',
                    'sender_slug' => $agent?->slug ?? 'user',
                    'sender_color' => $agent?->color ?? '#6B7280',
                    'sender_title' => $agent?->title ?? '',
                    'message' => $msg->message,
                    'phase' => $msg->attachments_json ? (json_decode($msg->attachments_json, true)['phase'] ?? null) : null,
                    'created_at' => $msg->created_at,
                ];
            })->toArray(),
            'phase' => $meta['phase'] ?? 'complete',
            'goal' => $meta['goal'] ?? '',
            'plan' => $meta['plan'] ?? null,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // USER PARTICIPATION — the user is IN the meeting
    // ═══════════════════════════════════════════════════════════

    /**
     * User sends a message into the meeting.
     * Sarah facilitates — decides who responds.
     * If user @mentions an agent, that agent responds directly.
     */
    public function userMessage(int $meetingId, int $userId, string $message): array
    {
        $meeting = Meeting::findOrFail($meetingId);
        if ($meeting->status !== 'active') {
            return ['error' => 'Meeting is not active'];
        }

        $meta = json_decode($meeting->metadata_json, true);
        $workspace = Workspace::find($meeting->workspace_id);
        $agentSlugs = $meta['agents'] ?? [];

        // Store user message
        MeetingMessage::create([
            'meeting_id' => $meetingId,
            'sender_type' => 'user',
            'sender_id' => $userId,
            'message' => $message,
            'attachments_json' => json_encode(['phase' => 'user_input']),
        ]);

        // Detect if user is addressing a specific agent
        $targetAgent = $this->detectTargetAgent($message, $agentSlugs);
        $responses = [];

        if ($targetAgent) {
            // User asked a specific agent — that agent responds
            $agent = Agent::where('slug', $targetAgent)->first();
            if ($agent) {
                $response = $this->respondToUser($agent, $meeting, $workspace, $message, $agentSlugs);
                $responses[] = ['agent' => $targetAgent, 'response' => $response];
            }
        } else {
            // No specific agent mentioned — Sarah facilitates
            $sarah = Agent::where('slug', 'sarah')->first();
            if ($sarah) {
                $response = $this->sarahFacilitateResponse($sarah, $meeting, $workspace, $message, $agentSlugs);
                $responses[] = ['agent' => 'sarah', 'response' => $response];
            }
        }

        return [
            'meeting_id' => $meetingId,
            'responses' => $responses,
            'tokens_used' => $meta['tokens_used'] ?? 0,
        ];
    }

    /**
     * User ends the meeting early.
     * Sarah wraps up with whatever has been discussed so far.
     */
    public function endMeeting(int $meetingId, int $userId): array
    {
        $meeting = Meeting::findOrFail($meetingId);
        if ($meeting->status !== 'active') {
            return ['error' => 'Meeting is not active'];
        }

        $meta = json_decode($meeting->metadata_json, true);
        $workspace = Workspace::find($meeting->workspace_id);

        // Sarah wraps up
        $sarah = Agent::where('slug', 'sarah')->first();
        if ($sarah) {
            $allMessages = $this->getMessages($meetingId);
            $transcript = implode("\n", array_map(fn($m) => "[{$m['sender_name']}]: {$m['message']}", array_slice($allMessages, -10)));

            $prompt = "The client has ended the meeting early. Based on what was discussed so far:\n\n" .
                "{$transcript}\n\n" .
                "Give a brief wrap-up (max 80 words). Summarize key takeaways and any action items that were agreed on. " .
                "If nothing concrete was decided, say so honestly.";

            $response = $this->agentThink($sarah, $prompt, $workspace, $meeting);
            $this->storeMessage($meetingId, $sarah, $response, 'wrap_up');
        }

        $this->completeMeeting($meeting);

        return [
            'meeting_id' => $meetingId,
            'status' => 'completed',
            'ended_by' => 'user',
            'tokens_used' => $meta['tokens_used'] ?? 0,
        ];
    }

    /**
     * Get cost estimate for a meeting before it starts.
     */
    public function estimateMeetingCost(array $agentSlugs = []): array
    {
        $agentCount = min(count($agentSlugs) ?: 5, self::MAX_AGENTS_PER_MEETING);
        $rounds = self::MAX_MEETING_ROUNDS;

        $breakdown = [];
        // Opening: Sarah
        $breakdown[] = ['round' => 'Opening', 'agent' => 'sarah', 'max_tokens' => self::MAX_TOKENS_PER_AGENT, 'credits' => 1];
        // Contributions: each agent
        for ($i = 1; $i < $agentCount; $i++) {
            $slug = $agentSlugs[$i] ?? "agent_{$i}";
            $breakdown[] = ['round' => 'Expert Input', 'agent' => $slug, 'max_tokens' => self::MAX_TOKENS_PER_AGENT, 'credits' => 1];
        }
        // Debate: all agents
        $breakdown[] = ['round' => 'Discussion', 'agent' => 'all', 'max_tokens' => self::MAX_TOKENS_PER_AGENT * $agentCount, 'credits' => 2];
        // Synthesis: Sarah
        $breakdown[] = ['round' => 'Plan Creation', 'agent' => 'sarah', 'max_tokens' => 300, 'credits' => 1];

        $totalCredits = array_sum(array_column($breakdown, 'credits'));

        return [
            'breakdown' => $breakdown,
            'total_credits' => $totalCredits,
            'max_tokens' => self::MAX_TOKENS_TOTAL,
            'max_rounds' => self::MAX_MEETING_ROUNDS,
            'max_agents' => self::MAX_AGENTS_PER_MEETING,
            'agent_token_cap' => self::MAX_TOKENS_PER_AGENT,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // PRIVATE — User Response Handling
    // ═══════════════════════════════════════════════════════════

    /**
     * Detect if user is addressing a specific agent.
     * Looks for @mentions or name references.
     */
    private function detectTargetAgent(string $message, array $agentSlugs): ?string
    {
        $lower = strtolower($message);
        $agentNames = Agent::whereIn('slug', $agentSlugs)->pluck('slug', 'name')->toArray();

        // Check @mentions first
        foreach ($agentSlugs as $slug) {
            if (str_contains($lower, "@{$slug}")) return $slug;
        }

        // Check name mentions
        foreach ($agentNames as $name => $slug) {
            if (str_contains($lower, strtolower($name))) return $slug;
        }

        return null;
    }

    /**
     * A specific agent responds to the user's question.
     */
    private function respondToUser(Agent $agent, Meeting $meeting, Workspace $workspace, string $userMessage, array $agentSlugs): string
    {
        $experience = $this->agentExperience->buildExperienceContext($agent->id, $workspace->industry);
        $recentMessages = $this->getMessages($meeting->id);
        $context = implode("\n", array_map(fn($m) => "[{$m['sender_name']}]: {$m['message']}", array_slice($recentMessages, -5)));

        $prompt = "You are {$agent->name}, {$agent->title}.\n" .
            "You're in a strategy meeting for {$workspace->business_name}.\n\n" .
            "Recent discussion:\n{$context}\n\n" .
            ($experience ? "Your experience:\n{$experience}\n\n" : '') .
            "The client just asked you directly:\n\"{$userMessage}\"\n\n" .
            "Answer their question from your area of expertise. Be helpful, specific, and honest. Max 100 words.";

        $response = $this->agentThink($agent, $prompt, $workspace, $meeting);
        $this->storeMessage($meeting->id, $agent, $response, 'user_response');
        return $response;
    }

    /**
     * Sarah facilitates — responds to user or delegates to the right agent.
     */
    private function sarahFacilitateResponse(Agent $sarah, Meeting $meeting, Workspace $workspace, string $userMessage, array $agentSlugs): string
    {
        $recentMessages = $this->getMessages($meeting->id);
        $context = implode("\n", array_map(fn($m) => "[{$m['sender_name']}]: {$m['message']}", array_slice($recentMessages, -5)));
        $team = implode(', ', $agentSlugs);

        $prompt = "You are Sarah, meeting facilitator for {$workspace->business_name}.\n" .
            "Team: {$team}\n\n" .
            "Recent discussion:\n{$context}\n\n" .
            "The client says:\n\"{$userMessage}\"\n\n" .
            "Respond as the facilitator. If it's a general question, answer it. " .
            "If it's specific to an agent's expertise, answer but mention which team member handles that area. " .
            "If the client seems confused, clarify the plan. If they're giving direction, acknowledge and adjust. Max 100 words.";

        $response = $this->agentThink($sarah, $prompt, $workspace, $meeting);
        $this->storeMessage($meeting->id, $sarah, $response, 'user_response');
        return $response;
    }

    // ═══════════════════════════════════════════════════════════
    // MEETING ROUNDS
    // ═══════════════════════════════════════════════════════════

    /**
     * Round 1: Sarah opens the meeting, states the goal, sets context.
     */
    private function runOpening(Meeting $meeting, Workspace $workspace, string $goal, array $agents): void
    {
        $sarah = Agent::where('slug', 'sarah')->first();
        $agentNames = Agent::whereIn('slug', $agents)->pluck('name', 'slug')->toArray();
        $teamList = implode(', ', array_map(fn($s, $n) => "{$n}", array_keys($agentNames), $agentNames));

        $context = $this->buildWorkspaceContext($workspace);
        $knowledge = $this->globalKnowledge->buildAgentContext('marketing', $workspace->industry, $workspace->location);

        $prompt = "You are Sarah, Digital Marketing Manager, opening a strategy meeting.\n\n" .
            "Team present: {$teamList}\n" .
            "Client: {$workspace->business_name}\n{$context}\n\n" .
            ($knowledge ? "Your knowledge:\n{$knowledge}\n\n" : '') .
            "Goal from the client: \"{$goal}\"\n\n" .
            "Open this meeting naturally. State the goal clearly, give relevant business context, " .
            "and then ask each team member for their expert input. Be specific about what you want from each person based on their expertise. " .
            "Keep it conversational and professional — like a real marketing team huddle. Max 150 words.";

        $response = $this->agentThink($sarah, $prompt, $workspace, $meeting);
        $this->storeMessage($meeting->id, $sarah, $response, 'opening');
    }

    /**
     * Round 2: Each specialist contributes their expert take.
     * PARALLEL — each agent thinks independently with their own data.
     */
    private function runContributions(Meeting $meeting, Workspace $workspace, string $goal, array $agents): void
    {
        $previousMessages = $this->getMessages($meeting->id);
        $specialists = array_filter($agents, fn($s) => $s !== 'sarah');

        foreach ($specialists as $slug) {
            $agent = Agent::where('slug', $slug)->first();
            if (!$agent) continue;

            // Build agent-specific context
            $experience = $this->agentExperience->buildExperienceContext($agent->id, $workspace->industry);
            $engineBriefing = $this->getAgentEngineBriefing($slug);
            $memories = $this->agentExperience->buildMemoryContext($workspace->id, $agent->id);
            $globalContext = $this->globalKnowledge->buildAgentContext($this->agentToEngine($slug), $workspace->industry, $workspace->location);

            $prompt = "You are {$agent->name}, {$agent->title}.\n\n" .
                "You're in a strategy meeting. Sarah just opened with:\n" .
                "\"{$this->getLastMessageFrom($previousMessages, 'sarah')}\"\n\n" .
                "Client: {$workspace->business_name} ({$workspace->industry}, {$workspace->location})\n" .
                "Goal: \"{$goal}\"\n\n" .
                ($experience ? "Your experience:\n{$experience}\n\n" : '') .
                ($engineBriefing ? "Your tools & knowledge:\n{$engineBriefing}\n\n" : '') .
                ($memories ? "What you remember about this client:\n{$memories}\n\n" : '') .
                ($globalContext ? "Industry data:\n{$globalContext}\n\n" : '') .
                "Give your expert contribution. What should we do in YOUR domain? " .
                "Be specific — cite data if you have it. Suggest concrete actions. " .
                "If you see risks or opportunities the team should know about, say so. " .
                "If you disagree with a common approach for this industry, explain why. " .
                "Keep it conversational. Max 120 words.";

            $response = $this->agentThink($agent, $prompt, $workspace, $meeting);
            $this->storeMessage($meeting->id, $agent, $response, 'contribution');
        }
    }

    /**
     * Round 3: Agents respond to each other — agree, disagree, build on ideas.
     * This is where the real collaboration happens.
     */
    private function runDebate(Meeting $meeting, Workspace $workspace, string $goal, array $agents): void
    {
        $allMessages = $this->getMessages($meeting->id);
        $contributionMessages = array_filter($allMessages, fn($m) => ($m['phase'] ?? '') === 'contribution');

        // Each agent (including Sarah) gets to respond to what they heard
        foreach ($agents as $slug) {
            $agent = Agent::where('slug', $slug)->first();
            if (!$agent) continue;

            // Build summary of what others said
            $otherContributions = array_filter($contributionMessages, fn($m) => ($m['sender_slug'] ?? '') !== $slug);
            $othersText = implode("\n\n", array_map(fn($m) => "{$m['sender_name']}: \"{$m['message']}\"", $otherContributions));

            if (empty($othersText) && $slug !== 'sarah') continue;

            $experience = $this->agentExperience->buildExperienceContext($agent->id, $workspace->industry);

            $prompt = "You are {$agent->name}, {$agent->title}.\n\n" .
                "Strategy meeting for {$workspace->business_name} ({$workspace->industry}).\n" .
                "Goal: \"{$goal}\"\n\n" .
                "Your colleagues just shared their ideas:\n{$othersText}\n\n" .
                ($experience ? "Your experience:\n{$experience}\n\n" : '') .
                ($slug === 'sarah' ?
                    "As the meeting leader, respond to the team's suggestions. " .
                    "Acknowledge good ideas. Challenge anything that seems off based on your experience. " .
                    "Point out synergies between suggestions. Ask clarifying questions if needed. " .
                    "Start shaping the direction."
                    :
                    "Respond to what you heard. Do you agree or disagree with any suggestions? " .
                    "Can you build on someone else's idea? Do you see a risk they missed? " .
                    "Is there a conflict between suggestions that needs resolving? " .
                    "Be direct and honest — this is a working session, not a polite meeting."
                ) .
                "\nKeep it natural and conversational. Max 100 words.";

            $response = $this->agentThink($agent, $prompt, $workspace, $meeting);
            $this->storeMessage($meeting->id, $agent, $response, 'debate');
        }
    }

    /**
     * Round 4: Sarah synthesizes everything into a concrete plan.
     * The plan EMERGES from the discussion.
     */
    private function runSynthesis(Meeting $meeting, Workspace $workspace, string $goal, array $agents): void
    {
        $sarah = Agent::where('slug', 'sarah')->first();
        $allMessages = $this->getMessages($meeting->id);

        // Full meeting transcript for Sarah
        $transcript = implode("\n\n", array_map(
            fn($m) => "[{$m['sender_name']}] ({$m['phase']}): {$m['message']}",
            $allMessages
        ));

        $prompt = "You are Sarah, Digital Marketing Manager. The team discussion is complete.\n\n" .
            "Client: {$workspace->business_name} ({$workspace->industry}, {$workspace->location})\n" .
            "Goal: \"{$goal}\"\n\n" .
            "Full meeting transcript:\n{$transcript}\n\n" .
            "Now synthesize everything into a CONCRETE action plan. Your plan must:\n" .
            "1. Acknowledge the best ideas from each team member\n" .
            "2. Resolve any disagreements (explain why you chose one approach over another)\n" .
            "3. List specific tasks with who does what\n" .
            "4. Note what needs client approval before execution\n" .
            "5. Set priorities (what we do first, what can wait)\n\n" .
            "Format your plan clearly. The client will read this directly.\n" .
            "End by asking the client for approval to proceed. Max 300 words.";

        $response = $this->agentThink($sarah, $prompt, $workspace, $meeting);
        $this->storeMessage($meeting->id, $sarah, $response, 'synthesis');

        // Extract actionable tasks from synthesis
        $plan = $this->extractPlanFromSynthesis($meeting->id, $workspace, $goal, $agents, $response);

        // Store plan in meeting metadata
        $meta = json_decode($meeting->metadata_json, true);
        $meta['plan'] = $plan;
        $meeting->update(['metadata_json' => json_encode($meta)]);
    }

    private function completeMeeting(Meeting $meeting): void
    {
        $meta = json_decode($meeting->metadata_json ?? '{}', true);
        $reservationRef = $meta['reservation_ref'] ?? null;
        $creditCost     = (int) ($meta['credit_cost'] ?? 0);

        // Phase 3 fix — commit the reserved credits on completion.
        if ($reservationRef && $creditCost > 0) {
            try {
                $this->creditService->commit($meeting->workspace_id, $reservationRef, $creditCost);
            } catch (\Throwable $e) {
                // Reservation may already be committed/released (race, duplicate call).
                // Log but don't fail the meeting completion.
                Log::warning('AgentMeetingEngine::completeMeeting credit commit failed', [
                    'meeting_id' => $meeting->id,
                    'ref'        => $reservationRef,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $meeting->update([
            'status'             => 'completed',
            'total_credits_used' => $creditCost,
        ]);

        // ── Create tasks from synthesis plan ──
        $plan = $meta['plan'] ?? null;
        if (is_array($plan)) {
            foreach ($plan as $planTask) {
                if (!is_array($planTask) || empty($planTask['action'])) continue;
                $agentSlug = $planTask['agent'] ?? 'sarah';
                // Map sarah→sarah (not dmm) for DB storage
                if ($agentSlug === 'dmm') $agentSlug = 'sarah';

                $taskId = DB::table('tasks')->insertGetId([
                    'workspace_id' => $meeting->workspace_id,
                    'engine' => $planTask['engine'] ?? 'marketing',
                    'action' => $planTask['action'] ?? 'follow_up',
                    'payload_json' => json_encode(['description' => $planTask['description'] ?? '', 'from_meeting' => $meeting->id]),
                    'status' => 'pending',
                    'source' => 'agent',
                    'assigned_agents_json' => json_encode([$agentSlug]),
                    'priority' => $planTask['priority'] ?? 'normal',
                    'progress_message' => $planTask['description'] ?? ucfirst(str_replace('_', ' ', $planTask['action'])),
                    'requires_approval' => $planTask['requires_approval'] ?? false,
                    'credit_cost' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Populate meeting_tasks pivot
                DB::table('meeting_tasks')->insert([
                    'meeting_id' => $meeting->id,
                    'task_id' => $taskId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            Log::info("[Meeting] Created " . count($plan) . " tasks from synthesis", ['meeting_id' => $meeting->id]);
        }
    }

    // ═══════════════════════════════════════════════════════════
    // AGENT THINKING
    // ═══════════════════════════════════════════════════════════

    /**
     * Make an agent ACTUALLY THINK — with hard token cap.
     * Tracks tokens on the meeting record. Stops if budget exceeded.
     */
    private function agentThink(Agent $agent, string $prompt, Workspace $workspace, ?Meeting $meeting = null): string
    {
        // Check meeting token budget before calling LLM
        if ($meeting) {
            $meta = json_decode($meeting->metadata_json ?? '{}', true);
            $tokensUsed = $meta['tokens_used'] ?? 0;
            if ($tokensUsed >= self::MAX_TOKENS_TOTAL) {
                return "[Budget reached — {$agent->name}'s input was skipped to stay within the approved credit allocation]";
            }
        }

        if (!$this->runtime->isConfigured()) {
            return $this->fallbackResponse($agent, $workspace);
        }

        // MIGRATED 2026-04-13 (Phase 0.17b): switched from aiRun fold-pattern
        // to chatJson. Agent persona is the system prompt; the meeting prompt
        // is the user prompt. The LLM returns {"contribution":"<under 100 words>"}.
        $systemPrompt = "You are {$agent->name}, {$agent->title}. "
                      . "You have a distinct personality and expertise. Speak naturally, as yourself. "
                      . "Never break character. Never say 'as an AI'. You are a marketing professional in a team meeting. "
                      . "IMPORTANT: Keep your response under 100 words. Be concise and relevant only. "
                      . "Output ONLY a valid JSON object of the form {\"contribution\":\"<your meeting input>\"}. "
                      . "No markdown, no commentary outside the JSON.";

        $result = $this->runtime->chatJson($systemPrompt, $prompt, [
            'agent_slug'  => $agent->slug,
            'agent_name'  => $agent->name,
            'agent_title' => $agent->title,
            'industry'    => $workspace->industry ?? '',
            'meeting_id'  => $meeting?->id ? (string) $meeting->id : '',
        ], self::MAX_TOKENS_PER_AGENT);

        if (($result['success'] ?? false) && is_array($result['parsed'] ?? null)) {
            $contribution = $result['parsed']['contribution'] ?? $result['text'] ?? '';
            // We don't get token count back from runtime — estimate from text length
            $tokensThisCall = (int) ceil(strlen($contribution) / 4);

            // Track tokens on meeting record
            if ($meeting) {
                $meta = json_decode($meeting->metadata_json ?? '{}', true);
                $meta['tokens_used'] = ($meta['tokens_used'] ?? 0) + $tokensThisCall;
                $meeting->update(['metadata_json' => json_encode($meta)]);
            }

            // Record on agent's profile
            $this->agentExperience->recordTaskCompletion($agent->id, 'orchestration', 'meeting_contribution', $workspace->industry, [
                'tokens_used' => $tokensThisCall, 'success' => true,
            ]);

            return $contribution;
        }

        return $this->fallbackResponse($agent, $workspace);
    }

    private function fallbackResponse(Agent $agent, Workspace $workspace): string
    {
        // FIX 2026-04-12 (Phase 1.0.10 / doc 13): was a hardcoded 6-agent map
        // (sarah/james/priya/marcus/elena/alex). The agents table has 21 active
        // agents — 15 of them fell through to a generic line. Now builds the
        // fallback dynamically from the agent's DB row + workspace context.
        $industry = $workspace->industry ?? 'this industry';
        $location = $workspace->location ?? 'the region';
        $title    = $agent->title ?? 'specialist';
        $desc     = $agent->description ?? '';

        $expertise = $desc !== '' ? " ({$desc})" : '';

        return "From my perspective as {$agent->name}, {$title}{$expertise}, "
             . "I'd focus on what's worked for similar {$industry} businesses in {$location}. "
             . "Let me build a tailored approach based on the team's input.";
    }

    // ═══════════════════════════════════════════════════════════
    // HELPERS
    // ═══════════════════════════════════════════════════════════

    private function storeMessage(int $meetingId, Agent $agent, string $message, string $phase): void
    {
        MeetingMessage::create([
            'meeting_id' => $meetingId,
            'sender_type' => 'agent',
            'sender_id' => $agent->id,
            'message' => $message,
            'attachments_json' => json_encode(['phase' => $phase, 'agent_slug' => $agent->slug]),
        ]);
    }

    private function getMessages(int $meetingId): array
    {
        return MeetingMessage::where('meeting_id', $meetingId)
            ->orderBy('created_at')
            ->get()
            ->map(function ($msg) {
                $agent = $msg->sender_type === 'agent' ? Agent::find($msg->sender_id) : null;
                $attachments = json_decode($msg->attachments_json ?? '{}', true);
                return [
                    'sender_name' => $agent?->name ?? 'User',
                    'sender_slug' => $attachments['agent_slug'] ?? ($agent?->slug ?? 'user'),
                    'message' => $msg->message,
                    'phase' => $attachments['phase'] ?? null,
                ];
            })->toArray();
    }

    private function getLastMessageFrom(array $messages, string $slug): string
    {
        $agentMsgs = array_filter($messages, fn($m) => ($m['sender_slug'] ?? '') === $slug);
        return !empty($agentMsgs) ? end($agentMsgs)['message'] : '';
    }

    private function selectTeam(string $goal): array
    {
        $team = ['sarah']; // Always
        $lower = strtolower($goal);

        // Match relevant specialists by keyword
        $matches = [
            'james' => '/\b(seo|keyword|search|rank|organic|audit|serp|google)\b/',
            'alex'  => '/\b(technical|speed|schema|site|core web|mobile|crawl|index)\b/',
            'diana' => '/\b(local|map|google business|citation|gmb|near me)\b/',
            'ryan'  => '/\b(link|backlink|outreach|authority|pr|digital pr)\b/',
            'priya' => '/\b(content|write|article|blog|copy|editorial)\b/',
            'leo'   => '/\b(brand|headline|ad|conversion|landing page|copy)\b/',
            'maya'  => '/\b(caption|hashtag|social content|reel|short.form)\b/',
            'nora'  => '/\b(strategy|calendar|editorial|thought leader|content plan)\b/',
            'marcus'=> '/\b(social|instagram|facebook|post|tiktok|linkedin|community)\b/',
            'zara'  => '/\b(instagram|reels|stories|follower|growth)\b/',
            'tyler' => '/\b(linkedin|b2b|thought leader|professional)\b/',
            'aria'  => '/\b(tiktok|reels|viral|short.form|trend)\b/',
            'jordan'=> '/\b(analytics|roi|data|metrics|reporting|insights)\b/',
            'elena' => '/\b(lead|crm|customer|pipeline|nurture|funnel)\b/',
            'sam'   => '/\b(email|campaign|newsletter|drip|sequence)\b/',
            'kai'   => '/\b(nurture|scoring|lead score|follow.up|drip)\b/',
            'vera'  => '/\b(automation|workflow|trigger|sequence|multi.channel)\b/',
            'max'   => '/\b(conversion|cro|funnel|a.b test|optimize)\b/',
        ];

        foreach ($matches as $slug => $pattern) {
            if (preg_match($pattern, $lower)) {
                $team[] = $slug;
            }
        }

        // Minimum 4 agents per meeting (Sarah + 3 specialists)
        if (count($team) < 4) {
            // Add most relevant agents based on common meeting topics
            $defaults = ['james', 'priya', 'marcus', 'elena', 'jordan'];
            foreach ($defaults as $d) {
                if (!in_array($d, $team)) $team[] = $d;
                if (count($team) >= 4) break;
            }
        }

        // Cap at MAX_AGENTS_PER_MEETING (already enforced in startMeeting, but be safe)
        return array_unique(array_slice($team, 0, self::MAX_AGENTS_PER_MEETING));
    }

    private function agentToEngine(string $slug): string
    {
        return match ($slug) {
            'james' => 'seo', 'priya' => 'write', 'marcus' => 'social',
            'elena' => 'crm', 'alex' => 'seo', default => 'marketing',
        };
    }

    private function getAgentEngineBriefing(string $slug): string
    {
        $engine = $this->agentToEngine($slug);
        $briefing = $this->engineIntel->getBriefing($engine);
        if (empty($briefing['tools'])) return '';

        $lines = [];
        foreach (array_slice($briefing['tools'], 0, 5) as $tool) {
            $eff = $tool->effectiveness_score ? ' (effectiveness: ' . round($tool->effectiveness_score * 100) . '%)' : '';
            $lines[] = "- {$tool->key}{$eff}";
        }
        foreach (array_slice($briefing['best_practices'] ?? [], 0, 3) as $bp) {
            $lines[] = "Best practice: {$bp->content}";
        }
        return implode("\n", $lines);
    }

    private function buildWorkspaceContext(Workspace $workspace): string
    {
        $parts = [];
        if ($workspace->industry) $parts[] = "Industry: {$workspace->industry}";
        if ($workspace->location) $parts[] = "Location: {$workspace->location}";
        if ($workspace->goal) $parts[] = "Business goal: {$workspace->goal}";
        $services = is_string($workspace->services_json) ? json_decode($workspace->services_json, true) : ($workspace->services_json ?? []);
        if (!empty($services)) $parts[] = "Services: " . (is_array($services) ? implode(', ', $services) : $services);
        return implode("\n", $parts);
    }

    /**
     * Extract a structured plan from Sarah's synthesis message.
     * Uses runtime LLM to parse natural language into tasks.
     *
     * MIGRATED 2026-04-13 (Phase 0.17b): switched from aiRun fold-pattern to
     * chatJson. Note: chat_json's `response_format: json_object` requires a
     * JSON object (not a bare array), so the LLM is instructed to return
     * {"tasks":[...]} and we extract the tasks array on this side.
     */
    private function extractPlanFromSynthesis(int $meetingId, Workspace $workspace, string $goal, array $agents, string $synthesis): ?array
    {
        if (!$this->runtime->isConfigured()) return null;

        $systemPrompt = "Extract actionable tasks from a marketing plan. "
                      . "Output ONLY a valid JSON object of the form {\"tasks\":[...]}. "
                      . "Each task in the tasks array must have: "
                      . "{\"engine\":\"seo|write|creative|social|marketing|crm|builder\","
                      . "\"action\":\"specific_action\",\"agent\":\"agent_slug\","
                      . "\"description\":\"what to do\",\"priority\":\"high|medium|low\","
                      . "\"requires_approval\":true|false}. "
                      . "Available agents: " . implode(', ', $agents) . ". "
                      . "No markdown, no commentary outside the JSON.";

        $userPrompt = "PLAN TO EXTRACT:\n{$synthesis}";

        $result = $this->runtime->chatJson($systemPrompt, $userPrompt, [
            'task'       => 'plan_extraction',
            'meeting_id' => (string) $meetingId,
            'goal'       => $goal,
        ], 800);

        if (!($result['success'] ?? false) || !is_array($result['parsed'] ?? null)) return null;

        $tasks = $result['parsed']['tasks'] ?? null;
        return is_array($tasks) ? $tasks : null;
    }
}
