<?php

namespace Tests\Feature\Business;

use App\Core\Business\BusinessContext;
use App\Core\Business\BusinessProfileResolver;
use App\Core\Business\BusinessScopeGuard;
use App\Models\Business;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RFC-0011 U3: which business a turn is about, the sticky thread business, the question, and the scope guard. */
class BusinessContextTest extends TestCase
{
    private int $ws; private Business $chef; private Business $gym; private Business $pets;

    protected function setUp(): void
    {
        parent::setUp();
        $u = new \App\Models\User(); $u->name = 'Ctx Test'; $u->email = 'ctx-' . uniqid() . '@example.test'; $u->password = 'Ctx-Test-Password-1'; $u->status = 'active'; $u->save();
        $this->ws = (int) DB::table('workspaces')->insertGetId(['name' => 'ctx-test', 'slug' => 'ctx-test-' . uniqid(), 'timezone' => 'UTC', 'created_by' => $u->id, 'business_name' => 'Chef Red Test', 'industry' => 'private chef', 'location' => 'Newark, NJ', 'created_at' => now(), 'updated_at' => now()]);
        Artisan::call('business:backfill', ['--workspace' => $this->ws]);
        $this->chef = Business::where('workspace_id', $this->ws)->where('is_default', true)->first();
        $this->gym = Business::create(['workspace_id' => $this->ws, 'name' => 'Boss Mac Gym', 'slug' => 'boss-mac-gym', 'aliases_json' => ['the gym'], 'industry' => 'fitness', 'services_json' => ['Memberships', 'Personal training'], 'location' => 'Jersey City, NJ', 'pricing_anchor' => '$49/month', 'is_default' => false, 'sort_order' => 1]);
        $this->pets = Business::create(['workspace_id' => $this->ws, 'name' => 'Boss Mac Pet Shop', 'slug' => 'boss-mac-pet-shop', 'industry' => 'pet supplies', 'is_default' => false, 'sort_order' => 2]);
        DB::table('websites')->insert([
            ['workspace_id' => $this->ws, 'business_id' => $this->chef->id, 'name' => 'Chef Red Test', 'subdomain' => 'ctx-chef-' . uniqid() . '.levelupgrowth.io', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $this->ws, 'business_id' => $this->gym->id, 'name' => 'Boss Mac Gym', 'subdomain' => 'boss-mac-gym-' . uniqid() . '.levelupgrowth.io', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()],
        ]);
        config(['business.profiles' => false, 'business.qa_workspaces' => [$this->ws]]);
        app(BusinessProfileResolver::class)->forget($this->ws);
        app(BusinessContext::class)->clearSticky($this->ws);
    }

    private function ctx(string $msg, ?string $site = null): array { return app(BusinessContext::class)->resolve($this->ws, $msg, $site); }

    public function test_single_business_workspaces_never_ask(): void
    {
        config(['business.qa_workspaces' => []]);
        app(BusinessProfileResolver::class)->forget($this->ws);
        $c = $this->ctx('how is my business doing?');
        $this->assertFalse($c['multi']); $this->assertSame('single', $c['mode']); $this->assertSame($this->chef->id, $c['business_id']); $this->assertNull($c['ask']);
    }

    public function test_an_unnamed_business_question_asks_once_naming_every_business(): void
    {
        $c = $this->ctx('how is my business doing?');
        $this->assertSame('ambiguous', $c['mode']);
        $this->assertStringContainsString('Chef Red Test', $c['ask']); $this->assertStringContainsString('Boss Mac Gym', $c['ask']); $this->assertStringContainsString('Boss Mac Pet Shop', $c['ask']);
        $this->assertNull($c['business_id']);
    }

