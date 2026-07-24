<?php

namespace App\Core\Publisher;

/**
 * The single seam between the Publisher connectors and the outside world.
 *
 * Every provider call goes through a Transport. In tests and in every code path
 * that has not been explicitly authorised for live sending, that Transport is a
 * MockTransport with no network access at all. This is what lets the real
 * connector logic — endpoint shapes, error mapping, container workflows — be
 * fully exercised while it remains impossible to contact Meta by accident.
 *
 * HttpTransport is deliberately inert until something constructs it, and nothing
 * does yet: no route is wired, and PublisherService resolves MockTransport
 * unless config('publisher.live_transport') is true, which no config file sets.
 * Live sending becomes a deliberate one-line change once Meta permissions and
 * designated test assets exist.
 */
interface Transport
{
    /** @return array{status:int, json:array, error:?string, request_id:?string} */
    public function post(string $url, array $payload, array $headers = []): array;

    /** @return array{status:int, json:array, error:?string, request_id:?string} */
    public function get(string $url, array $query = [], array $headers = []): array;

    public function isLive(): bool;
}
