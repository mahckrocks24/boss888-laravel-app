<?php

namespace App\Connectors\Infrastructure\Email\Migadu;

use App\Connectors\Infrastructure\Email\Migadu\MigaduCapabilityMap as Map;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * INFRA888 · E5 — the only class in the platform that opens a socket to this vendor.
 *
 * THREE THINGS THIS CLASS EXISTS TO GUARANTEE
 *
 * 1. A credential never appears in an exception, a log line, or a diagnostic
 *    array. It is held in one private property, passed to one call, and
 *    redacted from anything that could be persisted.
 * 2. A mutating request is never retried automatically. Reads may retry under a
 *    small bounded policy; mutations get exactly one attempt, and an
 *    inconclusive result is reported as inconclusive.
 * 3. Nothing leaves this process unless a credential was explicitly installed
 *    AND network calls were explicitly enabled. During E5 that means the
 *    default configuration cannot reach the internet at all, which is what
 *    makes "no unexpected network call occurred" a property of the code rather
 *    than a promise in a document.
 */
final class MigaduClient
{
    /** Bounded so a hung provider cannot hold a queue worker indefinitely. */
    private const CONNECT_TIMEOUT = 10;
    private const TOTAL_TIMEOUT   = 30;

    /** A response larger than this is treated as malformed rather than parsed. */
    private const MAX_RESPONSE_BYTES = 2_097_152;

    /** Reads only. Mutations are never retried here. */
    private const READ_RETRIES     = 2;
    private const READ_RETRY_SLEEP = 250;

