<?php

namespace Tests\Feature\Sarah;

use App\Core\Strategy\SarahDailyOrchestrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * SARAH888 · SBS-001 · Task T0.2 Part A — synthesis failure telemetry.
 *
 * WHAT WAS INVISIBLE
 * SarahDailyOrchestrator::tryDedicatedEndpoint() returned null on BOTH the
 * non-2xx and the empty-body path without logging anything, and its catch block
 * logged at debug level, which this installation does not emit. The runtime
 * enforces its own ~30s deadline and answers
 *
 *     HTTP 503 {"error":"request_timeout","message":"Request took too long …"}
 *
 * so Laravel's 60s timeout never fired. A platform-wide synthesis outage running
 * from 2026-07-27 produced no application-log evidence of which endpoint failed
 * or why — only a downstream "aiRun returned empty" from the fallback.
 *
 * These tests pin the durable evidence contract: every failed attempt records
 * workspace, cycle id, path, classification, endpoint, status, elapsed and the
 * runtime's correlation ids, and no attempt writes to the database.
 *
 * NO DATABASE. The synthesis methods are exercised directly through reflection
 * with the HTTP client faked, so no gather() call and no persistence occurs.
 * testSynthesisFailureWritesNothingToTheDatabase proves it by counting queries
 * rather than asserting it in prose (SBS-Q-041).
 *
 * Traces: MP Law 10 (Silence Is a Defect) · SBS-O-001 · SBS-O-003.
 */
class SarahDailySynthesisTelemetryTest extends TestCase
{
    private const DEDICATED = '*/internal/sarah/synthesize-daily';
    private const AI_RUN    = '*/ai/run';

    private const FAKE_URL    = 'https://runtime.test';
    private const FAKE_SECRET = 'test-secret';

    /**
     * phpunit.xml deliberately blanks provider credentials, so without this the
     * orchestrator short-circuits at the isConfigured() guard and records
     * classification=not_configured — correct behaviour, but not the branch under
     * test. RuntimeClient reads RUNTIME_URL/RUNTIME_SECRET through env() in its
     * constructor, so the values are placed in every repository Laravel's Env
     * consults and the container instance is discarded to force a fresh build.
     */
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

        // Guard the guard: if credentials did not take, every assertion below
        // would silently measure the not_configured branch instead.
        $this->assertTrue(
            app(\App\Connectors\RuntimeClient::class)->isConfigured(),
            'Test setup failed to configure the runtime; the branch under test would not be reached.'
        );

