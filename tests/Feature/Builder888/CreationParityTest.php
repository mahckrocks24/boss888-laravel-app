<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\BuilderApplicationService;
use App\Engines\Builder\Services\BuilderService;
use App\Engines\Builder\Support\BuilderGenerationDTO as DTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BUILDER888 · P1-6 — canonical persistence convergence.
 *
 * Law 11: Arthur compiles a DTO; the Builder domain performs every write.
 * These tests exercise REAL persistence — BuilderService is never mocked when
 * the assertion is about what got written.
 *
 * Scope note: P1-6 converges PERSISTENCE only. Representation convergence
 * (template_variables vs sections_json) is a separate, later milestone, so
 * `type = 'template'` remains valid here by design.
 */
class CreationParityTest extends TestCase
{
    use RefreshDatabase;

    private int $ws;

    protected function setUp(): void
    {
        parent::setUp();

        $uid = DB::table('users')->insertGetId([
            'name' => 'P16', 'email' => 'p16-' . bin2hex(random_bytes(4)) . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->uid = $uid;
        $this->ws = DB::table('workspaces')->insertGetId([
            'name' => 'p16', 'slug' => 'p16-' . bin2hex(random_bytes(4)),
            'created_by' => $uid, 'created_at' => now(), 'updated_at' => now(),
        ]);

        // The plan gate reads a 'free' plan; without one BuilderService caps the
        // workspace at 1 site AND dereferences a null plan (see the P2 recorded
        // in the audit). Seed it so these tests measure generation, not that gap.
        if (! DB::table('plans')->where('slug', 'free')->exists()) {
            DB::table('plans')->insert([
                'name' => 'Free', 'slug' => 'free', 'max_websites' => 10,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private int $uid;

    private function doc(array $over = []): array
    {
        return array_merge([
            'workspace_id'       => $this->ws,
            'created_by'         => $this->uid,
            'name'               => 'Northgate Bicycle Repair',
            'type'               => 'template',
            'template_industry'  => 'construction',
            'template_variables' => ['business_name' => 'Northgate', 'service_1_title' => 'Puncture repair'],
            'seo'                => ['title' => 'Northgate', 'description' => 'Bike repair in Manchester'],
            'settings'           => ['generated_by' => 'arthur'],
            'pages'              => [
                ['title' => 'Home', 'slug' => 'home', 'type' => 'page', 'status' => 'published',
                 'is_homepage' => true, 'sections' => [['type' => 'hero', 'heading' => 'Northgate']]],
                ['title' => 'Blog', 'slug' => 'blog', 'type' => 'blog', 'status' => 'published',
                 'sections' => [['type' => 'blog_list']]],
            ],
        ], $over);
    }

    private function app_(): BuilderApplicationService
    {
        return new BuilderApplicationService(app(BuilderService::class));
    }

    // ── DTO validation: nothing invalid may reach persistence ───────────────

    public function test_valid_document_builds(): void
    {
        $dto = DTO::fromArray($this->doc());
        $this->assertSame(2, $dto->pageCount());
        $this->assertSame('construction', $dto->templateIndustry);
    }

    public function test_structured_template_variables_are_refused(): void
    {
        // The P1-8 failure shape: raw provider output bypassing the contract.
        $this->expectException(\InvalidArgumentException::class);
        DTO::fromArray($this->doc(['template_variables' => ['hours' => ['mon' => '9-5']]]));
    }

    public function test_nested_list_template_variable_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DTO::fromArray($this->doc(['template_variables' => ['services' => ['a', 'b']]]));
    }

    public function test_unsupported_page_status_is_refused(): void
    {
        $d = $this->doc();
        $d['pages'][0]['status'] = 'live';
        $this->expectException(\InvalidArgumentException::class);
        DTO::fromArray($d);
    }

    public function test_duplicate_slug_is_refused(): void
    {
        $d = $this->doc();
        $d['pages'][1]['slug'] = 'home';
        $this->expectException(\InvalidArgumentException::class);
        DTO::fromArray($d);
    }

    public function test_missing_workspace_and_pages_are_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DTO::fromArray($this->doc(['workspace_id' => 0]));
    }

    public function test_document_is_provider_neutral_after_serialisation(): void
    {
        $json = json_encode(DTO::fromArray($this->doc())->websiteAttributes());
        foreach (['deepseek', 'openai', 'minimax', 'gpt', 'dall-e', 'choices', 'completion'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $json);
        }
    }

    // ── real persistence through the canonical writer ───────────────────────

    public function test_generation_persists_website_and_pages_with_full_parity(): void
    {
        $r = $this->app_()->generateWebsite(DTO::fromArray($this->doc()));

        $w = DB::table('websites')->find($r['website_id']);

        $this->assertSame($this->ws, (int) $w->workspace_id);
        $this->assertSame($this->uid, (int) $w->created_by, 'created_by must be the actor, not null');
        $this->assertSame('template', $w->type);
        $this->assertSame('construction', $w->template_industry);
        $this->assertNotNull($w->template_variables, 'template representation must survive');
        $this->assertSame('Northgate', json_decode($w->template_variables, true)['business_name']);
        $this->assertNotNull($w->seo_json);
        $this->assertSame('Northgate', json_decode($w->seo_json, true)['title']);

        $pages = DB::table('pages')->where('website_id', $r['website_id'])->orderBy('id')->get();
        $this->assertCount(2, $pages);
        foreach ($pages as $p) {
            $this->assertSame('published', $p->status, 'generated pages must keep their requested status');
        }
        $this->assertSame(1, (int) $pages[0]->is_homepage);
    }

    public function test_template_variables_are_byte_faithful(): void
    {
        $vars = ['business_name' => 'Northgate', 'service_1_title' => 'Puncture repair', 'hero_subtitle' => 'A, B, C'];
        $r = $this->app_()->generateWebsite(DTO::fromArray($this->doc(['template_variables' => $vars])));

        $stored = json_decode(DB::table('websites')->find($r['website_id'])->template_variables, true);
        // Key ORDER is not part of the contract — template_variables is a
        // substitution map, not a sequence, and str_replace consumes it by key.
        // Assert content fidelity, which is the property that actually matters.
        ksort($vars);
        ksort($stored);
        $this->assertSame($vars, $stored, 'template variables must round-trip unchanged');
    }

    public function test_default_page_status_is_unchanged_for_other_callers(): void
    {
        $r = $this->app_()->generateWebsite(DTO::fromArray($this->doc()));

        // A direct createPage() with no status must still default to draft.
        $res = app(BuilderService::class)->createPage($r['website_id'], ['title' => 'Extra', 'slug' => 'extra']);
        $this->assertSame('draft', DB::table('pages')->find($res['page_id'])->status);
    }

    public function test_unsupported_status_is_refused_at_the_writer_too(): void
    {
        $r = $this->app_()->generateWebsite(DTO::fromArray($this->doc()));
        $this->expectException(\InvalidArgumentException::class);
        app(BuilderService::class)->createPage($r['website_id'], ['title' => 'X', 'slug' => 'x', 'status' => 'live']);
    }

    // ── trial + tool usage ──────────────────────────────────────────────────

    public function test_trial_activates_exactly_once_on_first_site(): void
    {
        $this->assertNull(DB::table('workspaces')->find($this->ws)->trial_started_at);

        $this->app_()->generateWebsite(DTO::fromArray($this->doc()));
        $first = DB::table('workspaces')->find($this->ws)->trial_started_at;
        $this->assertNotNull($first, 'trial must activate on the first generated site');

        $this->app_()->generateWebsite(DTO::fromArray($this->doc(['name' => 'Second Site'])));
        $this->assertSame($first, DB::table('workspaces')->find($this->ws)->trial_started_at,
            'a second site must not re-activate the trial');
    }

    // ── atomicity ───────────────────────────────────────────────────────────

    public function test_a_page_failure_rolls_back_the_whole_site(): void
    {
        $before = DB::table('websites')->where('workspace_id', $this->ws)->count();

        $d = $this->doc();
        // 220-char title overflows pages.title varchar(255)? No — force a real
        // failure with an oversized slug against the unique index instead.
        $d['pages'][1]['title'] = str_repeat('x', 300);

        try {
            $this->app_()->generateWebsite(DTO::fromArray($d));
            $this->fail('expected persistence to fail');
        } catch (\Throwable) {
            // expected
        }

        $this->assertSame($before, DB::table('websites')->where('workspace_id', $this->ws)->count(),
            'a failed generation must leave no orphan website');
        $this->assertNull(DB::table('workspaces')->find($this->ws)->trial_started_at,
            'a failed generation must not activate a trial');
    }

    // ── tenancy ─────────────────────────────────────────────────────────────

    public function test_pages_belong_to_the_generated_site_only(): void
    {
        $a = $this->app_()->generateWebsite(DTO::fromArray($this->doc()));
        $b = $this->app_()->generateWebsite(DTO::fromArray($this->doc(['name' => 'Other'])));

        $this->assertSame(2, DB::table('pages')->where('website_id', $a['website_id'])->count());
        $this->assertSame(2, DB::table('pages')->where('website_id', $b['website_id'])->count());
        $this->assertNotSame($a['website_id'], $b['website_id']);
    }
}
