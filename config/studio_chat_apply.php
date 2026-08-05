<?php

// ═══════════════════════════════════════════════════════════════════════════
// STUDIO888 · Integration Milestone 1 — server-verified single text edit.
//
// DEFAULT OFF. When true, a /studio/chat turn whose LLM result is EXACTLY ONE
// supported `update_field` action is applied + verified + persisted SERVER-SIDE
// through the committed HtmlProjectionAdapter, and the response carries NO
// browser action (the authoritative invariant: actions XOR server-apply, never
// both). Every unsupported / unsafe / unverified / dirty-editor / concurrent
// case falls back to the UNCHANGED legacy browser path.
//
// Toggle: STUDIO_CHAT_SERVER_APPLY_TEXT=true|false  (config:clear, never
// config:cache — the platform runtime reads env() directly).
// ═══════════════════════════════════════════════════════════════════════════

return [
    'server_apply_text' => env('STUDIO_CHAT_SERVER_APPLY_TEXT', false),

    // Controlled-pilot scope. 0 => applies to ALL workspaces when the flag
    // is ON (eventual rollout). >0 => the server path is limited to that ONE
    // workspace, so a pilot can never affect other customers.
    'pilot_workspace_id' => (int) env('STUDIO_CHAT_SERVER_APPLY_WS', 0),
];
