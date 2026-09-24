<?php

namespace Tests\Feature\Seo;

use App\Engines\SEO\Services\AeoSettingsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * K2 (2026-09-25): llms.txt belongs to the website being requested.
 *
 * It was workspace-scoped and picked "the primary published website" as
 * orderByDesc('id') — the newest — then served that index at every site in the
 * workspace. Measured 2026-09-24: 13 workspaces have more than one published
 * site; MR Systems advertised Revere's pages, and Aurelia (a restaurant) was
 * described as "Services in London" from a real-estate sibling. The host came
 * from domain ?: subdomain and never custom_domain, so all 3 custom-domain
 * sites advertised their internal *.levelupgrowth.io host.
 */
class LlmsTxtWebsiteScopeTest extends TestCase
{
    private int $user;
    private int $ws;
    private int $wsForeign;
    private array $sites = [];
    private array $businesses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = (int) DB::table('users')->insertGetId([
            'name' => 'K2 llms', 'email' => 'k2-' . uniqid() . '@example.test',
            'password' => password_hash('K2-Llms-Password-1', PASSWORD_BCRYPT),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ws = $this->makeWorkspace('K2 Workspace');
        $this->wsForeign = $this->makeWorkspace('K2 Foreign');
    }

    protected function tearDown(): void
    {
        if ($this->sites) {
            DB::table('pages')->whereIn('website_id', $this->sites)->delete();
            DB::table('seo_settings')->whereIn('website_id', $this->sites)->delete();
            DB::table('websites')->whereIn('id', $this->sites)->delete();
        }
        DB::table('businesses')->whereIn('workspace_id', [$this->ws, $this->wsForeign])->delete();
        DB::table('workspaces')->whereIn('id', [$this->ws, $this->wsForeign])->delete();
        DB::table('users')->where('id', $this->user)->delete();
        parent::tearDown();
    }

    private function makeWorkspace(string $name): int
    {
        return (int) DB::table('workspaces')->insertGetId([
            'name' => $name, 'slug' => 'k2-' . uniqid(), 'timezone' => 'UTC',
            'business_name' => $name, 'created_by' => $this->user,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeSite(int $ws, array $attrs, ?string $businessName = null, array $businessAttrs = []): int
    {
        $businessId = null;
        if ($businessName !== null) {
            $businessId = (int) DB::table('businesses')->insertGetId(array_merge([
                'workspace_id' => $ws, 'name' => $businessName,
                'slug' => 'k2biz-' . uniqid(), 'is_default' => 0, 'sort_order' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ], $businessAttrs));
            $this->businesses[] = $businessId;
        }

        $id = (int) DB::table('websites')->insertGetId(array_merge([
            'workspace_id' => $ws, 'name' => 'K2 Site', 'status' => 'published',
            'domain_verified' => 0, 'business_id' => $businessId,
            'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
        $this->sites[] = $id;

        return $id;
    }

    private function addPage(int $siteId, string $slug, string $title): void
    {
        DB::table('pages')->insert([
            'website_id' => $siteId, 'slug' => $slug, 'title' => $title,
            'status' => 'published', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function svc(): AeoSettingsService
    {
        return app(AeoSettingsService::class);
    }

    public function test_a_custom_domain_site_advertises_its_own_host(): void
    {
        $site = $this->makeSite($this->ws, [
            'name' => 'AMG', 'subdomain' => 'amg-k2.levelupgrowth.io',
            'custom_domain' => 'amg-k2-example.com', 'domain_verified' => 1,
        ], 'AMG Travel');
        $this->addPage($site, 'about', 'About');

        $body = $this->svc()->regenerateLlmsTxtForWebsite($site);

        $this->assertStringContainsString('https://amg-k2-example.com/about', $body);
        $this->assertStringNotContainsString('amg-k2.levelupgrowth.io', $body, 'the internal subdomain must not be advertised');
    }

    public function test_a_lug_subdomain_site_uses_its_subdomain(): void
    {
        $site = $this->makeSite($this->ws, ['name' => 'Plain', 'subdomain' => 'plain-k2.levelupgrowth.io'], 'Plain Co');
        $this->addPage($site, 'contact', 'Contact');

        $this->assertStringContainsString('https://plain-k2.levelupgrowth.io/contact', $this->svc()->regenerateLlmsTxtForWebsite($site));
    }

    public function test_each_site_in_a_multi_site_workspace_gets_its_own_index(): void
    {
        $a = $this->makeSite($this->ws, ['name' => 'Alpha', 'subdomain' => 'alpha-k2.levelupgrowth.io'], 'Alpha Bakery');
        $b = $this->makeSite($this->ws, ['name' => 'Beta', 'subdomain' => 'beta-k2.levelupgrowth.io'], 'Beta Dental');
        $this->addPage($a, 'menu', 'Menu');
        $this->addPage($b, 'treatments', 'Treatments');

        $bodyA = $this->svc()->regenerateLlmsTxtForWebsite($a);
        $bodyB = $this->svc()->regenerateLlmsTxtForWebsite($b);

        $this->assertStringStartsWith('# Alpha Bakery', $bodyA);
        $this->assertStringStartsWith('# Beta Dental', $bodyB);
        $this->assertStringContainsString('/menu', $bodyA);
        $this->assertStringNotContainsString('/treatments', $bodyA, "Alpha must not list Beta's pages");
        $this->assertStringNotContainsString('beta-k2.levelupgrowth.io', $bodyA, "Alpha must not advertise Beta's host");
        $this->assertStringNotContainsString('alpha-k2.levelupgrowth.io', $bodyB, "Beta must not advertise Alpha's host");
    }

    public function test_the_newest_sibling_no_longer_speaks_for_the_whole_workspace(): void
    {
        // The exact shape of the defect: MR Systems (older) vs Revere (newest).
        $older = $this->makeSite($this->ws, ['name' => 'Older', 'subdomain' => 'older-k2.levelupgrowth.io'], 'Older Systems');
        $this->addPage($older, 'case-studies', 'Case studies');
        $newest = $this->makeSite($this->ws, ['name' => 'Newest', 'subdomain' => 'newest-k2.levelupgrowth.io'], 'Newest Wellness');

        $body = $this->svc()->regenerateLlmsTxtForWebsite($older);

        $this->assertStringStartsWith('# Older Systems', $body);
        $this->assertStringContainsString('https://older-k2.levelupgrowth.io/case-studies', $body);
        $this->assertGreaterThan($older, $newest, "the sibling really is the newest by id");
        $this->assertStringNotContainsString('newest-k2', $body);
    }

    public function test_a_foreign_workspace_site_never_appears(): void
    {
        $mine = $this->makeSite($this->ws, ['name' => 'Mine', 'subdomain' => 'mine-k2.levelupgrowth.io'], 'Mine Ltd');
        $this->addPage($mine, 'home', 'Home');
        $theirs = $this->makeSite($this->wsForeign, ['name' => 'Theirs', 'subdomain' => 'theirs-k2.levelupgrowth.io'], 'Theirs Ltd');
        $this->addPage($theirs, 'secret', 'Secret');

        $body = $this->svc()->regenerateLlmsTxtForWebsite($mine);

        $this->assertStringNotContainsString('theirs-k2', $body);
        $this->assertStringNotContainsString('/secret', $body);
    }

    public function test_an_unpublished_website_yields_no_index(): void
    {
        $draft = $this->makeSite($this->ws, ['name' => 'Draft', 'subdomain' => 'draft-k2.levelupgrowth.io', 'status' => 'draft'], 'Draft Co');
        $this->addPage($draft, 'hidden', 'Hidden');

        // A draft still resolves a host, but it carries no profile in the
        // engines (EV-1097) and the public route never reaches it: the
        // middleware selects on status='published'.
        $served = DB::table('websites')->where('id', $draft)->where('status', 'published')->exists();
        $this->assertFalse($served, 'the public llms.txt route only selects published websites');
    }

    public function test_missing_optional_profile_information_still_yields_a_valid_index(): void
    {
        $site = $this->makeSite($this->ws, ['name' => 'Bare', 'subdomain' => 'bare-k2.levelupgrowth.io'], 'Bare Co');
        $this->addPage($site, 'about', 'About');

        $body = $this->svc()->regenerateLlmsTxtForWebsite($site);

        $this->assertStringStartsWith('# Bare Co', $body);
        $this->assertStringContainsString('https://bare-k2.levelupgrowth.io/about', $body);
        $this->assertStringContainsString('llmstxt.org', $body);
    }

    public function test_an_industry_slug_is_humanised_not_published_raw(): void
    {
        $site = $this->makeSite(
            $this->ws,
            ['name' => 'Adjang', 'subdomain' => 'adjang-k2.levelupgrowth.io'],
            'Adjang',
            ['industry' => 'restaurant_counter', 'location' => 'New Jersey']
        );

        $body = $this->svc()->regenerateLlmsTxtForWebsite($site);

        $this->assertStringNotContainsString('restaurant_counter', $body, 'a template slug is not a description');
        $this->assertStringContainsString('Restaurant counter in New Jersey', $body);
    }

    public function test_the_body_is_cached_per_website_and_regenerates(): void
    {
        $site = $this->makeSite($this->ws, ['name' => 'Cached', 'subdomain' => 'cached-k2.levelupgrowth.io'], 'Cached Co');
        $this->addPage($site, 'one', 'One');

        $first = $this->svc()->getLlmsTxtForWebsite($site);
        $this->assertStringContainsString('/one', $first);

        $cached = DB::table('seo_settings')->where('website_id', $site)->where('key', 'llms_txt_cache')->value('value');
        $this->assertNotEmpty($cached, 'the body must be cached against THIS website');

        $this->addPage($site, 'two', 'Two');
        $this->assertStringNotContainsString('/two', $this->svc()->getLlmsTxtForWebsite($site), 'a fresh cache is served, not rebuilt on every hit');
        $this->assertStringContainsString('/two', $this->svc()->regenerateLlmsTxtForWebsite($site), 'an explicit regeneration picks the new page up');
    }
}
