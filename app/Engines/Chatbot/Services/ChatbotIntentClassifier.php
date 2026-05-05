<?php

namespace App\Engines\Chatbot\Services;

/**
 * CHATBOT888 — Rule-first intent classifier with capture extraction.
 *
 * Runs before any LLM call. Cheap, deterministic, free. Returns a
 * confidence score; if high enough the orchestrator can skip the LLM
 * intent classification step (LLM still produces the answer text when
 * one is needed). Always extracts capture fields (email, phone, name,
 * date, time) regardless of intent confidence — these are stored on
 * the session and drive the state machine.
 *
 * Returned shape:
 *   [
 *     'intent'           => 'faq'|'lead_capture'|'booking'|'callback'
 *                          |'escalation'|'out_of_scope'|'unknown',
 *     'confidence'       => float 0..1,
 *     'source'           => 'rule'|'fallback'  (LLM source set by caller)
 *     'captured_fields'  => ['name'=>?, 'email'=>?, 'phone'=>?, 'date'=>?, 'time'=>?, 'service'=>?],
 *     'reason'           => string for telemetry
 *   ]
 */
class ChatbotIntentClassifier
{
    public const HIGH_CONFIDENCE = 0.80;
    public const MED_CONFIDENCE  = 0.55;

    /**
     * Pattern list — first match wins for intent assignment. Order from
     * most-specific to most-general so booking beats lead_capture beats faq.
     */
    private const INTENT_PATTERNS = [
        // BOOKING — explicit booking/reservation/appointment intent
        ['intent' => 'booking',  'confidence' => 0.90, 'pattern' => '/\b(book|reserve|schedule|appointment|meeting)\s+(a|an|the|my)?\s*(call|consultation|demo|meeting|table|session|slot|time)?/i'],
        ['intent' => 'booking',  'confidence' => 0.85, 'pattern' => '/\b(can i|may i|i.?d like to|i want to|i would like to)\s+(book|schedule|reserve)/i'],
        ['intent' => 'booking',  'confidence' => 0.85, 'pattern' => '/\bset up (a|an) (call|meeting|appointment|demo)\b/i'],

        // CALLBACK — request a phone call back
        ['intent' => 'callback', 'confidence' => 0.90, 'pattern' => '/\b(call (me|us) back|callback|please call|give me a call|ring me)\b/i'],
        ['intent' => 'callback', 'confidence' => 0.80, 'pattern' => '/\b(can someone|can you|could you)\s+(call|phone|ring)\s+(me|us)\b/i'],

        // LEAD CAPTURE — explicit contact / message intent
        ['intent' => 'lead_capture', 'confidence' => 0.85, 'pattern' => '/\b(contact|reach|email|message|get in touch with)\s+(me|us)\b/i'],
        ['intent' => 'lead_capture', 'confidence' => 0.75, 'pattern' => '/\b(send (me )?(more )?info|info(rmation)? on|details about|brochure|quote|quotation)\b/i'],
        ['intent' => 'lead_capture', 'confidence' => 0.70, 'pattern' => '/\b(interested in|tell me about|how much|pricing|cost of)\b/i'],

        // OUT-OF-SCOPE — visitor went off-topic; cheap to detect
        ['intent' => 'out_of_scope', 'confidence' => 0.70, 'pattern' => '/\b(weather|sports|stock(s)?|crypto|bitcoin|joke(s)?|recipe|movie|song|lyrics|football|politics|religion)\b/i'],

        // ESCALATION (jailbreak / prompt-injection patterns)
        ['intent' => 'escalation', 'confidence' => 0.95, 'pattern' => '/\b(ignore (all )?(prior|previous|above) (instructions|rules|prompts)|disregard (your|the) (system|instructions))\b/i'],
        ['intent' => 'escalation', 'confidence' => 0.85, 'pattern' => '/\b(act as|pretend (to be|you.?re)|roleplay (as)?)\b/i'],

        // FAQ — short factual questions
        ['intent' => 'faq', 'confidence' => 0.60, 'pattern' => '/^\s*(what|where|when|how|do|does|are|is|can)\s+/i'],
    ];

    /**
     * Pure regex match on user text → intent + confidence + captures.
     */
    public function classify(string $userMessage, array $sessionCapturedFields = []): array
    {
        $userMessage = trim($userMessage);
        $captured = $this->extractCaptures($userMessage);

        // Merge with already-captured fields so the orchestrator sees the full set.
        if (! empty($sessionCapturedFields) && is_array($sessionCapturedFields)) {
            foreach ($sessionCapturedFields as $k => $v) {
                if (! isset($captured[$k]) || $captured[$k] === null) {
                    $captured[$k] = $v;
                }
            }
        }

        // Match patterns
        foreach (self::INTENT_PATTERNS as $rule) {
            if (preg_match($rule['pattern'], $userMessage)) {
                return [
                    'intent'          => $rule['intent'],
                    'confidence'      => $rule['confidence'],
                    'source'          => 'rule',
                    'captured_fields' => $captured,
                    'reason'          => 'matched_pattern',
                ];
            }
        }

        // No rule matched — return 'unknown' so caller falls back to LLM.
        return [
            'intent'          => 'unknown',
            'confidence'      => 0.0,
            'source'          => 'rule',
            'captured_fields' => $captured,
            'reason'          => 'no_pattern_match',
        ];
    }

