<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Support\BuilderErrorContract as EC;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BUILDER888 · P1-8B — customer-safe errors, truthful state, failure settlement.
 *
 * Frozen Journey A produced BOTH defects in one response:
 *
 *   {"type":"complete", "build_outcome":"error",
 *    "build_error":"Website rendering failed: str_replace(): Argument #2 ..."}
 *
 * A failed build labelled complete, carrying a raw PHP TypeError.
 */
class ErrorStateContractTest extends TestCase
{
    /** Fragments that must never reach a customer. */
    private const LEAKS = [
        'str_replace(', 'TypeError', 'Argument #2', 'Stack trace', '/var/www/',
        'SQLSTATE', 'App\\', 'Illuminate\\', '.php', 'DeepSeek', 'OpenAI',
        'dall-e', 'gpt-image', 'RuntimeClient', 'Exception',
    ];

    private function assertNoLeak(string $text, string $where): void
    {
        foreach (self::LEAKS as $leak) {
            $this->assertStringNotContainsStringIgnoringCase(
                $leak, $text, "internal detail '{$leak}' leaked to the customer in {$where}"
            );
        }
    }

    // ── the customer half ───────────────────────────────────────────────────

    public function test_the_exact_frozen_type_error_is_never_shown_to_a_customer(): void
    {
        $e = new \TypeError('str_replace(): Argument #2 ($replace) must be of type string when argument #1 ($search) is a string');

        $r = EC::fromThrowable($e);

        $this->assertNoLeak($r['build_error'], 'build_error');
        $this->assertSame(EC::TEMPLATE_RENDER_FAILED, $r['error_category']);
        $this->assertStringContainsString('progress is safe', $r['build_error']);
    }

    public function test_every_category_produces_leak_free_customer_wording(): void
    {
        foreach ([
            EC::GENERATION_FAILED, EC::INVALID_GENERATION, EC::TEMPLATE_RENDER_FAILED,
            EC::PROVIDER_UNAVAILABLE, EC::TIMEOUT, EC::VALIDATION_FAILED, EC::PERSISTENCE_FAILED,
        ] as $category) {
            $r = EC::failure($category);
            $this->assertNoLeak($r['build_error'], $category);
            $this->assertNotSame('', trim($r['build_error']));
            $this->assertSame($category, $r['error_category']);
        }
    }

    public function test_database_and_provider_failures_are_classified_not_leaked(): void
    {
        $db = EC::fromThrowable(new \Illuminate\Database\QueryException(
            'mysql', 'insert into `websites` …', [], new \Exception("SQLSTATE[HY000]: table 'x' doesn't exist")
        ));
        $this->assertSame(EC::PERSISTENCE_FAILED, $db['error_category']);
        $this->assertNoLeak($db['build_error'], 'query exception');

        $to = EC::fromThrowable(new \RuntimeException('cURL error 28: Operation timed out after 120000 ms'));
        $this->assertSame(EC::TIMEOUT, $to['error_category']);
        $this->assertNoLeak($to['build_error'], 'timeout');

        $conn = EC::fromThrowable(new \Illuminate\Http\Client\ConnectionException('Connection refused to runtime'));
        $this->assertSame(EC::PROVIDER_UNAVAILABLE, $conn['error_category']);
        $this->assertNoLeak($conn['build_error'], 'connection');
    }

    public function test_a_correlation_id_is_always_minted_and_unique(): void
    {
        $a = EC::fromThrowable(new \TypeError('x'));
        $b = EC::fromThrowable(new \TypeError('x'));

        $this->assertNotEmpty($a['correlation_id']);
        $this->assertStringStartsWith('bld_', $a['correlation_id']);
        $this->assertNotSame($a['correlation_id'], $b['correlation_id']);
    }

    public function test_retry_classification_is_present_and_sane(): void
    {
        $this->assertTrue(EC::failure(EC::PROVIDER_UNAVAILABLE)['retryable']);
        $this->assertTrue(EC::failure(EC::TIMEOUT)['retryable']);
        // Our own render bug: an identical retry will fail identically.
        $this->assertFalse(EC::failure(EC::TEMPLATE_RENDER_FAILED)['retryable']);
        $this->assertFalse(EC::failure(EC::VALIDATION_FAILED)['retryable']);
    }

