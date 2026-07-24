<?php

namespace App\Services\Domains\Providers;

use App\Services\Domains\Contracts\CustomHostnameProvider;
use App\Services\Domains\CustomHostnameResult;
use App\Services\Domains\ProviderException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Cloudflare for SaaS — Custom Hostnames adapter (current v4 API,
 * /zones/{zone}/custom_hostnames). All Cloudflare-specific request/response
 * mapping lives here; callers see only {@see CustomHostnameResult}.
 *
 * Certificates are domain-validated (type=dv). Routing uses the fallback-origin
 * model: the customer CNAMEs their hostname at the configured routing target,
 * which Cloudflare proxies to the fallback origin.
 */
class CloudflareSaasProvider implements CustomHostnameProvider
{
    private string $base;

    public function __construct(
        private ?string $zoneId = null,
        private ?string $apiToken = null,
        private ?string $routingTarget = null,
        private string $sslMethod = 'txt',
    ) {
        $this->zoneId        = $zoneId        ?: (string) config('cloudflare.zone_id');
        $this->apiToken      = $apiToken      ?: (string) config('cloudflare.api_token');
        $this->routingTarget = $routingTarget ?: (string) config('cloudflare.routing_target');
        $this->sslMethod     = $sslMethod ?: (string) config('cloudflare.ssl_validation_method', 'txt');
        $this->base = "https://api.cloudflare.com/client/v4/zones/{$this->zoneId}/custom_hostnames";
    }

    public function key(): string
    {
        return 'cloudflare_saas';
    }

    public function createCustomHostname(string $hostname, array $options = []): CustomHostnameResult
    {
        $method = $options['ssl_method'] ?? $this->sslMethod;
        $payload = [
            'hostname' => $hostname,
            'ssl' => [
                'method'        => $method,          // 'txt' | 'http'
                'type'          => 'dv',
                'bundle_method' => 'ubiquitous',
                'wildcard'      => false,
                'settings'      => ['min_tls_version' => '1.2'],
            ],
        ];

        $body = $this->call('POST', '', $payload);
        return $this->map($body['result'] ?? []);
    }

    public function getCustomHostname(string $providerHostnameId): ?CustomHostnameResult
    {
        $body = $this->call('GET', '/'.$providerHostnameId, null, allow404: true);
        if ($body === null) {
            return null; // provider no longer has it
        }
        return $this->map($body['result'] ?? []);
    }

    public function listCustomHostnames(string $hostname): array
    {
        $body = $this->call('GET', '?hostname='.rawurlencode($hostname));
        return array_map(fn ($r) => $this->map($r), $body['result'] ?? []);
    }

    public function verifyCustomHostname(string $providerHostnameId): CustomHostnameResult
    {
        // Re-send the SSL config on the single-hostname path to re-trigger DCV
        // (there is no dedicated revalidate endpoint in the v4 API).
        $payload = ['ssl' => ['method' => $this->sslMethod, 'type' => 'dv']];
        $body = $this->call('PATCH', '/'.$providerHostnameId, $payload);
        return $this->map($body['result'] ?? []);
    }

    public function deleteCustomHostname(string $providerHostnameId): bool
    {
        // Idempotent: an already-absent hostname (404) counts as deleted.
        $this->call('DELETE', '/'.$providerHostnameId, null, allow404: true);
        return true;
    }

    public function getValidationInstructions(CustomHostnameResult $result): array
    {
        $records = [];

        // 1) Routing — CNAME the hostname at our proxied routing target.
        $records[] = [
            'type'    => 'CNAME',
            'name'    => $result->hostname,
            'value'   => $this->routingTarget,
            'purpose' => 'routing',
        ];

        // 2) Ownership + SSL validation records carried on the result.
        foreach ($result->validationRecords as $r) {
            if (($r['purpose'] ?? null) !== 'routing') {
                $records[] = $r;
            }
        }

        return $records;
    }

    public function getCertificateStatus(string $providerHostnameId): ?string
    {
        return $this->getCustomHostname($providerHostnameId)?->sslStatus;
    }

    /* ---------------------------------------------------------------- mapping */