    /**
     * Extract structured fields the visitor volunteered in free text.
     * Always non-destructive — returns null per slot when not found.
     */
    public function extractCaptures(string $text): array
    {
        $captured = [
            'name'    => null,
            'email'   => null,
            'phone'   => null,
            'date'    => null,
            'time'    => null,
            'service' => null,
        ];

        // Email — strict RFC-ish pattern
        if (preg_match('/[a-z0-9._%+\-]+@[a-z0-9.\-]+\.[a-z]{2,}/i', $text, $m)) {
            $captured['email'] = strtolower($m[0]);
        }

        // Phone — international or national, 7+ digits with optional +/spaces/dashes
        if (preg_match('/(\+?\d[\d\s\-().]{6,}\d)/', $text, $m)) {
            $digits = preg_replace('/\D/', '', $m[1]);
            if ($digits !== null && strlen($digits) >= 7 && strlen($digits) <= 15) {
                // Re-prepend + if original had it
                $captured['phone'] = (str_starts_with($m[1], '+') ? '+' : '') . $digits;
            }
        }

        // Name volunteering — "my name is X", "I'm X", "this is X"
        if (preg_match('/\b(?:my name is|i.?m|i am|this is|name.?s)\s+([A-Z][a-z]+(?:\s+[A-Z][a-z]+)?)/i', $text, $m)) {
            $candidate = trim($m[1]);
            // Reject if it's clearly a verb phrase (e.g. "I'm interested")
            if (! preg_match('/^(?:interested|looking|trying|just|here|going|coming|new|sure|good|fine|busy|tired)$/i', $candidate)) {
                $captured['name'] = $candidate;
            }
        }

        // Date — YYYY-MM-DD or natural language relative
        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $text, $m)) {
            $captured['date'] = $m[1];
        } elseif (preg_match('/\b(today|tomorrow|next (?:monday|tuesday|wednesday|thursday|friday|saturday|sunday|week))\b/i', $text, $m)) {
            $captured['date'] = $this->normaliseRelativeDate(strtolower($m[1]));
        } elseif (preg_match('/\b(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/i', $text, $m)) {
            $captured['date'] = $this->nextWeekday(strtolower($m[1]));
        }

        // Time — 14:30, 2:30 pm, 2pm
        if (preg_match('/\b(\d{1,2}):(\d{2})\s*(am|pm)?\b/i', $text, $m)) {
            $captured['time'] = $this->normaliseTime((int)$m[1], (int)$m[2], $m[3] ?? null);
        } elseif (preg_match('/\b(\d{1,2})\s*(am|pm)\b/i', $text, $m)) {
            $captured['time'] = $this->normaliseTime((int)$m[1], 0, $m[2]);
        }

        return $captured;
    }

    private function normaliseRelativeDate(string $phrase): ?string
    {
        try {
            return match (true) {
                $phrase === 'today'    => now()->toDateString(),
                $phrase === 'tomorrow' => now()->addDay()->toDateString(),
                str_starts_with($phrase, 'next ') => $this->nextWeekday(substr($phrase, 5)),
                default => null,
            };
        } catch (\Throwable) { return null; }
    }

    private function nextWeekday(string $day): ?string
    {
        $map = [
            'monday' => 1, 'tuesday' => 2, 'wednesday' => 3,
            'thursday' => 4, 'friday' => 5, 'saturday' => 6, 'sunday' => 0,
        ];
        if (! isset($map[$day])) return null;
        $target = $map[$day];
        try {
            $now = now();
            $current = (int) $now->dayOfWeek;
            $delta = ($target - $current + 7) % 7;
            if ($delta === 0) $delta = 7; // "monday" said on monday → next monday
            return $now->addDays($delta)->toDateString();
        } catch (\Throwable) { return null; }
    }

    private function normaliseTime(int $h, int $m, ?string $ampm): ?string
    {
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) return null;
        $ampm = $ampm ? strtolower($ampm) : null;
        if ($ampm === 'pm' && $h < 12) $h += 12;
        if ($ampm === 'am' && $h === 12) $h = 0;
        if ($h > 23) return null;
        return sprintf('%02d:%02d', $h, $m);
    }
}
