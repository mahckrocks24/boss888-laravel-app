<?php

namespace Tests\Feature\Social888;

use App\Connectors\SocialConnector;
use App\Core\Publisher\ConnectionHealth;
use App\Engines\Social\Services\SocialService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SOCIAL-PAGEPICK-1 (2026-09-25). The Facebook callback discovers Pages and asks; nothing is stored
 * until the customer confirms; only the chosen Page (and its linked Instagram) is stored; the token
 * is encrypted at rest and never returned by the accounts API.
 */
class FacebookPagePickerTest extends TestCase
{
    private int $ws = 991301;
    private int $otherWs = 991302;

    private int $uid = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->cleanup();
        // social_accounts.workspace_id is a foreign key: the fixture workspaces must exist.
        $this->uid = (int) DB::table('users')->insertGetId([
            'name' => 'Page Picker Fixture', 'email' => 'pagepick-' . uniqid() . '@example.test', 'password' => bcrypt('x'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ([$this->ws, $this->otherWs] as $ws) {
            DB::table('workspaces')->insert([
                'id' => $ws, 'name' => 'Page Picker WS ' . $ws, 'slug' => 'pagepick-' . $ws . '-' . uniqid(), 'created_by' => $this->uid,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        Http::preventStrayRequests();
    }

    protected function tearDown(): void
    {
        $this->cleanup();
        parent::tearDown();
    }

    private function cleanup(): void
    {
        DB::table('social_accounts')->whereIn('workspace_id', [$this->ws, $this->otherWs])->delete();
        DB::table('workspaces')->whereIn('id', [$this->ws, $this->otherWs])->delete();
        if ($this->uid) {
            DB::table('users')->where('id', $this->uid)->delete();
        }
    }

    private function connector(): SocialConnector
    {
        $c = new SocialConnector();
        // The connector reads env() in its constructor; give it fake app credentials.
        foreach (['fbAppId' => 'app-id', 'fbAppSecret' => 'app-secret', 'fbRedirectUri' => 'https://staging.test/api/social/oauth/facebook/callback'] as $prop => $val) {
            $ref = new \ReflectionProperty(SocialConnector::class, $prop);
            $ref->setAccessible(true);
            $ref->setValue($c, $val);
        }

        return $c;
    }

    private function fakeGraph(int $pages = 2, bool $igOnFirst = true): void
    {
        Http::fake(function ($request) use ($pages, $igOnFirst) {
            $url = $request->url();
            if (str_contains($url, '/oauth/access_token')) {
                return Http::response(['access_token' => 'USER-TOKEN', 'expires_in' => 5184000], 200);
            }
            if (str_contains($url, '/me/accounts')) {
                $data = [];
                for ($i = 1; $i <= $pages; $i++) {
                    $data[] = ['id' => 'page-' . $i, 'name' => 'Page ' . $i, 'access_token' => 'PAGE-TOKEN-' . $i, 'category' => 'Restaurant', 'picture' => ['data' => ['url' => 'https://img.test/' . $i . '.png']]];
                }
                return Http::response(['data' => $data], 200);
            }
            if (preg_match('#/page-(\d+)\?#', $url . '?', $m) || preg_match('#/page-(\d+)$#', strtok($url, '?'), $m)) {
                $n = (int) $m[1];
                if ($n === 1 && $igOnFirst) {
                    return Http::response(['instagram_business_account' => ['id' => 'ig-1', 'username' => 'page_one_ig', 'followers_count' => 12]], 200);
                }
                return Http::response(['id' => 'page-' . $n], 200);
            }
            return Http::response(['error' => ['message' => 'unexpected ' . $url]], 400);
        });
    }

    private function stateFor(int $ws): string
    {
        $state = $ws . '_nonce-test';
        Cache::put('social_oauth_state:' . $state, ['workspace_id' => $ws, 'platform' => 'facebook', 'nonce' => 'nonce-test'], now()->addMinutes(10));

        return $state;
    }

    public function test_callback_discovers_pages_and_stores_nothing(): void
    {
        $this->fakeGraph(2);
        $res = $this->connector()->handleCallback('code-1', $this->stateFor($this->ws), $this->ws);

        $this->assertTrue($res['success'], json_encode($res));
        $this->assertTrue($res['choose']);
        $this->assertNotEmpty($res['pending_key']);
        $this->assertCount(2, $res['pages']);
        $this->assertSame('Page 1', $res['pages'][0]['name']);
        $this->assertSame('page_one_ig', $res['pages'][0]['instagram']);
        $this->assertNull($res['pages'][1]['instagram']);
        $this->assertStringNotContainsString('TOKEN', json_encode($res['pages']), 'the picker payload carries no token');
        $this->assertSame(0, DB::table('social_accounts')->where('workspace_id', $this->ws)->count(), 'nothing stored before the choice');
    }

    public function test_confirm_stores_only_the_chosen_page_and_its_instagram_encrypted(): void
    {
        $this->fakeGraph(2);
        $c = $this->connector();
        $res = $c->handleCallback('code-1', $this->stateFor($this->ws), $this->ws);

        $out = $c->confirmPages($res['pending_key'], ['page-1'], $this->ws);

        $this->assertTrue($out['success'], json_encode($out));
        $this->assertSame(2, $out['stored'], 'the Page and its linked Instagram');
        $rows = DB::table('social_accounts')->where('workspace_id', $this->ws)->orderBy('platform')->get();
        $this->assertSame(['facebook', 'instagram'], $rows->pluck('platform')->all());
        $this->assertSame(0, DB::table('social_accounts')->where('workspace_id', $this->ws)->where('account_id', 'page-2')->count(), 'the unchosen Page is not stored');

        $fb = $rows->firstWhere('platform', 'facebook');
        $this->assertNotEmpty($fb->credentials_encrypted);
        $this->assertSame(['_' => 'encrypted'], json_decode($fb->credentials_json, true), 'plaintext column carries only the placeholder');
        $this->assertStringNotContainsString('PAGE-TOKEN', (string) $fb->credentials_json);
        $this->assertStringNotContainsString('PAGE-TOKEN', (string) $fb->credentials_encrypted);
        $this->assertSame('PAGE-TOKEN-1', ConnectionHealth::readCredentials($fb)['access_token'], 'decrypts through the envelope');
        $this->assertSame('page-1', DB::table('social_accounts')->where('workspace_id', $this->ws)->where('platform', 'instagram')->value('linked_page_id'));

        $this->assertNull(Cache::get('social_oauth_pending:' . $res['pending_key']), 'the stash is consumed');
        $again = $c->confirmPages($res['pending_key'], ['page-1'], $this->ws);
        $this->assertSame('PENDING_EXPIRED', $again['code'] ?? null, 'a replay is refused');
    }

    public function test_confirm_refuses_another_workspace_and_unshared_pages(): void
    {
        $this->fakeGraph(1, false);
        $c = $this->connector();
        $res = $c->handleCallback('code-1', $this->stateFor($this->ws), $this->ws);

        $this->assertSame('WORKSPACE_MISMATCH', $c->confirmPages($res['pending_key'], ['page-1'], $this->otherWs)['code'] ?? null);
        $this->assertSame('PAGE_NOT_IN_GRANT', $c->confirmPages($res['pending_key'], ['page-99'], $this->ws)['code'] ?? null);
        $this->assertSame('NO_PAGE_CHOSEN', $c->confirmPages($res['pending_key'], [], $this->ws)['code'] ?? null);
        $this->assertSame(0, DB::table('social_accounts')->whereIn('workspace_id', [$this->ws, $this->otherWs])->count());
    }

    public function test_zero_pages_is_explained_not_connected(): void
    {
        $this->fakeGraph(0);
        $res = $this->connector()->handleCallback('code-1', $this->stateFor($this->ws), $this->ws);

        $this->assertFalse($res['success']);
        $this->assertStringContainsString('tick the Page', $res['error']);
    }

    public function test_rerequest_asks_facebook_to_show_page_selection_again(): void
    {
        $c = $this->connector();
        $this->assertStringNotContainsString('auth_type=', $c->getAuthUrl('facebook', $this->ws));
        $this->assertStringContainsString('auth_type=rerequest', $c->getAuthUrl('facebook', $this->ws, true));
    }

    public function test_accounts_api_never_returns_a_credential_column(): void
    {
        $this->fakeGraph(1, false);
        $c = $this->connector();
        $res = $c->handleCallback('code-1', $this->stateFor($this->ws), $this->ws);
        $c->confirmPages($res['pending_key'], ['page-1'], $this->ws);

        $list = app(SocialService::class)->listAccounts($this->ws);
        $this->assertCount(1, $list);
        $row = (array) $list[0];
        $this->assertArrayNotHasKey('credentials_json', $row);
        $this->assertArrayNotHasKey('credentials_encrypted', $row);
        $this->assertStringNotContainsString('TOKEN', json_encode($list));
        $this->assertSame('Page 1', $row['account_name']);
    }
}
