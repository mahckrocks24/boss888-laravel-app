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

        if (\App\Core\Managed\ManagedWorkspaces::isManaged($workspaceId)) {
            return $this->answerManaged($workspaceId, $question, $history);
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
            $text .= "\n\nThis workspace has no credits left, so this is the documentation answer without a conversation. Chat is 1 credit for every 5 messages; more credits come with a bigger plan or your monthly renewal (**Billing**).";
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
        return ['counter' => (int) ($meter['counter'] ?? 0), 'debited' => (bool) ($meter['debited'] ?? false), 'sufficient' => (bool) ($meter['sufficient'] ?? true), 'threshold' => \App\Core\Billing\CreditService::CHAT_METER_EVERY];
    }

    private function pack(string $answer, array $sources, ?array $handoff, array $followups, array $meter, string $mode): array
    {
        return ['answer' => $answer, 'sources' => $sources, 'handoff' => $handoff, 'followups' => $followups, 'chat_meter' => $meter, 'mode' => $mode];
    }

    // ── MANAGED-4 (RFC-0029, Owner 2026-10-05: "add Aria inside it but intelligence must be limited to the plan
    //    coverage only") ──────────────────────────────────────────────────────────────────────────────────────────

    private function answerManaged(int $workspaceId, string $question, array $history): array
    {
        $corpus = $this->corpus->forScope('managed');
        $passages = $corpus->retrieve($question, 6);
        $facts = $this->managedFacts($workspaceId);
        $free = ['counter' => 0, 'debited' => false, 'sufficient' => true];   // included in the package

        $reply = $this->askManaged($question, $history, $passages, $facts);
        if ($reply === null) {
            if ($passages === []) {
                return $this->pack('That is outside what I can help with for your Managed Hosting package. ' . $this->supportLine($facts), [], null, $this->managedStarters(3), $free, 'fallback');
            }
            $top = $passages[0];

            return $this->pack('**' . $top['title'] . "**\n" . $top['text'], [$this->sourceOut($top)], null,
                array_map(fn ($p) => $p['title'], array_slice($passages, 1, 3)), $free, 'fallback');
        }
        $reply['chat_meter'] = $free;
        $reply['mode'] = 'model';

        return $reply;
    }

    /** What Aria may know about a managed account: its package, its service status, its email state. Nothing else. */
    public function managedFacts(int $workspaceId): array
    {
        $c = DB::table('managed_contracts')->where('workspace_id', $workspaceId)->orderByDesc('term_end')->first();
        $ws = DB::table('workspaces')->where('id', $workspaceId)->first(['name', 'business_name']);
        $status = [];
        try { $status = \App\Core\Managed\ManagedStatus::forWorkspace($workspaceId); } catch (\Throwable $e) {}
        $mailboxes = 0;
        $emailDomain = null;
        try {
            $emailDomain = DB::table('email_domains')->where('workspace_id', $workspaceId)->value('domain');
            $mailboxes = DB::table('email_mailboxes')->where('workspace_id', $workspaceId)->whereNotIn('lifecycle_state', ['deleted'])->count();
        } catch (\Throwable $e) {}

        return [
            'organisation'   => $ws ? ($ws->business_name ?: $ws->name) : null,
            'package'        => $c?->package_name,
            'inclusions'     => $c ? (json_decode((string) $c->inclusions_json, true) ?: []) : [],
            'amount'         => $c ? ($c->currency . ' ' . number_format($c->amount_minor / 100, 2)) : null,
            'paid_at'        => $c?->paid_at,
            'term'           => $c ? ($c->term_start . ' to ' . $c->term_end) : null,
            'support_until'  => $c?->support_until,
            'invoice'        => $c?->invoice_ref,
            'receipt'        => $c?->receipt_ref,
            'domain'         => $status['host'] ?? null,
            'website'        => $status['website']['label'] ?? null,
            'uptime_30d'     => $status['website']['uptime_30d'] ?? null,
            'email_status'   => $status['email']['label'] ?? null,
            'certificate'    => $status['certificate']['label'] ?? null,
            'email_on_us'    => $emailDomain !== null,
            'mailboxes'      => $mailboxes,
            'support_phone'  => config('managed.support_phone'),
            'support_email'  => config('managed.support_email'),
            'today'          => now()->toDateString(),
        ];
    }

    private function supportLine(array $f): string
    {
        return 'Contact LevelUpGrowth support' . ($f['support_phone'] ? ' on ' . $f['support_phone'] : '') . ($f['support_email'] ? ' or at ' . $f['support_email'] : '') . '.';
    }

    private function askManaged(string $question, array $history, array $passages, array $f): ?array
    {
        if (! $this->runtime->isConfigured()) {
            return null;
        }
        $doc = '';
        foreach ($passages as $i => $p) {
            $doc .= '[' . ($i + 1) . '] ' . $p['title'] . "\n" . $p['text'] . "\n\n";
        }
        if ($doc === '') {
            $doc = "(no passage matched this question)\n";
        }

        $system = "You are Aria, the help assistant for a client's Managed Hosting package with LevelUpGrowth. The client's package covers ONLY: website hosting, database management, business email hosting, backups and security, the IT support period, the package's billing, and how to use this portal (Email, Billing, Account, service status).\n"
            . "RULES\n"
            . "- Answer ONLY from the PASSAGES and the PACKAGE FACTS below. Never invent a price, a date, a limit, a policy or a feature.\n"
            . "- If the question is outside the package (marketing, AI agents, content, SEO, social media, website building or design work, other LevelUpGrowth plans, credits, or anything not in the passages), say in one sentence that it is outside what you can help with for their Managed Hosting package, and give the support phone and email. Do not explain or describe other LevelUpGrowth products, and never mention Sarah, Arthur, plans or credits.\n"
            . "- If the passages do not cover a package question, say so plainly and give the support phone and email.\n"
            . "- You never perform actions and cannot change anything on the account. Tell the client where to do it in the portal (**Email**, **Billing**, **Account**) or to contact support.\n"
            . "- Use the PACKAGE FACTS for questions about their own package, dates, payment, support period, website status or email.\n"
            . "- Short answers: two to five sentences, or a short bulleted list. Markdown: **bold** for page names, - for bullets. No headings.\n"
            . "- Write dates the way people read them, for example 7 December 2026, never 2026-12-07.\n"
            . "- Never mention providers, vendors, models, servers or internal system names. Never mention other systems or clients hosted by LevelUpGrowth.\n"
            . PromptTemplates::languageRule() . "\n"
            . "OUTPUT: valid JSON only, this exact shape:\n"
            . '{"answer": "markdown text", "sources": [1, 2], "followups": ["a related question about their package", "another"]}' . "\n"
            . 'sources = the passage numbers you used (empty when none). followups = up to three short questions the passages can answer.';

        $facts = "PACKAGE FACTS\n"
            . 'Organisation: ' . ($f['organisation'] ?? 'unknown') . "\n"
            . 'Package: ' . ($f['package'] ?? 'unknown') . "\n"
            . 'Includes: ' . implode('; ', $f['inclusions']) . "\n"
            . 'Paid: ' . ($f['amount'] ?? 'unknown') . ($f['paid_at'] ? ' on ' . $f['paid_at'] : '') . " (paid in full)\n"
            . 'Term: ' . ($f['term'] ?? 'unknown') . "\n"
            . 'Free IT support until: ' . ($f['support_until'] ?? 'not included') . "\n"
            . 'Invoice: ' . ($f['invoice'] ?? '-') . ', receipt: ' . ($f['receipt'] ?? '-') . " (copies on request from support)\n"
            . 'Website: ' . ($f['domain'] ?? 'unknown') . ' - ' . ($f['website'] ?? 'unknown') . ($f['uptime_30d'] !== null ? ', ' . $f['uptime_30d'] . '% uptime in the last 30 days' : '') . "\n"
            . 'Email: ' . ($f['email_status'] ?? 'unknown') . '; ' . ($f['email_on_us'] ? $f['mailboxes'] . ' mailboxes on LevelUpGrowth' : 'mailboxes are still on their current email provider; the move to LevelUpGrowth is not scheduled yet') . "\n"
            . 'Secure connection: ' . ($f['certificate'] ?? 'unknown') . "\n"
            . 'Support: phone ' . ($f['support_phone'] ?? '-') . ', email ' . ($f['support_email'] ?? '-') . "\n"
            . 'Today: ' . $f['today'] . "\n\n";

        $hist = '';
        foreach (array_slice($history, -self::HISTORY_TURNS) as $h) {
            $c = trim(mb_substr((string) ($h['content'] ?? ''), 0, 600));
            if ($c !== '') {
                $hist .= (($h['role'] ?? '') === 'assistant' ? 'Aria' : 'Client') . ': ' . $c . "\n";
            }
        }
        $user = $facts . "PASSAGES\n" . $doc . ($hist !== '' ? "EARLIER IN THIS CONVERSATION\n" . $hist . "\n" : '') . "QUESTION\n" . $question . "\n\nRespond with the JSON object.";

        $res = $this->runtime->chatJson($system, $user, [], 600);
        $parsed = is_array($res['parsed'] ?? null) ? $res['parsed'] : null;
        if (! ($res['success'] ?? false) || ! $parsed || trim((string) ($parsed['answer'] ?? '')) === '') {
            Log::warning('[aria] managed answer unavailable', ['error' => $res['error'] ?? null]);

            return null;
        }
        $sources = [];
        foreach ((array) ($parsed['sources'] ?? []) as $n) {
            $i = (int) $n - 1;
            if (isset($passages[$i])) {
                $sources[] = $this->sourceOut($passages[$i]);
            }
        }
        $followups = [];
        foreach ((array) ($parsed['followups'] ?? []) as $q) {
            $q = trim((string) $q);
            if ($q !== '' && count($followups) < 3) {
                $followups[] = mb_substr($q, 0, 120);
            }
        }

        return $this->pack(trim((string) $parsed['answer']), $sources, null, $followups, [], 'model');
    }

    private function managedStarters(int $n = 8): array
    {
        return array_slice([
            'What does our package include?',
            'When does our package end, and when does IT support end?',
            'Is our website backed up?',
            'How do I add a person to our email?',
            'How does moving our email to LevelUpGrowth work?',
            'What happens if our website goes down?',
            'Where are our invoices and receipts?',
            'How do I change my portal password?',
        ], 0, $n);
    }

    private function managedSuggestions(int $workspaceId): array
    {
        $f = $this->managedFacts($workspaceId);

        return [
            'greeting' => 'Hi, I am Aria. Ask me anything about your Managed Hosting package: your website hosting, email, backups and security, billing and this portal.',
            'account'  => ['package' => $f['package'], 'support_until' => $f['support_until']],
            'starters' => $this->managedStarters(),
            'topics'   => $this->corpus->forScope('managed')->topics(),
        ];
    }

    public function starterFollowups(): array
    {
        return ['What does my plan include?', 'How do credits work?', 'How do I edit my website?'];
    }

    /** The starter questions and topic groups the panel shows before the first question. */
    public function suggestions(int $workspaceId): array
    {
        if (\App\Core\Managed\ManagedWorkspaces::isManaged($workspaceId)) {
            return $this->managedSuggestions($workspaceId);
        }

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
