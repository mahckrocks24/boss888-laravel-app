<?php

namespace Tests\Feature\Sarah;

use App\Core\Integrity\AgentClaimValidator;
use App\Core\Sarah888\CompletionReport;
use App\Core\Sarah888\ContentTarget;
use App\Core\Sarah888\DraftPublishing;
use App\Core\Sarah888\SpendPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Helpers\Boss888TestHelper;
use Tests\Support\RouteSource;
use Tests\TestCase;

/**
 * RISK-0186 (2026-09-17) — Sarah execution integrity, the three EV-1056 manifestations and their invariants:
 *   A. the owner's answer to "which website?" resumes the commissioned work (never a bare statement);
 *      a queue claim with nothing queued is stripped, a correction is kept, a credit figure stands on the ledger;
 *   B. several sites named in one order → each piece bound from the owner's own clause, never the open site;
 *      an unplaceable piece is asked about; a QA-rejected piece is never reported "ready";
 *   C. "write … and publish it on X" is creation, not a bulk publish of existing drafts.
 */
class Risk0186ExecutionIntegrityTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    private int $bakery; private int $yoga;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
        $ws = $this->testWorkspace->id;
        $this->bakery = (int) DB::table('websites')->insertGetId(['workspace_id' => $ws, 'name' => 'QA Signup Bakery', 'subdomain' => 'qa-signup-bakery-900.levelupgrowth.io', 'status' => 'published', 'created_at' => now(), 'updated_at' => now()]);
        $this->yoga = (int) DB::table('websites')->insertGetId(['workspace_id' => $ws, 'name' => 'QA Harbour Yoga', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
    }

    private const ORDER = 'Yes, go ahead now: write both articles — the sourdough starters article for QA Signup Bakery and the morning vinyasa for beginners article for QA Harbour Yoga. Drafts only; I will approve publishing.';

    // ── A ──────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_the_answer_to_which_website_resumes_the_commissioned_work(): void
    {
        $ws = $this->testWorkspace->id;
        $policy = app(SpendPolicy::class);
        $answer = 'Put the sourdough article on QA Signup Bakery.';
        $bare = $policy->assessTurnInConversation($answer, $ws);
        $this->assertSame('statement', $bare['classification'], 'with no open question the line is a statement');
        $this->assertFalse((bool) $bare['authorized']);

        // exactly what the content ask-path now arms (agents-01.php, RISK-0186)
        Cache::put(SpendPolicy::pendingClarifyKey($ws), ['action' => 'write_article', 'asked_at' => time(), 'owner_text' => 'Also write an article about sourdough starters.'], now()->addMinutes(15));
        $resumed = $policy->assessTurnInConversation($answer, $ws);
        $this->assertTrue((bool) $resumed['authorized']);
        $this->assertSame('directive', $resumed['classification']);
        $this->assertTrue((bool) ($resumed['clarify_answer'] ?? false));
        $this->assertSame('Also write an article about sourdough starters.', $resumed['original_text']);
        $this->assertNull(Cache::get(SpendPolicy::pendingClarifyKey($ws)), 'the question is consumed');
    }

    public function test_the_content_ask_path_arms_the_clarify_key_and_the_validator_gets_the_ledger_sum(): void
    {
        $src = RouteSource::all();
        $ask = strpos($src, "ContentTarget::askWhich(\$__ct['sites'], \$__ct['named']);");
        $this->assertNotFalse($ask);
        $arm = strpos($src, "SpendPolicy::pendingClarifyKey((int) \$wsId), ['action' => 'write_article'", $ask);
        $this->assertNotFalse($arm, 'the content clarify question arms the P6-g key');
        $this->assertLessThan(1200, $arm - $ask);
        $this->assertStringContainsString('->validate($reply, $wsId, $slug, $__didQueue, $__engagedAgents, $__recentActions, $__recentSpecialists, $__queuedCost)', $src);
        $this->assertStringContainsString('ContentTarget::bindTask((string) $__ownerMessage, $__ct[\'named\'], $payload)', $src);
        $orch = (string) file_get_contents(base_path('app/Core/TaskSystem/Orchestrator.php'));
        $this->assertStringContainsString('CompletionReport::forArticle((int) $root->id', $orch);
        $this->assertStringNotContainsString('$msg = "Your article \\"{$art->title}\\" is ready', $orch);
    }

    public function test_a_queue_claim_with_nothing_queued_is_stripped_and_a_correction_is_kept(): void
    {
        $v = app(AgentClaimValidator::class); $ws = $this->testWorkspace->id;
        $claim = 'Answering your earlier message ("Put the sourdough article on QA Signup Bakery."): Queued both — the sourdough piece for QA Signup Bakery, and the morning vinyasa article for QA Harbour Yoga, since that one never actually got started either. Each is going out as a full job: draft, meta, featured image, internal links. Call it 3 credits apiece, so 6 off your 24. The duplicate One-to-One Yoga entry still needs clearing before that site goes live.';
        $r = $v->validate($claim, $ws, 'sarah', false);
        $this->assertStringNotContainsString('Queued both', $r['reply']);
        $this->assertStringNotContainsString('going out as a full job', $r['reply']);
        $this->assertStringNotContainsString('6 off your 24', $r['reply']);
        $this->assertStringContainsString('One-to-One Yoga entry still needs clearing', $r['reply'], 'ordinary sentences survive');
        $this->assertCount(3, $r['stripped']);

        $kept = $v->validate($claim, $ws, 'sarah', true, [], [], [], 6);
        $this->assertStringContainsString('Queued both', $kept['reply'], 'the same sentence is fine when this turn really queued');
        $this->assertStringContainsString('6 off your 24', $kept['reply'], 'and the figure matches the ledger');

        $wrongFigure = $v->validate($claim, $ws, 'sarah', true, [], [], [], 4);
        $this->assertStringContainsString('Queued both', $wrongFigure['reply']);
        $this->assertStringNotContainsString('6 off your 24', $wrongFigure['reply'], 'a figure the ledger does not carry is stripped');

        $correction = 'In the last 7 days: 0 completed, 0 failed. I have to correct myself on something: I told you I\'d queued the sourdough article for QA Signup Bakery and the vinyasa article for QA Harbour Yoga, and the queue shows neither one. They exist as commitments only — no task was actually created, so nothing is in flight. Say the word and I\'ll set them running.';
        $c = $v->validate($correction, $ws, 'sarah', false);
        $this->assertStringContainsString('I have to correct myself', $c['reply']);
        $this->assertStringContainsString('queue shows neither one', $c['reply']);
        $this->assertStringContainsString('no task was actually created', $c['reply']);
        $this->assertSame([], $c['stripped'], 'a correction is never stripped');
    }

    // ── B ──────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_several_named_sites_are_never_resolved_to_the_open_site(): void
    {
        $ws = $this->testWorkspace->id;
        $ct = ContentTarget::resolve($ws, self::ORDER, 'https://qa-signup-bakery-900.levelupgrowth.io');
        $this->assertNull($ct['site']);
        $this->assertSame('multiple_named', $ct['reason']);
        $this->assertCount(2, $ct['named']);

        $one = ContentTarget::resolve($ws, 'Put the sourdough article on QA Signup Bakery.', '');
        $this->assertSame($this->bakery, $one['site']['id']);
        $none = ContentTarget::resolve($ws, 'Also write an article about sourdough starters.', '');
        $this->assertNull($none['site']);
        $this->assertSame('none', $none['reason'], 'nothing named on a two-site workspace → ask');
    }

    public function test_each_piece_is_bound_from_the_owners_own_clause(): void
    {
        $ws = $this->testWorkspace->id;
        $named = ContentTarget::resolve($ws, self::ORDER, '')['named'];
        $sourdough = ['title' => "Sourdough Starters: A Beginner's Guide to Getting Yours Going", 'topic' => 'how to start and keep a sourdough starter at home'];
        $vinyasa = ['title' => 'Morning Vinyasa for Beginners: What to Expect in Your First Class', 'topic' => 'a beginner-friendly walkthrough of a morning vinyasa class'];
        $b1 = ContentTarget::bindTask(self::ORDER, $named, $sourdough);
        $b2 = ContentTarget::bindTask(self::ORDER, $named, $vinyasa);
        $this->assertSame($this->bakery, $b1['site']['id'] ?? null, json_encode($b1));
        $this->assertSame($this->yoga, $b2['site']['id'] ?? null, json_encode($b2));
        $this->assertSame('clause_match', $b1['reason']);

        $unplaceable = ContentTarget::bindTask(self::ORDER, $named, ['title' => 'Ten Tips for Autumn Marketing', 'topic' => 'seasonal promotions']);
        $this->assertNull($unplaceable['site']);
        $this->assertSame('no_clause_matches', $unplaceable['reason']);
        $this->assertStringContainsString('which website should it go on — QA Signup Bakery or QA Harbour Yoga?', ContentTarget::askWhichFor('Ten Tips for Autumn Marketing', $named));
    }

    public function test_a_qa_rejected_piece_is_never_reported_ready_and_the_website_is_named(): void
    {
        $rejected = CompletionReport::compose('Morning Vinyasa for Beginners', 'with a featured image', '0 internal links', 'rejected', ['Off-topic for QA Signup Bakery: This article is about yoga classes and has no relevance to a cafe business or its customers.'], 'QA Signup Bakery');
        $this->assertStringNotContainsString('is ready', $rejected);
        $this->assertStringContainsString('did not pass my QA', $rejected);
        $this->assertStringContainsString('NOT ready', $rejected);
        $this->assertStringContainsString('for QA Signup Bakery', $rejected);
        $this->assertStringContainsString('no relevance to a cafe business', $rejected);

        $needs = CompletionReport::compose('Piece', 'with a featured image', '1 internal links', 'needs_owner', ['QA could not run'], 'QA Harbour Yoga');
        $this->assertStringNotContainsString('is ready', $needs);
        $this->assertStringContainsString('needs your eyes', $needs);

        $ok = CompletionReport::compose('Sourdough Starters', 'with a featured image', '1 internal links', 'accepted', [], 'QA Signup Bakery');
        $this->assertSame('Your article "Sourdough Starters" for QA Signup Bakery is ready — with a featured image, 1 internal links. You\'ll find it in your drafts.', $ok);

        // and from a real row: verdict + website read from the task, not assumed
        $ws = $this->testWorkspace->id;
        $tid = (int) DB::table('tasks')->insertGetId(['workspace_id' => $ws, 'engine' => 'write', 'action' => 'write_article', 'category' => 'create', 'status' => 'completed', 'source' => 'agent', 'priority' => 'normal',
            'payload_json' => json_encode(['title' => 'Morning Vinyasa for Beginners', 'website_id' => $this->bakery]), 'qa_status' => 'rejected', 'qa_json' => json_encode(['verdict' => 'rejected', 'reasons' => ['Off-topic for QA Signup Bakery']]), 'created_at' => now(), 'updated_at' => now()]);
        $msg = CompletionReport::forArticle($tid, 'Morning Vinyasa for Beginners', 'with a featured image', '0 internal links');
        $this->assertStringContainsString('NOT ready', $msg);
        $this->assertStringContainsString('for QA Signup Bakery', $msg);
    }

    // ── C ──────────────────────────────────────────────────────────────────────────────────────────────────────────
    public function test_write_and_publish_is_creation_not_a_bulk_publish(): void
    {
        $this->assertFalse(DraftPublishing::asks('Good. Write one short blog article about morning vinyasa for beginners and publish it on QA Harbour Yoga.'));
        $this->assertFalse(DraftPublishing::asks('Draft a post about autumn menus and then make it live on the bakery site.'));
        $this->assertTrue(DraftPublishing::asks('Publish the drafts.'));
        $this->assertTrue(DraftPublishing::asks('Can you publish them all now?'));
        $this->assertFalse(DraftPublishing::asks("Don't publish the articles yet."));
    }
}