        return $o;
    }

    /** Invoke a private synthesis method without running the full daily cycle. */
    private function invokePrivate(SarahDailyOrchestrator $o, string $method, array $args)
    {
        $m = (new \ReflectionClass($o))->getMethod($method);
        $m->setAccessible(true);

        return $m->invokeArgs($o, $args);
    }

    /** Minimal state — never reaches the network shape assertions, only carried through. */
    private function state(): array
    {
        return ['workspace_id' => 2, 'seo' => ['orphan_pages' => 0], '_rule_candidates' => []];
    }

    /** Capture every Log::warning payload emitted during the callback. */
    private function captureWarnings(callable $fn): array
    {
        $captured = [];
        Log::listen(function ($message) use (&$captured) {
            if (($message->level ?? null) === 'warning') {
                $captured[] = ['message' => $message->message, 'context' => $message->context];
            }
        });
        $fn();

        return $captured;
    }

    private function synthesisFailures(array $warnings): array
    {
        return array_values(array_filter(
            $warnings,
            static fn (array $w): bool => str_contains($w['message'], 'synthesis attempt failed')
        ));
    }

    /** REQUIREMENT 1 + 3 — dedicated endpoint 503 logs a warning carrying both request ids. */
    public function testDedicatedEndpointTimeoutProducesWarningWithCorrelationIds(): void
    {
        Http::fake([self::DEDICATED => Http::response(
            ['error' => 'request_timeout', 'message' => 'Request took too long — please retry.'],
            503,
            ['x-request-id' => 'ed8900ac-dfa7-4c07-a51c-c886fe121dec',
             'x-railway-request-id' => 'THDba2g3TMS6sUQOyCLmYg']
        )]);

        $o = $this->orchestrator();
        $result = null;
        $warnings = $this->captureWarnings(function () use ($o, &$result) {
            $result = $this->invokePrivate($o, 'tryDedicatedEndpoint', [2, $this->state()]);
        });

        $this->assertNull($result, 'A 503 must still yield null so the fallback runs.');

        $failures = $this->synthesisFailures($warnings);
        $this->assertCount(1, $failures, 'Exactly one durable failure record is required.');

        $c = $failures[0]['context'];
        $this->assertSame(2, $c['workspace_id']);
        $this->assertNotEmpty($c['cycle_id']);
        $this->assertSame('dedicated', $c['path']);
        $this->assertSame('non_2xx', $c['classification']);
        $this->assertSame('/internal/sarah/synthesize-daily', $c['endpoint']);
        $this->assertSame(503, $c['http_status']);
        $this->assertSame('request_timeout', $c['error_code']);
        $this->assertStringContainsString('too long', $c['error_message']);
        $this->assertIsInt($c['elapsed_ms']);
        $this->assertSame('ed8900ac-dfa7-4c07-a51c-c886fe121dec', $c['runtime_request_id']);
        $this->assertSame('THDba2g3TMS6sUQOyCLmYg', $c['railway_request_id']);
    }

    /** REQUIREMENT 2 — a 200 carrying no brief is a failure and must be recorded. */
    public function testEmptySuccessfulResponseProducesWarning(): void
    {
        Http::fake([self::DEDICATED => Http::response(
            ['proposed_actions' => [], 'workspace_id' => 2], 200, ['x-request-id' => 'req-empty-1']
        )]);

        $o = $this->orchestrator();
        $result = null;
        $warnings = $this->captureWarnings(function () use ($o, &$result) {
            $result = $this->invokePrivate($o, 'tryDedicatedEndpoint', [2, $this->state()]);
        });

        $this->assertNull($result);
        $failures = $this->synthesisFailures($warnings);
        $this->assertCount(1, $failures);
        $this->assertSame('empty_body', $failures[0]['context']['classification']);
        $this->assertSame(200, $failures[0]['context']['http_status']);
        $this->assertSame('req-empty-1', $failures[0]['context']['runtime_request_id']);
        $this->assertContains('proposed_actions', $failures[0]['context']['body_keys']);
    }

    /** A transport exception must also leave evidence — it previously logged at debug. */
    public function testTransportExceptionProducesWarning(): void
    {
        Http::fake([self::DEDICATED => fn () => throw new \RuntimeException('connect timeout')]);

        $o = $this->orchestrator();
        $warnings = $this->captureWarnings(function () use ($o) {
            $this->invokePrivate($o, 'tryDedicatedEndpoint', [2, $this->state()]);
        });

        $failures = $this->synthesisFailures($warnings);
        $this->assertCount(1, $failures);
        $this->assertSame('exception', $failures[0]['context']['classification']);
        $this->assertStringContainsString('connect timeout', $failures[0]['context']['exception']);
    }

    /** REQUIREMENT 4 — fallback failure produces its own record on the fallback path. */
    public function testFallbackFailureProducesWarning(): void
    {
        Http::fake([self::AI_RUN => Http::response(
            ['error' => 'request_timeout', 'message' => 'Request took too long — please retry.'], 503
        )]);

        $o = $this->orchestrator();
        $result = null;
        $warnings = $this->captureWarnings(function () use ($o, &$result) {
            $result = $this->invokePrivate($o, 'fallbackViaAiRun', [2, $this->state()]);
        });

        $this->assertNull($result);
        $failures = $this->synthesisFailures($warnings);
        $this->assertNotEmpty($failures);

        $c = $failures[0]['context'];
        $this->assertSame('fallback', $c['path']);
        $this->assertSame('non_2xx', $c['classification']);
        $this->assertSame('/ai/run', $c['endpoint']);
        $this->assertSame(0, $c['text_len'], 'This is the value that read text_len=0 in production.');
        $this->assertSame('request_timeout', $c['error_code']);
        $this->assertSame(3000, $c['max_tokens']);
        $this->assertGreaterThan(0, $c['prompt_bytes']);
        $this->assertArrayNotHasKey('prompt', $c, 'The prompt itself must never be logged.');
    }

    /** Both attempts in one cycle must share a cycle id so the failure is reconstructable. */
    public function testBothPathsShareOneCycleIdAndRecordSeparately(): void
    {
        Http::fake([
            self::DEDICATED => Http::response(['error' => 'request_timeout'], 503),
            self::AI_RUN    => Http::response(['error' => 'request_timeout'], 503),
        ]);

        $o = $this->orchestrator();
        $warnings = $this->captureWarnings(function () use ($o) {
            $this->invokePrivate($o, 'synthesizeViaRuntime', [2, $this->state()]);
        });

        $failures = $this->synthesisFailures($warnings);
        $this->assertCount(2, $failures, 'One record per attempted path.');
        $this->assertSame('dedicated', $failures[0]['context']['path']);
        $this->assertSame('fallback', $failures[1]['context']['path']);
        $this->assertSame(
            $failures[0]['context']['cycle_id'],
            $failures[1]['context']['cycle_id'],
            'Both attempts belong to the same cycle.'
        );
    }

    /** REQUIREMENT 5 — a failed synthesis must not produce any customer-facing brief. */
    public function testFailedSynthesisCreatesNoCustomerFacingBrief(): void
    {
        Http::fake([
            self::DEDICATED => Http::response(['error' => 'request_timeout'], 503),
            self::AI_RUN    => Http::response(['error' => 'request_timeout'], 503),
        ]);

        $o = $this->orchestrator();
        $result = $this->invokePrivate($o, 'synthesizeViaRuntime', [2, $this->state()]);

        $this->assertNull(
            $result,
            'Null is what makes runDaily() skip postToChat(); a non-null result here would publish a brief.'
        );
    }

    /**
     * REQUIREMENT 6 — no database MUTATION occurs from a failed synthesis.
     *
     * Reads do occur, and deliberately so: RuntimeClient::aiRun() calls
     * enrichContextWithWorkspace(), which reads seo_settings, seo_keywords,
     * articles and workspace_knowledge before every generation. That is
     * pre-existing behaviour on the fallback path and is untouched by Part A.
     * What must hold is that a failing cycle writes nothing — no brief, no
     * proposal, no partial state — so the assertion is on write statements.
     */
    public function testFailedSynthesisPerformsNoDatabaseMutation(): void
    {
        Http::fake([
            self::DEDICATED => Http::response(['error' => 'request_timeout'], 503),
            self::AI_RUN    => Http::response(['error' => 'request_timeout'], 503),
        ]);

        $o = $this->orchestrator();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->invokePrivate($o, 'synthesizeViaRuntime', [2, $this->state()]);
        $queries = DB::getRawQueryLog();
        DB::disableQueryLog();

        $writes = array_values(array_filter(
            $queries,
            static fn (array $q): bool => (bool) preg_match(
                '/^\s*(insert|update|delete|replace|truncate|alter|drop|create)\b/i',
                (string) ($q['raw_query'] ?? '')
            )
        ));

        $this->assertSame([], $writes, 'A failed synthesis must not mutate the database.');
    }

    /** The telemetry helper itself must be pure — logging may never query at all. */
    public function testTelemetryHelperPerformsNoDatabaseQueries(): void
    {
        $o = $this->orchestrator();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->invokePrivate($o, 'logSynthesisFailure', [2, 'dedicated', 'non_2xx', ['http_status' => 503]]);
        $queries = DB::getRawQueryLog();
        DB::disableQueryLog();

        $this->assertSame([], $queries, 'logSynthesisFailure() must not touch the database.');
    }
}
