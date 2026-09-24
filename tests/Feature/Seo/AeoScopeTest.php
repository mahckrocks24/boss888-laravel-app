<?php

namespace Tests\Feature\Seo;

use App\Core\Business\CanonicalSite;
use App\Engines\SEO\Services\AeoSettingsService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * K6 (2026-09-25): where the machine-facing configuration is scoped, and why.
 *
 * The reconciliation, on measured evidence rather than symmetry:
 *
 *   PER WEBSITE — the content index (llms.txt) and the canonical host that
 *   every URL in it and the robots.txt Sitemap line are built from. A site's
 *   contents and its address are properties of that site. K2 moved llms.txt;
 *   robots.txt already derived its Sitemap host from the website.
 *
 *   PER WORKSPACE — the AI-crawler allow list. Whether GPTBot, ClaudeBot,
 *   PerplexityBot or CCBot may read the customer's material is a stance the
 *   customer takes, not an attribute of one site. All seven multi-site
 *   workspaces hold one identical, permissive policy; none has ever diverged.
 *   aeo_settings is keyed on workspace_id alone, and re-keying a live table to
 *   support a divergence nobody has asked for would add duplication and
 *   migration risk for no gain.
 *
 * These tests pin that split so a later change cannot quietly make robots.txt
 * cross-advertise, nor fork the crawler stance per site by accident.
 */
class AeoScopeTest extends TestCase
{
    private int $user;
    private int $ws;
    private array $sites = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = (int) DB::table('users')->insertGetId([
            'name' => 'K6 scope', 'email' => 'k6-' . uniqid() . '@example.test',
            'password' => password_hash('K6-Scope-Password-1', PASSWORD_BCRYPT),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'K6 Workspace', 'slug' => 'k6-' . uniqid(), 'timezone' => 'UTC',
            'created_by' => $this->user, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->sites) {
            DB::table('seo_settings')->whereIn('website_id', $this->sites)->delete();
            DB::table('websites')->whereIn('id', $this->sites)->delete();
        }
        DB::table('aeo_settings')->where('workspace_id', $this->ws)->delete();
        DB::table('businesses')->where('workspace_id', $this->ws)->delete();
        DB::table('workspaces')->where('id', $this->ws)->delete();
        DB::table('users')->where('id', $this->user)->delete();
        parent::tearDown();
    }

    private function makeSite(array $attrs): int
    {
        $id = (int) DB::table('websites')->insertGetId(array_merge([
            'workspace_id' => $this->ws, 'name' => 'K6 Site', 'status' => 'published',
            'domain_verified' => 0, 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
        $this->sites[] = $id;

        return $id;
    }

    /** The Sitemap line must name the site being crawled, never a sibling. */
    public function test_each_site_advertises_its_own_sitemap_host(): void
    {
        $plain = $this->makeSite(['subdomain' => 'k6plain.levelupgrowth.io']);
        $custom = $this->makeSite([
            'subdomain' => 'k6internal.levelupgrowth.io',
            'custom_domain' => 'k6custom-example.com', 'domain_verified' => 1,
        ]);

        $resolver = new CanonicalSite();

        $this->assertSame('k6plain.levelupgrowth.io', $resolver->forWebsite($plain)['host']);
        $this->assertSame(
            'k6custom-example.com',
            $resolver->forWebsite($custom)['host'],
            'a verified custom domain is the site Google is crawling; its sitemap must live there'
        );
    }

    /**
     * Google ignores a Sitemap: directive that points at a different host than
     * the site being crawled, so a sibling's host in robots.txt silently costs
     * the customer their sitemap.
     */
    public function test_a_sibling_host_is_never_a_valid_sitemap_target(): void
    {
        $a = $this->makeSite(['subdomain' => 'k6a.levelupgrowth.io']);
        $b = $this->makeSite(['subdomain' => 'k6b.levelupgrowth.io']);

        $resolver = new CanonicalSite();
        $hostA = $resolver->forWebsite($a)['host'];
        $hostB = $resolver->forWebsite($b)['host'];

        $this->assertNotSame($hostA, $hostB);
        $this->assertStringNotContainsString($hostB, 'https://' . $hostA . '/sitemap.xml');
    }

    /** The crawler stance is the customer's, and it is one stance per workspace. */
    public function test_the_crawler_policy_is_workspace_level_and_shared(): void
    {
        $this->makeSite(['subdomain' => 'k6one.levelupgrowth.io']);
        $this->makeSite(['subdomain' => 'k6two.levelupgrowth.io']);

        $svc = app(AeoSettingsService::class);
        $svc->update($this->ws, ['allow_gptbot' => 0, 'allow_claudebot' => 1]);

        $first = $svc->renderRobotsTxt($this->ws, 'https://k6one.levelupgrowth.io/sitemap.xml');
        $second = $svc->renderRobotsTxt($this->ws, 'https://k6two.levelupgrowth.io/sitemap.xml');

        // Same stance, different sitemap line.
        $this->assertSame(
            preg_replace('/^Sitemap:.*$/m', '', $first),
            preg_replace('/^Sitemap:.*$/m', '', $second),
            'both sites of a workspace take the same position on AI crawlers'
        );
        $this->assertStringContainsString('k6one.levelupgrowth.io/sitemap.xml', $first);
        $this->assertStringContainsString('k6two.levelupgrowth.io/sitemap.xml', $second);
        $this->assertStringNotContainsString('k6two', $first, 'and neither names the other');
    }

    /** A refusal must actually be expressed to the crawler it refuses. */
    public function test_a_refused_crawler_is_disallowed_by_name(): void
    {
        $svc = app(AeoSettingsService::class);
        $svc->update($this->ws, ['allow_gptbot' => 0, 'allow_claudebot' => 1]);

        $body = $svc->renderRobotsTxt($this->ws, 'https://k6one.levelupgrowth.io/sitemap.xml');

        $this->assertMatchesRegularExpression('/User-agent: GPTBot\s*\nDisallow: \//', $body);
        $this->assertMatchesRegularExpression('/User-agent: ClaudeBot\s*\nAllow: \//', $body);
    }

    /** The content index is per site; the stance is not. */
    public function test_the_content_index_is_cached_against_the_website_not_the_workspace(): void
    {
        $a = $this->makeSite(['subdomain' => 'k6cachea.levelupgrowth.io']);
        $b = $this->makeSite(['subdomain' => 'k6cacheb.levelupgrowth.io']);

        $svc = app(AeoSettingsService::class);
        $svc->regenerateLlmsTxtForWebsite($a);
        $svc->regenerateLlmsTxtForWebsite($b);

        $rows = DB::table('seo_settings')->whereIn('website_id', [$a, $b])
            ->where('key', 'llms_txt_cache')->pluck('website_id')->all();

        sort($rows);
        $this->assertSame([min($a, $b), max($a, $b)], $rows, 'one cached body per website');
    }
}
