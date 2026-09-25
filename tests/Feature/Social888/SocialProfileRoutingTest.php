<?php

namespace Tests\Feature\Social888;

use App\Connectors\SocialConnector;
use App\Core\Distribution\ArticleDistributionService;
use App\Core\Publisher\ConnectionHealth;
use App\Engines\Social\Services\SocialAccountResolver;
use App\Engines\Social\Services\SocialService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SOCIAL-PROFILE-1 (2026-09-25): with several businesses in one workspace, the business a post is about
 * picks the Page. Never "the newest connected account".
 */
class SocialProfileRoutingTest extends TestCase
{
    private int $ws = 991401;      // two businesses: A (default) and B, plus C with no Page
    private int $single = 991402;  // one business, one unassigned Page (the pre-profile world)
    private int $uid = 0;
    private int $bizA; private int $bizB; private int $bizC; private int $bizS;
    private int $siteA; private int $siteB; private int $siteS;
    private int $pageA; private int $pageB; private int $pageS;
    private int $articleA;

    protected function setUp(): void
    {
        parent::setUp();
        config(['business.profiles' => true]);
        Cache::flush();
        $this->cleanup();
        $this->uid = (int) DB::table('users')->insertGetId(['name' => 'Profile Fixture', 'email' => 'profile-' . uniqid() . '@example.test', 'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now()]);
        foreach ([$this->ws, $this->single] as $ws) {
            DB::table('workspaces')->insert(['id' => $ws, 'name' => 'Profile WS ' . $ws, 'slug' => 'profile-' . $ws . '-' . uniqid(), 'created_by' => $this->uid, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->bizA = $this->business($this->ws, 'Chef Red', true);
        $this->bizB = $this->business($this->ws, 'Boss Mac Gym', false);
        $this->bizC = $this->business($this->ws, 'Copperleaf Joinery', false);
        $this->bizS = $this->business($this->single, 'Solo Bakery', true);
        $this->siteA = $this->website($this->ws, 'Chef Red Site', $this->bizA);
        $this->siteB = $this->website($this->ws, 'Gym Site', $this->bizB);
        $this->siteS = $this->website($this->single, 'Solo Site', null);
        $this->pageA = $this->account($this->ws, 'facebook', 'pg-a', 'Chef Red Page', $this->bizA);
        $this->pageB = $this->account($this->ws, 'facebook', 'pg-b', 'Gym Page', $this->bizB);
        $this->pageS = $this->account($this->single, 'facebook', 'pg-s', 'Solo Page', null);
        $this->articleA = (int) DB::table('articles')->insertGetId(['workspace_id' => $this->ws, 'website_id' => $this->siteA, 'title' => 'Chef Red news', 'status' => 'published', 'content' => '<p>Body</p>', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        foreach ([$this->ws, $this->single] as $ws) {
            DB::table('social_posts')->where('workspace_id', $ws)->delete();
            DB::table('social_accounts')->where('workspace_id', $ws)->delete();
            DB::table('articles')->where('workspace_id', $ws)->delete();
            DB::table('websites')->where('workspace_id', $ws)->delete();
            DB::table('businesses')->where('workspace_id', $ws)->delete();
            DB::table('workspaces')->where('id', $ws)->delete();
        }
        if ($this->uid) { DB::table('users')->where('id', $this->uid)->delete(); }
    }

    private function business(int $ws, string $name, bool $default): int
    {
        return (int) DB::table('businesses')->insertGetId(['workspace_id' => $ws, 'name' => $name, 'slug' => strtolower(str_replace(' ', '-', $name)) . '-' . uniqid(), 'is_default' => $default ? 1 : 0, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function website(int $ws, string $name, ?int $biz): int
    {
        return (int) DB::table('websites')->insertGetId(['workspace_id' => $ws, 'name' => $name, 'subdomain' => strtolower(str_replace(' ', '-', $name)) . '.levelupgrowth.io', 'business_id' => $biz, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function account(int $ws, string $platform, string $accountId, string $name, ?int $biz): int
    {
        $id = (int) DB::table('social_accounts')->insertGetId(['workspace_id' => $ws, 'platform' => $platform, 'account_id' => $accountId, 'account_name' => $name, 'business_id' => $biz, 'status' => 'connected', 'credentials_json' => json_encode(['_' => 'encrypted']), 'created_at' => now(), 'updated_at' => now()]);
        ConnectionHealth::storeCredentials($id, ['access_token' => 'TOKEN-' . $accountId, 'page_id' => $accountId]);

        return $id;
    }

    // ------------------------------------------------------------ resolver --

    public function test_the_business_picks_the_page_by_post_website_or_article(): void
    {
        $r = new SocialAccountResolver();

        $this->assertSame($this->pageB, (int) $r->resolve($this->ws, 'facebook', $this->bizB)['account']->id, 'explicit business');
        $this->assertSame($this->pageB, (int) $r->resolve($this->ws, 'facebook', null, $this->siteB)['account']->id, 'website → business');
        $this->assertSame($this->pageA, (int) $r->resolve($this->ws, 'facebook', null, null, $this->articleA)['account']->id, 'article → website → business');
        $this->assertSame($this->pageA, (int) $r->resolve($this->ws, 'facebook', $this->bizB, null, null, $this->pageA)['account']->id, 'an explicit account always wins');
    }

    public function test_two_pages_and_no_business_is_refused_with_the_names_not_the_newest(): void
    {
        $r = (new SocialAccountResolver())->resolve($this->ws, 'facebook');

        $this->assertFalse($r['ok']);
        $this->assertSame('AMBIGUOUS_BUSINESS', $r['reason']);
        $this->assertStringContainsString('Which business is this post for?', $r['message']);
        $this->assertSame(['Chef Red Page (Chef Red)', 'Gym Page (Boss Mac Gym)'], array_column($r['candidates'], 'label'));
    }

    public function test_a_business_without_a_page_is_named_not_guessed(): void
    {
        $r = (new SocialAccountResolver())->resolve($this->ws, 'facebook', $this->bizC);

        $this->assertFalse($r['ok']);
        $this->assertSame('NO_PAGE_FOR_BUSINESS', $r['reason']);
        $this->assertStringContainsString('Copperleaf Joinery has no Facebook Page connected', $r['message']);
    }

    public function test_a_single_unassigned_page_in_a_single_business_workspace_still_works(): void
    {
        $r = new SocialAccountResolver();
        $this->assertSame($this->pageS, (int) $r->resolve($this->single, 'facebook')['account']->id, 'no business known: the only Page');
        $this->assertSame($this->pageS, (int) $r->resolve($this->single, 'facebook', null, $this->siteS)['account']->id, 'website with the default business: the only unassigned Page');
        $this->assertSame('NOT_CONNECTED', $r->resolve($this->single, 'instagram')['reason']);
    }

    // ------------------------------------------------------------- publish --

    public function test_publish_refuses_an_ambiguous_post_and_records_the_business_when_known(): void
    {
        $svc = app(SocialService::class);

        $amb = $svc->createPost($this->ws, ['platform' => 'facebook', 'content' => 'Hello from somewhere']);
        try {
            $svc->publishPost((int) $amb['post_id'], $this->ws);
            $this->fail('an ambiguous post must not publish');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Which business is this post for?', $e->getMessage());
        }
        $row = DB::table('social_posts')->where('id', $amb['post_id'])->first();
        $this->assertSame('permanent:AMBIGUOUS_BUSINESS', $row->failure_class);
        $this->assertNull($row->social_account_id, 'no Page was picked');

        $known = $svc->createPost($this->ws, ['platform' => 'facebook', 'content' => 'Gym news', 'business_id' => $this->bizB]);
        $this->assertSame($this->bizB, (int) DB::table('social_posts')->where('id', $known['post_id'])->value('business_id'));
        try { $svc->publishPost((int) $known['post_id'], $this->ws); } catch (\Throwable $e) { /* transport is not under test */ }
        $this->assertSame($this->pageB, (int) DB::table('social_posts')->where('id', $known['post_id'])->value('social_account_id'), 'the business picked the Page and it was recorded');
    }

    public function test_article_share_without_an_account_resolves_by_the_articles_website(): void
    {
        $svc = app(ArticleDistributionService::class);

        $r = $svc->requestShare($this->ws, ['article_id' => $this->articleA, 'platform' => 'facebook']);
        $this->assertNotContains($r['error'] ?? null, ['MISSING_ACCOUNT_ID', 'AMBIGUOUS_BUSINESS', 'NO_PAGE_FOR_BUSINESS', 'ACCOUNT_NOT_FOUND'], json_encode($r));
        if (($r['ok'] ?? false) === true) {
            $this->assertSame($this->pageA, (int) DB::table('social_posts')->where('id', $r['share_id'] ?? 0)->value('social_account_id'));
        }

        $orphan = (int) DB::table('articles')->insertGetId(['workspace_id' => $this->ws, 'website_id' => null, 'title' => 'No site', 'status' => 'published', 'content' => '<p>x</p>', 'created_at' => now(), 'updated_at' => now()]);
        $r2 = $svc->requestShare($this->ws, ['article_id' => $orphan, 'platform' => 'facebook']);
        $this->assertSame('AMBIGUOUS_BUSINESS', $r2['error'] ?? null, json_encode($r2));
        $this->assertStringContainsString('Which business', $r2['message'] ?? '');
    }

    // ------------------------------------------------------------- accounts --

    public function test_confirm_pages_records_the_business_and_instagram_follows_its_page(): void
    {
        $accounts = [
            ['platform' => 'facebook', 'account_id' => 'pg-new', 'account_name' => 'New Page', 'access_token' => 'T1'],
            ['platform' => 'instagram', 'account_id' => 'ig-new', 'account_name' => 'new_ig', 'access_token' => 'T1', 'linked_page_id' => 'pg-new'],
        ];
        Cache::put('social_oauth_pending:k-test', ['workspace_id' => $this->ws, 'accounts' => $accounts], now()->addMinutes(5));

        $out = (new SocialConnector())->confirmPages('k-test', ['pg-new'], $this->ws, ['pg-new' => $this->bizC]);

        $this->assertTrue($out['success'], json_encode($out));
        $this->assertSame($this->bizC, (int) DB::table('social_accounts')->where('workspace_id', $this->ws)->where('account_id', 'pg-new')->value('business_id'));
        $this->assertSame($this->bizC, (int) DB::table('social_accounts')->where('workspace_id', $this->ws)->where('account_id', 'ig-new')->value('business_id'), 'Instagram follows its Page');

        Cache::put('social_oauth_pending:k-foreign', ['workspace_id' => $this->ws, 'accounts' => [$accounts[0]]], now()->addMinutes(5));
        (new SocialConnector())->confirmPages('k-foreign', ['pg-new'], $this->ws, ['pg-new' => $this->bizS]);
        $this->assertNull(DB::table('social_accounts')->where('workspace_id', $this->ws)->where('account_id', 'pg-new')->value('business_id'), 'another workspace\'s business is never accepted');
    }

    public function test_account_business_can_be_changed_and_is_named_in_the_accounts_list(): void
    {
        $svc = app(SocialService::class);
        $ig = $this->account($this->ws, 'instagram', 'ig-a', 'chefred_ig', null);
        DB::table('social_accounts')->where('id', $ig)->update(['linked_page_id' => 'pg-a']);

        $this->assertSame('NOT_FOUND', $svc->setAccountBusiness($this->ws, $this->pageA, $this->bizS)['code'] ?? null, 'foreign business refused');
        $r = $svc->setAccountBusiness($this->ws, $this->pageA, $this->bizC);
        $this->assertTrue($r['success']);
        $this->assertSame('Copperleaf Joinery', $r['business_name']);
        $this->assertSame($this->bizC, (int) DB::table('social_accounts')->where('id', $ig)->value('business_id'), 'linked Instagram follows');

        $list = collect($svc->listAccounts($this->ws));
        $this->assertSame('Copperleaf Joinery', $list->firstWhere('id', $this->pageA)->business_name);
        $this->assertSame('Boss Mac Gym', $list->firstWhere('id', $this->pageB)->business_name);
        $this->assertArrayNotHasKey('credentials_encrypted', (array) $list->first());
    }
}
