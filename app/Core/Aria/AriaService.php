<?php

namespace App\Core\Aria;

use App\Connectors\RuntimeClient;
use App\Core\Billing\CreditService;
use App\Core\LLM\PromptTemplates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ARIA888 — Aria, the platform FAQ (2026-09-15, DEC-0054).
 *
 * What she is: a question-answering surface over PlatformFaqCorpus. She explains the platform, the plans, the
 * credits and where things live, and points the customer to Sarah or Arthur when they want work done.
 *
 * What she is not (and cannot be): an executor. The model is called through RuntimeClient::chatJson — the plain
 * language-model proxy with no tool router and no agent persona — so there is no path from a question to an
 * action. The earlier assistant ran under Sarah's runtime persona and was held back by prompt text alone.
 *
 * Money: the Owner's standing rule — 1 credit per 10 messages on every chat surface (CreditService::meterChat,
 * reason aria_message). When the workspace has no credits she still answers, from the documentation alone
 * (no model call, nothing charged), so help is never dark.
 */
class AriaService
{
    public const MAX_QUESTION = 1000;
    public const HISTORY_TURNS = 6;

    public function __construct(
        private PlatformFaqCorpus $corpus,
        private RuntimeClient $runtime,
        private CreditService $credits,
    ) {}

    /**
     * @param array<int, array{role:string, content:string}> $history  the client's last turns (not persisted)
     * @return array{answer:string, sources:array, handoff:?array, followups:array, chat_meter:array, mode:string}
     */
    public function answer(int $workspaceId, string $question, array $history = []): array
    {
        $question = trim(mb_substr(trim($question), 0, self::MAX_QUESTION));
        if ($question === '') {
            return $this->pack('Ask me anything about the platform: what a feature does, where it lives, what a plan includes, what something costs.', [], null, $this->starterFollowups(), ['counter' => 0, 'debited' => false, 'sufficient' => true], 'empty');
        }

        $meter = $this->credits->meterChat($workspaceId, 'aria_message');
        $passages = $this->corpus->retrieve($question, 6);
        $facts = $this->accountFacts($workspaceId);

        if (!($meter['sufficient'] ?? false)) {
            // Out of credits: answer from the documentation alone. Nothing is charged, nothing is invented.
            return $this->documentationOnly($passages, $facts, $meter, 'out_of_credits');
        }

        $reply = $this->ask($question, $history, $passages, $facts);
        if ($reply === null) {
            return $this->documentationOnly($passages, $facts, $meter, 'fallback');
        }
        $reply['chat_meter'] = $this->meterOut($meter);
        $reply['mode'] = 'model';
        return $reply;
    }

    /** Plan name, credits and trial state — the only account facts Aria reads. */
    public function accountFacts(int $workspaceId): array
    {
        $plan = 'Free';
        try {
            $planId = DB::table('subscriptions')->where('workspace_id', $workspaceId)->where('status', 'active')->orderByDesc('id')->value('plan_id');
            if ($planId) { $plan = (string) (DB::table('plans')->where('id', $planId)->value('name') ?: 'Free'); }
        } catch (\Throwable $e) {}
        $available = null;
        try { $available = (int) ($this->credits->getBalance($workspaceId)['available'] ?? 0); } catch (\Throwable $e) {}
        $trial = false;
        try {
            $ws = DB::table('workspaces')->where('id', $workspaceId)->first(['is_trial', 'trial_expires_at']);
            $trial = $ws && (int) $ws->is_trial === 1 && $ws->trial_expires_at && strtotime((string) $ws->trial_expires_at) > time();
        } catch (\Throwable $e) {}
        return ['plan' => $plan, 'credits_available' => $available, 'on_trial' => $trial];
    }

