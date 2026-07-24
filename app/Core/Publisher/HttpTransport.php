<?php

namespace App\Core\Publisher;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real HTTP to Meta.
 *
 * NOT REACHABLE YET. PublisherService only constructs this when
 * config('publisher.live_transport') is true, and no config file sets it — there
 * is no config/publisher.php, so the default false applies. Enabling live
 * sending is therefore a deliberate, reviewable act, not a default.
 *
 * It exists now so the connector code is written against the real response
 * shapes, including the timeout case that produces an UNCERTAIN outcome.
 */
final class HttpTransport implements Transport
{
    public function __construct(private int $timeout = 20) {}

    public function post(string $url, array $payload, array $headers = []): array
    {
        return $this->send('post', $url, $payload, $headers);
    }

    public function get(string $url, array $query = [], array $headers = []): array
    {
        return $this->send('get', $url, $query, $headers);
    }

    private function send(string $verb, string $url, array $data, array $headers): array
    {
        try {
            $r = Http::withHeaders($headers)->timeout($this->timeout)->{$verb}($url, $data);

            return [
                'status'     => $r->status(),
                'json'       => is_array($r->json()) ? $r->json() : [],
                'error'      => $r->successful() ? null : 'HTTP_' . $r->status(),
                // Meta's correlation handle. This is what lets an uncertain
                // outcome be reconciled instead of blindly retried.
                'request_id' => $r->header('x-fb-request-id') ?: ($r->header('x-fb-trace-id') ?: null),
            ];
        } catch (\Illuminate\Http\Client\ConnectionException) {
            // The dangerous case: the request may or may not have been applied.
            return ['status' => 0, 'json' => [], 'error' => 'TRANSPORT_TIMEOUT', 'request_id' => null];
        } catch (\Throwable $e) {
            Log::warning('[Publisher] transport error', ['error' => $e->getMessage()]);
            return ['status' => 0, 'json' => [], 'error' => 'TRANSPORT_ERROR', 'request_id' => null];
        }
    }

    public function isLive(): bool
    {
        return true;
    }
}