    private string $lastCorrelationId = '';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $accountEmail,
        private readonly string $apiKey,
        private readonly bool $networkEnabled,
        private readonly string $baseUrl = Map::API_BASE_URL,
    ) {
    }

    public function lastCorrelationId(): string
    {
        return $this->lastCorrelationId;
    }

    /** @throws MigaduTransportException */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query, null, mutating: false);
    }

    /** @throws MigaduTransportException */
    public function post(string $path, array $payload): array
    {
        return $this->send('POST', $path, [], $payload, mutating: true);
    }

    /** @throws MigaduTransportException */
    public function put(string $path, array $payload): array
    {
        return $this->send('PUT', $path, [], $payload, mutating: true);
    }

    /** @throws MigaduTransportException */
    public function delete(string $path): array
    {
        return $this->send('DELETE', $path, [], null, mutating: true);
    }

    /**
     * @return array{status:int,body:array,headers:array,correlation_id:string}
     *
     * @throws MigaduTransportException on transport failure only. A non-2xx
     *         response is returned normally so the classifier — not the client —
     *         decides what it means.
     */
    private function send(string $method, string $path, array $query, ?array $payload, bool $mutating): array
    {
        $correlationId = (string) Str::uuid();
        $this->lastCorrelationId = $correlationId;

        // ── THE HARD STOP ───────────────────────────────────────────────────
        // Not a feature flag with a sensible default: an explicit refusal. E5 is
        // read-only validation, and the code must be incapable of reaching the
        // provider until an operator turns it on deliberately.
        if (! $this->networkEnabled) {
            throw MigaduTransportException::unreachable(
                'Outbound calls to the email service are disabled in this environment.'
            );
        }

        if ($this->accountEmail === '' || $this->apiKey === '') {
            throw MigaduTransportException::unreachable(
                'No credential is installed for the email service.'
            );
        }

        $started = microtime(true);

        try {
            $request = $this->http
                ->withBasicAuth($this->accountEmail, $this->apiKey)
                ->withHeaders([
                    'Accept'       => 'application/json',
                    'Content-Type' => 'application/json',
                    // Identifies us to the vendor's operators without naming a
                    // customer. Never carries a workspace or a domain.
                    'User-Agent'   => 'LevelUpGrowth-Infrastructure/1.0 (+https://levelupgrowth.io)',
                    'X-Request-Id' => $correlationId,
                ])
                ->withOptions([
                    'verify'          => true,   // TLS verification is never negotiable
                    'allow_redirects' => false,  // a redirect would re-send credentials elsewhere
                ])
                ->connectTimeout(self::CONNECT_TIMEOUT)
                ->timeout(self::TOTAL_TIMEOUT);

            // Reads retry; mutations never do. A retried POST is how a customer
            // ends up with two mailboxes and one invoice line.
            if (! $mutating) {
                $request = $request->retry(self::READ_RETRIES, self::READ_RETRY_SLEEP, throw: false);
            }

            $response = match ($method) {
                'GET'    => $request->get($this->url($path), $query),
                'POST'   => $request->post($this->url($path), $payload ?? []),
                'PUT'    => $request->put($this->url($path), $payload ?? []),
                'DELETE' => $request->delete($this->url($path)),
                default  => throw MigaduTransportException::malformed('Unsupported method.'),
            };
        } catch (ConnectionException $e) {
            // Laravel raises this both for connect failures and for read
            // timeouts, and the two are not equally safe. A connect failure
            // cannot have mutated anything; a read timeout might have.
            $this->log($method, $path, null, $correlationId, $started, 'transport');

            throw $this->connectionFailureFor($e, $mutating);
        } catch (MigaduTransportException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->log($method, $path, null, $correlationId, $started, 'transport');

            throw $mutating
                ? MigaduTransportException::indeterminate('The request did not complete.', $e)
                : MigaduTransportException::unreachable('The request did not complete.', $e);
        }

        $body = $this->decode($response);

        $this->log($method, $path, $response->status(), $correlationId, $started, 'http');

        return [
            'status'         => $response->status(),
            'body'           => $body,
            'headers'        => $this->safeHeaders($response),
            'correlation_id' => $correlationId,
        ];
    }

    /**
     * A connect-phase failure is safe to retry even for a mutation. A timeout
     * after the request went out is not, and the distinction is worth reading
     * the message for, because getting it wrong duplicates mailboxes.
     */
    private function connectionFailureFor(ConnectionException $e, bool $mutating): MigaduTransportException
    {
        $message = strtolower($e->getMessage());

        $neverSent = str_contains($message, 'could not resolve')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'failed to connect')
            || str_contains($message, 'ssl');

        if ($neverSent || ! $mutating) {
            return MigaduTransportException::unreachable('We could not reach the email service.', $e);
        }

        return MigaduTransportException::indeterminate(
            'The email service did not respond in time, and the request may have been applied.',
            $e
        );
    }

    /**
     * Body handling. An unparseable or oversized body is malformed, which for a
     * mutation is ambiguous — not a failure. Guessing "it failed" here is how a
     * successful create gets retried.
     *
     * @throws MigaduTransportException
     */
    private function decode(Response $response): array
    {
        $raw = $response->body();

        if (strlen($raw) > self::MAX_RESPONSE_BYTES) {
            throw MigaduTransportException::malformed('The email service returned an oversized response.');
        }

        // 204 and empty 200s are legitimate for DELETE.
        if (trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw MigaduTransportException::malformed('The email service returned a response we could not read.');
        }

        // A scalar or list is wrapped so callers always receive an array shape.
        return is_array($decoded) ? $decoded : ['value' => $decoded];
    }

    /**
     * Rate-limit and correlation headers only. Everything else is dropped rather
     * than filtered, because an allowlist cannot leak a header nobody predicted.
     */
    private function safeHeaders(Response $response): array
    {
        $wanted = [
            'retry-after',
            'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset',
            'ratelimit-limit', 'ratelimit-remaining', 'ratelimit-reset',
            'x-request-id',
        ];

        $out = [];

        foreach ($wanted as $name) {
            $value = $response->header($name);

            if ($value !== '' && $value !== null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /**
     * How long to wait before a retryable call is attempted again.
     *
     * The provider publishes no rate-limit documentation, so an absent header is
     * reported as unknown and the caller applies its own backoff rather than
     * this class inventing a number and calling it provider behaviour.
     */
    public static function retryAfterSeconds(array $headers): ?int
    {
        foreach (['retry-after', 'x-ratelimit-reset', 'ratelimit-reset'] as $name) {
            $value = $headers[$name] ?? null;

            if ($value === null) {
                continue;
            }

            if (is_numeric($value)) {
                return max(0, (int) $value);
            }

            $timestamp = strtotime((string) $value);

            if ($timestamp !== false) {
                return max(0, $timestamp - time());
            }
        }

        return null;
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
    }

    /**
     * Structured, provider-neutral, and carrying no payload.
     *
     * The path is logged because it is needed to diagnose anything at all, but
     * a path contains a domain and a mailbox local part — so it is reduced to
     * its shape before it is written.
     */
    private function log(string $method, string $path, ?int $status, string $correlationId, float $started, string $phase): void
    {
        Log::channel(config('logging.default'))->info('infra.email.provider_call', [
            'capability'     => 'email',
            'method'         => $method,
            'route'          => self::routeShape($path),
            'status'         => $status,
            'phase'          => $phase,
            'duration_ms'    => (int) ((microtime(true) - $started) * 1000),
            'correlation_id' => $correlationId,
        ]);
    }

    /**
     * `domains/acme.test/mailboxes/ceo` becomes `domains/{domain}/mailboxes/{id}`.
     * Operators keep the diagnostic value; the log keeps no personal data.
     */
    public static function routeShape(string $path): string
    {
        $segments = explode('/', trim($path, '/'));
        $shape = [];

        foreach ($segments as $i => $segment) {
            if ($i === 0) {
                $shape[] = $segment;
                continue;
            }

            $previous = $segments[$i - 1] ?? '';

            $shape[] = match ($previous) {
                'domains'     => '{domain}',
                'mailboxes'   => '{mailbox}',
                'aliases'     => '{alias}',
                'rewrites'    => '{rewrite}',
                'forwardings' => '{address}',
                default       => $segment,
            };
        }

        return implode('/', $shape);
    }

    /**
     * Belt and braces for anything that might still be persisted by a caller.
     * Redaction at the point of writing is the primary control; this is the
     * second one, because credentials leak through the path nobody predicted.
     */
    public static function redact(string $text, string ...$secrets): string
    {
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '[redacted]', $text);
            }
        }

        return preg_replace('/(Basic|Bearer)\s+[A-Za-z0-9+\/=._-]+/i', '$1 [redacted]', $text) ?? $text;
    }
}
