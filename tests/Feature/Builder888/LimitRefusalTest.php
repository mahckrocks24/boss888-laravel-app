<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Exceptions\BuilderRefusedException;
use App\Engines\Builder\Services\BuilderApplicationService;
use App\Engines\Builder\Services\BuilderService;
use App\Engines\Builder\Support\BuilderErrorContract as EC;
use App\Engines\Builder\Support\BuilderGenerationDTO as DTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BUILDER888 · P1-6-a — a plan-limit refusal is a business outcome.
 *
 * BuilderService::createWebsite() reports entitlement refusals as a RETURN
 * VALUE (success=false, limit_reached=true, error=<customer wording>), not an
 * exception. The orchestrator originally checked only for website_id, so the
 * customer was told "We couldn't save this website" — an internal-fault message
 * for an actionable, self-serviceable condition.
 */
class LimitRefusalTest extends TestCase
{
    use RefreshDatabase;

    private int $ws;
    private int $uid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uid = DB::table('users')->insertGetId([
            'name' => 'lim', 'email' => 'lim-' . bin2hex(random_bytes(4)) . '@x.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ws = DB::table('workspaces')->insertGetId([
            'name' => 'lim', 'slug' => 'lim-' . bin2hex(random_bytes(4)),
            'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // A plan that permits exactly one website, so the second is refused.
        DB::table('plans')->updateOrInsert(
            ['slug' => 'free'],
            ['name' => 'Free', 'max_websites' => 1, 'created_at' => now(), 'updated_at' => now()]
        );
    }

    private function doc(string $name): DTO
    {
        return DTO::fromArray([
            'workspace_id' => $this->ws,
            'created_by'   => $this->uid,
            'name'         => $name,
            'type'         => 'template',
            'template_industry'  => 'retail_shop',
            'template_variables' => ['business_name' => $name],
            'pages' => [[
                'title' => 'Home', 'slug' => 'home', 'status' => 'published',
                'is_homepage' => true, 'sections' => ['schemaVersion' => 1, 'sections' => []],
            ]],
        ]);
    }

    private function svc(): BuilderApplicationService
    {
        return new BuilderApplicationService(app(BuilderService::class));
    }

    public function test_a_plan_limit_refusal_is_not_reported_as_a_persistence_failure(): void
    {
        $this->svc()->generateWebsite($this->doc('First Site'));   // consumes the single slot

        try {
            $this->svc()->generateWebsite($this->doc('Second Site'));
            $this->fail('expected the second generation to be refused');
        } catch (BuilderRefusedException $e) {
            $this->assertTrue($e->limitReached);
            $this->assertStringContainsStringIgnoringCase('limit', $e->getMessage());
            // The platform's own actionable wording, not a generic fault.
            $this->assertStringNotContainsStringIgnoringCase("couldn't save", $e->getMessage());
        }
    }

    public function test_the_refusal_envelope_is_truthful_and_not_retryable(): void
    {
        $envelope = EC::failureWithMessage(
            EC::LIMIT_REACHED,
            'Website limit reached (1 on Free plan). Upgrade to create more.'
        );

        $this->assertSame('error', $envelope['type']);
        $this->assertSame(EC::LIMIT_REACHED, $envelope['error_category']);
        $this->assertFalse($envelope['retryable'], 'retrying an entitlement refusal cannot succeed');
        $this->assertStringContainsString('Upgrade to create more', $envelope['build_error']);
        $this->assertFalse(EC::isSuccessful($envelope));
    }

    public function test_a_refusal_leaves_no_partial_website(): void
    {
        $this->svc()->generateWebsite($this->doc('First Site'));
        $before = DB::table('websites')->where('workspace_id', $this->ws)->count();

        try { $this->svc()->generateWebsite($this->doc('Second Site')); } catch (BuilderRefusedException) {}

        $this->assertSame($before, DB::table('websites')->where('workspace_id', $this->ws)->count());
        $this->assertSame(1, DB::table('pages')
            ->whereIn('website_id', DB::table('websites')->where('workspace_id', $this->ws)->pluck('id'))
            ->count(), 'the refused generation must not leave orphan pages');
    }

    public function test_a_genuine_persistence_fault_is_still_classified_as_such(): void
    {
        // Refusal handling must not swallow real faults.
        $r = EC::fromThrowable(new \Illuminate\Database\QueryException(
            'mysql', 'insert', [], new \Exception('SQLSTATE[HY000]')
        ));
        $this->assertSame(EC::PERSISTENCE_FAILED, $r['error_category']);
        $this->assertNotSame(EC::LIMIT_REACHED, $r['error_category']);
    }
}
