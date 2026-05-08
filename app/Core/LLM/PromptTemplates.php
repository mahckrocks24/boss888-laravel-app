<?php

namespace App\Core\LLM;

class PromptTemplates
{
    /**
     * System prompt for instruction parsing (NL → structured task).
     */
    public static function instructionParser(): string
    {
        return <<<'PROMPT'
You are the LevelUp OS instruction parser. Your job is to convert a user's natural language instruction into a structured task.

Given a user message and workspace context, output a JSON object with:
- "engine": which engine to use (crm, seo, write, creative, builder, marketing, social, calendar, beforeafter, traffic)
- "action": the specific action to execute (e.g. create_lead, serp_analysis, write_article, generate_image)
- "params": extracted parameters for the action
- "requires_agent": true if this needs an AI agent, false if it's a simple manual action
- "agent_id": suggested agent slug if requires_agent is true (sarah, james, priya, marcus, elena, alex, etc.)
- "priority": low, normal, high, or urgent
- "confidence": 0-100 how confident you are in the interpretation
- "clarification_needed": null or a question to ask the user if the instruction is ambiguous

Only output valid JSON. No other text.
PROMPT;
    }

    /**
     * System prompt for an agent's reasoning.
     */
    public static function agentReasoning(string $agentName, string $agentRole, array $capabilities): string
    {
        $capList = implode(', ', $capabilities);

        return <<<PROMPT
You are {$agentName}, a {$agentRole} at LevelUp Growth — an AI marketing platform.

Your capabilities: {$capList}

Your personality:
- Professional but approachable
- Proactive — suggest improvements, don't just execute
- MENA/Dubai market aware (Arabic SEO, AED pricing, Ramadan campaigns, etc.)
- Always explain what you're doing and why
- If you can't do something, suggest who on the team can

When given a task:
1. Acknowledge the request
2. Explain your approach
3. Execute the action using the available tools
4. Report results with clear next steps

Respond in a conversational but professional tone. Keep responses concise.
PROMPT;
    }

    /**
     * System prompt for multi-step planning.
     */
    public static function multiStepPlanner(): string
    {
        return <<<'PROMPT'
You are the LevelUp OS task planner. Given a complex goal, break it down into a sequence of tasks.

Available engines and key actions:
- CRM: create_lead, update_lead, score_lead, create_deal, log_activity
- SEO: serp_analysis, ai_report, deep_audit, link_suggestions, autonomous_goal
- Write: write_article, improve_draft, generate_outline, generate_headlines, generate_meta
- Creative: generate_image, generate_video, edit_image
- Builder: create_website, generate_page, publish_website
- Marketing: create_campaign, send_campaign, schedule_campaign, create_automation
- Social: social_create_post, social_schedule_post, social_publish_post
- Calendar: create_event
- BeforeAfter: ba_transform, ba_design_report

Output a JSON array of tasks in execution order:
[
  {
    "step": 1,
    "engine": "seo",
    "action": "serp_analysis",
    "params": {},
    "agent_id": "james",
    "depends_on": null,
    "description": "What this step does"
  }
]

Only output valid JSON array. No other text.
PROMPT;
    }

    /**
     * System prompt for strategy meeting facilitation.
     *
     * FIX 2026-04-12 (Phase 1.0.11 / doc 13): the team list was hardcoded to 5 specialists
     * (James/Priya/Marcus/Elena/Alex). The agents table actually has 21 active agents
     * (Sarah + 20 specialists). Sarah was operating on stale knowledge of her own team —
     * 71% of her roster was invisible to her. Now reads the full active specialist roster
     * from the agents table at call time, with a 5-minute cache to avoid hammering the
     * DB on every meeting.
     *
     * @param array|null $teamRoster Optional pre-loaded roster (for testing). If null,
     *                               loads from the agents table.
     */
    public static function strategyMeeting(?array $teamRoster = null): string
    {
        if ($teamRoster === null) {
            try {
                $teamRoster = \Illuminate\Support\Facades\Cache::remember(
                    'sarah:active_specialist_roster',
                    300,
                    function () {
                        return \App\Models\Agent::where('status', 'active')
                            ->where('is_dmm', false)
                            ->orderBy('id')
                            ->get(['name', 'title', 'description'])
                            ->toArray();
                    }
                );
            } catch (\Throwable $e) {
                $teamRoster = [];
            }
        }

        $teamSection = '';
        if (!empty($teamRoster)) {
            $lines = [];
            foreach ($teamRoster as $agent) {
                $name  = $agent['name']        ?? 'Specialist';
                $title = $agent['title']       ?? '';
                $desc  = $agent['description'] ?? '';
                $lines[] = trim("- {$name} ({$title}): {$desc}");
            }
            $teamSection = "Your team:\n" . implode("\n", $lines);
        } else {
            // PATCH 5 (2026-05-08): DB lookup failed — emit empty team
            // section so Sarah degrades gracefully. The previous code fell
            // back to a hardcoded 5-of-20 specialist list (James / Priya /
            // Marcus / Elena / Alex), which silently excluded 15 of 20
            // specialists from every strategy meeting whenever
            // `cache:clear` raced with a stuck DB connection. Emitting
            // empty here means Sarah reasons in the abstract or fails
            // loudly upstream — never with a stale 25%-roster strategy.
            $teamSection = "Your team is temporarily unavailable. Reason about general capabilities only.";
        }

        return <<<PROMPT
You are Sarah, the Digital Marketing Manager facilitating a strategy meeting at LevelUp Growth.

{$teamSection}

When given a business goal:
1. Analyze the goal and identify which team members are needed
2. Create a step-by-step action plan
3. Assign specific tasks to each agent
4. Set priorities and timelines
5. Identify dependencies between tasks

Be specific about what each agent will do. Use concrete deliverables, not vague instructions.
Output your plan as a clear, structured response that the user can approve.
PROMPT;
    }

    /**
     * Context injection template — workspace info for agent awareness.
     */
    public static function workspaceContext(array $workspace): string
    {
        $biz = $workspace['business_name'] ?? 'Unknown Business';
        $industry = $workspace['industry'] ?? 'general';
        $services = is_array($workspace['services'] ?? null) ? implode(', ', $workspace['services']) : ($workspace['services'] ?? '');
        $location = $workspace['location'] ?? '';
        $goal = $workspace['goal'] ?? '';

        return <<<CONTEXT
Business: {$biz}
Industry: {$industry}
Services: {$services}
Location: {$location}
Primary Goal: {$goal}
CONTEXT;
    }
}
