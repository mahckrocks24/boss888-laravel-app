<?php

namespace Tests\Feature\Sarah;

use App\Core\Orchestration\ProactiveStrategyEngine;
use App\Core\Strategy\WorkspaceStateGatherer;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A second website was built and Sarah said nothing about it.
 *
 * When the FIRST site went up she raised first_content and seo_audit proposals the next day. That generator
 * was later deleted, and everything that replaced it works on content that already exists — generate_meta,
 * insert_link, fix_orphans, improve_draft, refresh_stale, expand_thin_pages. All of them need something to
 * act ON, so a brand-new site with no articles matched nothing and the owner watched it sit there.
 *
 * Two things were missing and both are held here: she could not SEE the site (her state carried a count, not
 * a portfolio), and nothing could FIRE for it.
 */
class WebsiteLaunchProposalTest extends TestCase
{
    /** postAsAgent silently skips an unknown slug, so the agent has to exist for a chat post to land. */
    private function ensureSarahAgent(): void
    {
        if (! DB::table('agents')->where('slug', 'sarah')->exists()) {
            DB::table('agents')->insert([
                'slug' => 'sarah', 'name' => 'Sarah', 'role' => 'Digital Marketing Manager',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function workspace(): int
    {
        $this->ensureSarahAgent();

        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'launch-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('workspaces')->insertGetId([
            'name' => 'Business', 'slug' => 'launch-' . uniqid(), 'created_by' => $uid,
            'onboarded' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function website(int $ws, string $name, string $industry = 'marketing_agency'): int
    {
        return (int) DB::table('websites')->insertGetId([
            'workspace_id' => $ws, 'name' => $name, 'status' => 'published', 'type' => 'template',
            'template_industry' => $industry, 'subdomain' => strtolower(uniqid()) . '.levelupgrowth.io',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function article(int $ws, int $websiteId): void
    {
        DB::table('articles')->insert([
            'workspace_id' => $ws, 'website_id' => $websiteId, 'title' => 'A post ' . uniqid(),
            'slug' => 'p-' . uniqid(), 'content' => '<p>body</p>', 'status' => 'published',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /*──────────────────────────────── she can see the site */

    public function test_her_state_names_the_websites_instead_of_counting_them(): void
    {
        $ws = $this->workspace();
        $site = $this->website($ws, 'Miyguel Graphic Designs');

        $state = app(WorkspaceStateGatherer::class)->gather($ws);
        $builder = $state['builder'] ?? [];

        $this->assertArrayHasKey('websites', $builder, 'a count is not a portfolio');
        $this->assertSame('Miyguel Graphic Designs', $builder['websites'][0]['name']);
        $this->assertSame($site, $builder['websites'][0]['website_id']);
        $this->assertFalse($builder['websites'][0]['has_content'], 'and she can see it has nothing on it');
        $this->assertCount(1, $builder['websites_without_content']);
    }

    /** A site being worked on is not a site needing a launch. */
    public function test_a_website_with_content_is_not_listed_as_needing_a_launch(): void
    {
        $ws = $this->workspace();
        $site = $this->website($ws, 'Established Site');
        $this->article($ws, $site);

        $builder = app(WorkspaceStateGatherer::class)->gather($ws)['builder'];

        $this->assertTrue($builder['websites'][0]['has_content']);
        $this->assertSame([], $builder['websites_without_content']);
    }

    /*──────────────────────────────── something fires for it */

    public function test_a_website_with_no_content_gets_a_launch_plan_proposed(): void
    {
        $ws = $this->workspace();
        $site = $this->website($ws, 'Miyguel Graphic Designs');

        $raised = app(ProactiveStrategyEngine::class)->newWebsiteCheck($ws);

        $this->assertCount(1, $raised);
        $this->assertSame($site, $raised[0]['website_id']);

        $p = DB::table('strategy_proposals')->where('id', $raised[0]['proposal_id'])->first();
        $this->assertSame(ProactiveStrategyEngine::TYPE_WEBSITE_LAUNCH, $p->type);
        $this->assertSame('website', $p->entity_type);
        $this->assertSame($site, (int) $p->entity_id, 'the proposal names the website it is for');
        $this->assertSame('pending_approval', $p->status);
        $this->assertGreaterThan(0, (int) $p->total_credits, 'a launch spends credits, so the owner decides');
    }

    /** She says it in the chat, naming the site — not only in a table the owner may never open. */
    public function test_she_raises_it_in_the_conversation_and_names_the_site(): void
    {
        $ws = $this->workspace();
        $this->website($ws, 'Miyguel Graphic Designs');

        app(ProactiveStrategyEngine::class)->newWebsiteCheck($ws);

        $said = DB::table('agent_messages')->where('workspace_id', $ws)
            ->where('role', 'agent')->orderByDesc('id')->value('content');

        $this->assertNotNull($said);
        $this->assertStringContainsString('Miyguel Graphic Designs', $said);
        $this->assertStringNotContainsString('marketing_agency', $said,
            'a template slug must never be shown to a person');
        $this->assertStringContainsString('marketing agency', $said);
    }

    /** Raised once. A launch the owner ignored is not a reason to ask again every day. */
    public function test_the_proposal_is_raised_only_once_per_website(): void
    {
        $ws = $this->workspace();
        $this->website($ws, 'Miyguel Graphic Designs');
        $engine = app(ProactiveStrategyEngine::class);

        $this->assertCount(1, $engine->newWebsiteCheck($ws));
        $this->assertCount(0, $engine->newWebsiteCheck($ws), 'nagging is not proactivity');
        $this->assertSame(1, DB::table('strategy_proposals')->where('workspace_id', $ws)
            ->where('type', ProactiveStrategyEngine::TYPE_WEBSITE_LAUNCH)->count());
    }

    /** Nothing fires for a site that is already being worked on. */
    public function test_no_launch_plan_for_a_website_that_already_has_content(): void
    {
        $ws = $this->workspace();
        $site = $this->website($ws, 'Established Site');
        $this->article($ws, $site);

        $this->assertCount(0, app(ProactiveStrategyEngine::class)->newWebsiteCheck($ws));
    }

    /** Each website in a business gets its own launch, because each is its own business problem. */
    public function test_each_empty_website_gets_its_own_plan(): void
    {
        $ws = $this->workspace();
        $withContent = $this->website($ws, 'Established Site');
        $this->article($ws, $withContent);
        $this->website($ws, 'Second Site');
        $this->website($ws, 'Third Site');

        $raised = app(ProactiveStrategyEngine::class)->newWebsiteCheck($ws);

        $this->assertCount(2, $raised);
        $this->assertNotContains($withContent, array_column($raised, 'website_id'));
    }
}
