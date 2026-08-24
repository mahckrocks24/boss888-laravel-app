<?php

namespace App\Http\Controllers\Api\Admin;

use App\Engines\Ads\Services\AdReportService;
use App\Engines\Ads\Services\AdSettingsService;
use App\Engines\Ads\Services\AdStatsRollupService;
use App\Engines\Ads\Services\InventoryProfileService;
use App\Engines\Ads\Support\AdSettings;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * ADS888 admin API — phase A1, READ ONLY.
 *
 * Every endpoint here is a GET that returns what an existing service already
 * computes. No business logic lives in this controller: the artisan commands and
 * this API call the same services, so the console and the screen can never
 * disagree about what the platform is doing.
 *
 * SECURITY
 * Mounted behind ['auth.jwt','admin'] — AdminMiddleware checks
 * `$user->is_platform_admin`, enforced SERVER-SIDE on every route. INFRA888
 * shipped its Operations tab hidden in the UI but open on the API; a hidden nav
 * item is not a permission, and AdminAdsAccessTest asserts each route 403s
 * without the role.
 *
 * A1 deliberately contains NO mutation. Settings editing (A2) and campaign CRUD
 * (A3) land separately, because those need MFA step-up and upload handling that
 * read-only screens do not.
 */
class AdminAdsController
{
    public function __construct(
        private readonly AdReportService $reports,
        private readonly AdSettingsService $settings,
        private readonly InventoryProfileService $profiles,
        private readonly AdStatsRollupService $rollup,
    ) {
    }

    /**
     * GET /api/admin/ads/status
     *
     * The at-a-glance answer to "is advertising on, and what is it doing".
     * Deliberately the first endpoint: the most common admin question is
     * whether anything is live at all.
     */
    public function status(): JsonResponse
    {
        $master = $this->settings->bool(AdSettings::MASTER_ENABLED);
        $modal  = $this->settings->bool(AdSettings::MODAL_ENABLED);

        $activeSlots = DB::table('ad_slots')->where('is_active', true)->pluck('code')->all();

        $pendingCreatives = (int) DB::table('ad_creatives')->where('review_state', 'pending')->count();
        $activeCampaigns  = (int) DB::table('ad_campaigns')->where('status', 'active')->count();
        $paidCampaigns    = (int) DB::table('ad_campaigns')->where('status', 'active')->where('kind', 'paid')->count();

        $inventory = $this->profiles->summary();

        return response()->json([
            'serving'          => $master,
            'master_enabled'   => $master,
            'modal_enabled'    => $modal,
            'video_enabled'    => $this->settings->bool(AdSettings::MODAL_ALLOW_VIDEO),
            'active_slots'     => $activeSlots,
            'campaigns'        => ['active' => $activeCampaigns, 'paid' => $paidCampaigns],
            'creatives_pending'=> $pendingCreatives,
            'inventory'        => [
                'profiled'  => $inventory['total'],
                'sellable'  => $inventory['sellable_for_targeting'],
                'house_only'=> $inventory['unsellable'],
            ],
            // Stated plainly so nobody has to infer it from a zero row.
            'note' => $master
                ? 'Advertising is LIVE on eligible sites.'
                : 'Advertising is OFF platform-wide. No advertisement can render anywhere.',
        ]);
    }

    /** GET /api/admin/ads/dashboard?days=30 */
    public function dashboard(Request $request): JsonResponse
    {
        return response()->json($this->reports->dashboard($this->days($request)));
    }

    /** GET /api/admin/ads/inventory?days=30 */
    public function inventory(Request $request): JsonResponse
    {
        return response()->json($this->reports->inventory($this->days($request)));
    }

    /** GET /api/admin/ads/invalid-traffic?days=7 */
    public function invalidTraffic(Request $request): JsonResponse
    {
        return response()->json($this->reports->invalidTraffic($this->days($request, 7)));
    }

    /** GET /api/admin/ads/revenue?days=90 */
    public function revenue(Request $request): JsonResponse
    {
        return response()->json($this->reports->revenue($this->days($request, 90)));
    }

    /** GET /api/admin/ads/campaigns/{id}/report?days=30 */
    public function campaignReport(Request $request, int $id): JsonResponse
    {
        $data = $this->reports->campaign($id, $this->days($request));

        if (isset($data['error'])) {
            return response()->json($data, 404);
        }

        return response()->json($data);
    }

