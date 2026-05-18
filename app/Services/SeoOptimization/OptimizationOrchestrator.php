<?php

namespace App\Services\SeoOptimization;

use App\Core\Billing\FeatureGateService;
use App\Jobs\SeoOptimization\OptimizeWpAttachmentJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OptimizationOrchestrator
 *
 * Single dispatch surface for single-image optimization. Per-request:
 *   1. Plan gate (canUseImageOptimization)
 *   2. Image row lookup in seo_images
 *   3. Skip-if-in-progress / already-optimized
 *   4. Classify media class (C1/C2/unroutable/capability_unknown)
 *   5. Dispatch the right job (currently only C2 supported in Phase A)
 *
 * Returns shape (NEVER claims optimization success on initial dispatch):
 *   {
 *     accepted: bool,
 *     status: string (one of the canonical status enum values),
 *     job_id: ?string (uuid for FE correlation when accepted=true),
 *     reason: ?string (human-readable when accepted=false),
 *     media_class: ?string,
 *     required_plan: ?string,
 *     http_status: int (for the route handler to use)
 *   }
 */
class OptimizationOrchestrator
{
    public function __construct(
        private FeatureGateService $featureGate,
        private ConnectorCapabilityProbe $capabilityProbe,
    ) {}

    /**
     * Dispatch single-image optimization. Returns "accepted" + initial status
     * only — actual optimization happens async in a queue job. FE must poll
     * getStatus() to learn the outcome.
     */
    public function dispatch(int $wsId, ?int $userId, string $imageUrl): array
    {
        // ── 0. Input validation ─────────────────────────────────────────
        if (! filter_var($imageUrl, FILTER_VALIDATE_URL) || ! preg_match('#^https?://#i', $imageUrl)) {
            return [
                'accepted'    => false,
                'status'      => 'failed',
                'reason'      => 'invalid_image_url',
                'http_status' => 422,
            ];
        }

        // ── 1. Plan gate ────────────────────────────────────────────────
        if (! $this->featureGate->canUseImageOptimization($wsId)) {
            return [
                'accepted'      => false,
                'status'        => 'blocked',
                'reason'        => 'plan_upgrade_required',
                'required_plan' => 'growth',
                'http_status'   => 403,
            ];
        }

        // ── 2. Row lookup ───────────────────────────────────────────────
        $row = DB::table('seo_images')
            ->where('workspace_id', $wsId)
            ->where('image_url', $imageUrl)
            ->first();

        if (! $row) {
            return [
                'accepted'    => false,
                'status'      => 'failed',
                'reason'      => 'image_not_in_audit',
                'http_status' => 404,
            ];
        }

        // ── 3. Skip if already in flight or completed ───────────────────
        $currentStatus = (string) ($row->optimization_status ?? 'not_attempted');
        if (in_array($currentStatus, ['optimized', 'optimized_external', 'queued', 'running'], true)) {
            return [
                'accepted'    => false,
                'status'      => $currentStatus,
                'reason'      => 'already_in_state',
                'http_status' => 200,
            ];
        }

        // ── 4. Classify ─────────────────────────────────────────────────
        $classification = $this->classify($wsId, $imageUrl);

        switch ($classification) {
            case 'c2_wp':
                break; // fall through to dispatch

            case 'capability_unknown':
                // Transient probe failure — do NOT mark seo_images.
                // FE shows "capability_unknown" so user can retry shortly.
                return [
                    'accepted'    => false,
                    'status'      => 'capability_unknown',
                    'reason'      => 'connector_capability_probe_failed_transiently',
                    'http_status' => 200,
                ];

            case 'blocked_no_connector':
                // Persistent failure — mark in DB so UI reflects honestly.
                DB::table('seo_images')
                    ->where('id', $row->id)
                    ->update([
                        'optimization_status' => 'blocked_no_connector',
                        'updated_at'          => now(),
                    ]);
                return [
                    'accepted'    => false,
                    'status'      => 'blocked_no_connector',
                    'reason'      => 'wp_lacks_connector_transport',
                    'http_status' => 200,
                ];

            case 'unroutable':
                // Image is not on any connected WP — mark + return.
                DB::table('seo_images')
                    ->where('id', $row->id)
                    ->update([
                        'optimization_status' => 'failed',
                        'updated_at'          => now(),
                    ]);
                return [
                    'accepted'    => false,
                    'status'      => 'failed',
                    'reason'      => 'image_not_on_connected_wp_site',
                    'http_status' => 200,
                ];

            case 'c1_not_supported_yet':
                return [
                    'accepted'    => false,
                    'status'      => 'failed',
                    'reason'      => 'c1_workspace_optimization_deferred_to_phase_f',
                    'http_status' => 501,
                ];

            default:
                return [
                    'accepted'    => false,
                    'status'      => 'failed',
                    'reason'      => 'unknown_classification:' . $classification,
                    'http_status' => 500,
                ];
        }

        // ── 5. Dispatch C2 job ──────────────────────────────────────────
        $jobId = (string) Str::uuid();
        DB::table('seo_images')
            ->where('id', $row->id)
            ->update([
                'optimization_status'   => 'queued',
                'optimization_provider' => 'lugs',
                'updated_at'            => now(),
            ]);

        OptimizeWpAttachmentJob::dispatch(
            $wsId,
            (int) ($userId ?? 0),
            (int) $row->id,
            $imageUrl,
            $jobId
        );

        return [
            'accepted'    => true,
            'job_id'      => $jobId,
            'status'      => 'queued',
            'media_class' => 'c2_wp',
            'http_status' => 202,
        ];
    }

