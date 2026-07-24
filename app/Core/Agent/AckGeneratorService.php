<?php

namespace App\Core\Agent;

/**
 * v1.4.4 (2026-05-30) — Heuristic instant acknowledgment generator.
 *
 * Sarah's chat endpoint runs a 15-23s LLM pipeline. With the old single-shot
 * response model, the SPA showed a typing indicator for the full 15s — the
 * user perceived this as "she's slow / stuck".
 *
 * This service generates a short acknowledgment ("Got it — pulling that up.")
 * from the user's intent in ~0ms (regex, no LLM). The route handler returns
 * the ack immediately via fastcgi_finish_request() so the SPA can render it
 * in <1s. The heavy LLM call continues in the same FPM worker; the final
 * reply lands in agent_messages and the SPA polls for it.
 *
 * Modelled on ChatGPT / Claude two-phase response UX: instant acknowledgment
 * → tool execution → final answer.
 *
 * Pure function — no DB, no LLM, no I/O. Deterministic but with randomized
 * template selection so the same intent doesn't always produce the same ack.
 */
class AckGeneratorService
{
    /**
     * Generate a short acknowledgment for the given user message.
     *
     * @param string $userContent  the raw user message text
     * @param string $agentSlug    target agent (sarah / james / etc) — informs tone
     * @param string $agentName    display name for fallback
     * @return string  the acknowledgment text (1-2 sentences, no trailing newline)
     */
    public function generate(string $userContent, string $agentSlug = 'sarah', string $agentName = 'Sarah'): string
    {
        $text = strtolower(trim($userContent));
        if ($text === '') return $this->pick(['On it.', 'One moment.', 'Let me check.']);

        // Confirmation replies — Sarah is about to EXECUTE, not investigate.
        $confirmRe = '/^(yes|y|yeah|yep|ok|okay|sure|proceed|go|go ahead|do it|confirm|approved|approve|let\'s go|let\'s do it)[\s\.\!]*$/i';
        if (preg_match($confirmRe, $text)) {
            return $this->pick([
                'Got it — kicking off now.',
                'On it. Starting the work.',
                'Confirmed. Launching the tasks.',
                'Great, getting started.',
                'Perfect — proceeding now.',
            ]);
        }

        // Reject / cancel
        if (preg_match('/^(no|n|nope|cancel|stop|nevermind|never mind|wait)[\s\.\!]*$/i', $text)) {
            return $this->pick([
                'Got it — holding off.',
                'OK, paused. What would you like instead?',
                'No problem — let me know what to do next.',
            ]);
        }

        // Greeting only — a warm greeting, NOT a promise of work. (Boss feedback
        // 2026-07-23: "Hi Sarah -> Pulling that up ... does not make sense.")
        if (preg_match('/^(hi|hello|hey|good morning|good afternoon|good evening|yo|sup|howdy)[\s\!\,\.\?]*( sarah| james| alex| priya| marcus| elena)?[\s\!\,\.\?]*$/i', $text)) {
            $hr = (int) date('G');
            $tod = $hr < 12 ? 'Morning' : ($hr < 17 ? 'Afternoon' : 'Evening');
            return $this->pick([
                "Hey, Chef!",
                "Hi there!",
                "{$tod}, Chef!",
                "Hey — good to see you.",
            ]);
        }

        // Social smalltalk, not a work request — a task-flavored ack
        // ("On it — pulling that up") reads as nonsensical here, so answer
        // in the same conversational register. Split so the reply fits:
        // a question about Sarah vs. praise/thanks get different responses.
        if (preg_match('/\b(how are you|how\'?s it going|how have you been|how you doing|how\'?re you|you doing (ok|okay|well|good|alright))\b/i', $text)) {
            return $this->pick([
                "Doing great — thanks for asking, Chef!",
                "All good here, Chef. Ready when you are.",
                "Doing well, thanks! What can I help with?",
            ]);
        }
        if (preg_match('/\b(thank you|thanks|thx|much appreciated|appreciate it|good job|great job|nice work|well done|love it|awesome|amazing work)\b/i', $text)) {
            return $this->pick([
                "Appreciate that, Chef!",
                "Thanks, Chef — glad it helped.",
                "Anytime, Chef.",
                "My pleasure.",
            ]);
        }

        // Intent: investigation / read state ("what / how many / which / show / list / scan / check / look at")
        if (preg_match('/\b(what|how many|how much|which|where|when|why|who)\b/i', $text)
            || preg_match('/\b(show|list|tell me|find|look up|look at|see|view|browse|scan|check|review|read|summari[sz]e|audit|inspect|investigate)\b/i', $text)) {
            return $this->pick([
                "Let me pull that up for you.",
                "On it — checking now.",
                "One moment, looking into it.",
                "Pulling the data — be right with you.",
                "Let me check the state and get back to you.",
                "Looking now — give me a sec.",
            ]);
        }

        // Intent: status / progress / update
        if (preg_match('/\b(status|progress|update|where (are|is) (we|that|those|those tasks)|how (is|are) (we|things|that|those)|are (they|those|the tasks|the images|the drafts) (done|ready|finished|generated|published))\b/i', $text)) {
            return $this->pick([
                "Checking the queue now.",
                "Let me see where things stand.",
                "Pulling the current status — one sec.",
                "Looking at the workspace state.",
            ]);
        }

        // Intent: failure / error / problem
        if (preg_match('/\b(why (did|does|is|are)|failed|crashed|broken|stuck|not working|won\'t|wont|didn\'t|didnt|isn\'t|isnt|error|bug)\b/i', $text)) {
            return $this->pick([
                "Let me check what happened.",
                "On it — diagnosing now.",
                "Looking at the failure now.",
                "Pulling the error details.",
            ]);
        }

        // Intent: creation / generation / build (Sarah will delegate)
        if (preg_match('/\b(write|draft|generate|create|build|make|produce|publish|post|design|render|launch|set up|setup|add|new article|new post|new page|new image|featured image)\b/i', $text)) {
            return $this->pick([
                "Got it — let me plan that out.",
                "On it. Setting up the right specialists.",
                "Let me think through the right approach.",
                "Working out the plan — one moment.",
                "Got it. Scoping that out now.",
            ]);
        }

        // Intent: schedule / plan / strategize
        if (preg_match('/\b(plan|strategy|strategic|schedule|roadmap|propose|recommend|suggest|advise|brainstorm|idea)\b/i', $text)) {
            return $this->pick([
                "Let me think this through.",
                "Working on the strategy — one sec.",
                "Pulling up your current state to anchor the plan.",
                "On it. Mapping it out now.",
            ]);
        }

        // Intent: comparison / analysis / why
        if (preg_match('/\b(compare|comparison|analy[zs]e|analysis|breakdown|deep dive|explain|reason)\b/i', $text)) {
            return $this->pick([
                "Let me dig in and get back to you.",
                "Working through the analysis.",
                "On it — breaking this down.",
            ]);
        }

        // Intent: edit / change / modify (existing thing)
        if (preg_match('/\b(edit|change|modify|update|fix|adjust|tweak|rewrite|rework|improve|refactor|rename|move|delete|remove)\b/i', $text)) {
            return $this->pick([
                "Got it — let me line up the edit.",
                "On it. Pulling the current version.",
                "One moment — looking at what needs changing.",
            ]);
        }

        // Approval-style: "go", "do that", "let's do it"
        if (preg_match('/\b(do (that|it|those)|let\'s do|let me know|tell me|sounds good|approved)\b/i', $text)) {
            return $this->pick([
                "Got it — starting now.",
                "On it.",
                "Working on it.",
            ]);
        }

        // Default fallback
        return $this->pick([
            "Let me look into that.",
            "On it — one moment.",
            "Checking now.",
            "Let me pull that up.",
            "Got it. Working on it now.",
        ]);
    }

    private function pick(array $opts): string
    {
        return $opts[array_rand($opts)];
    }
}
