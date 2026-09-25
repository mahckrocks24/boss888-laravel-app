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
 * PublisherService and SocialService::publishPost resolve HttpTransport only when
 * config('publisher.live_transport') is true. SOCIAL-LIVE-1 (2026-09-25): that
 * value now comes from config/publisher.php <- PUBLISHER_LIVE_TRANSPORT in .env,
 * switched on by the Owner; phpunit.xml pins it to false so the suite can never
 * contact Meta.
 */
interface Transport
{
    /** @return array{status:int, json:array, error:?string, request_id:?string} */
    public function post(string $url, array $payload, array $headers = []): array;

    /** @return array{status:int, json:array, error:?string, request_id:?string} */
    public function get(string $url, array $query = [], array $headers = []): array;

    public function isLive(): bool;
}
