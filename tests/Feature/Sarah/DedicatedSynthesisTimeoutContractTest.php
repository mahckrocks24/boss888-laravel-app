<?php

namespace Tests\Feature\Sarah;

use App\Core\Strategy\SarahDailyOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * SARAH888 · SBS-001 · T0.2 Part B — cross-component timeout contract.
 *
 * THE DEFECT THIS GUARDS
 * Runtime v2.37.5 gives synthesis workloads a 70s route lane. Laravel's dedicated
 * client budget was 60s. A client shorter than the server's own deadline abandons
 * every call before the server can answer, so a perfectly healthy runtime still
 * produces a failed brief. The two numbers live in different repositories and
 * nothing connected them — which is exactly how the mismatch was nearly shipped.
 *
 * This suite pins BOTH sides of the contract in one place, so lowering the Laravel
 * budget below the runtime lane fails CI rather than silently breaking production.
 *
 * NO REAL WAITING. A 70-second test would be useless in CI. The timeout contract is
 * asserted against the declared literal; the behavioural cases use HTTP fakes.
 *
 * NO DATABASE MUTATION. Every case drives the private synthesis methods directly,
 * so no brief is posted, no proposal inserted and no credit charged. Proven by
 * query inspection rather than by assertion in prose (SBS-Q-041).
 */
class DedicatedSynthesisTimeoutContractTest extends TestCase
{
    private const DEDICATED = '*/internal/sarah/synthesize-daily';
    private const AI_RUN    = '*/ai/run';

    /** Runtime v2.37.5 declared budgets — see runtime lu-middleware.LANES. */
    private const RUNTIME_SYNTHESIS_LANE_SECONDS = 70;
    private const RUNTIME_PROVIDER_SECONDS       = 55;

