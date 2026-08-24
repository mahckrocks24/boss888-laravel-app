<?php

namespace App\Core\Onboarding;

use App\Connectors\RuntimeClient;
use App\Core\Memory\WorkspaceMemoryService;
use App\Models\Workspace;
use Illuminate\Support\Facades\Log;

/**
 * MISSION-018 WS-4 (2026-08-24). "After signup the customer meets Sarah"
 * (MISSION-018 §7). The Owner rejected the five-step quiz; this is its
 * replacement — Sarah interviews the customer through the real Runtime LLM
 * and understands what they say.
 *
 * Adapted from the V2 platform's proven OnboardingInterview (EV-0585). The
 * design that transfers, verbatim in intent:
 *
 *   - REAL LLM ONLY. No regex, no keyword matching, no scripted
 *     pseudo-comprehension. The customer's reply goes to the Runtime with
 *     what is already known and what remains; the Runtime returns Sarah's
 *     next line AND the facts it recognised. This class decides nothing about
 *     meaning — it only refuses what it cannot verify.
 *   - REFUSE UNQUOTED FACTS. A recognised fact MUST carry a quote of the
 *     customer's own words. A claim with no quote is an invented fact, which
 *     the Owner's instruction forbids. The quote is evidence a human said it,
 *     not a matching key.
 *   - SUFFICIENCY FROM THE VOCABULARY. The brief names the criteria from the
 *     class constants below, so it can never ask Sarah to store something the
 *     platform has no field for, and the "what's still missing" list is
 *     arithmetic on the platform's own records — not a reading of the chat.
 *
 * Runtime boundary: uses RuntimeClient::chatJson (stateless /ai/run
 * task=chat_json) so provider choice stays in the Runtime (MC-0003) and no
 * conversation state leaves Laravel. Reachable and proven ~1.5s on the
 * interactive lane, model deepseek-v4-flash.
 */
class OnboardingInterviewService
{
    /** Business facts Sarah must understand before the operation can begin. */
    public const BUSINESS_FACTS = [
        'what_it_does'  => 'what the business actually does',
        'what_it_sells' => 'the products or services it sells',
        'who_it_serves' => 'the customers or audience it serves',
        'primary_goal'  => 'the main growth objective',
        'industry'      => 'the industry or category',
        'location'      => 'where the business operates',
    ];

    /** Digital presence assets, each customer_owned or absent. */
    public const PRESENCE_ASSETS = [
        'website'        => 'a live website',
        'domain'         => 'a custom domain',
        'hosting'        => 'website hosting',
        'business_email' => 'business email on their domain',
        'analytics'      => 'analytics or Search Console connected',
    ];

    private const AGENT = 'Sarah';

    public function __construct(
        private RuntimeClient $runtime,
        private WorkspaceMemoryService $memory,
    ) {}

    /**
     * Sarah's opening line, before the customer has said anything. The greeting
     * is hers — produced by the model — not a sentence the client wrote in her
     * name.
     */
    public function open(int $workspaceId): array
    {
        [$known, $standings] = $this->currentState($workspaceId);

        $out = $this->runtime->chatJson(
            $this->brief($known, $standings),
            'The client has just arrived and has said nothing yet. Introduce yourself '
                .'warmly in two sentences, say what you will do together, and ask your '
                .'first question. Recognise nothing.',
            [],
            900
        );

        return $this->interpret($workspaceId, $out, applyFacts: false);
    }

    /**
     * One customer turn: their message plus the transcript so far. Returns
     * Sarah's reply, the facts recognised from THIS message (persisted), and
     * whether the interview now has enough to begin.
     *
     * @param  list<array{role:string,content:string}>  $history
     */
    public function respondTo(int $workspaceId, string $message, array $history): array
    {
        [$known, $standings] = $this->currentState($workspaceId);

        $out = $this->runtime->chatJson(
            $this->brief($known, $standings),
            $this->transcript($history, $message),
            [],
            1200
        );

        return $this->interpret($workspaceId, $out, applyFacts: true);
    }

