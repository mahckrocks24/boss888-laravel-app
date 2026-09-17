<?php

namespace Tests\Feature\Sarah;

use App\Core\Integrity\AgentClaimValidator;
use App\Core\Sarah888\ArticleIdClaimGuard;
use App\Core\Sarah888\InternalIdLeakGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\Support\RouteSource;
use Tests\TestCase;

/**
 * Owner, 2026-09-18 — two things Sarah said on the Owner's own workspace that she must never say again:
 *   "Everything's on track. Let me know what you need! I haven't queued that yet — say the word and I'll set it running."
 *   (a footer pointing at a false sentence the guard had just removed), and article / request / task NUMBERS
 *   ("#1054", "request #12579", "(#32621)") — database ids the customer has no way to see.
 */
class NoInternalIdsAndNoDanglingFooterTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void { parent::setUp(); $this->setUpBoss888(); }

    public function test_a_removed_false_claim_leaves_no_dangling_footer_when_the_reply_still_says_something(): void
    {
        $v = app(AgentClaimValidator::class);
        $ws = $this->testWorkspace->id;
        $model = "Everything's on track. Let me know what you need! The two articles are queued for publishing on chefredraymundo.com, and I'm ready to move forward as soon as you give the go-ahead.";
        $r = $v->validate($model, $ws, 'sarah', false);
        $this->assertSame("Everything's on track. Let me know what you need!", $r['reply']);
        $this->assertCount(1, $r['stripped'], 'the false "queued for publishing" sentence is still removed');
        $this->assertStringNotContainsString("haven't queued", $r['reply']);

        // when the claim WAS the whole reply, the honest line is all that is left — and it is the right one
        $only = $v->validate("I've queued the two articles for publishing.", $ws, 'sarah', false);
        $this->assertSame("I haven't queued that yet — say the word and I'll set it running.", $only['reply']);
    }

    public function test_internal_ids_become_what_the_customer_can_see(): void
    {
        $ws = $this->testWorkspace->id;
        $a1 = (int) DB::table('articles')->insertGetId(['workspace_id' => $ws, 'title' => 'How to Hire a Private Chef in New Jersey: What to Expect', 'slug' => 'hire-chef-nj', 'content' => '<p>x</p>', 'status' => 'draft', 'type' => 'blog_post', 'created_at' => now(), 'updated_at' => now()]);
        $a2 = (int) DB::table('articles')->insertGetId(['workspace_id' => $ws, 'title' => 'Private Chef vs Personal Chef', 'slug' => 'chef-vs-chef', 'content' => '<p>x</p>', 'status' => 'draft', 'type' => 'blog_post', 'created_at' => now(), 'updated_at' => now()]);
        $tid = (int) DB::table('tasks')->insertGetId(['workspace_id' => $ws, 'engine' => 'builder', 'action' => 'ask_arthur', 'category' => 'create', 'status' => 'pending', 'source' => 'agent', 'priority' => 'normal', 'payload_json' => json_encode(['title' => 'Change the hero headline on QA Harbour Yoga']), 'created_at' => now(), 'updated_at' => now()]);
        $aid = (int) DB::table('approvals')->insertGetId(['workspace_id' => $ws, 'engine' => 'builder', 'action' => 'ask_arthur', 'capability_key' => 'builder.ask_arthur', 'task_id' => $tid, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        $foreignArticle = (int) DB::table('articles')->insertGetId(['workspace_id' => $this->createAdditionalWorkspace('Other Tenant')->id, 'title' => 'Someone Else', 'slug' => 'x-else', 'content' => '<p>x</p>', 'status' => 'draft', 'type' => 'blog_post', 'created_at' => now(), 'updated_at' => now()]);

        // the drafts-truth guard's own list form ("#id "title"") keeps just the titles
        $in = "The earliest drafts ready to publish are #{$a1} \"How to Hire a Private Chef in New Jersey: What …\", #{$a2} \"Private Chef vs Personal Chef\".";
        $out = InternalIdLeakGuard::apply($in, $ws)['reply'];
        $this->assertSame('The earliest drafts ready to publish are "How to Hire a Private Chef in New Jersey: What …", "Private Chef vs Personal Chef".', $out);

        // a bare article number → its title; an approval → the review queue; a task → its description
        $out = InternalIdLeakGuard::apply("I can publish article #{$a1} now. It's ready for your approval (request #{$aid}). The Arthur request (#{$tid}) is pending.", $ws)['reply'];
        $this->assertStringContainsString('"How to Hire a Private Chef in New Jersey: What to Expect"', $out);
        $this->assertStringContainsString("It's ready for your approval in your review queue.", $out);
        // a small test id collides across tables (task 1 = approval 1): the lead word "request" may resolve to either — never to a number
        $this->assertMatchesRegularExpression('/The Arthur (request "Change the hero headline on QA Harbour Yoga"|in your review queue) is pending./', $out);
        $this->assertDoesNotMatchRegularExpression('/#\d+/', $out);

        // a number that names nothing in this workspace (another tenant's, or invented) is dropped, not spoken
        $out = InternalIdLeakGuard::apply("The approval ID for the hero change is #32621, not the #12556 I quoted earlier. Article #{$foreignArticle} is also live.", $ws)['reply'];
        $this->assertDoesNotMatchRegularExpression('/#\d+/', $out);
        $this->assertStringNotContainsString('Someone Else', $out, 'another tenant\'s title never appears');

        // the drafts guard itself no longer emits numbers
        $g = app(ArticleIdClaimGuard::class)->validate('The earliest drafts ready to publish are #183 and #184.', $ws);
        $this->assertDoesNotMatchRegularExpression('/#\d+/', $g['reply']);

        // and the two approval sentences no longer carry an id
        $src = RouteSource::all() . (string) file_get_contents(base_path('app/Core/Sarah888/BuilderEditPromotion.php'));
        $this->assertStringNotContainsString('(request #', $src);
        $this->assertStringContainsString('InternalIdLeakGuard::apply((string) $reply, (int) $wsId)', RouteSource::all());
        $this->assertStringContainsString('NO INTERNAL NUMBERS', (string) file_get_contents(base_path('app/Core/Orchestration/ToolSchemaService.php')));
    }
}