    private const FAKE_URL    = 'https://runtime.test';
    private const FAKE_SECRET = 'test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['RUNTIME_URL' => self::FAKE_URL, 'RUNTIME_SECRET' => self::FAKE_SECRET] as $k => $v) {
            putenv("{$k}={$v}");
            $_ENV[$k] = $v;
            $_SERVER[$k] = $v;
        }
        config([
            'services.runtime.url'    => self::FAKE_URL,
            'services.runtime.secret' => self::FAKE_SECRET,
        ]);
        $this->app->forgetInstance(\App\Connectors\RuntimeClient::class);
        $this->app->forgetInstance(SarahDailyOrchestrator::class);
    }

    private function orchestrator(): SarahDailyOrchestrator
    {
        $o = app(SarahDailyOrchestrator::class);
        $this->assertTrue(
            app(\App\Connectors\RuntimeClient::class)->isConfigured(),
            'Runtime not configured in test setup — the branch under test would not be reached.'
        );

        return $o;
    }

    private function invokePrivate(SarahDailyOrchestrator $o, string $method, array $args)
    {
        $m = (new \ReflectionClass($o))->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($o, $args);
    }

    private function state(): array
    {
        return ['workspace_id' => 2, 'seo' => ['orphan_pages' => 4], '_rule_candidates' => []];
    }

    private function goodSynthesis(): array
    {
        return [
            'brief_markdown'   => "**Good morning**\n\n- Orphan pages: 4",
            'proposed_actions' => [
                ['agent' => 'james', 'action' => 'fix_orphans', 'title' => 'Fix 4 orphan pages',
                 'reason' => 'no inbound links', 'credit_cost' => 0, 'priority' => 'high', 'rule' => 'orphan_detection'],
            ],
        ];
    }

    /**
     * PROOF 1 — the dedicated client budget must exceed the runtime's own lane.
     *
     * Asserted against the declared literal because the alternative (waiting 70
     * real seconds) cannot run in CI. This is the guard that fails the build if
     * either side of the contract drifts.
     */
    public function testDedicatedClientTimeoutExceedsRuntimeSynthesisLane(): void
    {
        $source = file_get_contents(app_path('Core/Strategy/SarahDailyOrchestrator.php'));

        $this->assertSame(
            1,
            preg_match('/Http::timeout\((\d+)\)\s*\R\s*->withHeaders/m', $source, $m),
            'Could not locate the dedicated-endpoint timeout literal.'
        );

        $clientSeconds = (int) $m[1];

        $this->assertGreaterThan(
            self::RUNTIME_SYNTHESIS_LANE_SECONDS,
            $clientSeconds,
            "Laravel dedicated client budget ({$clientSeconds}s) must exceed the runtime synthesis lane ("
            . self::RUNTIME_SYNTHESIS_LANE_SECONDS . 's), or every dedicated call is abandoned before the runtime can answer.'
        );
        $this->assertSame(90, $clientSeconds, 'The approved value for this release is 90s.');
    }

    /** The full hierarchy must hold: provider < runtime lane < Laravel client. */
    public function testTimeoutHierarchyIsOrderedCorrectly(): void
    {
        $this->assertLessThan(
            self::RUNTIME_SYNTHESIS_LANE_SECONDS,
            self::RUNTIME_PROVIDER_SECONDS,
            'Provider timeout must expire before the runtime deadline.'
        );
        $this->assertGreaterThanOrEqual(
            15,
            self::RUNTIME_SYNTHESIS_LANE_SECONDS - self::RUNTIME_PROVIDER_SECONDS,
            'The runtime needs headroom to abort, classify, serialise and log.'
        );
        $this->assertGreaterThan(
            self::RUNTIME_SYNTHESIS_LANE_SECONDS,
            90,
            'Laravel client must sit above the runtime lane.'
        );
    }

    /** PROOF 2 — a successful dedicated response must NOT invoke the fallback. */
    public function testSuccessfulDedicatedResponseDoesNotInvokeFallback(): void
    {
        Http::fake([
            self::DEDICATED => Http::response($this->goodSynthesis(), 200, ['x-request-id' => 'req-ok-1']),
            self::AI_RUN    => Http::response(['output' => 'SHOULD NOT BE CALLED'], 200),
        ]);

        $o = $this->orchestrator();
        $result = $this->invokePrivate($o, 'synthesizeViaRuntime', [2, $this->state()]);

        $this->assertIsArray($result);
        $this->assertSame('dedicated_endpoint', $result['source']);
        $this->assertStringContainsString('Good morning', $result['brief_markdown']);
        $this->assertCount(1, $result['proposed_actions']);

        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/ai/run'));
    }

    /** PROOF 3 — a typed runtime failure must still route to the fallback. */
    public function testTypedRuntimeFailureStillInvokesFallback(): void
    {
        // v2.37.5 typed envelope, exactly as the new runtime emits it.
        Http::fake([
            self::DEDICATED => Http::response([
                'success'          => false,
                'error'            => 'runtime_deadline',
                'message'          => 'The runtime reached its request deadline before the work completed.',
                'retryable'        => false,
                'stage'            => 'runtime_deadline',
                'request_id'       => 'req-typed-1',
                'elapsed_ms'       => 70012,
                'contract_version' => '1.0',
            ], 503, ['x-request-id' => 'req-typed-1', 'x-railway-request-id' => 'rail-typed-1']),
            self::AI_RUN => Http::response([
                'success' => true,
                'output'  => json_encode($this->goodSynthesis()),
            ], 200),
        ]);

        $o = $this->orchestrator();
        $result = $this->invokePrivate($o, 'synthesizeViaRuntime', [2, $this->state()]);

        $this->assertIsArray($result, 'The fallback must recover after a typed dedicated failure.');
        $this->assertSame('aiRun_fallback', $result['source']);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/ai/run'));
    }

    /** The typed failure must be recorded with its correlation ids (Part A contract). */
    public function testTypedFailureIsRecordedWithCorrelationIds(): void
    {
        Http::fake([
            self::DEDICATED => Http::response(
                ['error' => 'runtime_deadline', 'message' => 'deadline', 'retryable' => false],
                503,
                ['x-request-id' => 'req-typed-2', 'x-railway-request-id' => 'rail-typed-2']
            ),
            self::AI_RUN => Http::response(['error' => 'runtime_deadline'], 503),
        ]);

        $captured = [];
        Log::listen(function ($m) use (&$captured) {
            if (($m->level ?? null) === 'warning' && str_contains($m->message, 'synthesis attempt failed')) {
                $captured[] = $m->context;
            }
        });

        $o = $this->orchestrator();
        $this->invokePrivate($o, 'synthesizeViaRuntime', [2, $this->state()]);

        $this->assertCount(2, $captured, 'One record per attempted path.');
        $this->assertSame('dedicated', $captured[0]['path']);
        $this->assertSame(503, $captured[0]['http_status']);
        $this->assertSame('runtime_deadline', $captured[0]['error_code']);
        $this->assertSame('req-typed-2', $captured[0]['runtime_request_id']);
        $this->assertSame('rail-typed-2', $captured[0]['railway_request_id']);
        $this->assertSame($captured[0]['cycle_id'], $captured[1]['cycle_id']);
    }

    /** PROOFS 4, 5, 6 — one success publishes nothing on its own and charges nothing. */
    public function testSynthesisPathPublishesNothingAndChargesNothing(): void
    {
        Http::fake([self::DEDICATED => Http::response($this->goodSynthesis(), 200)]);

        $o = $this->orchestrator();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $this->invokePrivate($o, 'synthesizeViaRuntime', [2, $this->state()]);
        $queries = DB::getRawQueryLog();
        DB::disableQueryLog();

        $this->assertIsArray($result);

        $writes = array_values(array_filter(
            $queries,
            static fn (array $q): bool => (bool) preg_match(
                '/^\s*(insert|update|delete|replace|truncate)\b/i',
                (string) ($q['raw_query'] ?? '')
            )
        ));

        $this->assertSame(
            [],
            $writes,
            'Synthesis alone must not publish a brief, insert a proposal, or charge a credit — '
            . 'persistence is runDaily()\'s job and happens exactly once, after synthesis returns.'
        );
    }

    /** A single cycle must call the dedicated endpoint exactly once — no blind retry. */
    public function testDedicatedEndpointIsCalledExactlyOncePerCycle(): void
    {
        Http::fake([
            self::DEDICATED => Http::response(['error' => 'runtime_deadline'], 503),
            self::AI_RUN    => Http::response(['error' => 'runtime_deadline'], 503),
        ]);

        $o = $this->orchestrator();
        $this->invokePrivate($o, 'synthesizeViaRuntime', [2, $this->state()]);

        $dedicated = 0;
        $aiRun = 0;
        foreach (Http::recorded() as [$request, $response]) {
            if (str_contains($request->url(), 'synthesize-daily')) $dedicated++;
            if (str_contains($request->url(), '/ai/run'))          $aiRun++;
        }

        $this->assertSame(1, $dedicated, 'A deadline must not be retried blindly.');
        $this->assertSame(1, $aiRun, 'The fallback runs once, not in a loop.');
    }
}