    /** What is already on record for this workspace, in the interview's shape. */
    private function currentState(int $workspaceId): array
    {
        $ws = Workspace::findOrFail($workspaceId);
        $data = is_array($ws->onboarding_data) ? $ws->onboarding_data : [];
        $iv = is_array($data['interview'] ?? null) ? $data['interview'] : [];

        $known = [];
        foreach (array_keys(self::BUSINESS_FACTS) as $key) {
            if (!empty($iv['facts'][$key]['value'])) {
                $known[$key] = $iv['facts'][$key]['value'];
            }
        }
        $standings = [];
        foreach (array_keys(self::PRESENCE_ASSETS) as $key) {
            if (!empty($iv['presence'][$key]['value'])) {
                $standings[$key] = $iv['presence'][$key]['value'];
            }
        }
        return [$known, $standings];
    }

    private function brief(array $known, array $standings): string
    {
        $facts = implode(', ', array_keys(self::BUSINESS_FACTS));
        $assets = implode(', ', array_keys(self::PRESENCE_ASSETS));

        $alreadyKnown = ($known === [] && $standings === [])
            ? 'Nothing yet. This is the start of the conversation.'
            : json_encode(['business' => $known, 'infrastructure' => $standings]);

        $outstanding = array_merge(
            array_diff(array_keys(self::BUSINESS_FACTS), array_keys($known)),
            array_diff(array_keys(self::PRESENCE_ASSETS), array_keys($standings)),
        );
        $missing = $outstanding === [] ? 'Nothing. You have everything you need.' : implode(', ', $outstanding);
        $agent = self::AGENT;

        return <<<PROMPT
        You are {$agent}, an experienced Digital Marketing Manager meeting a new client
        for the first time. You are having a real conversation, not administering a form.

        YOUR PURPOSE
        Understand this business well enough to start running its growth operation.
        Be warm, brief and genuinely curious. Ask one thing at a time. Follow what the
        client actually says rather than working through a checklist.

        WHAT YOU ALREADY KNOW
        {$alreadyKnown}

        Never ask about something you already know. If the client just told you several
        things at once, acknowledge that and move on to what is still missing. Never
        list anything above as "recognised" — it is already recorded. "recognised" is
        ONLY for what the client states in their latest message.

        SUFFICIENCY CRITERIA — the minimum before you can begin
        Business facts: {$facts}
        Infrastructure, each either "customer_owned" or "absent": {$assets}

        These are the MINIMUM, not the limit. Notice positioning, differentiators,
        markets, constraints, competitors and channels too, and record them as context.
        Cover infrastructure naturally in conversation, never as a checklist.

        RULES
        - Record a fact ONLY if the client actually stated it. Never infer or assume.
        - If something is ambiguous or strategically important, ask rather than guess.
        - When you have the minimum, say so naturally and stop interviewing.

        BEFORE YOU WRITE ANYTHING, WORK THROUGH THIS LIST
        {$missing}
        Take each in turn: did the client state it in the message they JUST sent? They
        routinely answer several at once. If so, it goes in "recognised" with their
        exact words. If not, it goes nowhere.

        OUTPUT — strict JSON, no prose, no markdown fences:
        {
          "reply": "what you say next, in your own voice",
          "recognised": [
            {"kind":"business","key":"one business fact key","value":"what they said","quote":"their words"},
            {"kind":"infrastructure","key":"one asset key","value":"customer_owned or absent","quote":"their words"}
          ],
          "context": [{"note":"other useful business context they stated","quote":"their words"}],
          "sufficient": false
        }

        "recognised" and "context" may be empty and often are. Report ONLY what the
        client states in their LATEST message; read earlier turns for meaning, never to
        repeat. Every entry MUST carry a quote of the client's own words. Set
        "sufficient" true only when every criterion above is satisfied.
        PROMPT;
    }

    /**
     * @param  list<array{role:string,content:string}>  $history
     */
    private function transcript(array $history, string $message): string
    {
        $lines = [];
        foreach ($history as $turn) {
            $who = ($turn['role'] ?? '') === 'agent' ? self::AGENT : 'Client';
            $lines[] = $who.': '.($turn['content'] ?? '');
        }
        $lines[] = '';
        $lines[] = 'THE CLIENT HAS JUST SAID:';
        $lines[] = $message;
        return implode("\n", $lines);
    }

