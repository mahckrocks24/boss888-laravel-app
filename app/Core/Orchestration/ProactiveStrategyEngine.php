<?php

namespace App\Core\Orchestration;

use App\Core\Billing\CreditService;
use App\Core\Intelligence\GlobalKnowledgeService;
use App\Core\Notifications\NotificationService;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Proactive Strategy Engine — Sarah initiates, user consents, agents execute.
 *
 * HARD GUARDRAIL:
 *   Sarah's ONLY autonomous action = send a TEMPLATE message (zero credits).
 *   Every LLM call, every scan, every generation = costs credits.
 *   Credits must be ESTIMATED → SHOWN TO USER → APPROVED → RESERVED → then spent.
 *   No exceptions. No "free research." No surprise charges.
 *
 * Flow:
 *   1. Onboarding completes → Sarah sends cost estimate (TEMPLATE, 0 credits)
 *   2. User reviews estimated cost → approves or declines
 *   3. Credits reserved upfront → meeting runs → credits committed
 *   4. Any execution plan → cost breakdown shown → approved → reserved → executed
 *
 * Credit costs (defined in capability map, mirroring CREDIT888):
 *   - Strategy meeting: ~8 credits (6 agent contributions + synthesis)
 *   - SERP analysis: 1 credit
 *   - Deep audit: 3 credits
 *   - AI report: 2 credits
 *   - Write article: 3 credits
 *   - Generate image: 2 credits
 *   - Generate video: 5 credits
 */
class ProactiveStrategyEngine
{
    // Credit costs per action (matches CREDIT888 capability map)
    private const CREDIT_COSTS = [
        'strategy_meeting' => 8,
        'serp_analysis' => 1,
        'deep_audit' => 3,
        'ai_report' => 2,
        'write_article' => 3,
        'improve_draft' => 2,
        'generate_outline' => 1,
        'generate_image' => 2,
        'generate_video' => 5,
        'social_create_post' => 0,  // Manual creation is free
        'social_ai_post' => 1,     // AI-generated post costs 1
        'create_campaign' => 0,     // Creation is free, sending costs
        'send_campaign' => 2,
    ];

    // Max tokens per agent per meeting round
    private const AGENT_TOKEN_CAP = 200;

    public function __construct(
        private CreditService $credits,
        private AgentMeetingEngine $meetings,
        private SarahOrchestrator $sarah,
        private GlobalKnowledgeService $globalKnowledge,
        private NotificationService $notifications,
    ) {}