    /**
     * GET /api/admin/ads/settings
     *
     * Read-only in A1, but it returns everything the editor will need — current
     * value, shipped default, whether it is overridden, and the description —
     * plus the values that are deliberately NOT tunable, so "why can't I change
     * viewability" is answerable in the UI rather than in someone's memory.
     */
    public function settings(): JsonResponse
    {
        return response()->json([
            'settings' => $this->settings->all(),
            'fixed_by_design' => AdSettings::fixedByDesign(),
            'editable' => true,
            'note' => 'Changes take effect within 60 seconds and are recorded in the audit log.',
        ]);
    }

    /**
     * PUT /api/admin/ads/settings/{key}
     *
     * Guardrails are enforced here, not in the browser. A refusal explains
     * itself and can be overridden with `force`, which records the change as
     * forced — identical behaviour to `ads:settings --force`, so the console
     * and the screen can never diverge.
     */
    public function updateSetting(Request $request, string $key): JsonResponse
    {
        if (! AdSettings::isKnown($key)) {
            return response()->json(['error' => "Unknown setting: {$key}"], 404);
        }

        $payload = $request->validate([
            'value' => 'present',
            'force' => 'nullable|boolean',
        ]);

        $value = $payload['value'];
        $force = (bool) ($payload['force'] ?? false);

        // A JSON body already carries the right type; a form post gives strings,
        // so a JSON-shaped string is decoded rather than stored as text.
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            if (json_last_error() === JSON_ERROR_NONE && ! is_string($decoded)) {
                $value = $decoded;
            }
        }

        $objection = AdSettings::validate($key, $value);

        if ($objection !== null && ! $force) {
            return response()->json([
                'error'      => $objection,
                'refused'    => true,
                'forceable'  => true,
                'hint'       => 'Re-send with "force": true if this is deliberate. It will be recorded as forced.',
            ], 422);
        }

        $before = $this->settings->get($key);

        try {
            $this->settings->set(
                $key,
                $value,
                $request->user()?->id,
                'admin-ui' . ($objection !== null ? ' (forced)' : '')
            );
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'ok'      => true,
            'key'     => $key,
            'before'  => $before,
            'after'   => $this->settings->get($key),
            'forced'  => $objection !== null,
            'warning' => $objection,
            'note'    => 'Takes effect within 60 seconds (settings cache).',
        ]);
    }

    /** POST /api/admin/ads/settings/{key}/reset */
    public function resetSetting(Request $request, string $key): JsonResponse
    {
        if (! AdSettings::isKnown($key)) {
            return response()->json(['error' => "Unknown setting: {$key}"], 404);
        }

        $before = $this->settings->get($key);
        $this->settings->set($key, AdSettings::default($key), $request->user()?->id, 'admin-ui --reset');

        return response()->json([
            'ok'     => true,
            'key'    => $key,
            'before' => $before,
            'after'  => $this->settings->get($key),
        ]);
    }

    /** GET /api/admin/ads/inventory/unclassified — the ad-ops worklist. */
    public function unclassified(): JsonResponse
    {
        $rows = $this->profiles->unclassified();

        return response()->json([
            'count' => count($rows),
            'sites' => $rows,
            'note'  => 'These sites serve house ads only and are never matched to a paid targeted campaign.',
        ]);
    }

    /**
     * GET /api/admin/ads/reconcile?date=YYYY-MM-DD
     *
     * Rarely opened, decisive when it is: proves the nightly rollup is neither
     * dropping nor double-counting before anyone is billed from it.
     */
    public function reconcile(Request $request): JsonResponse
    {
        $date = (string) $request->query('date', now()->subDay()->toDateString());

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return response()->json(['error' => 'date must be YYYY-MM-DD'], 422);
        }

        try {
            return response()->json($this->rollup->reconcile($date));
        } catch (Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /** GET /api/admin/ads/audit?limit=50&action=settings.update */
    public function audit(Request $request): JsonResponse
    {
        $limit  = max(1, min(200, (int) $request->query('limit', 50)));
        $action = $request->query('action');

        $rows = DB::table('ad_audit_log')
            ->when(is_string($action) && $action !== '', fn ($q) => $q->where('action', $action))
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get();

        return response()->json([
            'count'   => $rows->count(),
            'entries' => $rows->map(fn ($r) => [
                'occurred_at'  => $r->occurred_at,
                'actor'        => $r->actor_label ?? ('user:' . ($r->actor_id ?? '?')),
                'action'       => $r->action,
                'subject_type' => $r->subject_type,
                'subject_id'   => $r->subject_id,
                'before'       => $this->json($r->before),
                'after'        => $this->json($r->after),
            ])->all(),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────

    private function days(Request $request, int $default = 30): int
    {
        return max(1, min(365, (int) $request->query('days', $default)));
    }

    private function json(mixed $raw): mixed
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        $decoded = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : $raw;
    }
}
