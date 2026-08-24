<?php

/*
|--------------------------------------------------------------------------
| Chat / live-refresh timing — MISSION-018 WS-1 (2026-08-24), RISK-0050
|--------------------------------------------------------------------------
| Single source for the two values that MUST stay in a fixed relationship:
| the client poll interval and the server-side event lookback window.
|
| The 2026-07-26 Sarah live-refresh incident happened because the lookback
| was SMALLER than the poll interval, leaving a per-tick blind spot that
| silently dropped a generated, stored, charged reply. The invariant
| "lookback > poll interval" was held only by a code comment.
|
| Now the lookback is DERIVED from the poll interval (interval + margin), so
| the two can never diverge: change poll_interval_ms and the lookback moves
| with it. AgentDispatchService::getEvents reads the derived value; the chat
| dispatch response serves poll_interval_ms to the client from here too.
*/

return [
    'poll_interval_ms' => (int) env('CHAT_POLL_INTERVAL_MS', 2500),

    // Added to ceil(poll_interval_ms / 1000) to form the event lookback. Covers
    // a slow tick, a backgrounded tab resuming, and created_at second-rounding.
    // Duplicates inside the overlap are deduped by event id on both clients, so
    // a wider window is free.
    'event_lookback_margin_seconds' => (int) env('CHAT_EVENT_LOOKBACK_MARGIN', 3),
];
