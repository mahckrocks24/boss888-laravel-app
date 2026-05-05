<?php

namespace App\Engines\Chatbot\Services;

use Illuminate\Support\Facades\DB;

/**
 * CHATBOT888 — Conversation finite state machine.
 *
 * Drives in-flow capture: instead of presenting a form, the bot asks for
 * one missing field at a time. State + captured fields persist on the
 * chatbot_sessions row across turns.
 *
 *   STATES
 *   ──────
 *   idle               — fresh session OR last turn was a generic FAQ
 *   collecting_lead    — visitor expressed lead intent; bot is gathering name/email
 *   collecting_booking — visitor wants to book; bot is gathering name/email/date/time
 *   collecting_callback— visitor wants a callback; bot is gathering name/phone
 *   ready_to_submit    — all required fields captured; next turn submits
 *   submitted          — Lead + (optional) Calendar event written
 *   escalated          — out-of-scope / jailbreak / kb-miss → escalation row
 *
 *   REQUIRED FIELDS BY FLOW
 *   ───────────────────────
 *   lead     : name + email                                  → finalise_lead
 *   booking  : name + email + (date OR phone)                → finalise_booking
 *   callback : name + phone                                  → finalise_callback
 */
class ChatbotSessionStateService
{
    public const STATE_IDLE                = 'idle';
    public const STATE_COLLECTING_LEAD     = 'collecting_lead';
    public const STATE_COLLECTING_BOOKING  = 'collecting_booking';
    public const STATE_COLLECTING_CALLBACK = 'collecting_callback';
    public const STATE_READY_TO_SUBMIT     = 'ready_to_submit';
    public const STATE_SUBMITTED           = 'submitted';
    public const STATE_ESCALATED           = 'escalated';

    public const ACTION_ANSWER          = 'answer';
    public const ACTION_ASK_FIELD       = 'ask_field';
    public const ACTION_FINALISE_LEAD   = 'finalise_lead';
    public const ACTION_FINALISE_BOOKING= 'finalise_booking';
    public const ACTION_FINALISE_CALLBACK = 'finalise_callback';
    public const ACTION_ESCALATE        = 'escalate';

    /**
     * Read the FSM cursor + captured fields for a session.
     */
    public function read(int $sessionId): array
    {
        $row = DB::table('chatbot_sessions')->where('id', $sessionId)
            ->select(['state', 'captured_fields_json'])->first();
        if (! $row) {
            return ['state' => self::STATE_IDLE, 'captured' => []];
        }
        $captured = $row->captured_fields_json
            ? (is_string($row->captured_fields_json) ? json_decode($row->captured_fields_json, true) : $row->captured_fields_json)
            : [];
        return [
            'state'    => $row->state ?: self::STATE_IDLE,
            'captured' => is_array($captured) ? $captured : [],
        ];
    }

    /**
     * Write the FSM cursor + captured fields back. Called once per turn at
     * the end of orchestration.
     */
    public function write(int $sessionId, string $state, array $captured): void
    {
        DB::table('chatbot_sessions')->where('id', $sessionId)->update([
            'state'                => $state,
            'captured_fields_json' => json_encode($this->cleanCaptured($captured)),
        ]);
    }

