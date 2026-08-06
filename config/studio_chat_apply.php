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

    // Feature Sprint 1 — AI Style Editing (set_style). Same default-OFF + workspace scope.
    'server_apply_style' => env('STUDIO_CHAT_SERVER_APPLY_STYLE', false),

    // Feature Sprint 2 - AI Image Editing (replace + generate-and-replace). Default OFF, same ws scope.
    'server_apply_image' => env('STUDIO_CHAT_SERVER_APPLY_IMAGE', false),

    // Feature Sprint 3 - verified brand colour application. Default OFF, same ws scope.
    'server_apply_brand' => env('STUDIO_CHAT_SERVER_APPLY_BRAND', false),
    // Extra trusted image hosts (comma-separated). Same-origin (app host) + relative URLs are always allowed.
    'image_trusted_hosts' => array_values(array_filter(array_map('trim', explode(',', (string) env('STUDIO_CHAT_IMAGE_TRUSTED_HOSTS', ''))))),

    // Controlled-pilot scope. 0 => applies to ALL workspaces when the flag
    // is ON (eventual rollout). >0 => the server path is limited to that ONE
    // workspace, so a pilot can never affect other customers.
    'pilot_workspace_id' => (int) env('STUDIO_CHAT_SERVER_APPLY_WS', 0),
];
