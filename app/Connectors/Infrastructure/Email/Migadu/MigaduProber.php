<?php

namespace App\Connectors\Infrastructure\Email\Migadu;

use App\Connectors\Infrastructure\Contracts\EmailProviderProber;
use App\Connectors\Infrastructure\Email\Migadu\MigaduRequestFactory as Req;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * INFRA888 · E6 — the vendor's half of the neutral prober.
 *
 * Translates logical targets into this vendor's paths, performs the call, and
 * returns raw HTTP facts. Everything provider-shaped stops here; the caller
 * receives status codes, headers and structural descriptions only.
 */
final class MigaduProber implements EmailProviderProber
{
    public function __construct(private readonly MigaduClient $client)
    {
    }

    public function targets(): array
    {
        return [
            self::TARGET_ACCOUNT, self::TARGET_DOMAIN, self::TARGET_DOMAIN_RECORDS,
            self::TARGET_DOMAIN_DIAGNOSE, self::TARGET_DOMAIN_USAGE,
            self::TARGET_MAILBOXES, self::TARGET_MAILBOX, self::TARGET_ALIASES,
            self::TARGET_REWRITES, self::TARGET_FORWARDINGS,
            self::TARGET_MISSING_DOMAIN, self::TARGET_MISSING_MAILBOX,
        ];
    }

    public function probe(string $target, array $args = []): array
    {
        $domain = (string) ($args['domain'] ?? '');
        $localPart = (string) ($args['local_part'] ?? '');

        $path = match ($target) {
            self::TARGET_ACCOUNT         => Req::domains(),
            self::TARGET_DOMAIN          => Req::domain($domain),
            self::TARGET_DOMAIN_RECORDS  => Req::domainRecords($domain),
            self::TARGET_DOMAIN_DIAGNOSE => Req::domainDiagnostics($domain),
            self::TARGET_DOMAIN_USAGE    => Req::domainUsage($domain),
            self::TARGET_MAILBOXES       => Req::mailboxes($domain),
            self::TARGET_MAILBOX         => Req::mailbox($domain, $localPart),
            self::TARGET_ALIASES         => Req::aliases($domain),
            self::TARGET_REWRITES        => Req::rewrites($domain),
            self::TARGET_FORWARDINGS     => Req::forwardings($domain, $localPart),
            // Deliberately absent objects, for error-mapping evidence. Random so
            // a real object can never be hit by coincidence.
            self::TARGET_MISSING_DOMAIN  => Req::domain('absent-' . bin2hex(random_bytes(6)) . '.test'),
            self::TARGET_MISSING_MAILBOX => Req::mailbox($domain, 'absent-' . bin2hex(random_bytes(6))),
            default => throw new \InvalidArgumentException('Unknown probe target.'),
        };

        $started = microtime(true);

        try {
            $response = $this->client->get($path);

            return [
                'status'         => $response['status'],
                'headers'        => $response['headers'],
                'shape'          => $this->shapeOf($response['body']),
                'row_count'      => $this->rowCount($response['body']),
                'top_level_keys' => array_slice(array_keys($response['body']), 0, 12),
                'latency_ms'     => (int) ((microtime(true) - $started) * 1000),
                'retry_after'    => MigaduClient::retryAfterSeconds($response['headers']),
            ];
        } catch (Throwable $e) {
            return [
                'status'         => 0,
                'headers'        => [],
                'shape'          => 'transport_failure',
                'row_count'      => null,
                'top_level_keys' => [],
                'latency_ms'     => (int) ((microtime(true) - $started) * 1000),
                'retry_after'    => null,
            ];
        }
    }

    public function probeUnauthenticated(): array
    {
        $bad = new MigaduClient(
            http: app(HttpFactory::class),
            accountEmail: 'nobody@levelupgrowth.io',
            apiKey: 'deliberately-invalid-key-for-live-validation',
            networkEnabled: true,
        );

        $started = microtime(true);

        try {
            $response = $bad->get(Req::domains());

            return [
                'status'     => $response['status'],
                'latency_ms' => (int) ((microtime(true) - $started) * 1000),
            ];
        } catch (Throwable) {
            return ['status' => 0, 'latency_ms' => (int) ((microtime(true) - $started) * 1000)];
        }
    }

    private function shapeOf(array $body): string
    {
        if ($body === []) {
            return 'empty';
        }

        return array_is_list($body) ? 'bare_list' : 'keyed_object';
    }

    private function rowCount(array $body): ?int
    {
        if (array_is_list($body)) {
            return count($body);
        }

        foreach (array_values(MigaduCapabilityMap::LIVE_ENVELOPES) as $key) {
            if (isset($body[$key]) && is_array($body[$key])) {
                return count($body[$key]);
            }
        }

        return null;
    }
}
