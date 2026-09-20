<?php

namespace App\Http\Controllers\Api;

use App\Engines\Builder\Services\ArthurEditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Patch 8.5 Tier 1 — Arthur structured-JSON edit endpoint.
 *
 * POST /api/builder/pages/{pageId}/arthur-edit
 * Body: {message: "...", section_index?: int}
 *
 * Workspace-scoped via pages → websites.workspace_id join. Refuses with
 * HTTP 422 on pages without sections_json (i.e. legacy static-HTML-only
 * pages like Chef Red — those must use the legacy
 * /api/builder/websites/{id}/arthur-edit closure until T3.4 migrates them).
 */
class ArthurEditController
{
    public function __construct(
        protected ArthurEditService $arthur,
    ) {}

    public function edit(Request $request, int $pageId): JsonResponse
    {
        $wsId = (int) $request->attributes->get('workspace_id');
        if ($wsId <= 0) {
            return response()->json(['error' => 'Workspace context missing'], 400);
        }

        // Verify the page belongs to the caller's workspace via the websites join.
        $page = DB::table('pages')
            ->join('websites', 'websites.id', '=', 'pages.website_id')
            ->where('pages.id', $pageId)
            ->where('websites.workspace_id', $wsId)
            ->select('pages.id', 'pages.sections_json', 'pages.slug', 'websites.subdomain')
            ->first();
        if (! $page) {
            return response()->json(['error' => 'Page not found'], 404);
        }

        // U2 (2026-09-20): the section picker sends an explicit plan {section, anchor, where} — a click, not a chat
        // message: no chat meter, no intent model, no re-parsing of words. Validated here against the catalogue.
        $explicitPlan = null;
        if (is_array($request->input('plan'))) {
            $pl = $request->input('plan');
            $secType = (string) preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($pl['section'] ?? '')));
            if ($secType === '' || ! isset(\App\Engines\Builder\Support\BuilderCapabilities::SECTIONS[$secType])) {
                return response()->json(['success' => false, 'error' => 'unknown_section', 'message' => 'That section is not one Arthur can add.'], 422);
            }
            $explicitPlan = ['section' => $secType,
                'anchor' => (string) preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) ($pl['anchor'] ?? 'contact'))) ?: 'contact',
                'where'  => (($pl['where'] ?? 'before') === 'after') ? 'after' : 'before'];
        }
        // CHAT METER (2026-09-15): every chat message counts — 1 credit per 10, on every chat surface of the platform.
        $__meter = $explicitPlan ? ['sufficient' => true, 'debited' => false] : app(\App\Core\Billing\CreditService::class)->meterChat($wsId, 'arthur_message');
        if (empty($__meter['sufficient'])) {
            return response()->json(['error' => 'insufficient_credits', 'required_credits' => 1, 'message' => 'Not enough credits to chat — 1 credit covers 10 messages. Add credits under Billing to continue.'], 402);
        }
        // RISK-0100 — opt-in optimistic lock (parity with the direct save path). If
        // the caller sent the base_version it loaded (sha1 of sections_json), refuse a
        // stale AI edit with 409 BEFORE reserving credits or calling the runtime, so a
        // concurrent change is never silently overwritten. No base_version => unchanged.
        $baseVersion = (string) $request->input('base_version', '');
        if ($baseVersion !== '' && ! hash_equals(sha1((string) $page->sections_json), $baseVersion)) {
            return response()->json([
                'error'    => 'This page was changed by someone else since you opened it. Reload to get the latest version, then re-apply your change.',
                'conflict' => true,
            ], 409);
        }

        // Refuse legacy static-HTML pages — Patch 8.6 (Chef Red migration) handles those.
        if (empty($page->sections_json) || $page->sections_json === '[]' || strlen($page->sections_json) < 5) {
            return response()->json([
                'error'  => 'This page uses the legacy static-HTML path. Migrate to sections_json (T3.4) before using Arthur JSON edits.',
                'legacy' => true,
            ], 422);
        }

        $validated = $request->validate([
            'message'       => 'required|string|max:2000',
            'section_index' => 'nullable|integer|min:0',
            // SELECTION888 (2026-09-15): the element / section the customer clicked in the editor
            'selected'       => 'nullable|array',
            'selected.block' => 'nullable|string|max:80',
            'selected.field' => 'nullable|string|max:120',
            'plan'           => 'nullable|array',
        ]);

        // A2 (2026-06-24) — meter Arthur prompt edits at 1 credit per block edit
        // (Boss 2026-06-23). Deterministic "instant" field edits don't hit this
        // endpoint (they save directly), so they stay free. Reserve BEFORE the
        // runtime call (cost-gating doc), commit only on a real applied edit,
        // release on no-op/failure. (The agent path is metered separately by
        // EngineExecutionService via the ai_builder_action capability = 1cr.)
        $credits = app(\App\Core\Billing\CreditService::class);
        $reservationRef = null;
        try {
            $rsv = $credits->reserveCredits($wsId, 1, 'arthur_edit', $pageId);
            $reservationRef = $rsv->reservation_reference;
        } catch (\Throwable $e) {
            return response()->json([
                'error'            => 'insufficient_credits',
                'required_credits' => 1,
                'message'          => 'Not enough credits to edit (1 credit per change).',
            ], 402);
        }

        try {
            $result = $this->arthur->editPage(
                pageId:       $pageId,
                userMessage:  $validated['message'],
                sectionIndex: $validated['section_index'] ?? null,
                // 2026-09-14: the requester travels with the request — the kernel auto-approves review-tier studio
                // actions (video, image edits) only for a direct user action carrying user_id.
                context:      ['subdomain' => $page->subdomain ?? null, 'selected' => $validated['selected'] ?? null, 'plan' => $explicitPlan,
                               'user_id'   => (int) ($request->attributes->get('user_id') ?? optional($request->user())->id ?? 0) ?: null],
            );
            if (!empty($result['delegated'])) {
                // STRESS 2026-09-06: Arthur already priced pages/sections/edits himself — never bill the reservation on top
                $credits->releaseReservedCredits($reservationRef);
                $result['credits_used'] = (int) ($result['credits'] ?? 0);
            } elseif (($result['success'] ?? false) && (int) ($result['actions_applied'] ?? 0) > 0) {
                $credits->commitReservedCredits($reservationRef);
                $result['credits_used'] = 1;
            } else {
                $credits->releaseReservedCredits($reservationRef);
                $result['credits_used'] = 0;
            }
            // RISK-0100 — return the post-edit optimistic-lock token so the editor can
            // advance base_version after an applied edit (field-saves do not touch
            // sections_json, so this only changes on arthur-edit/updatePage).
            if (($result['success'] ?? false)) {
                $result['version'] = sha1((string) \Illuminate\Support\Facades\DB::table('pages')->where('id', $pageId)->value('sections_json'));
            }
            if (! empty($__meter['debited'])) { $result['chat_meter'] = 1; $result['credits_used'] = (int) ($result['credits_used'] ?? 0) + 1; foreach (['message', 'reply'] as $__k) { if (! empty($result[$__k]) && is_string($result[$__k])) { $result[$__k] = rtrim($result[$__k]) . ' (1 credit — every 10th chat message)'; } } }
            return response()->json($result);
        } catch (\Throwable $e) {
            if ($reservationRef) {
                $credits->releaseReservedCredits($reservationRef);
            }
            return response()->json([
                'error'   => 'Arthur edit failed',
                'detail'  => $e->getMessage(),
            ], 500);
        }
    }
}
