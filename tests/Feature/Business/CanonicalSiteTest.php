<?php

namespace Tests\Feature\Business;

use App\Core\Business\CanonicalSite;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * K1 (2026-09-25): an article's structured data must name the customer's own
 * site. The defect this pins: WriteService read a workspace-wide
 * seo_settings.site_url and fell back to 'https://levelupgrowth.io', so 174 of
 * 202 article JSON-LD blobs named the platform, 11 named staging and 8 named
 * 127.0.0.1.
 *
 * The rule: identity comes from the website row, or no host is published.
 */
class CanonicalSiteTest extends TestCase
{
    private int $ws;
    private int $wsOther;
    private int $user;
    private array $sites = [];
    private array $articles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = (int) DB::table('users')->insertGetId([
            'name' => 'K1 Canonical',
            'email' => 'k1-' . uniqid() . '@example.test',
            'password' => password_hash('K1-Canonical-Password-1', PASSWORD_BCRYPT),
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->ws = $this->makeWorkspace('K1 Primary');
        $this->wsOther = $this->makeWorkspace('K1 Foreign');
    }

    protected function tearDown(): void
    {
        if ($this->articles) {
            DB::table('articles')->whereIn('id', $this->articles)->delete();
        }
        if ($this->sites) {
            DB::table('websites')->whereIn('id', $this->sites)->delete();
        }
        DB::table('businesses')->whereIn('workspace_id', [$this->ws, $this->wsOther])->delete();
        DB::table('workspaces')->whereIn('id', [$this->ws, $this->wsOther])->delete();
        DB::table('users')->where('id', $this->user)->delete();
        parent::tearDown();
    }

    private function makeWorkspace(string $name): int
    {
        return (int) DB::table('workspaces')->insertGetId([
            'name' => $name, 'slug' => 'k1-' . uniqid(), 'timezone' => 'UTC',
            'business_name' => $name, 'created_by' => $this->user,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeSite(int $ws, array $attrs = []): int
    {
        $id = (int) DB::table('websites')->insertGetId(array_merge([
            'workspace_id' => $ws, 'name' => 'K1 Site', 'status' => 'published',
            'domain_verified' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
        $this->sites[] = $id;

        return $id;
    }

    private function makeArticle(int $ws, ?int $siteId): int
    {
        $id = (int) DB::table('articles')->insertGetId([
            'workspace_id' => $ws, 'website_id' => $siteId, 'title' => 'K1 article',
            'slug' => 'k1-' . uniqid(), 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->articles[] = $id;

        return $id;
    }

    private function resolver(): CanonicalSite
    {
        return new CanonicalSite();
    }

    // ---- host precedence -------------------------------------------------

    public function test_verified_custom_domain_wins_over_the_subdomain(): void
    {
        $site = $this->makeSite($this->ws, [
            'subdomain' => 'amg.levelupgrowth.io',
            'custom_domain' => 'amgtravelandtours.com',
            'domain_verified' => 1,
        ]);

        $this->assertSame('amgtravelandtours.com', $this->resolver()->forWebsite($site)['host']);
    }

    public function test_unverified_custom_domain_is_ignored(): void
    {
        $site = $this->makeSite($this->ws, [
            'subdomain' => 'pending.levelupgrowth.io',
            'custom_domain' => 'not-yet-pointed.com',
            'domain_verified' => 0,
        ]);

        $this->assertSame('pending.levelupgrowth.io', $this->resolver()->forWebsite($site)['host']);
    }

    public function test_domain_column_is_used_when_there_is_no_custom_domain(): void
    {
        $site = $this->makeSite($this->ws, ['domain' => 'cheflisted.com']);

        $this->assertSame('cheflisted.com', $this->resolver()->forWebsite($site)['host']);
    }

    public function test_lug_subdomain_is_a_valid_identity(): void
    {
        $site = $this->makeSite($this->ws, ['subdomain' => 'sgtravel.levelupgrowth.io']);

        $identity = $this->resolver()->forWebsite($site);
        $this->assertSame('sgtravel.levelupgrowth.io', $identity['host']);
        $this->assertSame('https://sgtravel.levelupgrowth.io', $identity['url']);
    }

    public function test_a_website_with_no_usable_host_resolves_to_nothing(): void
    {
        $site = $this->makeSite($this->ws, ['subdomain' => '', 'domain' => '', 'custom_domain' => '']);

        $this->assertNull($this->resolver()->forWebsite($site));
    }

    // ---- host hygiene ----------------------------------------------------

    /** @dataProvider impossibleHosts */
    public function test_impossible_hosts_are_refused(string $value, string $why): void
    {
        $this->assertNull(CanonicalSite::normaliseHost($value), $why);
    }

    public static function impossibleHosts(): array
    {
        return [
            ['127.0.0.1', 'the loopback address reached 8 stored blobs'],
            ['localhost', 'never a public identity'],
            ['::1', 'IPv6 loopback'],
            ['staging.levelupgrowth.io', 'staging reached 11 stored blobs'],
            ['b4-bakery-ot3.levelupgrowth.io.levelupgrowth.io', 'the doubled-suffix concatenation bug'],
            ['', 'empty'],
            ['   ', 'blank'],
            ['nodot', 'not a hostname'],
            ['203.0.113.7', 'a bare IP is not an identity'],
        ];
    }

    public function test_scheme_port_and_path_are_stripped(): void
    {
        $this->assertSame('example.com', CanonicalSite::normaliseHost('https://example.com/blog/post'));
        $this->assertSame('example.com', CanonicalSite::normaliseHost('http://example.com:8443'));
        $this->assertSame('example.com', CanonicalSite::normaliseHost('EXAMPLE.com/'));
        $this->assertSame('example.com', CanonicalSite::normaliseHost('example.com.'));
    }

    // ---- article resolution ---------------------------------------------

    public function test_article_resolves_its_own_site_in_a_multi_site_workspace(): void
    {
        $mine = $this->makeSite($this->ws, ['subdomain' => 'mine.levelupgrowth.io', 'name' => 'Mine']);
        $this->makeSite($this->ws, ['subdomain' => 'sibling.levelupgrowth.io', 'name' => 'Sibling']);
        $article = $this->makeArticle($this->ws, $mine);

        $identity = $this->resolver()->forArticle($article);
        $this->assertSame('mine.levelupgrowth.io', $identity['host'], 'an article must never borrow a sibling site identity');
        $this->assertFalse($identity['ambiguous']);
    }

    public function test_article_without_a_website_uses_the_only_published_site(): void
    {
        $only = $this->makeSite($this->ws, ['subdomain' => 'only.levelupgrowth.io']);
        $this->makeSite($this->ws, ['subdomain' => 'draft.levelupgrowth.io', 'status' => 'draft']);
        $article = $this->makeArticle($this->ws, null);

        $identity = $this->resolver()->forArticle($article);
        $this->assertSame('only.levelupgrowth.io', $identity['host']);
        $this->assertSame($only, $identity['website_id']);
    }

    public function test_article_without_a_website_is_ambiguous_when_several_are_published(): void
    {
        $this->makeSite($this->ws, ['subdomain' => 'one.levelupgrowth.io']);
        $this->makeSite($this->ws, ['subdomain' => 'two.levelupgrowth.io']);
        $article = $this->makeArticle($this->ws, null);

        $identity = $this->resolver()->forArticle($article);
        $this->assertNull($identity['host'], 'guessing between two sites would publish one site identity on another site article');
        $this->assertTrue($identity['ambiguous']);
        $this->assertSame('workspace_has_several_published_websites', $identity['reason']);
    }

    public function test_the_platform_is_never_substituted_for_a_failure(): void
    {
        $article = $this->makeArticle($this->ws, null);

        $identity = $this->resolver()->forArticle($article);
        $this->assertNull($identity['url'], 'the old code published https://levelupgrowth.io here');
        $this->assertSame('workspace_has_no_published_website', $identity['reason']);
    }

    public function test_tenancy_an_article_never_resolves_a_foreign_workspace_site(): void
    {
        $foreign = $this->makeSite($this->wsOther, ['subdomain' => 'foreign.levelupgrowth.io']);
        $article = $this->makeArticle($this->ws, null);

        $identity = $this->resolver()->forArticle($article);
        $this->assertNotSame($foreign, $identity['website_id']);
        $this->assertNull($identity['host']);
    }

    public function test_connector_workspace_uses_its_configured_wordpress_address(): void
    {
        // ws 7 (shukranuae.com) has no website rows at all — its site is in
        // WordPress and seo_settings.site_url is the customer's own evidence.
        DB::table('seo_settings')->insert([
            'workspace_id' => $this->ws, 'website_id' => 0, 'key' => 'site_url',
            'value' => 'https://shukranuae.example', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $article = $this->makeArticle($this->ws, null);

        $identity = $this->resolver()->forArticle($article);
        $this->assertSame('shukranuae.example', $identity['host']);
        $this->assertSame('connector_workspace_site_url', $identity['reason']);

        DB::table('seo_settings')->where('workspace_id', $this->ws)->delete();
    }

    public function test_a_corrupt_connector_site_url_publishes_nothing(): void
    {
        // 20 of 23 live site_url rows carry a doubled suffix; one is 127.0.0.1:8093.
        DB::table('seo_settings')->insert([
            'workspace_id' => $this->ws, 'website_id' => 0, 'key' => 'site_url',
            'value' => 'https://qa-cat-566.levelupgrowth.io.levelupgrowth.io', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $article = $this->makeArticle($this->ws, null);

        $this->assertNull($this->resolver()->forArticle($article)['host']);

        DB::table('seo_settings')->where('workspace_id', $this->ws)->delete();
    }

    public function test_connector_fallback_never_overrides_a_real_website(): void
    {
        $site = $this->makeSite($this->ws, ['subdomain' => 'real.levelupgrowth.io']);
        DB::table('seo_settings')->insert([
            'workspace_id' => $this->ws, 'website_id' => 0, 'key' => 'site_url',
            'value' => 'https://something-else.example', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $article = $this->makeArticle($this->ws, $site);

        $this->assertSame('real.levelupgrowth.io', $this->resolver()->forArticle($article)['host']);

        DB::table('seo_settings')->where('workspace_id', $this->ws)->delete();
    }

    public function test_a_draft_site_is_not_treated_as_published_identity(): void
    {
        $this->makeSite($this->ws, ['subdomain' => 'draftonly.levelupgrowth.io', 'status' => 'draft']);
        $article = $this->makeArticle($this->ws, null);

        $identity = $this->resolver()->forArticle($article);
        $this->assertNull($identity['host'], 'a draft has no profile and no public identity (EV-1097)');
    }
}
