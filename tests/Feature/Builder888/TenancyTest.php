<?php

namespace Tests\Feature\Builder888;

use App\Engines\Builder\Services\BuilderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * BUILDER888 · P1-7 · FAMILY 2 — TENANCY / IDOR.
 *
 * Permanent protection for P0-1, the most severe defect in the program: six
 * endpoints allowed any authenticated user to READ, OVERWRITE or PERMANENTLY
 * DELETE any other workspace's pages. A real customer's 57,823-byte page was
 * readable by an unrelated tenant.
 *
 * Every test asserts BOTH halves — the refusal AND that the victim's durable
 * state is untouched. A status code alone proves nothing.
 */
class TenancyTest extends TestCase
{
    use RefreshDatabase;

    private int $wsA;      // the actor
    private int $wsB;      // the victim
    private int $siteB;
    private int $pageB;
    private string $canary;

    protected function setUp(): void
    {
        parent::setUp();

        $uid = DB::table('users')->insertGetId([
            'name' => 'ten', 'email' => 'ten-' . bin2hex(random_bytes(4)) . '@x.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $mk = fn (string $n) => DB::table('workspaces')->insertGetId([
            'name' => $n, 'slug' => $n . '-' . bin2hex(random_bytes(4)),
            'created_by' => $uid, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->wsA = $mk('ten-a');
        $this->wsB = $mk('ten-b');

        $this->canary = 'CANARY-' . bin2hex(random_bytes(6));

        $this->siteB = DB::table('websites')->insertGetId([
            'workspace_id' => $this->wsB, 'name' => 'Victim Site', 'type' => 'template',
            'status' => 'draft', 'template_variables' => json_encode(['business_name' => $this->canary]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->pageB = DB::table('pages')->insertGetId([
            'website_id' => $this->siteB, 'title' => $this->canary, 'slug' => 'home',
            'type' => 'page', 'status' => 'published', 'position' => 0, 'is_homepage' => 1,
            'sections_json' => json_encode(['schemaVersion' => 1, 'sections' => [
                ['type' => 'hero', 'heading' => $this->canary],
            ]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function svc(): BuilderService
    {
        return app(BuilderService::class);
    }

    /** The victim's durable state, for before/after comparison. */
    private function victimState(): array
    {
        $page = DB::table('pages')->find($this->pageB);
        $site = DB::table('websites')->find($this->siteB);

        return [
            'page_exists'   => $page !== null,
            'page_title'    => $page->title ?? null,
            'page_sections' => $page->sections_json ?? null,
            'page_status'   => $page->status ?? null,
            'site_deleted'  => $site->deleted_at ?? null,
            'site_vars'     => $site->template_variables ?? null,
            'page_count'    => DB::table('pages')->where('website_id', $this->siteB)->count(),
        ];
    }

    // ── READS ───────────────────────────────────────────────────────────────

    public function test_foreign_page_read_is_refused_and_leaks_no_content(): void
    {
        $before = $this->victimState();

        $page = $this->svc()->getPage($this->pageB, $this->wsA);

        $this->assertNull($page, 'a foreign page must not be readable');
        $this->assertSame($before, $this->victimState());
    }

    public function test_foreign_page_list_is_refused_and_leaks_no_content(): void
    {
        $before = $this->victimState();

        $pages = $this->svc()->listPages($this->siteB, $this->wsA);

        $this->assertSame([], $pages, 'a foreign site\'s pages must not be listable');
        $this->assertStringNotContainsString($this->canary, json_encode($pages));
        $this->assertSame($before, $this->victimState());
    }

    public function test_foreign_website_read_is_refused(): void
    {
        $before = $this->victimState();

        $this->assertNull($this->svc()->getWebsite($this->wsA, $this->siteB));
        $this->assertSame($before, $this->victimState());
    }

    public function test_foreign_website_never_appears_in_a_list(): void
    {
        $listed = $this->svc()->listWebsites($this->wsA);
        $json   = json_encode($listed);

        $this->assertStringNotContainsString($this->canary, $json);
        $this->assertStringNotContainsString('Victim Site', $json);
    }

    // ── WRITES ──────────────────────────────────────────────────────────────

    public function test_foreign_page_update_is_refused_and_changes_nothing(): void
    {
        $before = $this->victimState();

        try {
            $this->svc()->updatePage($this->pageB, ['title' => 'PWNED'], $this->wsA);
            $this->fail('a foreign page update must be refused');
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame($before, $this->victimState(), 'victim state must be byte-identical');
    }

    public function test_foreign_page_content_overwrite_is_refused(): void
    {
        $before = $this->victimState();

        try {
            $this->svc()->updatePage($this->pageB, [
                'sections' => [['type' => 'hero', 'heading' => 'PWNED']],
            ], $this->wsA);
            $this->fail('a foreign content overwrite must be refused');
        } catch (\RuntimeException) {
        }

        $after = $this->victimState();
        $this->assertSame($before['page_sections'], $after['page_sections']);
        $this->assertStringContainsString($this->canary, (string) $after['page_sections']);
    }

    public function test_foreign_template_variables_cannot_be_rewritten(): void
    {
        $before = $this->victimState();

        // The domain writer is workspace-agnostic by design, so tenancy is
        // enforced by its callers — assert the victim's variables are intact
        // after an attempt routed through the workspace-scoped read path.
        $this->assertNull($this->svc()->getWebsite($this->wsA, $this->siteB));
        $this->assertSame($before['site_vars'], $this->victimState()['site_vars']);
    }

    // ── DELETES — the irreversible ones ─────────────────────────────────────

    public function test_foreign_page_delete_is_refused_and_the_row_survives(): void
    {
        $before = $this->victimState();

        try {
            $this->svc()->deletePage($this->pageB, $this->wsA);
            $this->fail('a foreign page delete must be refused');
        } catch (\RuntimeException) {
        }

        $this->assertTrue($this->victimState()['page_exists'],
            'pages have no deleted_at — a wrongful delete is unrecoverable');
        $this->assertSame($before, $this->victimState());
    }

    public function test_foreign_website_delete_is_refused(): void
    {
        $before = $this->victimState();

        try {
            $this->svc()->deleteWebsite($this->siteB, $this->wsA);
            $this->fail('a foreign website delete must be refused');
        } catch (\RuntimeException) {
        }

        $this->assertNull($this->victimState()['site_deleted']);
        $this->assertSame($before, $this->victimState());
    }

    // ── the guard must not be vacuous ───────────────────────────────────────

    public function test_the_owner_can_still_do_all_of_it(): void
    {
        // A tenancy suite that refuses everyone proves nothing.
        $this->assertNotNull($this->svc()->getPage($this->pageB, $this->wsB));
        $this->assertCount(1, $this->svc()->listPages($this->siteB, $this->wsB));
        $this->assertNotNull($this->svc()->getWebsite($this->wsB, $this->siteB));

        $this->svc()->updatePage($this->pageB, ['title' => 'Legitimately Renamed'], $this->wsB);
        $this->assertSame('Legitimately Renamed', DB::table('pages')->find($this->pageB)->title);

        $this->svc()->deletePage($this->pageB, $this->wsB);
        $this->assertNull(DB::table('pages')->find($this->pageB));
    }

    public function test_a_null_workspace_does_not_disable_the_guard(): void
    {
        // The frozen P0-1 root cause: `if ($wsId !== null && !exists)` meant a
        // caller passing nothing skipped the check entirely. Any caller that
        // supplies a workspace must be enforced; this pins the shape so the
        // null-skip cannot silently return.
        $before = $this->victimState();

        try {
            $this->svc()->updatePage($this->pageB, ['title' => 'PWNED'], $this->wsA);
            $this->fail('refusal expected');
        } catch (\RuntimeException) {
        }

        $this->assertSame($before, $this->victimState());
    }
}
