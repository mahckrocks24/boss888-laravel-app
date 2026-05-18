<?php

namespace App\Services\SeoOptimization;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OptimizationVerificationService
 *
 * Independent post-execution verification of a C2 (WordPress) image
 * optimization claim. NEVER trusts connector's "success=true" alone —
 * runs an independent HEAD probe of the image URL and a WebP existence
 * probe (if WebP was claimed).
 *
 * Returns verdict:
 *   'verified'   — independent HEAD confirms size matches claim within
 *                  ±2%, image is reachable
 *   'unverified' — any verification check failed; downstream caller MUST
 *                  set optimization_status='unverified' (NOT 'optimized')
 */
class OptimizationVerificationService
{
    private const HEAD_TIMEOUT_S      = 10;
    private const WEBP_TIMEOUT_S      = 5;
    private const SIZE_TOLERANCE_PCT  = 2.0;

    /**
     * Verify a C2 optimization.
     *
     * @param string  $imageUrl         URL the connector claims it optimized
     * @param int     $claimedNewSize   Bytes the connector says the new file is
     * @param ?string $webpUrl          WebP URL if claimed (verified separately)
     * @param ?array  $statusFromConnector Optional independent status payload
     *                                  for cross-check (from /optimization-status)
     *
     * @return array {
     *   verdict: 'verified'|'unverified',
     *   actual_size_bytes: int,           the HEAD-probed size (0 if unreachable)
     *   webp_verified: bool,              webp URL returned 200 + image content-type
     *   issues: string[],                 human-readable failure reasons
     *   checks: array                     per-check diagnostic blob
     * }
     */
    public function verifyC2(
        string $imageUrl,
        int $claimedNewSize,
        ?string $webpUrl = null,
        ?array $statusFromConnector = null
    ): array {
        $issues = [];
        $checks = [];

        // ── CHECK 1: independent HEAD probe ─────────────────────────────
        $actualSize = 0;
        try {
            $resp = Http::timeout(self::HEAD_TIMEOUT_S)->head($imageUrl);
            if ($resp->successful()) {
                $actualSize = (int) $resp->header('Content-Length');
                $checks['head_probe'] = [
                    'ok'          => true,
                    'http_status' => $resp->status(),
                    'actual_size' => $actualSize,
                    'claimed_size'=> $claimedNewSize,
                ];

                if ($actualSize > 0 && $claimedNewSize > 0) {
                    $deltaPct = abs($actualSize - $claimedNewSize) / max($claimedNewSize, 1) * 100;
                    $within   = $deltaPct <= self::SIZE_TOLERANCE_PCT;
                    $checks['size_match'] = [
                        'ok'         => $within,
                        'delta_pct'  => round($deltaPct, 2),
                        'tolerance'  => self::SIZE_TOLERANCE_PCT,
                    ];
                    if (! $within) {
                        $issues[] = sprintf(
                            'size_mismatch (head=%d, claimed=%d, delta=%.2f%%)',
                            $actualSize, $claimedNewSize, $deltaPct
                        );
                    }
                } elseif ($actualSize === 0) {
                    $issues[] = 'head_returned_zero_content_length';
                    $checks['size_match'] = ['ok' => false, 'reason' => 'zero_content_length'];
                }
            } else {
                $issues[] = 'head_probe_http_' . $resp->status();
                $checks['head_probe'] = ['ok' => false, 'http_status' => $resp->status()];
            }
        } catch (\Throwable $e) {
            $issues[] = 'head_probe_unreachable: ' . $e->getMessage();
            $checks['head_probe'] = ['ok' => false, 'error' => $e->getMessage()];
        }

        // ── CHECK 2: WebP existence (if claimed) ────────────────────────
        $webpVerified = false;
        if ($webpUrl) {
            try {
                $resp = Http::timeout(self::WEBP_TIMEOUT_S)->head($webpUrl);
                $contentType = (string) $resp->header('Content-Type');
                $webpVerified = $resp->successful()
                    && stripos($contentType, 'image/') === 0;
                $checks['webp_probe'] = [
                    'ok'           => $webpVerified,
                    'http_status'  => $resp->status(),
                    'content_type' => $contentType,
                ];
                if (! $webpVerified) {
                    $issues[] = 'webp_missing_or_wrong_type';
                }
            } catch (\Throwable $e) {
                $issues[] = 'webp_probe_unreachable';
                $checks['webp_probe'] = ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        // ── CHECK 3: connector status re-query consistency (optional) ───
        if ($statusFromConnector !== null) {
            $reportedSize = (int) ($statusFromConnector['current_size'] ?? 0);
            if ($reportedSize > 0 && $reportedSize !== $claimedNewSize) {
                $issues[] = sprintf(
                    'connector_status_inconsistent (status=%d, optimize_claim=%d)',
                    $reportedSize, $claimedNewSize
                );
                $checks['status_consistency'] = [
                    'ok'              => false,
                    'status_size'     => $reportedSize,
                    'optimize_size'   => $claimedNewSize,
                ];
            } else {
                $checks['status_consistency'] = ['ok' => true];
            }
        }

        // ── Verdict: head_probe AND size_match must both pass ───────────
        $headOk    = ($checks['head_probe']['ok']  ?? false) === true;
        $sizeOk    = ($checks['size_match']['ok']  ?? true)  === true; // absent = pass (only checked when both sizes > 0)
        $statusOk  = ($checks['status_consistency']['ok'] ?? true) === true;

        $verdict = ($headOk && $sizeOk && $statusOk) ? 'verified' : 'unverified';

        return [
            'verdict'           => $verdict,
            'actual_size_bytes' => $actualSize,
            'webp_verified'     => $webpVerified,
            'issues'            => $issues,
            'checks'            => $checks,
        ];
    }
}