    /**
     * Read the Runtime envelope, refuse anything unverifiable, persist what
     * survives. `chatJson` returns ['success','parsed','text','raw'].
     */
    private function interpret(int $workspaceId, array $out, bool $applyFacts): array
    {
        if (($out['success'] ?? false) !== true || !is_array($out['parsed'] ?? null)) {
            Log::warning('onboarding interview: runtime envelope unusable', [
                'workspace_id' => $workspaceId,
                'success' => $out['success'] ?? null,
                'has_parsed' => is_array($out['parsed'] ?? null),
            ]);
            return [
                'ok' => false,
                'reply' => "I'm having trouble hearing you for a moment — could you say that again?",
                'recognised' => [],
                'sufficient' => false,
            ];
        }

        $parsed = $out['parsed'];
        $reply = is_string($parsed['reply'] ?? null) ? $parsed['reply'] : '';
        $recognised = is_array($parsed['recognised'] ?? null) ? $parsed['recognised'] : [];
        $context = is_array($parsed['context'] ?? null) ? $parsed['context'] : [];
        $sufficient = ($parsed['sufficient'] ?? false) === true;

        $accepted = [];
        if ($applyFacts) {
            $accepted = $this->persist($workspaceId, $recognised, $context);
        }

        return [
            'ok' => true,
            'reply' => $reply,
            'recognised' => $accepted,
            'sufficient' => $sufficient,
        ];
    }

    /**
     * Apply only verifiable recognised entries. Returns what was accepted.
     * Refuses: unknown fact/asset keys, standings that are not
     * customer_owned/absent, and ANY entry without a non-empty quote.
     */
    private function persist(int $workspaceId, array $recognised, array $context): array
    {
        $ws = Workspace::findOrFail($workspaceId);
        $data = is_array($ws->onboarding_data) ? $ws->onboarding_data : [];
        $iv = is_array($data['interview'] ?? null) ? $data['interview'] : ['facts' => [], 'presence' => [], 'context' => []];
        $accepted = [];

        foreach ($recognised as $entry) {
            $kind = $entry['kind'] ?? '';
            $key = $entry['key'] ?? '';
            $value = trim((string) ($entry['value'] ?? ''));
            $quote = trim((string) ($entry['quote'] ?? ''));

            if ($value === '' || $quote === '') {
                continue; // unquoted or empty — the Owner's "do not invent facts"
            }

            if ($kind === 'business' && isset(self::BUSINESS_FACTS[$key])) {
                $iv['facts'][$key] = ['value' => $value, 'quote' => $quote, 'at' => now()->toIso8601String()];
                $accepted[] = ['kind' => 'business', 'key' => $key, 'value' => $value];
            } elseif ($kind === 'infrastructure' && isset(self::PRESENCE_ASSETS[$key])) {
                if (!in_array($value, ['customer_owned', 'absent'], true)) {
                    continue; // not a standing a customer may state
                }
                $iv['presence'][$key] = ['value' => $value, 'quote' => $quote, 'at' => now()->toIso8601String()];
                $accepted[] = ['kind' => 'infrastructure', 'key' => $key, 'value' => $value];
            }
        }

        foreach ($context as $note) {
            $text = trim((string) ($note['note'] ?? ''));
            $quote = trim((string) ($note['quote'] ?? ''));
            if ($text !== '' && $quote !== '') {
                $iv['context'][] = ['note' => $text, 'quote' => $quote, 'at' => now()->toIso8601String()];
            }
        }

        // Mirror the mapped facts onto the workspace's own columns so the rest
        // of the platform (which reads business_name/industry/goal/location)
        // sees what Sarah learned, not just the interview blob.
        $coreUpdate = ['onboarding_data' => array_merge($data, ['interview' => $iv])];
        if (!empty($iv['facts']['industry']['value']))     $coreUpdate['industry'] = $iv['facts']['industry']['value'];
        if (!empty($iv['facts']['primary_goal']['value']))  $coreUpdate['goal'] = $iv['facts']['primary_goal']['value'];
        if (!empty($iv['facts']['location']['value']))      $coreUpdate['location'] = $iv['facts']['location']['value'];
        if (!empty($iv['facts']['what_it_sells']['value'])) $coreUpdate['services_json'] = [$iv['facts']['what_it_sells']['value']];
        $ws->update($coreUpdate);

        // Context notes become governed, persistent business intelligence Sarah
        // can draw on later (MISSION-018 §7).
        if (!empty($iv['context'])) {
            try {
                $this->memory->set($workspaceId, 'onboarding.business_context', $iv['context']);
            } catch (\Throwable $e) {
                Log::warning('onboarding interview: memory write failed', ['error' => $e->getMessage()]);
            }
        }

        return $accepted;
    }
}
