<?php

namespace Tests\Feature\Studio;

use App\Engines\Studio\Readiness\CompilerReadinessService;
use App\Models\CreativeJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 Phase L — read-only aggregate readiness reporting.
 */
class CompilerReadinessAggregationTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    private function svc(): CompilerReadinessService
    {
        return app(CompilerReadinessService::class);
    }

    private function makeJob(string $class, int $score, string $verdict, bool $normId, string $status = 'completed'): void
    {
        $wp = in_array($verdict, ['PASS', 'PASS_WITH_WARNINGS'], true);
        CreativeJob::create([
            'workspace_id' => $this->testWorkspace->id,
            'type' => 'generation', 'capability' => 'generate_image', 'status' => $status,
            'original_prompt' => 'p',
            'metadata' => [
                'compiler'   => ['version' => '1.0.0-shadow'],
                'guardrails' => ['verdict' => $verdict],
                'execution_observation' => ['actual_provider_prompt' => 'x'],
                'compiler_readiness' => [
                    'divergence_class' => $class,
                    'readiness_score'  => $score,
                    'normalized_identical' => $normId,
                    'byte_identical' => false,
                    'capability_match' => true,
                    'aspect_ratio_match' => true,
                    'guardrail_verdict' => $verdict,
                    'would_pass' => $wp, 'would_review' => $verdict === 'REVIEW', 'would_fail' => $verdict === 'FAIL',
                ],
            ],
        ]);
    }

    /** @test */
    public function no_data_returns_insufficient_data(): void
    {
        $this->assertSame('INSUFFICIENT_DATA', $this->svc()->report($this->testWorkspace->id)['state']);
    }

    /** @test */
    public function below_minimum_sample_returns_insufficient_data(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->makeJob('NORMALIZATION_ONLY', 100, 'PASS', true);
        }
        $rep = $this->svc()->report($this->testWorkspace->id);
        $this->assertSame('INSUFFICIENT_DATA', $rep['state']); // 5 < 100
        $this->assertSame(5, $rep['jobs_comparable']);
    }

    /** @test */
    public function distribution_agreement_and_guardrail_tally_are_accurate(): void
    {
        $this->makeJob('NORMALIZATION_ONLY', 100, 'PASS', true);
        $this->makeJob('NORMALIZATION_ONLY', 98, 'PASS', true);
        $this->makeJob('PRODUCTION_ENHANCED', 70, 'PASS_WITH_WARNINGS', false);
        $this->makeJob('CONFLICTING_INSTRUCTIONS', 40, 'REVIEW', false);
        $this->makeJob('BOTH_DIVERGED', 55, 'FAIL', false);

        $r = $this->svc()->report($this->testWorkspace->id);
        $this->assertSame(5, $r['jobs_comparable']);
        $this->assertSame(2, $r['divergence_distribution']['NORMALIZATION_ONLY']);
        $this->assertSame(1, $r['divergence_distribution']['CONFLICTING_INSTRUCTIONS']);
        $this->assertSame(40.0, $r['normalized_agreement_rate']);   // 2/5
        $this->assertSame(20.0, $r['conflict_rate']);               // 1/5
        $this->assertSame(20.0, $r['guardrail_fail_rate']);         // 1/5
        $this->assertSame(3, $r['would_pass']);                     // PASS + WARN
        $this->assertSame(1, $r['would_review']);
        $this->assertSame(1, $r['would_fail']);
        $this->assertSame(2, $r['guardrail_verdict_distribution']['PASS']);
    }

    /** @test */
    public function observed_outcome_correlation_is_counted_neutrally(): void
    {
        $this->makeJob('NORMALIZATION_ONLY', 100, 'PASS', true, 'completed'); // pass_success
        $this->makeJob('BOTH_DIVERGED', 40, 'FAIL', false, 'completed');       // fail_success
        $this->makeJob('BOTH_DIVERGED', 40, 'FAIL', false, 'failed');          // fail_failure

        $corr = $this->svc()->report($this->testWorkspace->id)['observed_outcome_correlation'];
        $this->assertSame(1, $corr['pass_success']);
        $this->assertSame(1, $corr['fail_success']);
        $this->assertSame(1, $corr['fail_failure']);
    }

    /** @test */
    public function aggregation_is_deterministic_and_does_not_mutate_jobs(): void
    {
        $this->makeJob('NORMALIZATION_ONLY', 100, 'PASS', true);
        $this->makeJob('PRODUCTION_ENHANCED', 70, 'PASS_WITH_WARNINGS', false);

        $before = CreativeJob::orderBy('id')->pluck('metadata')->toArray();
        $r1 = $this->svc()->report($this->testWorkspace->id);
        $r2 = $this->svc()->report($this->testWorkspace->id);
        $after = CreativeJob::orderBy('id')->pluck('metadata')->toArray();

        $this->assertEquals($r1, $r2);        // deterministic
        $this->assertEquals($before, $after); // read-only — no mutation
    }
}
