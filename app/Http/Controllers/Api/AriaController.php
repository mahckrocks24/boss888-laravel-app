<?php

namespace App\Http\Controllers\Api;

use App\Core\Aria\AriaService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ARIA888 — the platform FAQ endpoints (DEC-0054, 2026-09-15). Read-only: nothing here changes the workspace.
 * Also serves the legacy POST /api/assistant so the old address answers as the FAQ.
 */
class AriaController
{
    public function __construct(private AriaService $aria) {}

    public function ask(Request $r): JsonResponse
    {
        $wsId = (int) $r->attributes->get('workspace_id');
        if ($wsId <= 0) return response()->json(['success' => false, 'error' => 'No workspace in this session.'], 422);
        $question = (string) $r->input('message', $r->input('question', ''));
        $history = [];
        foreach ((array) $r->input('history', []) as $h) {
            if (is_array($h) && isset($h['role'], $h['content'])) $history[] = ['role' => (string) $h['role'], 'content' => (string) $h['content']];
        }
        $out = $this->aria->answer($wsId, $question, $history);
        $out['success'] = true;
        $out['response'] = $out['answer']; // legacy key the old client read
        return response()->json($out);
    }

    public function suggestions(Request $r): JsonResponse
    {
        $wsId = (int) $r->attributes->get('workspace_id');
        if ($wsId <= 0) return response()->json(['success' => false, 'error' => 'No workspace in this session.'], 422);
        return response()->json(['success' => true] + $this->aria->suggestions($wsId));
    }
}