    private function ask(string $question, array $history, array $passages, array $facts): ?array
    {
        if (!$this->runtime->isConfigured()) return null;

        $doc = '';
        foreach ($passages as $i => $p) {
            $doc .= "[" . ($i + 1) . "] " . $p['title'] . "\n" . $p['text'] . ($p['links'] ? "\nPages: " . implode(', ', $p['links']) : '') . "\n\n";
        }
        if ($doc === '') $doc = "(no passage matched this question)\n";

        $system = "You are Aria, the LevelUpGrowth platform FAQ. You answer questions about how the platform works: features, where things live in the app, plans, prices, credits, billing, the AI workforce, the website builder, policies.\n"
            . "RULES\n"
            . "- Answer ONLY from the PASSAGES and the ACCOUNT FACTS below. If they do not cover the question, say plainly that the documentation does not cover it and suggest asking Sarah in the app or writing to hello@levelupgrowth.io. Never invent a feature, a price, a limit or a policy.\n"
            . "- You never perform actions. You do not change settings, build or edit websites, write content, spend credits or run tasks. When the customer asks for work to be done, explain in one sentence who does it and set handoff: Sarah for marketing, content, SEO, social, leads, images and video and anything the workforce does; Arthur for website building and editing.\n"
            . "- Be concrete: name the menu item and the view (Basic or Advanced) when telling someone where something is.\n"
            . "- Short answers. Two to five sentences, or a short bulleted list when listing plans or steps. Markdown: **bold** for the menu item names, - for bullets. No headings.\n"
            . "- Never mention providers, vendors, models or internal system names. Never call yourself Sarah.\n"
            . "- When the question is about the customer's own plan or credits, use ACCOUNT FACTS and say the plan name and the balance.\n"
            . PromptTemplates::languageRule() . "\n"
            . "OUTPUT: valid JSON only, this exact shape:\n"
            . '{"answer": "markdown text", "sources": [1, 2], "handoff": {"to": "sarah" | "arthur", "prompt": "what to ask them, in the customer\'s words"} | null, "followups": ["a related question the customer might ask next", "another"]}' . "\n"
            . "sources = the passage numbers you used (empty when none). followups = up to three short questions that the passages can answer.";

        $hist = '';
        foreach (array_slice($history, -self::HISTORY_TURNS) as $h) {
            $role = ($h['role'] ?? '') === 'assistant' ? 'Aria' : 'Customer';
            $c = trim(mb_substr((string) ($h['content'] ?? ''), 0, 600));
            if ($c !== '') $hist .= $role . ': ' . $c . "\n";
        }

        $user = "ACCOUNT FACTS\nPlan: " . $facts['plan'] . "\nCredits available: " . ($facts['credits_available'] === null ? 'unknown' : $facts['credits_available']) . ($facts['on_trial'] ? "\nOn the free AI trial: yes" : '') . "\n\n"
            . "PASSAGES\n" . $doc
            . ($hist !== '' ? "EARLIER IN THIS CONVERSATION\n" . $hist . "\n" : '')
            . "QUESTION\n" . $question . "\n\nRespond with the JSON object.";

        $t0 = microtime(true);
        $res = $this->runtime->chatJson($system, $user, [], 700);
        $ms = (int) round((microtime(true) - $t0) * 1000);
        $parsed = is_array($res['parsed'] ?? null) ? $res['parsed'] : null;
        if (!($res['success'] ?? false) || !$parsed || trim((string) ($parsed['answer'] ?? '')) === '') {
            Log::warning('[aria] model answer unavailable', ['error' => $res['error'] ?? null, 'ms' => $ms]);
            return null;
        }
        Log::info('[aria] answered', ['ms' => $ms, 'passages' => count($passages), 'top' => $passages[0]['id'] ?? null]);

        $sources = [];
        foreach ((array) ($parsed['sources'] ?? []) as $n) {
            $i = (int) $n - 1;
            if (isset($passages[$i])) $sources[] = $this->sourceOut($passages[$i]);
        }
        $handoff = null;
        if (is_array($parsed["handoff"] ?? null) && in_array($parsed["handoff"]["to"] ?? "", ["sarah", "arthur"], true)) {
            $handoff = ["to" => $parsed["handoff"]["to"], "prompt" => trim(mb_substr((string) ($parsed["handoff"]["prompt"] ?? $question), 0, 300)) ?: $question];
        }
        if ($handoff === null) $handoff = $this->inferHandoff($question);
        $followups = [];
        foreach ((array) ($parsed['followups'] ?? []) as $f) {
            $f = trim((string) $f);
            if ($f !== '' && count($followups) < 3) $followups[] = mb_substr($f, 0, 120);
        }
        return $this->pack(trim((string) $parsed['answer']), $sources, $handoff, $followups, [], 'model');
    }

