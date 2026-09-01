<?php

namespace Tests\Feature\Architecture;

use App\Core\Tenancy\WebsiteScope;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * INC-0006 — provenance for the records that describe a website's own traffic and content.
 *
 * Settings decide how a website behaves; these tables record what happened ON it. Contamination here is worse
 * than a shared setting, because it puts one business's visitors, questions and pages inside another site's
 * reporting — and the two sites can belong to the same workspace, so tenancy checks never fire.
 *
 * Two different shapes are asserted, deliberately:
 *
 *   seo_content_index carries website_id DIRECTLY. Every row is a page, and a page's host is its website.
 *   chatbot messages and escalations do NOT. They reach a website through the session they belong to, which
 *   is a mandatory, foreign-keyed link. Copying the website onto the child rows would create a second version
 *   of a fact the session already owns, and second versions drift.
 *
 * The rule both shapes share: what cannot be established is left unattributed, and an unattributed record
 * appears in NO website's view — never in the nearest one's.
 */
class WebsiteProvenanceChainTest extends TestCase
{
    /** @return array{0:int,1:int,2:int,3:int} ws, uid, siteA, siteB */
    private function businessWithTwoSites(): array
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'prov-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'Business', 'slug' => 'prov-' . uniqid(), 'created_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspace_users')->insert([
            'workspace_id' => $ws, 'user_id' => $uid, 'role' => 'owner',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $site = fn (string $name, string $host) => (int) DB::table('websites')->insertGetId([
            'workspace_id' => $ws, 'name' => $name, 'created_by' => $uid,
            'custom_domain' => $host, 'status' => 'published', 'type' => 'levelup',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $a = $site('Site A', 'alpha-' . uniqid() . '.example.test');
        $b = $site('Site B', 'beta-' . uniqid() . '.example.test');

        return [$ws, $uid, $a, $b];
    }

    private function hostOf(int $websiteId): string
    {
        return (string) DB::table('websites')->where('id', $websiteId)->value('custom_domain');
    }

    /*------------------------------------------------------------------ seo_content_index */

    /** A page's host decides its website. Nothing about ordering, recency or how many sites exist. */
    public function test_a_page_is_attributed_to_the_website_whose_host_it_is_on(): void
    {
        [$ws, , $a, $b] = $this->businessWithTwoSites();

        $this->assertSame($a, WebsiteScope::websiteForUrl($ws, 'https://' . $this->hostOf($a) . '/pricing'));
        $this->assertSame($b, WebsiteScope::websiteForUrl($ws, 'https://www.' . $this->hostOf($b) . '/about'));
    }

    /** A host this business does not own resolves to nothing — never to the nearest site. */
    public function test_a_foreign_host_is_left_unattributed_rather_than_assigned_to_a_sibling(): void
    {
        [$ws, , $a] = $this->businessWithTwoSites();

        $this->assertSame(
            WebsiteScope::BUSINESS_DEFAULT,
            WebsiteScope::websiteForUrl($ws, 'https://somewhere-else-' . uniqid() . '.example.test/page'),
            'guessing here would put a stranger\'s page inside a real site\'s inventory',
        );
        $this->assertNotSame($a, WebsiteScope::websiteForUrl($ws, 'https://unknown.example.test/x'));
    }

    /** Per-site inventories must not overlap, and must still reconcile to the business total. */
    public function test_site_inventories_do_not_overlap_and_reconcile_to_the_business_total(): void
    {
        [$ws, , $a, $b] = $this->businessWithTwoSites();

        $index = function (int $websiteId, string $host, string $path) use ($ws) {
            $url = 'https://' . $host . $path;
            DB::table('seo_content_index')->insert([
                'workspace_id' => $ws, 'website_id' => $websiteId, 'url' => $url,
                'url_hash' => hash('sha256', $url), 'title' => $path,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $index($a, $this->hostOf($a), '/one');
        $index($a, $this->hostOf($a), '/two');
        $index($b, $this->hostOf($b), '/one');

        // an honestly unattributed row: a page from a host this business no longer owns
        $orphanUrl = 'https://retired-' . uniqid() . '.example.test/legacy';
        DB::table('seo_content_index')->insert([
            'workspace_id' => $ws, 'website_id' => WebsiteScope::BUSINESS_DEFAULT, 'url' => $orphanUrl,
            'url_hash' => hash('sha256', $orphanUrl), 'title' => 'legacy',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $forSite = fn (int $id) => DB::table('seo_content_index')
            ->where('workspace_id', $ws)->where('website_id', $id)->count();
        $business = DB::table('seo_content_index')->where('workspace_id', $ws)->count();

        $this->assertSame(2, $forSite($a));
        $this->assertSame(1, $forSite($b), 'site B sees only its own page');
        $this->assertSame(4, $business, 'the business-wide view still sees everything');
        $this->assertSame($business, $forSite($a) + $forSite($b) + $forSite(WebsiteScope::BUSINESS_DEFAULT),
            'per-site subsets plus the unattributed remainder must reconcile to the business total');
    }

    /*------------------------------------------------------------------ the chatbot chain */

    /** The link a message uses to reach a website is mandatory and referentially enforced. */
    public function test_a_message_cannot_exist_without_the_session_that_names_its_website(): void
    {
        $col = DB::selectOne(
            "SELECT IS_NULLABLE n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbot_messages' AND COLUMN_NAME = 'session_id'"
        );
        $this->assertSame('NO', $col->n, 'session_id must be mandatory, or the chain is optional');

        $fk = DB::selectOne(
            "SELECT COUNT(*) c FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'chatbot_messages'
               AND COLUMN_NAME = 'session_id' AND REFERENCED_TABLE_NAME = 'chatbot_sessions'"
        );
        $this->assertSame(1, (int) $fk->c, 'the link must be enforced by the database, not by convention');
    }

    /** The acceptance criterion: site A's conversations never appear in site B's chatbot. */
    public function test_one_websites_conversations_never_appear_in_a_siblings(): void
    {
        [$ws, , $a, $b] = $this->businessWithTwoSites();

        $session = function (?int $websiteId) use ($ws) {
            $token = (int) DB::table('chatbot_widget_tokens')->insertGetId([
                'workspace_id' => $ws, 'website_id' => $websiteId, 'token_hash' => hash('sha256', uniqid()),
                'token_prefix' => substr(uniqid(), 0, 8), 'label' => 'test', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $id = (int) DB::table('chatbot_sessions')->insertGetId([
                'workspace_id' => $ws, 'website_id' => $websiteId, 'widget_token_id' => $token,
                'message_count' => 1, 'created_at' => now(),
            ]);
            DB::table('chatbot_messages')->insert([
                'session_id' => $id, 'workspace_id' => $ws, 'role' => 'user',
                'content' => 'hello from ' . ($websiteId ?? 'nowhere'), 'created_at' => now(),
            ]);

            return $id;
        };

        $sa = $session($a);
        $sb = $session($b);
        $orphan = $session(null);   // a visit whose host never resolved

        $conversationsFor = fn (int $websiteId) => DB::table('chatbot_sessions')
            ->where('workspace_id', $ws)->where('website_id', $websiteId)->pluck('id')->all();

        $this->assertSame([$sa], $conversationsFor($a));
        $this->assertSame([$sb], $conversationsFor($b));
        $this->assertNotContains($orphan, $conversationsFor($a),
            'an unattributed conversation belongs to no website, so it must surface in none of them');
        $this->assertNotContains($orphan, $conversationsFor($b));

        // and the messages inherit that separation through the session, without duplicating the fact
        $messagesFor = fn (int $websiteId) => DB::table('chatbot_messages as m')
            ->join('chatbot_sessions as s', 's.id', '=', 'm.session_id')
            ->where('s.workspace_id', $ws)->where('s.website_id', $websiteId)->count();

        $this->assertSame(1, $messagesFor($a));
        $this->assertSame(1, $messagesFor($b));
    }

    /** An escalation reaches its website the same way, through the session — never by a copied column. */
    public function test_an_escalation_resolves_its_website_through_its_session(): void
    {
        [$ws, , $a, $b] = $this->businessWithTwoSites();

        $mk = function (int $websiteId, string $question) use ($ws) {
            $token = (int) DB::table('chatbot_widget_tokens')->insertGetId([
                'workspace_id' => $ws, 'website_id' => $websiteId, 'token_hash' => hash('sha256', uniqid()),
                'token_prefix' => substr(uniqid(), 0, 8), 'label' => 'test', 'status' => 'active',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $sid = (int) DB::table('chatbot_sessions')->insertGetId([
                'workspace_id' => $ws, 'website_id' => $websiteId, 'widget_token_id' => $token,
                'message_count' => 1, 'created_at' => now(),
            ]);
            DB::table('chatbot_escalations')->insert([
                'workspace_id' => $ws, 'session_id' => $sid, 'question' => $question,
                'reason' => 'no_answer', 'status' => 'open', 'created_at' => now(), 'updated_at' => now(),
            ]);
        };

        $mk($a, 'question about A');
        $mk($b, 'question about B');

        $forSite = fn (int $websiteId) => DB::table('chatbot_escalations as e')
            ->leftJoin('chatbot_sessions as s', 's.id', '=', 'e.session_id')
            ->where('e.workspace_id', $ws)->where('s.website_id', $websiteId)
            ->pluck('e.question')->all();

        $this->assertSame(['question about A'], $forSite($a));
        $this->assertSame(['question about B'], $forSite($b));

        $this->assertFalse(
            \Illuminate\Support\Facades\Schema::hasColumn('chatbot_escalations', 'website_id'),
            'the website must stay owned by the session; a copy here would be a second truth that can drift'
        );
    }

    /**
     * chatbot_usage_logs is business-wide ON PURPOSE, and this records why rather than leaving it looking
     * like an oversight: it is a monthly counter read by the plan quota. A workspace has one plan and one
     * allowance, so the count that matters is the business's. It is never presented as one site's traffic.
     */
    public function test_usage_logs_are_a_business_wide_quota_not_per_site_reporting(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('chatbot_usage_logs', 'website_id'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('chatbot_usage_logs', 'session_id'));

        // If it ever becomes per-site reporting, this stops being true and the assertion above must change.
        $readers = [];
        foreach ([base_path('app'), base_path('routes')] as $root) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
            foreach ($it as $f) {
                if ($f->isFile() && $f->getExtension() === 'php'
                    && str_contains((string) file_get_contents($f->getPathname()), 'chatbot_usage_logs')) {
                    $readers[] = basename($f->getPathname());
                }
            }
        }
        sort($readers);
        $this->assertSame(['ChatbotResponseService.php', 'FeatureGateService.php'], $readers,
            'only the counter that writes it and the plan gate that reads it may touch this table');
    }
}
