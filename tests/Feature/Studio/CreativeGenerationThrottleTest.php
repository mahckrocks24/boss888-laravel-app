<?php

namespace Tests\Feature\Studio;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Phase G Fix 2 — interactive creative-generation throttling.
 *
 * Characterizes that a per-user request throttle (throttle:20,1) is attached to
 * EXACTLY the four interactive/manual heavy-generation endpoints, sits AFTER the
 * JWT auth middleware (so the RateLimiter signature is keyed by the authenticated
 * user, not the shared IP), and touches no other route — including the adjacent
 * low-cost studio suggest-copy endpoint, which is deliberately left unthrottled.
 *
 * These are pure route-table inspections: no DB, no queue, no provider calls.
 */
class CreativeGenerationThrottleTest extends TestCase
{
    private const LIMIT = 'throttle:20,1';

    private const TARGETS = [
        'api/creative/generate/image',
        'api/creative/generate/video',
        // STUDIO888 Phase O — masked/local image edit is an interactive,
        // credit-charged generation route and carries the same throttle.
        'api/creative/edit',
        'api/studio/ai/generate-image',
        'api/studio/ai/generate-design',
    ];

    private function postRoute(string $uri): ?\Illuminate\Routing\Route
    {
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === $uri && in_array('POST', $route->methods(), true)) {
                return $route;
            }
        }

        return null;
    }

    /** @test */
    public function creative_generate_image_route_is_throttled_20_per_minute(): void
    {
        $route = $this->postRoute('api/creative/generate/image');
        $this->assertNotNull($route, 'POST api/creative/generate/image must exist');
        $this->assertContains(self::LIMIT, $route->gatherMiddleware());
    }

    /** @test */
    public function creative_generate_video_route_is_throttled_20_per_minute(): void
    {
        $route = $this->postRoute('api/creative/generate/video');
        $this->assertNotNull($route, 'POST api/creative/generate/video must exist');
        $this->assertContains(self::LIMIT, $route->gatherMiddleware());
    }

    /** @test */
    public function studio_ai_generate_image_route_is_throttled_20_per_minute(): void
    {
        $route = $this->postRoute('api/studio/ai/generate-image');
        $this->assertNotNull($route, 'POST api/studio/ai/generate-image must exist');
        $this->assertContains(self::LIMIT, $route->gatherMiddleware());
    }

    /** @test */
    public function studio_ai_generate_design_route_is_throttled_20_per_minute(): void
    {
        $route = $this->postRoute('api/studio/ai/generate-design');
        $this->assertNotNull($route, 'POST api/studio/ai/generate-design must exist');
        $this->assertContains(self::LIMIT, $route->gatherMiddleware());
    }

    /** @test */
    public function throttle_runs_after_auth_so_the_rate_limit_key_is_user_aware(): void
    {
        $route = $this->postRoute('api/creative/generate/image');
        $this->assertNotNull($route);

        $middleware = array_values($route->middleware());
        $authIdx = array_search('auth.jwt', $middleware, true);
        $throttleIdx = array_search(self::LIMIT, $middleware, true);

        $this->assertNotFalse($authIdx, 'auth.jwt must wrap the route');
        $this->assertNotFalse($throttleIdx, 'throttle:20,1 must wrap the route');
        $this->assertGreaterThan(
            $authIdx,
            $throttleIdx,
            'throttle must resolve its signature AFTER auth sets the user, else it keys by IP'
        );
    }

    /** @test */
    public function adjacent_low_cost_studio_endpoint_is_not_throttled(): void
    {
        // suggest-copy is interactive but cheap; it was intentionally excluded from Fix 2.
        $route = $this->postRoute('api/studio/ai/suggest-copy');
        $this->assertNotNull($route, 'POST api/studio/ai/suggest-copy must exist');
        $this->assertNotContains(
            self::LIMIT,
            $route->gatherMiddleware(),
            'suggest-copy must remain unthrottled — Fix 2 targets heavy generation only'
        );
    }

    /** @test */
    public function exactly_the_four_interactive_generation_routes_carry_the_throttle(): void
    {
        $throttled = [];
        foreach (Route::getRoutes() as $route) {
            if (in_array(self::LIMIT, $route->gatherMiddleware(), true)) {
                $throttled[] = $route->uri();
            }
        }

        $throttled = array_values(array_unique($throttled));
        sort($throttled);

        $expected = self::TARGETS;
        sort($expected);

        $this->assertSame(
            $expected,
            $throttled,
            'throttle:20,1 must be attached to exactly the four interactive generation routes'
        );
    }
}
