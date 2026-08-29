<?php

namespace Tests\Feature\Chatbot888;

use App\Engines\Chatbot\Services\ChatbotKnowledgeService;
use App\Engines\Chatbot\Services\ChatbotWidgetTokenService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RISK-0118 (2026-08-29) — CHATBOT888 is website-scoped BY DEFAULT (Sarah888 stays workspace-wide).
 *
 * Two websites in one workspace: a visitor on Website A must never receive Website B's knowledge,
 * and a token minted for one host must accept the tenant's own hosts (custom domain, www, subdomain)
 * while refusing everyone else. Uses throwaway rows in a throwaway workspace id; cleans up.
 */
class WebsiteScopeIsolationTest extends TestCase
{
    private const WS = 999999902;
    private int $siteA = 0;
    private int $siteB = 0;
    private array $sourceIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('websites')->where('workspace_id', self::WS)->delete();
        DB::table('workspaces')->where('id', self::WS)->delete();
        DB::table('users')->where('email', 'iso-test-owner@example.test')->delete();
        $uid = (int) DB::table('users')->insertGetId(['name' => 'Iso Owner', 'email' => 'iso-test-owner@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspaces')->insert(['id' => self::WS, 'name' => 'Iso Test WS', 'slug' => 'iso-test-ws-' . self::WS, 'created_by' => $uid, 'created_at' => now(), 'updated_at' => now()]);
        $this->siteA = (int) DB::table('websites')->insertGetId(['workspace_id' => self::WS, 'name' => 'Iso Site A', 'subdomain' => 'iso-site-a.levelupgrowth.io', 'custom_domain' => 'iso-a.example', 'type' => 'template', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $this->siteB = (int) DB::table('websites')->insertGetId(['workspace_id' => self::WS, 'name' => 'Iso Site B', 'subdomain' => 'iso-site-b.levelupgrowth.io', 'type' => 'template', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $kb = app(ChatbotKnowledgeService::class);
        $this->sourceIds[] = $kb->ingestText(self::WS, 'A facts', 'The secret word for Site A is ALPHA. Site A opens at 6am.', $this->siteA);
        $this->sourceIds[] = $kb->ingestText(self::WS, 'B facts', 'The secret word for Site B is BRAVO. Site B opens at 8am.', $this->siteB);
    }

    protected function tearDown(): void
    {
        DB::table('chatbot_knowledge_chunks')->where('workspace_id', self::WS)->delete();
        DB::table('chatbot_knowledge_sources')->where('workspace_id', self::WS)->delete();
        DB::table('chatbot_widget_tokens')->where('workspace_id', self::WS)->delete();
        DB::table('websites')->where('workspace_id', self::WS)->delete();
        DB::table('workspaces')->where('id', self::WS)->delete();
        DB::table('users')->where('email', 'iso-test-owner@example.test')->delete();
        parent::tearDown();
    }

    public function test_retrieval_is_strictly_per_website(): void
    {
        $kb = app(ChatbotKnowledgeService::class);
        $a = implode(' ', array_column($kb->retrieveChunks(self::WS, 'secret word', 5, $this->siteA), 'chunk_text'));
        $b = implode(' ', array_column($kb->retrieveChunks(self::WS, 'secret word', 5, $this->siteB), 'chunk_text'));
        $this->assertStringContainsString('ALPHA', $a);
        $this->assertStringNotContainsString('BRAVO', $a, 'Site A visitor must never see Site B knowledge');
        $this->assertStringContainsString('BRAVO', $b);
        $this->assertStringNotContainsString('ALPHA', $b, 'Site B visitor must never see Site A knowledge');
    }

    public function test_workspace_level_chunks_do_not_bleed_into_a_scoped_site(): void
    {
        $kb = app(ChatbotKnowledgeService::class);
        $this->sourceIds[] = $kb->ingestText(self::WS, 'Unassigned', 'The secret word for nobody is ZULU.', null);
        $a = implode(' ', array_column($kb->retrieveChunks(self::WS, 'secret word', 5, $this->siteA), 'chunk_text'));
        $this->assertStringNotContainsString('ZULU', $a, 'an unassigned (workspace-level) source is not shared by accident');
    }

    public function test_origin_check_accepts_tenant_hosts_and_refuses_others(): void
    {
        $svc = app(ChatbotWidgetTokenService::class);
        $minted = $svc->mint(self::WS, null, null, ['iso-site-a.levelupgrowth.io'], 'Test');
        $row = DB::table('chatbot_widget_tokens')->where('workspace_id', self::WS)->orderByDesc('id')->first();

        $this->assertTrue($svc->originAllowed($row, 'https://iso-site-a.levelupgrowth.io'), 'listed host');
        $this->assertTrue($svc->originAllowed($row, null, 'https://iso-site-a.levelupgrowth.io/page'), 'Referer fallback (same-origin GET has no Origin)');
        $this->assertTrue($svc->originAllowed($row, 'https://iso-a.example'), 'the tenant custom domain, even though the token predates it');
        $this->assertTrue($svc->originAllowed($row, 'https://www.iso-a.example'), 'www variant');
        $this->assertTrue($svc->originAllowed($row, 'https://iso-site-b.levelupgrowth.io'), 'a sibling site of the same workspace (workspace token)');
        $this->assertFalse($svc->originAllowed($row, 'https://evil.example'), 'foreign host refused');
        $this->assertFalse($svc->originAllowed($row, null, null), 'no Origin and no Referer refused');
    }

    public function test_website_bound_token_accepts_only_its_own_site(): void
    {
        $svc = app(ChatbotWidgetTokenService::class);
        $svc->mint(self::WS, null, $this->siteA, ['iso-site-a.levelupgrowth.io'], 'Site A only');
        $row = DB::table('chatbot_widget_tokens')->where('workspace_id', self::WS)->orderByDesc('id')->first();
        $this->assertTrue($svc->originAllowed($row, 'https://iso-a.example'));
        $this->assertFalse($svc->originAllowed($row, 'https://iso-site-b.levelupgrowth.io'), 'a website-bound token must not serve the sibling site');
    }
}