    /**
     * Classify the image. Phase A supports only c2_wp dispatch.
     *
     * Returns: 'c2_wp' | 'unroutable' | 'capability_unknown' | 'blocked_no_connector'
     *          | 'c1_not_supported_yet'
     */
    private function classify(int $wsId, string $imageUrl): string
    {
        $siteUrl = DB::table('seo_settings')
            ->where('workspace_id', $wsId)
            ->where('key', 'site_url')
            ->value('value');

        if (! $siteUrl) {
            return 'unroutable';
        }

        $siteHost  = strtolower((string) parse_url((string) $siteUrl, PHP_URL_HOST));
        $imageHost = strtolower((string) parse_url($imageUrl, PHP_URL_HOST));

        if ($siteHost === '' || $imageHost === '' || $siteHost !== $imageHost) {
            return 'unroutable';
        }

        // Host matches the connected site — now probe capability
        $cap = $this->capabilityProbe->probe($wsId);

        if (($cap['status'] ?? '') === 'capability_unknown') {
            return 'capability_unknown';
        }

        if (($cap['optimization_available'] ?? false) === true) {
            return 'c2_wp';
        }

        // Capability probe succeeded but optimization not available —
        // distinct from transient failure
        return 'blocked_no_connector';
    }

    /**
     * Read current optimization state for FE polling.
     */
    public function getStatus(int $wsId, string $imageUrl): array
    {
        $row = DB::table('seo_images')
            ->where('workspace_id', $wsId)
            ->where('image_url', $imageUrl)
            ->first([
                'id',
                'optimization_status',
                'optimization_provider',
                'optimization_last_error',
                'last_optimized_at',
                'last_verified_at',
                'size_bytes',
                'verified_size_bytes',
                'saved_bytes',
                'webp_url',
                'webp_verified',
            ]);

        if (! $row) {
            return ['found' => false];
        }

        return [
            'found'                 => true,
            'status'                => $row->optimization_status ?? 'not_attempted',
            'provider'              => $row->optimization_provider,
            'last_error'            => $row->optimization_last_error,
            'last_optimized_at'     => $row->last_optimized_at,
            'last_verified_at'      => $row->last_verified_at,
            'current_size_bytes'    => (int) $row->size_bytes,
            'verified_size_bytes'   => $row->verified_size_bytes !== null ? (int) $row->verified_size_bytes : null,
            'saved_bytes'           => $row->saved_bytes !== null ? (int) $row->saved_bytes : null,
            'webp_url'              => $row->webp_url,
            'webp_verified'         => (bool) $row->webp_verified,
        ];
    }
}