    public function test_an_unknown_category_degrades_safely(): void
    {
        $r = EC::failure('not_a_real_category');
        $this->assertSame(EC::GENERATION_FAILED, $r['error_category']);
        $this->assertNoLeak($r['build_error'], 'unknown category');
    }

    // ── the state contract ──────────────────────────────────────────────────

    public function test_a_failure_is_never_labelled_complete(): void
    {
        $r = EC::fromThrowable(new \TypeError('boom'));

        $this->assertSame('error', $r['type'], 'a failed build must not be type=complete');
        $this->assertSame('error', $r['build_outcome']);
        $this->assertFalse(EC::isSuccessful($r));
    }

    public function test_the_frozen_contradiction_cannot_be_constructed(): void
    {
        // type=complete AND build_outcome=error was the frozen Journey A payload.
        foreach ([EC::fromThrowable(new \RuntimeException('x')), EC::failure(EC::TIMEOUT)] as $r) {
            $contradiction = ($r['type'] ?? null) === 'complete'
                          && ($r['build_outcome'] ?? null) === 'error';
            $this->assertFalse($contradiction, 'complete + error contradiction reconstructed');
        }
    }

    public function test_success_is_coherent_and_carries_no_error_fields(): void
    {
        $r = EC::success(['website_id' => 42]);

        $this->assertSame('complete', $r['type']);
        $this->assertSame('website_created', $r['build_outcome']);
        $this->assertNull($r['build_error']);
        $this->assertNull($r['error_category']);
        $this->assertTrue(EC::isSuccessful($r));
    }

    public function test_is_successful_rejects_every_partial_shape(): void
    {
        $this->assertFalse(EC::isSuccessful(['type' => 'complete', 'build_outcome' => 'error']));
        $this->assertFalse(EC::isSuccessful(['type' => 'complete', 'build_error' => 'something']));
        $this->assertFalse(EC::isSuccessful(['type' => 'error']));
        $this->assertFalse(EC::isSuccessful([]));
    }

    // ── failure must not settle anything ────────────────────────────────────

    public function test_a_failed_build_creates_no_website_page_or_credit_row(): void
    {
        $ws = 999_931;

        $before = [
            'websites' => DB::table('websites')->where('workspace_id', $ws)->count(),
            'credits'  => DB::table('credit_transactions')->where('workspace_id', $ws)->count(),
        ];

        // The contract is a pure value object — constructing a failure must
        // never touch persistence.
        EC::fromThrowable(new \TypeError('str_replace(): Argument #2'), EC::TEMPLATE_RENDER_FAILED, ['workspace_id' => $ws]);

        $this->assertSame($before['websites'], DB::table('websites')->where('workspace_id', $ws)->count());
        $this->assertSame($before['credits'], DB::table('credit_transactions')->where('workspace_id', $ws)->count());
    }

    public function test_a_failure_envelope_never_carries_a_website_id(): void
    {
        $r = EC::fromThrowable(new \RuntimeException('x'));

        $this->assertArrayNotHasKey('website_id', array_filter($r, fn ($v) => $v !== null));
        $this->assertFalse(EC::isSuccessful($r));
    }

    public function test_internal_cause_is_retained_for_operators(): void
    {
        // The customer wording must NOT contain it; the log must.
        \Illuminate\Support\Facades\Log::spy();

        $r = EC::fromThrowable(new \TypeError('str_replace(): Argument #2 ($replace) must be of type string'));

        $this->assertNoLeak($r['build_error'], 'customer half');

        \Illuminate\Support\Facades\Log::shouldHaveReceived('error')
            ->withArgs(function ($message, $ctx) {
                return $message === '[Builder888] operation failed'
                    && str_contains((string) ($ctx['internal_cause'] ?? ''), 'str_replace(')
                    && ($ctx['exception_class'] ?? '') === \TypeError::class
                    && ! empty($ctx['correlation_id']);
            })->once();
    }
}
