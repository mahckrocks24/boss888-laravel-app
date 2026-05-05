<?php

namespace App\Engines\Chatbot\Services;

/**
 * CHATBOT888 — Output guardrails for LLM responses.
 *
 * Runs AFTER the LLM call, BEFORE sending to the visitor and BEFORE
 * persisting to chatbot_messages. Three roles:
 *
 *   1. Schema enforcement — coerce / validate the parsed JSON shape so
 *      the orchestrator can rely on the keys + enums.
 *   2. Hallucination guard — if KB had ZERO hits AND intent is faq /
 *      business_inquiry, downgrade `confident` and prepend an honest
 *      hedge so the bot never invents a fact about the business.
 *   3. Content sanitisation — strip provider-name leaks, length-cap the
 *      answer, whitelist capture_fields enum.
 *
 * Always returns a sane shape — caller never has to defensively check.
 */
class ChatbotGuardrails
{
    private const ALLOWED_INTENTS = [
        'faq', 'business_inquiry', 'lead_capture', 'booking',
        'reservation', 'callback', 'escalation', 'out_of_scope',
    ];
    private const ALLOWED_CAPTURE_FIELDS = ['name', 'email', 'phone', 'preferred_time', 'notes'];
    private const MAX_ANSWER_LEN = 2000;
    private const PROVIDER_LEAK_PATTERNS = [
        '/\bdeepseek\b/i', '/\bopenai\b/i', '/\bchat ?gpt\b/i', '/\bgpt[-\s]?\d/i',
        '/\bclaude\b/i', '/\banthropic\b/i', '/\b(?:large language model|llm)\b/i',
        '/\bas an ai\b/i', '/\bi.?m an ai\b/i', '/\bi am an ai\b/i',
    ];

    /**
     * Apply all guardrails. Always returns a fully-shaped response.
     *
     * @param array|null $parsed   The LLM's parsed JSON output (or null on parse failure).
     * @param array      $context  Result of ChatbotContextBuilder::build (carries kb_hits_count).
     */
    public function apply(?array $parsed, array $context): array
    {
        // Parse failure → safe fallback
        if (! is_array($parsed)) {
            return $this->safeFallback('parse_failure');
        }

        // 1. Intent enum
        $intent = (string) ($parsed['intent'] ?? 'escalation');
        if (! in_array($intent, self::ALLOWED_INTENTS, true)) {
            $intent = 'escalation';
        }

        // 2. Answer text — coerce + sanitise
        $answer = (string) ($parsed['answer'] ?? '');
        $answer = $this->stripProviderLeaks($answer);
        if (mb_strlen($answer) > self::MAX_ANSWER_LEN) {
            $answer = mb_substr($answer, 0, self::MAX_ANSWER_LEN - 1) . '…';
        }
        if (trim($answer) === '') {
            $answer = "Sorry, I couldn't process that — could you rephrase?";
        }

        // 3. Hallucination guard — KB miss on a factual intent.
        // CONVERSION INTELLIGENCE 2026-05-03: when the LLM was confident
        // but had no KB source for a factual question, the safest move
        // is to convert: replace the answer with an honest "let me have
        // someone reach out" + flip needs_contact=true so the FSM /
        // widget can capture the lead. Better than a hedged answer that
        // still risks hallucination.
        $kbHits = (int) ($context['kb_hits_count'] ?? 0);
        $confident = (bool) ($parsed['confident'] ?? false);
        $needsContact = (bool) ($parsed['needs_contact'] ?? false);
        $smartFallbackApplied = false;
        if ($kbHits === 0 && in_array($intent, ['faq', 'business_inquiry'], true)) {
            if ($confident) {
                $confident = false;
                $answer = "I want to make sure you get the right info — can I have someone from the team reach out? What's the best email or number to use?";
                $needsContact = true;
                $smartFallbackApplied = true;
            } elseif (! preg_match('/\b(don.?t (have|know)|not sure|let me|i.?ll connect|escalate|connect you|may not|reach out|follow up|get back to)\b/i', $answer)) {
                // LLM already had low confidence but answer doesn't hedge or
                // offer escalation — append the smart fallback offer.
                $answer = rtrim($answer, ' .!?') . '. Want me to have someone from the team follow up with the exact details?';
                $needsContact = true;
            }
        }

        // 4. capture_fields whitelist
        $captureFields = is_array($parsed['capture_fields'] ?? null)
            ? array_values(array_intersect($parsed['capture_fields'], self::ALLOWED_CAPTURE_FIELDS))
            : [];
        // Smart-fallback boost: when we forced needs_contact, ensure the
        // capture_fields ship the standard lead-capture trio so the widget
        // (and FSM) know what to gather.
        if ($smartFallbackApplied && empty($captureFields)) {
            $captureFields = ['name', 'email', 'phone'];
        }

        // 5. needs_* booleans
        $needsBooking = (bool) ($parsed['needs_booking'] ?? false);

        // 6. booking_proposal — only accept the 3 expected keys, drop garbage
        $proposal = null;
        if (is_array($parsed['booking_proposal'] ?? null)) {
            $p = $parsed['booking_proposal'];
            $proposal = array_filter([
                'date'    => isset($p['date']) && is_string($p['date']) ? mb_substr($p['date'], 0, 32) : null,
                'time'    => isset($p['time']) && is_string($p['time']) ? mb_substr($p['time'], 0, 16) : null,
                'service' => isset($p['service']) && is_string($p['service']) ? mb_substr($p['service'], 0, 120) : null,
            ], fn($v) => $v !== null);
            if (empty($proposal)) $proposal = null;
        }

        // 7. escalation_reason
        $reason = isset($parsed['escalation_reason']) && is_string($parsed['escalation_reason'])
            ? mb_substr($parsed['escalation_reason'], 0, 200) : null;

        return [
            'intent'            => $intent,
            'answer'            => $answer,
            'confident'         => $confident,
            'needs_contact'     => $needsContact,
            'needs_booking'     => $needsBooking,
            'capture_fields'    => $captureFields,
            'booking_proposal'  => $proposal,
            'escalation_reason' => $reason,
        ];
    }

    public function safeFallback(string $reason): array
    {
        return [
            'intent'            => 'escalation',
            'answer'            => "Sorry, I'm having trouble understanding. Would you like to leave your name and email so the team can follow up?",
            'confident'         => false,
            'needs_contact'     => true,
            'needs_booking'     => false,
            'capture_fields'    => ['name', 'email', 'phone'],
            'booking_proposal'  => null,
            'escalation_reason' => $reason,
        ];
    }

    private function stripProviderLeaks(string $text): string
    {
        // Replace any provider/LLM-leak phrase with a neutral mention.
        return preg_replace(self::PROVIDER_LEAK_PATTERNS, 'the assistant', $text) ?? $text;
    }
}