    /**
     * A request for work that the model answered without a hand-off still gets one: an imperative about the
     * website goes to Arthur, any other imperative to Sarah. Questions ("how do I…", "can I…", "what…") get none.
     */
    public function inferHandoff(string $question): ?array
    {
        $q = mb_strtolower(trim($question));
        if ($q === '' || preg_match('/^(how|what|where|which|who|why|when|can i|could i|do i|does|is|are|will|should)\b/', $q)) return null;
        if (!preg_match('/\b(write|create|build|make|add|change|update|edit|generate|design|draft|post|schedule|send|plan|publish|set up|setup|move|resize|recolou?r|remove|delete|rewrite|improve|optimi[sz]e|track|import|launch|run|start)\b/', $q)) return null;
        $site = preg_match('/\b(website|site|page|section|hero|header|footer|button|logo|colou?rs?|palette|gradient|font|layout|listing|menu item|image on|photo on|banner|nav|contact form|booking form)\b/', $q) === 1;
        return ['to' => $site ? 'arthur' : 'sarah', 'prompt' => mb_substr(trim($question), 0, 300)];
    }

    private function documentationOnly(array $passages, array $facts, array $meter, string $mode): array
    {
        if ($passages === []) {
            $text = 'The documentation does not cover that. Ask Sarah in the app, or write to hello@levelupgrowth.io.';
            return $this->pack($text, [], null, $this->starterFollowups(), $this->meterOut($meter), $mode);
        }
        $top = $passages[0];
        $text = '**' . $top['title'] . "**\n" . $top['text'];
        if ($mode === 'out_of_credits') {
            $text .= "\n\nThis workspace has no credits left, so this is the documentation answer without a conversation. Chat is 1 credit for every 10 messages; top up under **Billing**.";
        }
        $followups = [];
        foreach (array_slice($passages, 1, 3) as $p) $followups[] = $p['title'];
        return $this->pack($text, [$this->sourceOut($top)], null, $followups, $this->meterOut($meter), $mode);
    }

    private function sourceOut(array $p): array
    {
        return ['title' => $p['title'], 'doc' => $p['doc'], 'url' => $p['links'][0] ?? null];
    }

    private function meterOut(array $meter): array
    {
        return ['counter' => (int) ($meter['counter'] ?? 0), 'debited' => (bool) ($meter['debited'] ?? false), 'sufficient' => (bool) ($meter['sufficient'] ?? true), 'threshold' => 10];
    }

    private function pack(string $answer, array $sources, ?array $handoff, array $followups, array $meter, string $mode): array
    {
        return ['answer' => $answer, 'sources' => $sources, 'handoff' => $handoff, 'followups' => $followups, 'chat_meter' => $meter, 'mode' => $mode];
    }

    public function starterFollowups(): array
    {
        return ['What does my plan include?', 'How do credits work?', 'How do I edit my website?'];
    }

    /** The starter questions and topic groups the panel shows before the first question. */
    public function suggestions(int $workspaceId): array
    {
        $facts = $this->accountFacts($workspaceId);
        return [
            'greeting' => 'Hi, I am Aria. Ask me anything about the platform: where things are, what a plan includes, what something costs, how Sarah and Arthur work.',
            'account'  => $facts,
            'starters' => [
                'What does my plan include?',
                'How do credits work and what does each action cost?',
                'How do I edit my website myself?',
                'What can Arthur add to my site?',
                'Who are the specialists Sarah manages?',
                'How do I connect my own domain?',
                'What is the difference between Basic and Advanced?',
                'How do I cancel or change my plan?',
            ],
            'topics' => $this->corpus->topics(),
        ];
    }
}
