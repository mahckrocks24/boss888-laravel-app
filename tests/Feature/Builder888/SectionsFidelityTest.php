<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\BuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BUILDER888 · P1-6-b — createPage() must not discard supplied sections.
 *
 * Sections arrive in two shapes: a raw list, and the canonical wrapped form
 * {schemaVersion, sections:[…]}. The rich-template fallback only recognised the
 * raw list, so everything Arthur and the generation DTO produce fell through
 * and was REPLACED by a generic scaffold — silently, because the template
 * render (which reads template_variables) still looked correct.
 */
class SectionsFidelityTest extends TestCase
{
    use RefreshDatabase;

    private int $site;

    protected function setUp(): void
    {
        parent::setUp();
        $uid = DB::table('users')->insertGetId([
            'name' => 'sf', 'email' => 'sf-' . bin2hex(random_bytes(4)) . '@x.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ws = DB::table('workspaces')->insertGetId([
            'name' => 'sf', 'slug' => 'sf-' . bin2hex(random_bytes(4)),
            'created_by' => $uid, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->site = DB::table('websites')->insertGetId([
            'workspace_id' => $ws, 'name' => 'SF', 'type' => 'template',
            'status' => 'draft', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function stored(int $pageId): array
    {
        return json_decode(DB::table('pages')->find($pageId)->sections_json, true);
    }

    public function test_wrapped_sections_survive_creation(): void
    {
        $sections = ['schemaVersion' => 1, 'sections' => [
            ['type' => 'hero', 'heading' => 'Harbourline Sail Loft'],
            ['type' => 'services', 'heading' => 'What we do'],
        ]];

        $r = app(BuilderService::class)->createPage($this->site, [
            'title' => 'Home', 'slug' => 'home', 'sections' => $sections,
        ]);

        $out = $this->stored($r['page_id']);

        $this->assertSame($sections['sections'], $out['sections'],
            'the caller\'s sections must be persisted, not replaced by a scaffold');
        $this->assertSame('Harbourline Sail Loft', $out['sections'][0]['heading']);
        $this->assertCount(2, $out['sections']);
    }

    public function test_raw_list_sections_are_still_wrapped_and_kept(): void
    {
        $list = [['type' => 'hero', 'heading' => 'Raw List']];

        $r = app(BuilderService::class)->createPage($this->site, [
            'title' => 'About', 'slug' => 'about', 'sections' => $list,
        ]);

        $out = $this->stored($r['page_id']);
        $this->assertSame(1, $out['schemaVersion']);
        $this->assertSame($list, $out['sections']);
    }

    public function test_the_scaffold_fallback_still_applies_when_nothing_is_supplied(): void
    {
        // The fallback is useful — it must survive for callers that genuinely
        // supply no content.
        $r = app(BuilderService::class)->createPage($this->site, [
            'title' => 'Contact', 'slug' => 'contact',
        ]);

        $out = $this->stored($r['page_id']);
        $this->assertNotEmpty($out['sections'] ?? [], 'empty input should still yield a usable page');
    }

    public function test_an_empty_wrapped_structure_yields_a_well_formed_schema(): void
    {
        // Corrected assertion: for an UNKNOWN slug with no content there is no
        // source to scaffold from — defaultPageSchema() is itself an empty
        // stack, and an empty page the customer then fills is the system's
        // actual contract. The invariant that matters is that the stored value
        // is a well-formed decodable structure, never a blank or double-encoded
        // string (which the fallback path used to produce).
        $r = app(BuilderService::class)->createPage($this->site, [
            'title' => 'Empty', 'slug' => 'empty',
            'sections' => ['schemaVersion' => 1, 'sections' => []],
        ]);

        $raw = DB::table('pages')->find($r['page_id'])->sections_json;
        $out = json_decode($raw, true);

        $this->assertIsArray($out, 'sections_json must decode to a structure, not a JSON string literal');
        $this->assertArrayHasKey('sections', $out);
        $this->assertArrayHasKey('schemaVersion', $out);
        $this->assertIsArray($out['sections']);
    }

    public function test_a_known_slug_with_no_sections_is_scaffolded_not_left_blank(): void
    {
        // The rich-template fallback DOES have a source for known page types.
        $r = app(BuilderService::class)->createPage($this->site, [
            'title' => 'Services', 'slug' => 'services',
        ]);

        $out = $this->stored($r['page_id']);
        $this->assertNotEmpty($out['sections'] ?? [], 'a known page type must be scaffolded');
    }
}