    /**
     * Decide the next state + action given current state, classifier intent,
     * and merged captured fields. Pure function — no side effects.
     *
     * Returns ['state' => ..., 'action' => ..., 'next_field' => ?, 'flow' => ?]
     */
    public function transition(string $currentState, string $intent, array $captured): array
    {
        // Detect which flow the intent maps onto, if any.
        $flowFromIntent = match ($intent) {
            'booking', 'reservation' => 'booking',
            'callback'               => 'callback',
            'lead_capture'           => 'lead',
            default                  => null,
        };

        // Hard escalation — out-of-scope / jailbreak.
        if ($intent === 'out_of_scope' || $intent === 'escalation') {
            return [
                'state'  => self::STATE_ESCALATED,
                'action' => self::ACTION_ESCALATE,
                'flow'   => null,
                'next_field' => null,
            ];
        }

        // If we're already mid-flow and the intent is faq/unknown, KEEP the flow
        // (visitor asked a side question — answer it but stay in the flow).
        // Otherwise, switch flow if a new flow intent fires.
        $activeFlow = $this->stateToFlow($currentState);
        $flow = $flowFromIntent ?: $activeFlow;

        if (! $flow) {
            // Pure FAQ / business_inquiry / unknown without flow → just answer.
            return [
                'state'  => self::STATE_IDLE,
                'action' => self::ACTION_ANSWER,
                'flow'   => null,
                'next_field' => null,
            ];
        }

        // Determine missing fields for the active flow.
        $required = $this->requiredFieldsForFlow($flow);
        $missing = [];
        foreach ($required as $field) {
            if (empty($captured[$field])) $missing[] = $field;
        }

        // All required captured → finalise on this turn.
        if (empty($missing)) {
            return [
                'state'  => self::STATE_SUBMITTED,
                'action' => match ($flow) {
                    'lead'     => self::ACTION_FINALISE_LEAD,
                    'booking'  => self::ACTION_FINALISE_BOOKING,
                    'callback' => self::ACTION_FINALISE_CALLBACK,
                },
                'flow'   => $flow,
                'next_field' => null,
            ];
        }

        // Still collecting — return the next field to ask for.
        return [
            'state'  => match ($flow) {
                'lead'     => self::STATE_COLLECTING_LEAD,
                'booking'  => self::STATE_COLLECTING_BOOKING,
                'callback' => self::STATE_COLLECTING_CALLBACK,
            },
            'action' => self::ACTION_ASK_FIELD,
            'flow'   => $flow,
            'next_field' => $missing[0],
        ];
    }

    /**
     * Generate a natural-language prompt for the next field. Used when
     * action=ask_field. Bot tone is influenced by context.tone but kept
     * concise here.
     */
    public function promptForField(string $field, string $flow, array $context): string
    {
        // CONVERSION INTELLIGENCE 2026-05-03 — conversion-y phrasing.
        // FSM logic UNCHANGED. Only the strings shown to visitors are softer
        // and oriented to forward motion.
        return match ($field) {
            'name'  => match ($flow) {
                'booking'  => "Happy to get that sorted for you. Could I get your name?",
                'callback' => "Happy to arrange a callback. Could I get your name first?",
                default    => "Happy to help with that — could I get your name?",
            },
            'email' => "What's the best number or email so the team can follow up?",
            'phone' => $flow === 'callback'
                ? "What's the best number to reach you on? Happy to call you back quickly."
                : "And a number to reach you on? (optional — just say 'skip' to move on)",
            'date'  => "Sure — what day works best for you? (e.g. tomorrow, next Tuesday, or 2026-05-10)",
            'time'  => "And what time suits you? (e.g. 2pm, 14:30)",
            default => "Could you share a bit more so we can get this sorted?",
        };
    }

    public function requiredFieldsForFlow(string $flow): array
    {
        return match ($flow) {
            'lead'     => ['name', 'email'],
            'booking'  => ['name', 'email', 'date'],   // time is nice-to-have, not required
            'callback' => ['name', 'phone'],
            default    => [],
        };
    }

    public function stateToFlow(string $state): ?string
    {
        return match ($state) {
            self::STATE_COLLECTING_LEAD     => 'lead',
            self::STATE_COLLECTING_BOOKING  => 'booking',
            self::STATE_COLLECTING_CALLBACK => 'callback',
            default                         => null,
        };
    }

    /**
     * Remove disallowed keys + null values from captured fields before persist.
     */
    private function cleanCaptured(array $captured): array
    {
        $allowed = ['name', 'email', 'phone', 'date', 'time', 'service', 'notes'];
        $out = [];
        foreach ($allowed as $k) {
            if (isset($captured[$k]) && $captured[$k] !== null && $captured[$k] !== '') {
                $out[$k] = is_string($captured[$k]) ? mb_substr($captured[$k], 0, 500) : $captured[$k];
            }
        }
        return $out;
    }
}