    /** Map a Cloudflare custom_hostname result object onto the neutral DTO. */
    private function map(array $r): CustomHostnameResult
    {
        $ssl = $r['ssl'] ?? [];

        $records = [];
        // Ownership pre-validation TXT (from ownership_verification).
        if (!empty($r['ownership_verification']['name'])) {
            $records[] = [
                'type'    => strtoupper($r['ownership_verification']['type'] ?? 'TXT'),
                'name'    => $r['ownership_verification']['name'],
                'value'   => $r['ownership_verification']['value'] ?? '',
                'purpose' => 'ownership',
            ];
        }
        // Certificate DCV records (txt_name / txt_value).
        foreach (($ssl['validation_records'] ?? []) as $vr) {
            if (!empty($vr['txt_name'])) {
                $records[] = [
                    'type'    => 'TXT',
                    'name'    => $vr['txt_name'],
                    'value'   => $vr['txt_value'] ?? '',
                    'purpose' => 'ssl',
                ];
            }
        }

        $errors = [];
        foreach (($r['verification_errors'] ?? []) as $e) {
            $errors[] = is_string($e) ? $e : ($e['message'] ?? json_encode($e));
        }
        foreach (($ssl['validation_errors'] ?? []) as $e) {
            $errors[] = is_string($e) ? $e : ($e['message'] ?? json_encode($e));
        }

        $hostnameStatus = $r['status'] ?? null;
        $sslStatus      = $ssl['status'] ?? null;

        return new CustomHostnameResult(
            providerHostnameId: (string) ($r['id'] ?? ''),
            hostname:           (string) ($r['hostname'] ?? ''),
            ownershipStatus:    $hostnameStatus,
            sslStatus:          $sslStatus,
            validationMethod:   $ssl['method'] ?? null,
            validationRecords:  $records,
            active:             $hostnameStatus === 'active' && $sslStatus === 'active',
            errors:             array_values(array_filter($errors)),
            raw:                $this->trimRaw($r),
        );
    }

    /** Keep a compact snapshot for metadata; drop large/irrelevant blobs. */
    private function trimRaw(array $r): array
    {
        return [
            'id'       => $r['id'] ?? null,
            'hostname' => $r['hostname'] ?? null,
            'status'   => $r['status'] ?? null,
            'ssl'      => [
                'status' => $r['ssl']['status'] ?? null,
                'method' => $r['ssl']['method'] ?? null,
            ],
            'created_at' => $r['created_at'] ?? null,
        ];
    }

    /* ----------------------------------------------------------------- http */

    private function client(): PendingRequest
    {
        return Http::withToken($this->apiToken)
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(8);
    }

    /**
     * Perform a Cloudflare API call with bounded retry on transient failures.
     * Returns the decoded envelope, or null when allow404 and the resource is
     * absent. Throws ProviderException on hard/unexpected failures.
     */
    private function call(string $method, string $path, ?array $body = null, bool $allow404 = false): ?array
    {
        if ($this->zoneId === '' || $this->apiToken === '') {
            throw new ProviderException('Cloudflare credentials are not configured.');
        }

        $url = $this->base.$path;
        $attempts = 0;
        $lastErr = null;

        while ($attempts < 3) {
            $attempts++;
            try {
                $resp = match (strtoupper($method)) {
                    'GET'    => $this->client()->get($url),
                    'POST'   => $this->client()->post($url, $body ?? []),
                    'PATCH'  => $this->client()->patch($url, $body ?? []),
                    'DELETE' => $this->client()->delete($url),
                    default  => throw new ProviderException("Unsupported method {$method}."),
                };
            } catch (\Throwable $e) {
                // Transport-level failure (DNS/timeout/connection) — retryable.
                $lastErr = new ProviderException('Cloudflare request failed: '.$e->getMessage(), [], true);
                usleep(200000 * $attempts);
                continue;
            }

            $status = $resp->status();
            if ($allow404 && $status === 404) {
                return null;
            }

            $json = $resp->json();
            if ($resp->successful() && ($json['success'] ?? false)) {
                return is_array($json) ? $json : [];
            }

            $messages = array_map(
                fn ($e) => trim((($e['code'] ?? '').' '.($e['message'] ?? ''))),
                $json['errors'] ?? []
            );
            $messages = array_values(array_filter($messages)) ?: ['Cloudflare API error (HTTP '.$status.').'];

            // Retry only transient statuses; otherwise fail fast.
            if (in_array($status, [429, 500, 502, 503, 504], true)) {
                $lastErr = new ProviderException('Cloudflare error: '.implode('; ', $messages), $messages, true);
                usleep(200000 * $attempts);
                continue;
            }

            throw new ProviderException('Cloudflare error: '.implode('; ', $messages), $messages, false);
        }

        throw $lastErr ?? new ProviderException('Cloudflare request failed after retries.', [], true);
    }
}
