<?php

namespace Tests\Feature\Auth;

use App\Core\Auth\RefreshTokenService;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * RISK-0192 (2026-09-19) — who a rate-limit bucket belongs to.
 *
 * The `api` limiter used to key on $request->user() ?: $request->ip(); throttle:api runs before JwtAuthMiddleware so
 * the user was always null, and with the proxies untrusted the IP was the Cloudflare edge node — one 60/min bucket
 * shared by every visitor behind an edge. These tests pin the identities: an authenticated request is its JWT subject
 * (240/min), an API key is its key (240/min), an anonymous request is its real IP (60/min), and session renewal has
 * its own limiter (20/min per token, 120/min per IP). The trusted-proxy behaviour is pinned through the request
 * itself: forwarded headers from Cloudflare are honoured, the same headers from anywhere else are ignored.
 */
class ApiThrottleIdentityTest extends TestCase
{
    private function limits(string $name, Request $request): array
    {
        $r = RateLimiter::limiter($name)($request);
        return array_map(fn ($l) => ['max' => $l->maxAttempts, 'key' => $l->key], is_array($r) ? $r : [$r]);
    }

    public function test_an_authenticated_request_is_its_user_at_240_per_minute(): void
    {
        $user = User::first() ?? User::create(['name' => 'Throttle Identity', 'email' => 'throttle-' . \Illuminate\Support\Str::random(8) . '@test.invalid', 'password' => bcrypt(\Illuminate\Support\Str::random(16))]);
        $token = app(RefreshTokenService::class)->issueAccessToken($user, null, null, null);
        $req = Request::create('/api/policy', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => '203.0.113.9']);
        $l = $this->limits('api', $req);
        $this->assertSame(240, $l[0]['max']);
        $this->assertSame('u:' . $user->id, $l[0]['key']);

        // the same user from another address is the same bucket
        $req2 = Request::create('/api/policy', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'REMOTE_ADDR' => '198.51.100.7']);
        $this->assertSame($l[0]['key'], $this->limits('api', $req2)[0]['key']);
    }

    public function test_a_bad_or_expired_bearer_is_anonymous_and_an_anonymous_request_is_its_ip_at_60(): void
    {
        $req = Request::create('/api/policy', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer eyJhbGciOiJIUzI1NiJ9.broken.token', 'REMOTE_ADDR' => '203.0.113.9']);
        $l = $this->limits('api', $req);
        $this->assertSame(60, $l[0]['max']);
        $this->assertSame('ip:203.0.113.9', $l[0]['key']);
    }

    public function test_an_api_key_is_its_own_bucket(): void
    {
        $req = Request::create('/api/policy', 'GET', server: ['HTTP_X_API_KEY' => 'lug_test_key_123', 'REMOTE_ADDR' => '203.0.113.9']);
        $l = $this->limits('api', $req);
        $this->assertSame(240, $l[0]['max']);
        $this->assertStringStartsWith('k:', $l[0]['key']);
        $this->assertStringNotContainsString('lug_test_key_123', $l[0]['key'], 'the key itself is never the cache key');
    }

    public function test_session_renewal_has_its_own_limiter_per_token_and_per_ip(): void
    {
        $req = Request::create('/api/auth/refresh', 'POST', ['refresh_token' => 'abc'], server: ['REMOTE_ADDR' => '203.0.113.9']);
        $l = $this->limits('refresh', $req);
        $this->assertCount(2, $l);
        $this->assertSame(120, $l[0]['max']); $this->assertSame('rip:203.0.113.9', $l[0]['key']);
        $this->assertSame(20, $l[1]['max']); $this->assertStringStartsWith('rt:', $l[1]['key']);
        $this->assertStringNotContainsString('abc', $l[1]['key']);
    }

    public function test_forwarded_headers_are_honoured_from_cloudflare_and_ignored_from_anywhere_else(): void
    {
        // through Cloudflare (edge 172.71.103.135 is in the trusted ranges): the client is the forwarded address
        $viaCf = $this->call('GET', '/api/policy', [], [], [], ['REMOTE_ADDR' => '172.71.103.135', 'HTTP_X_FORWARDED_FOR' => '198.51.100.44', 'HTTP_X_FORWARDED_PROTO' => 'https']);
        $this->assertSame('198.51.100.44', $this->app['request']->ip());

        // straight at the origin (not a Cloudflare address): the forwarded header is a lie and the client is the socket
        $direct = $this->call('GET', '/api/policy', [], [], [], ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_FOR' => '198.51.100.44', 'HTTP_CF_CONNECTING_IP' => '198.51.100.44']);
        $this->assertSame('203.0.113.9', $this->app['request']->ip());
    }
}
