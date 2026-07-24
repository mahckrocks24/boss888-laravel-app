<?php

namespace App\Connectors\Infrastructure\SelfHosted;

use App\Connectors\Infrastructure\Contracts\MonitoringProviderConnector;
use App\Connectors\Infrastructure\ProviderResult;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Self-hosted monitoring connector (Phase 3A) — the first REAL connector.
 *
 * NOT a Null connector. `probe()` performs an actual HTTP request against a
 * target and returns a real observation. It contacts no external monitoring
 * provider and holds no credential — the observer is our own infrastructure,
 * which the MonitoringProviderConnector contract explicitly allows ("our own
 * scheduler" is a valid monitoring provider).
 *
 * HANDS vs BRAIN: this connector is the hands — it makes the HTTP call and
 * normalizes the outcome. InfrastructureMonitoringService is the brain — it owns
 * the check config, the time series, incident open/close and audit. The
 * connector persists nothing.
 *
 * READ-ONLY BY CONSTRUCTION: the only network operation is a GET. It mutates no
 * customer infrastructure, which is why monitoring is the first capability that
 * ships without the gated provider adapter.
 */
class SelfHostedMonitoringConnector implements MonitoringProviderConnector
{
    /** A slow-but-up response over this many ms is reported `degraded`, not `up`. */
    public const DEGRADED_MS = 3000;

    public function provider(): string
    {
        return 'selfhosted';
    }

    public function capability(): string
    {
        return 'monitoring';
    }

    public function healthCheck(): ProviderResult
    {
        return ProviderResult::verified('active', null, 'selfhosted');
    }

    public function synchronize(string $providerResourceId): ProviderResult
    {
        return ProviderResult::verified('active', $providerResourceId);
    }

    public function verify(string $providerResourceId, array $expectation = []): ProviderResult
    {
        return ProviderResult::verified('active', $providerResourceId);
    }

    public function getStatus(string $checkId): ProviderResult
    {
        // Interface compliance: the contract's getStatus(string) has no URL. The
        // real observation is probe(), called by the service with full context.
        return ProviderResult::failed(
            'no_target', 'getStatus requires a target URL; the service uses probe().',
            'permanent', 'unknown'
        );
    }

    /**
     * The REAL observation: a single HTTP GET, normalized.
     *
     * Returns a purpose-built array (this is not a contract method), so a failure
     * can still carry response_ms and the http_code where one was received.
     *
     * @return array{status:string,http_code:?int,response_ms:int,error_code:?string,error_summary:?string}
     */
    public function probe(string $url, int $expectedStatus = 200, int $timeoutSeconds = 15): array
    {
        $start = microtime(true);

        try {
            $response = Http::timeout(max(1, $timeoutSeconds))
                ->withHeaders(['User-Agent' => 'INFRA888-Monitor/1.0'])
                ->get($url);

            $ms   = (int) round((microtime(true) - $start) * 1000);
            $code = $response->status();
            $ok   = $code === $expectedStatus || ($code >= 200 && $code < 400);

            if (!$ok) {
                return [
                    'status'        => 'down',
                    'http_code'     => $code,
                    'response_ms'   => $ms,
                    'error_code'    => 'unexpected_status',
                    'error_summary' => "Expected {$expectedStatus}, got {$code}.",
                ];
            }

            return [
                'status'        => $ms > self::DEGRADED_MS ? 'degraded' : 'up',
                'http_code'     => $code,
                'response_ms'   => $ms,
                'error_code'    => null,
                'error_summary' => null,
            ];
        } catch (Throwable $e) {
            return [
                'status'        => 'down',
                'http_code'     => null,
                'response_ms'   => (int) round((microtime(true) - $start) * 1000),
                'error_code'    => 'unreachable',
                'error_summary' => mb_substr($e->getMessage(), 0, 200),
            ];
        }
    }

    public function createCheck(array $check, string $idempotencyKey): ProviderResult
    {
        return ProviderResult::verified('active', $idempotencyKey, 'selfhosted', $check);
    }

    public function updateCheck(string $checkId, array $check, string $idempotencyKey): ProviderResult
    {
        return ProviderResult::verified('active', $checkId, 'selfhosted', $check);
    }

    public function deleteCheck(string $checkId, string $idempotencyKey): ProviderResult
    {
        return ProviderResult::verified('deleted', $checkId, 'selfhosted');
    }

    public function getIncidents(string $checkId, ?string $since = null): ProviderResult
    {
        return ProviderResult::verified('active', $checkId, 'selfhosted', ['incidents' => []]);
    }

    public function getUptime(string $checkId, string $windowStart, string $windowEnd): ProviderResult
    {
        return ProviderResult::verified('active', $checkId, 'selfhosted', []);
    }
}