    public function test_a_named_business_becomes_sticky_and_a_bare_follow_up_follows_it(): void
    {
        $c = $this->ctx('How is Boss Mac Gym doing?');
        $this->assertSame('named', $c['mode']); $this->assertSame($this->gym->id, $c['business_id']);
        $f = $this->ctx('and what are my prices?');
        $this->assertSame('sticky', $f['mode']); $this->assertSame($this->gym->id, $f['business_id']);
        $g = $this->ctx('what about the pet shop?');
        $this->assertSame('named', $g['mode']); $this->assertSame($this->pets->id, $g['business_id'], 'a partial name switches the business');
    }

    public function test_aliases_and_website_names_name_a_business(): void
    {
        $this->assertSame($this->gym->id, $this->ctx('write a post for the gym')['business_id']);
        $this->assertSame($this->gym->id, $this->ctx('check boss-mac-gym')['business_id']);
    }

    public function test_portfolio_words_cover_all_and_clear_the_sticky_business(): void
    {
        $this->ctx('How is Boss Mac Gym doing?');
        $c = $this->ctx('compare all my businesses');
        $this->assertSame('portfolio', $c['mode']); $this->assertNull($c['business_id']);
        $this->assertSame('ambiguous', $this->ctx('what are my prices?')['mode'], 'the sticky business was cleared');
    }

    public function test_two_names_in_one_message_are_a_portfolio_turn(): void
    {
        $c = $this->ctx('compare Boss Mac Gym with the pet shop');
        $this->assertSame('portfolio', $c['mode']); $this->assertCount(2, $c['named']);
    }

    public function test_a_message_not_about_the_business_uses_the_default_silently(): void
    {
        $c = $this->ctx('what does SEO stand for?');
        $this->assertSame('default', $c['mode']); $this->assertSame($this->chef->id, $c['business_id']); $this->assertNull($c['ask']);
    }

    public function test_the_seo_selected_site_is_only_a_hint(): void
    {
        $site = DB::table('websites')->where('business_id', $this->gym->id)->value('subdomain');
        $c = $this->ctx('how is my website going?', 'https://' . $site);
        $this->assertSame('ambiguous', $c['mode']); $this->assertSame($this->gym->id, $c['hint']);
        $this->assertStringContainsString('Boss Mac Gym', $c['ask']);
    }

    public function test_the_prompt_block_carries_the_roster_and_only_the_active_profile(): void
    {
        $c = $this->ctx('How is Boss Mac Gym doing?');
        $block = BusinessContext::promptBlock($c, app(BusinessProfileResolver::class), $this->ws);
        $this->assertStringContainsString("YOUR OWNER'S BUSINESSES (3", $block);
        $this->assertStringContainsString('ACTIVE BUSINESS FOR THIS TURN: Boss Mac Gym', $block);
        $this->assertStringContainsString('PRICES Boss Mac Gym CHARGES', $block); $this->assertStringContainsString('$49/month', $block);
        $this->assertStringNotContainsString('private chef', substr($block, strpos($block, 'ACTIVE BUSINESS')), 'the chef profile stays out of the gym turn');
    }

    public function test_the_scope_guard_strikes_sentences_that_name_another_business(): void
    {
        $c = $this->ctx('How is Boss Mac Gym doing?');
        $r = BusinessScopeGuard::apply("Boss Mac Gym is doing well with 3 new members. Chef Red Test also booked two dinners. Memberships are $49/month.", $c, $this->ws);
        $this->assertSame('Boss Mac Gym is doing well with 3 new members. Memberships are $49/month.', $r['reply']);
        $this->assertCount(1, $r['struck']);
        $p = $this->ctx('compare all my businesses');
        $this->assertSame([], BusinessScopeGuard::apply('Chef Red Test: fine. Boss Mac Gym: fine.', $p, $this->ws)['struck'], 'portfolio turns are untouched');
        $only = BusinessScopeGuard::apply('Chef Red Test booked two dinners.', $c, $this->ws);
        $this->assertStringContainsString('which business do you mean', $only['reply'], 'a reply with nothing left becomes the question');
    }
}
