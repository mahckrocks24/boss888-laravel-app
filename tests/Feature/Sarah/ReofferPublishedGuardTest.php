<?php

namespace Tests\Feature\Sarah;

use App\Core\LaunchScope\LaunchScopeLanguageGuard;
use App\Core\Sarah888\ArticleIdClaimGuard;
use App\Core\Sarah888\DerivedState;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Chef Red, 2026-09-01 17:53. The owner had published 37 articles at 14:59 and said so, twice. Sarah
 * answered: "To champion your marketing effectively, I recommend we publish the following articles
 * immediately to drive engagement: 300, 352, 354, 661, 662, and 186." Every one of those was already live.
 *
 * ArticleIdClaimGuard was supposed to have closed this at 218b087 earlier the same afternoon. It did not,
 * because it asked only whether the numbers BELONGED to the workspace. They did. The question it never
 * asked is whether the work still needed doing — so real numbers describing finished work sailed through
 * the one check meant to catch false claims about articles.
 *
 * Also held here: two things that made her unbearable to talk to rather than merely wrong — she had no
 * concept of a website at all, and every list she formatted was flattened into a wall of text.
 */
class ReofferPublishedGuardTest extends TestCase
{
    private function workspace(): int
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'reoffer-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('workspaces')->insertGetId([
            'name' => 'Business', 'slug' => 'reoffer-' . uniqid(), 'created_by' => $uid,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function article(int $ws, string $status, ?string $img = 'https://e.test/i.jpg'): int
    {
        return (int) DB::table('articles')->insertGetId([
            'workspace_id' => $ws, 'title' => 'Piece ' . uniqid(), 'slug' => 'a-' . uniqid(),
            'content' => '<p>body</p>', 'status' => $status, 'featured_image_url' => $img,
            'published_at' => $status === 'published' ? now() : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /*──────────────────────────── the defect itself */

    /** Real numbers, this workspace's, and still a false claim — because the work is done. */
    public function test_offering_to_publish_already_published_articles_is_corrected(): void
    {
        $ws = $this->workspace();
        $live = [$this->article($ws, 'published'), $this->article($ws, 'published')];
        $draft = $this->article($ws, 'draft');

        $reply = 'To champion your marketing effectively, I recommend we publish the following articles '
               . 'immediately to drive engagement: ' . $live[0] . ', ' . $live[1] . '.';

        $r = app(ArticleIdClaimGuard::class)->validate($reply, $ws);

        $this->assertTrue($r['corrected'], 'an offer to redo finished work must not reach the owner');
        $this->assertStringNotContainsString('I recommend we publish', $r['reply']);
        $this->assertStringContainsString('already published', $r['reply']);
        // Owner 2026-09-18: by title, never by number
        $this->assertStringContainsString('"' . mb_strimwidth((string) DB::table('articles')->where('id', $draft)->value('title'), 0, 48, '…') . '"', $r['reply'], 'and it names what IS still ready');
        $this->assertDoesNotMatchRegularExpression('/#\d+/', $r['reply']);
    }

    /** The half of the rule that must not over-reach: a true completion report keeps its numbers. */
    public function test_a_truthful_report_of_work_already_done_is_left_alone(): void
    {
        $ws = $this->workspace();
        $a = $this->article($ws, 'published');
        $b = $this->article($ws, 'published');

        $reply = "Done — I published articles {$a} and {$b} this afternoon; they're live now.";
        $r = app(ArticleIdClaimGuard::class)->validate($reply, $ws);

        $this->assertFalse($r['corrected'], 'reporting real work truthfully is not a false claim');
        $this->assertSame($reply, $r['reply']);
    }

    /** A genuine offer against genuine drafts must still get through untouched. */
    public function test_offering_to_publish_a_real_draft_is_untouched(): void
    {
        $ws = $this->workspace();
        $draft = $this->article($ws, 'draft');

        $reply = "I recommend we publish article {$draft} next.";
        $r = app(ArticleIdClaimGuard::class)->validate($reply, $ws);

        $this->assertFalse($r['corrected']);
        $this->assertStringContainsString((string) $draft, $r['reply']);
    }

    /** The original defect this guard was built for still has to be caught. */
    public function test_an_article_number_this_workspace_does_not_have_is_still_corrected(): void
    {
        $ws = $this->workspace();
        $this->article($ws, 'draft');

        $r = app(ArticleIdClaimGuard::class)->validate('The available drafts are 991183, 991184 and 991185.', $ws);

        $this->assertTrue($r['corrected']);
        $this->assertNotEmpty($r['foreign']);
        $this->assertStringNotContainsString('991183', $r['reply']);
    }

    /** Mixed: one sentence re-offers finished work, another is ordinary prose and must survive. */
    public function test_only_the_offending_sentence_is_replaced(): void
    {
        $ws = $this->workspace();
        $live = $this->article($ws, 'published');
        $this->article($ws, 'draft');

        $reply = "Traffic is up this week. I recommend we publish the following articles: {$live}. "
               . 'Let me know how you want to proceed.';

        $r = app(ArticleIdClaimGuard::class)->validate($reply, $ws);

        $this->assertTrue($r['corrected']);
        $this->assertStringContainsString('Traffic is up this week.', $r['reply']);
        $this->assertStringContainsString('Let me know how you want to proceed.', $r['reply']);
    }

    /*──────────────────────────── she can see the websites at all */

    /** "Aren't you across all things happening in this account?" — she was not, and said so. */
    public function test_derived_state_names_the_websites(): void
    {
        $ws = $this->workspace();
        $siteId = (int) DB::table('websites')->insertGetId([
            'workspace_id' => $ws, 'name' => 'Miyguel Graphic Designs', 'status' => 'published',
            'type' => 'template', 'subdomain' => 'mgd-' . uniqid() . '.levelupgrowth.io',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $rendered = app(DerivedState::class)->render($ws);

        $this->assertStringContainsString('Miyguel Graphic Designs', $rendered,
            'the chat reads DerivedState; a count is not an answer');
        $this->assertStringContainsString('NO content written yet', $rendered);
        $this->assertSame($siteId, app(DerivedState::class)->query('websites', $ws)['items'][0]['id']);
    }

    public function test_a_workspace_with_no_websites_says_so_plainly(): void
    {
        $rendered = app(DerivedState::class)->render($this->workspace());
        $this->assertStringContainsString('none built yet', $rendered);
    }

    /*──────────────────────────── lists stay readable */

    /** Every router reply passes through this guard; it used to flatten them all. */
    public function test_the_language_guard_no_longer_flattens_a_list(): void
    {
        $listed = "You have 2 websites:\n  • Alpha (published)\n  • Beta (draft)";

        $out = LaunchScopeLanguageGuard::apply($listed);

        $this->assertStringContainsString("\n", $out, 'a list arriving as one paragraph is unreadable');
        $this->assertSame(2, substr_count($out, '•'));
    }

    /** It must still do its actual job — collapsing runs of spaces. */
    public function test_runs_of_spaces_still_collapse(): void
    {
        $out = LaunchScopeLanguageGuard::apply("Two     spaces   here.");
        $this->assertStringNotContainsString('  ', $out);
    }
}
