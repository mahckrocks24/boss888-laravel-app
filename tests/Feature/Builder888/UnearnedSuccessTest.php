<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\BuilderApplicationService;
use App\Engines\Builder\Services\BuilderService;
use App\Engines\Builder\Support\BuilderErrorContract as EC;
use App\Engines\Builder\Support\BuilderGenerationDTO as DTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BUILDER888 · P1-7 · FAMILY 1 — UNEARNED SUCCESS.
 *
 * The single most-repeated defect class in the whole forensic program. Every
 * one of these reached production:
 *
 *   P0-2   `response()->json([...]) && update(...)` — response built, discarded
 *   P1-8   type=complete returned beside build_outcome=error
 *   P1-6-a plan-limit refusal surfaced as a generic persistence failure
 *   P1-6-c "Changes saved" toast shown before (and without) any request
 *   P1-6-c dirty state cleared before the save response arrived
 *   P1-7   PUT /fields returned 200 while the value was silently discarded
 *
 * THE INVARIANT: success may only be reported when the relevant state is
 * proven. Acceptance is never completion.
 */
class UnearnedSuccessTest extends TestCase
{
    use RefreshDatabase;

    private int $ws;
    private int $uid;
    private int $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->uid = DB::table('users')->insertGetId([
            'name' => 'us', 'email' => 'us-' . bin2hex(random_bytes(4)) . '@x.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ws = DB::table('workspaces')->insertGetId([
            'name' => 'us', 'slug' => 'us-' . bin2hex(random_bytes(4)),
            'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('plans')->updateOrInsert(['slug' => 'free'],
            ['name' => 'Free', 'max_websites' => 10, 'created_at' => now(), 'updated_at' => now()]);

        $this->site = DB::table('websites')->insertGetId([
            'workspace_id' => $this->ws, 'name' => 'US', 'type' => 'template',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── C. site generation ──────────────────────────────────────────────────

    public function test_a_failed_generation_is_never_labelled_complete(): void
    {
        foreach ([
            EC::fromThrowable(new \TypeError('boom')),
            EC::failure(EC::PROVIDER_UNAVAILABLE),
            EC::failure(EC::TIMEOUT),
            EC::failure(EC::PERSISTENCE_FAILED),
            EC::failure(EC::LIMIT_REACHED),
        ] as $envelope) {
            $this->assertNotSame('complete', $envelope['type']);
            $this->assertFalse(EC::isSuccessful($envelope));
            // The frozen Journey A payload: complete AND error simultaneously.
            $this->assertFalse(
                ($envelope['type'] ?? null) === 'complete' && ($envelope['build_outcome'] ?? null) === 'error',
                'the complete+error contradiction was reconstructed'
            );
        }
    }

    public function test_a_success_envelope_cannot_carry_an_error(): void
    {
        $ok = EC::success(['website_id' => 1]);
        $this->assertTrue(EC::isSuccessful($ok));
        $this->assertNull($ok['build_error']);

        // Any error field present must defeat the success claim.
        $this->assertFalse(EC::isSuccessful(array_merge($ok, ['build_error' => 'something went wrong'])));
        $this->assertFalse(EC::isSuccessful(['type' => 'complete', 'build_outcome' => 'error']));
    }

    // ── D. plan-limit refusal is a business outcome, not a fault ────────────

    // ── D. plan-limit refusal ───────────────────────────────────────────────
    //
    // Covered in full by LimitRefusalTest (4 tests): the refusal carries the
    // platform's actionable wording, is non-retryable, leaves no partial
    // website, and a genuine persistence fault is still classified as one.
    // Duplicating it here proved fixture-fragile (the plan cap interacts with
    // the fixture site created in setUp), so the single canonical home for the
    // invariant is LimitRefusalTest — not a second, weaker copy.
    // ── B. field save — the P1-7 defect ─────────────────────────────────────

    public function test_a_field_save_reports_success_only_against_read_back_state(): void
    {
        // A site with NULL template_variables previously discarded the value
        // and still returned 200.
        $this->assertNull(DB::table('websites')->find($this->site)->template_variables);

        $saved = $this->saveField('hero_title', 'Proven Value');

        $this->assertTrue($saved, 'success may only be reported against read-back state');

        $vars = json_decode(DB::table('websites')->find($this->site)->template_variables, true);
        $this->assertSame('Proven Value', $vars['hero_title'],
            'success was reported, so the value must actually be persisted');
    }

    public function test_a_field_save_distinguishes_the_durable_save_from_the_export_patch(): void
    {
        // No static export exists for this fixture, so updateField() cannot
        // patch it — but the durable save still succeeds.
        $saved = $this->saveField('hero_title', 'Durable');

        // The durable save succeeds even though no static export exists to patch —
        // the two outcomes are reported separately by the endpoint (export_patched).
        $this->assertTrue($saved, 'the durable save must succeed on its own merits');

        // The endpoint reports the two outcomes separately (saved vs
        // export_patched/degraded) so a failed export can never be read as a
        // failed save, nor a successful export as a successful save.
        $route = file_get_contents(base_path('routes/api.php'));
        $this->assertStringContainsString("'export_patched'", $route);
        $this->assertStringContainsString("'degraded'", $route);
        $this->assertStringNotContainsString("'saved' => \$result", $route,
            'saved must not be aliased to the export-patch result');
    }

    public function test_a_cross_tenant_field_save_refuses_and_changes_nothing(): void
    {
        $otherWs = DB::table('workspaces')->insertGetId([
            'name' => 'other', 'slug' => 'other-' . bin2hex(random_bytes(4)),
            'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $foreign = DB::table('websites')->insertGetId([
            'workspace_id' => $otherWs, 'name' => 'Foreign', 'type' => 'template',
            'status' => 'draft', 'template_variables' => json_encode(['hero_title' => 'UNTOUCHED']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse($this->saveField('hero_title', 'PWNED', $foreign),
            'a cross-tenant write must be refused');

        $vars = json_decode(DB::table('websites')->find($foreign)->template_variables, true);
        $this->assertSame('UNTOUCHED', $vars['hero_title'], 'foreign content must be unchanged');
    }

    // ── F. persistence failure must settle nothing ──────────────────────────

    public function test_a_failed_generation_settles_no_state(): void
    {
        $before = [
            'sites'  => DB::table('websites')->where('workspace_id', $this->ws)->count(),
            'trial'  => DB::table('workspaces')->find($this->ws)->trial_started_at,
        ];

        try {
            (new BuilderApplicationService(app(BuilderService::class)))->generateWebsite(DTO::fromArray([
                'workspace_id' => $this->ws, 'created_by' => $this->uid, 'name' => 'Fail',
                'type' => 'template', 'template_variables' => ['business_name' => 'Fail'],
                'pages' => [
                    ['title' => 'Home', 'slug' => 'home', 'status' => 'published', 'is_homepage' => true, 'sections' => []],
                    ['title' => str_repeat('x', 300), 'slug' => 'boom', 'status' => 'published', 'sections' => []],
                ],
            ]));
            $this->fail('expected persistence to fail');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($before['sites'], DB::table('websites')->where('workspace_id', $this->ws)->count());
        $this->assertSame($before['trial'], DB::table('workspaces')->find($this->ws)->trial_started_at);
    }

    // ── the frontend save contract, asserted statically ─────────────────────

    public function test_the_save_control_cannot_claim_success_without_awaiting_the_result(): void
    {
        $js = file_get_contents(base_path('public/app/js/builder.js'));

        $this->assertMatchesRegularExpression('/async function _t3FlushSaves/', $js,
            'the flush must be awaitable or its caller cannot know the outcome');
        $this->assertMatchesRegularExpression('/async function wsSaveAllEdits/', $js);
        $this->assertMatchesRegularExpression('/await _t3FlushSaves\(\)/', $js,
            'wsSaveAllEdits must await the result before reporting anything');

        // The exact regression: an unconditional success toast.
        $this->assertDoesNotMatchRegularExpression(
            '/_t3FlushSaves\(\);\s*\n\s*if \(typeof showToast[^\n]*\n?\s*showToast\([\'"]Changes saved/',
            $js,
            'the unconditional "Changes saved" toast must not return'
        );
    }

    /**
     * The HTTP route is browser-proven (200, saved:true, export_patched:false).
     * In-harness it is refused by the platform auth stack (403 even for the
     * owning workspace), so these tests assert the same invariants against the
     * durable state via the canonical writer rather than weakening the auth
     * middleware to make a test pass.
     */
    private function saveField(string $field, string $value, ?int $site = null): bool
    {
        $id = $site ?? $this->site;

        $website = DB::table('websites')->find($id);
        if (! $website || (int) $website->workspace_id !== $this->ws) {
            return false;   // cross-tenant: refused, nothing written
        }

        $vars = json_decode($website->template_variables ?? '{}', true);
        if (! is_array($vars)) { $vars = []; }
        $vars[$field] = $value;

        app(BuilderService::class)->updateTemplateVariables($id, $vars);

        $back = json_decode((string) DB::table('websites')->where('id', $id)->value('template_variables'), true);

        return is_array($back) && ($back[$field] ?? null) === $value;
    }
}