    /**
     * Triggered when workspace completes onboarding.
     * Sarah sends a TEMPLATE cost estimate — ZERO credits.
     * Nothing runs until user approves.
     */
    public function onOnboardingComplete(int $wsId, int $userId): array
    {
        $workspace = Workspace::findOrFail($wsId);
        $balance = $this->credits->getBalance($wsId);

        // Estimate what the initial strategy session will cost
        $estimate = $this->estimateInitialSessionCost($workspace);

        // Store the pending proposal (not executed yet)
        $proposalId = DB::table('strategy_proposals')->insertGetId([
            'workspace_id' => $wsId,
            'type' => 'initial_strategy',
            'title' => 'Initial Marketing Strategy Session',
            'description' => "Strategy meeting with your AI marketing team to create a comprehensive plan for {$workspace->business_name}",
            'status' => 'pending_approval',
            'cost_breakdown_json' => json_encode($estimate['breakdown']),
            'total_credits' => $estimate['total'],
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Send TEMPLATE notification — this is Sarah's ONLY free action
        $this->notifications->send($wsId, 'in_app', 'sarah_proposal', [
            'user_id' => $userId,
            'message' => $this->buildCostEstimateMessage($workspace, $estimate, $balance),
        ]);

        return [
            'proposal_id' => $proposalId,
            'status' => 'pending_approval',
            'estimated_credits' => $estimate['total'],
            'balance' => $balance['available'] ?? 0,
            'message' => 'Cost estimate sent. Waiting for user approval.',
        ];
    }

    /**
     * User approves the initial strategy session.
     * NOW credits are reserved and the meeting starts.
     */
    public function approveProposal(int $wsId, int $userId, int $proposalId): array
    {
        $proposal = DB::table('strategy_proposals')->where('id', $proposalId)->where('workspace_id', $wsId)->first();
        if (!$proposal) throw new \RuntimeException('Proposal not found');
        if ($proposal->status !== 'pending_approval') throw new \RuntimeException('Proposal already processed');

        $totalCredits = $proposal->total_credits;

        // Reserve credits BEFORE doing anything
        $hasCredits = $this->credits->hasBalance($wsId, $totalCredits);
        if (!$hasCredits) {
            DB::table('strategy_proposals')->where('id', $proposalId)->update(['status' => 'insufficient_credits', 'updated_at' => now()]);
            return [
                'success' => false,
                'error' => "Insufficient credits. Required: {$totalCredits}, available: " . ($this->credits->getBalance($wsId)['available'] ?? 0),
                'code' => 'NO_CREDITS',
            ];
        }

        $reservationRef = $this->credits->reserve($wsId, $totalCredits, "strategy_session:proposal:{$proposalId}");

        // Update proposal status
        DB::table('strategy_proposals')->where('id', $proposalId)->update([
            'status' => 'approved',
            'approved_at' => now(),
            'reservation_ref' => $reservationRef,
            'updated_at' => now(),
        ]);

        // NOW start the meeting (credits reserved)
        $workspace = Workspace::find($wsId);
        $goal = $this->buildOnboardingGoal($workspace);

        try {
            $meeting = $this->meetings->startMeeting($wsId, $userId, $goal);

            // Commit credits (meeting started successfully)
            $this->credits->commit($wsId, $reservationRef, $totalCredits);

            DB::table('strategy_proposals')->where('id', $proposalId)->update([
                'status' => 'executing',
                'meeting_id' => $meeting['meeting_id'] ?? null,
                'updated_at' => now(),
            ]);

            return [
                'success' => true,
                'meeting_id' => $meeting['meeting_id'],
                'credits_used' => $totalCredits,
                'message' => 'Strategy session started. Your team is collaborating now.',
            ];
        } catch (\Throwable $e) {
            // Release credits on failure
            $this->credits->release($wsId, $reservationRef);
            DB::table('strategy_proposals')->where('id', $proposalId)->update(['status' => 'failed', 'updated_at' => now()]);
            throw $e;
        }
    }

    /**
     * User declines the proposal. Zero credits spent.
     */
    public function declineProposal(int $wsId, int $proposalId): array
    {
        DB::table('strategy_proposals')->where('id', $proposalId)->where('workspace_id', $wsId)
            ->update(['status' => 'declined', 'updated_at' => now()]);

        return ['success' => true, 'credits_used' => 0, 'message' => 'Proposal declined. No credits were used.'];
    }

    /**
     * Estimate cost for any plan before execution.
     * Called by SarahOrchestrator before creating any plan.
     */
    public function estimatePlanCost(array $tasks): array
    {
        $breakdown = [];
        $total = 0;

        foreach ($tasks as $task) {
            $action = $task['action'] ?? '';
            $cost = self::CREDIT_COSTS[$action] ?? 0;

            // Check capability map for credit cost
            if ($cost === 0) {
                $cap = app(\App\Core\EngineKernel\CapabilityMapService::class)->resolveAction($task['engine'] ?? '', $action);
                $cost = $cap['credit_cost'] ?? 0;
            }

            $breakdown[] = [
                'engine' => $task['engine'] ?? '',
                'action' => $action,
                'agent' => $task['agent'] ?? $task['assigned_agent'] ?? 'sarah',
                'description' => $task['description'] ?? "{$task['engine']}/{$action}",
                'credits' => $cost,
            ];
            $total += $cost;
        }

        return ['breakdown' => $breakdown, 'total' => $total];
    }

    /**
     * Daily proactive check — Sarah reviews workspace health.
     * Sends TEMPLATE notifications only — zero credits.
     */
    public function dailyCheck(int $wsId): array
    {
        $workspace = Workspace::find($wsId);
        if (!$workspace || !$workspace->onboarded) return ['skipped' => true];

        $actions = [];

        // Check for pending approvals (template notification, free)
        $pendingApprovals = DB::table('execution_plans')
            ->where('workspace_id', $wsId)
            ->where('status', 'draft')
            ->where('requires_approval', true)
            ->count();

        $pendingProposals = DB::table('strategy_proposals')
            ->where('workspace_id', $wsId)
            ->where('status', 'pending_approval')
            ->count();

        if ($pendingApprovals > 0 || $pendingProposals > 0) {
            $total = $pendingApprovals + $pendingProposals;
            $actions[] = ['type' => 'pending_approvals', 'count' => $total];
            $this->notifications->send($wsId, 'in_app', 'sarah_reminder', [
                'message' => "You have {$total} item(s) waiting for your approval. " .
                    "Your AI team is ready to work once you give the go-ahead. Check the Strategy Room.",
            ]);
        }

        // Check for stale tasks (template notification, free)
        $staleTasks = DB::table('plan_tasks')
            ->join('execution_plans', 'plan_tasks.plan_id', '=', 'execution_plans.id')
            ->where('execution_plans.workspace_id', $wsId)
            ->where('plan_tasks.status', 'pending')
            ->where('plan_tasks.created_at', '<', now()->subHours(48))
            ->count();

        if ($staleTasks > 0) {
            $actions[] = ['type' => 'stale_tasks', 'count' => $staleTasks];
        }

        // Identify opportunities and propose them WITH cost estimates
        $opportunities = $this->findOpportunities($wsId, $workspace);
        foreach ($opportunities as $opp) {
            // Create a proposal with cost estimate
            DB::table('strategy_proposals')->insert([
                'workspace_id' => $wsId,
                'type' => $opp['type'],
                'title' => $opp['title'],
                'description' => $opp['description'],
                'status' => 'pending_approval',
                'cost_breakdown_json' => json_encode($opp['cost_breakdown']),
                'total_credits' => $opp['total_credits'],
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $actions[] = ['type' => 'opportunity', 'proposal' => $opp['title'], 'credits' => $opp['total_credits']];
        }

        return ['actions' => $actions, 'checked_at' => now()->toISOString()];
    }

    /**
     * Weekly performance review — template notification, zero credits.
     * If recommending new actions, sends cost estimate for approval.
     */
    public function weeklyReview(int $wsId): array
    {
        $workspace = Workspace::find($wsId);
        if (!$workspace || !$workspace->onboarded) return ['skipped' => true];

        $weekStart = now()->subWeek();

        $tasksCompleted = DB::table('plan_tasks')
            ->join('execution_plans', 'plan_tasks.plan_id', '=', 'execution_plans.id')
            ->where('execution_plans.workspace_id', $wsId)
            ->where('plan_tasks.status', 'completed')
            ->where('plan_tasks.completed_at', '>=', $weekStart)
            ->count();

        $creditsUsed = abs(DB::table('credit_transactions')
            ->where('workspace_id', $wsId)
            ->where('type', 'commit')
            ->where('created_at', '>=', $weekStart)
            ->sum('amount') ?? 0);

        $newLeads = DB::table('leads')
            ->where('workspace_id', $wsId)
            ->where('created_at', '>=', $weekStart)
            ->count();

        // Template notification — zero credits
        $this->notifications->send($wsId, 'in_app', 'sarah_weekly', [
            'message' => "Weekly report from Sarah:\n\n" .
                "Tasks completed: {$tasksCompleted}\n" .
                "Credits used: {$creditsUsed}\n" .
                "New leads: {$newLeads}\n\n" .
                "Check the Strategy Room for recommendations.",
        ]);

        return ['tasks_completed' => $tasksCompleted, 'credits_used' => $creditsUsed, 'new_leads' => $newLeads];
    }

    /**
     * Monthly strategy — proposes a new strategy meeting WITH cost estimate.
     */
    public function monthlyStrategy(int $wsId, int $userId): array
    {
        $workspace = Workspace::find($wsId);
        if (!$workspace || !$workspace->onboarded) return ['skipped' => true];

        $estimate = $this->estimateInitialSessionCost($workspace);

        $proposalId = DB::table('strategy_proposals')->insertGetId([
            'workspace_id' => $wsId,
            'type' => 'monthly_review',
            'title' => 'Monthly Strategy Review',
            'description' => "Monthly strategy session to review performance and plan ahead for {$workspace->business_name}",
            'status' => 'pending_approval',
            'cost_breakdown_json' => json_encode($estimate['breakdown']),
            'total_credits' => $estimate['total'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $balance = $this->credits->getBalance($wsId);

        $this->notifications->send($wsId, 'in_app', 'sarah_monthly_proposal', [
            'user_id' => $userId,
            'message' => "It's time for your monthly strategy review. I'd like to gather the team.\n\n" .
                "Estimated cost: {$estimate['total']} credits\n" .
                "Your balance: " . ($balance['available'] ?? 0) . " credits\n\n" .
                "Approve in the Strategy Room to start.",
        ]);

        return ['proposal_id' => $proposalId, 'estimated_credits' => $estimate['total']];
    }

    /**
     * List pending proposals for a workspace.
     */
    public function listProposals(int $wsId, ?string $status = null): array
    {
        $q = DB::table('strategy_proposals')->where('workspace_id', $wsId);
        if ($status) $q->where('status', $status);
        return $q->orderByDesc('created_at')->limit(20)->get()->toArray();
    }

    // ═══════════════════════════════════════════════════════════
    // PRIVATE
    // ═══════════════════════════════════════════════════════════

    private function estimateInitialSessionCost(Workspace $workspace): array
    {
        $agents = ['sarah', 'james', 'priya', 'marcus', 'elena'];
        $breakdown = [];

        // Meeting costs
        foreach ($agents as $agent) {
            $breakdown[] = [
                'action' => 'agent_contribution',
                'agent' => $agent,
                'description' => ucfirst($agent) . "'s expert analysis (~" . self::AGENT_TOKEN_CAP . " tokens)",
                'credits' => 1,
            ];
        }

        // Discussion round
        $breakdown[] = ['action' => 'meeting_debate', 'agent' => 'team', 'description' => 'Team discussion and debate', 'credits' => 2];

        // Sarah's synthesis
        $breakdown[] = ['action' => 'meeting_synthesis', 'agent' => 'sarah', 'description' => 'Sarah creates the final plan', 'credits' => 1];

        $total = array_sum(array_column($breakdown, 'credits'));

        return ['breakdown' => $breakdown, 'total' => $total];
    }

    private function buildCostEstimateMessage(Workspace $workspace, array $estimate, array $balance): string
    {
        $lines = [
            "Welcome to LevelUp, {$workspace->business_name}! I'm Sarah, your Digital Marketing Manager.",
            "",
            "I'd like to run a strategy session with the team to plan your digital marketing. Here's what it involves:",
            "",
        ];

        foreach ($estimate['breakdown'] as $item) {
            $lines[] = "  • {$item['description']}: {$item['credits']} credit(s)";
        }

        $lines[] = "";
        $lines[] = "Total estimated cost: {$estimate['total']} credits";
        $lines[] = "Your current balance: " . ($balance['available'] ?? 0) . " credits";
        $lines[] = "";
        $lines[] = "Approve in the Strategy Room to get started. No credits will be used until you approve.";

        return implode("\n", $lines);
    }

    private function buildOnboardingGoal(Workspace $workspace): string
    {
        $parts = ["Create a digital marketing strategy for {$workspace->business_name}"];
        if ($workspace->industry) $parts[] = "in {$workspace->industry}";
        if ($workspace->location) $parts[] = "targeting {$workspace->location}";
        if ($workspace->goal) {
            $goalMap = ['leads' => 'to generate leads', 'brand' => 'to build brand awareness',
                        'ecommerce' => 'to drive online sales', 'portfolio' => 'to showcase their portfolio'];
            $parts[] = $goalMap[$workspace->goal] ?? "with goal: {$workspace->goal}";
        }
        return implode(' ', $parts);
    }

    private function findOpportunities(int $wsId, Workspace $workspace): array
    {
        $opportunities = [];

        // Check if no content published
        $articleCount = DB::table('articles')->where('workspace_id', $wsId)->where('status', 'published')->count();
        if ($articleCount === 0) {
            $opportunities[] = [
                'type' => 'first_content',
                'title' => 'Publish your first article',
                'description' => 'Publishing SEO-optimized content boosts your search visibility. Priya can write your first article.',
                'cost_breakdown' => [['action' => 'write_article', 'agent' => 'priya', 'description' => 'AI-generated article', 'credits' => 3]],
                'total_credits' => 3,
            ];
        }

        // Check if no SEO audit done
        $hasAudit = DB::table('seo_audits')->where('workspace_id', $wsId)->where('type', 'full')->exists();
        if (!$hasAudit) {
            $opportunities[] = [
                'type' => 'seo_audit',
                'title' => 'Run your first SEO audit',
                'description' => 'A technical audit reveals quick wins for your website ranking.',
                'cost_breakdown' => [['action' => 'deep_audit', 'agent' => 'james', 'description' => 'Full technical SEO audit', 'credits' => 3]],
                'total_credits' => 3,
            ];
        }

        return $opportunities;
    }
}
