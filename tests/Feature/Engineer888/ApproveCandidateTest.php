<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Access\Engineer888Access;
use App\Core\Engineer888\Chat\ActionCardExecutor;
use App\Core\Engineer888\Chat\ActionCardService;
use App\Core\Engineer888\Chat\CardIssuanceService;
use App\Core\Engineer888\Chat\ConversationService;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * APPROVE_CANDIDATE — the typed statement, and the domain effect behind it.
 *
 * WHAT THESE TESTS ARE ACTUALLY FOR. An approval that works is unremarkable.
 * The claims worth proving are the refusals: that a click cannot become an
 * approval, that a sentence which is nearly right is not right, and above all
 * that a card can never report success unless the ledger really says APPROVED.
 * That last one is the defect this whole layer was built after
 * (ACTION_CARD_CONSUMED_WITHOUT_DOMAIN_EFFECT, 2026-08-05).
 *
 * FIXTURES, NOT PRODUCTION. Every record is built in the isolated
 * levelup_e888_test database. No real candidate is approved to satisfy a test.
 */
class ApproveCandidateTest extends TestCase
{
    use RefreshDatabase;

    private object $conversation;
    private int $projectId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->seedCanonical();
        $this->conversation = app(ConversationService::class)->forOwner($this->req());
        $this->projectId = (int) DB::table('engineering_projects')->insertGetId([
            'company' => 'LevelUp Growth', 'key' => 'fixture-project', 'name' => 'Fixture Project',
            'repository_path' => '/tmp/fixture', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── the statement ────────────────────────────────────────────────

    public function test_the_exact_statement_approves(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();

        $this->press($card, ['statement' => $this->statementFor($card)]);

        $row = $this->ledgerFor($c);
        $this->assertNotNull($row);
        $this->assertSame('APPROVED', $row->state);
    }

    public function test_an_empty_statement_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();

        $this->assertRefused($card, ['statement' => ''], 'APPROVAL_STATEMENT_REQUIRED');
    }

    public function test_a_missing_statement_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();

        $this->assertRefused($card, [], 'APPROVAL_STATEMENT_REQUIRED');
    }

    public function test_a_whitespace_only_statement_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();

        $this->assertRefused($card, ['statement' => "   \n\t  "], 'APPROVAL_STATEMENT_REQUIRED');
    }

    public function test_a_generic_i_approve_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();

        $this->assertRefused($card, ['statement' => 'I approve'], 'APPROVAL_STATEMENT_MISMATCH');
    }

    public function test_a_statement_naming_another_candidate_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();

        $foreign = str_replace(
            (string) $card->candidate_uuid,
            (string) Str::uuid(),
            $this->statementFor($card)
        );

        $this->assertRefused($card, ['statement' => $foreign], 'APPROVAL_STATEMENT_MISMATCH');
    }

    public function test_a_statement_carrying_a_different_fingerprint_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();

        $real = $this->statementFor($card);
        $fp = $this->fingerprintIn($real);
        $wrong = str_replace($fp, str_repeat('a', strlen($fp)), $real);

        $this->assertRefused($card, ['statement' => $wrong], 'APPROVAL_STATEMENT_MISMATCH');
    }

    public function test_a_truncated_fingerprint_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();

        $real = $this->statementFor($card);
        $fp = $this->fingerprintIn($real);

        // The short form a human might paste from a UI that abbreviates.
        $this->assertRefused($card,
            ['statement' => str_replace($fp, substr($fp, 0, 12), $real)],
            'APPROVAL_STATEMENT_MISMATCH');
    }

    public function test_missing_punctuation_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();

        $this->assertRefused($card,
            ['statement' => rtrim($this->statementFor($card), '.')],
            'APPROVAL_STATEMENT_MISMATCH');
    }

    public function test_a_case_difference_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();

        $this->assertRefused($card,
            ['statement' => lcfirst($this->statementFor($card))],
            'APPROVAL_STATEMENT_MISMATCH');
    }

    public function test_an_internal_whitespace_difference_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();

        $this->assertRefused($card,
            ['statement' => str_replace('I approve candidate', 'I  approve candidate', $this->statementFor($card))],
            'APPROVAL_STATEMENT_MISMATCH');
    }

    public function test_outer_whitespace_is_forgiven_by_trim_alone(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();

        $this->press($card, ['statement' => "\n  " . $this->statementFor($card) . "  \t\n"]);

        $this->assertSame('APPROVED', $this->ledgerFor($c)->state);
    }

    // ── the domain effect ────────────────────────────────────────────

    public function test_a_pending_row_is_opened_when_none_exists(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();

        $this->assertNull($this->ledgerFor($c), 'a candidate awaiting first review has no ledger row');

        $this->press($card, ['statement' => $this->statementFor($card)]);

        $this->assertSame(1, DB::table('engineering_candidate_approvals')
            ->where('candidate_id', $c->id)->count());
    }

    public function test_the_approval_persists_every_bound_field(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();
        $statement = $this->statementFor($card);

        $this->press($card, ['statement' => $statement, 'comment' => 'Reviewed the diff and the test.']);

        $row = $this->ledgerFor($c);

        $this->assertSame('APPROVED', $row->state);
        $this->assertSame($this->fingerprintIn($statement), $row->fingerprint);
        $this->assertSame(1, (int) $row->approver_user_id);
        $this->assertSame('Mark', $row->approver_name);
        $this->assertNotNull($row->approved_at, 'the approval must carry a decision timestamp');
        $this->assertSame('Reviewed the diff and the test.', $row->comment);
        $this->assertSame($statement, $row->statement, 'the sentence is stored verbatim');
    }

    public function test_the_comment_is_optional(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();

        $this->press($card, ['statement' => $this->statementFor($card)]);

        $this->assertSame('APPROVED', $this->ledgerFor($c)->state);
        $this->assertNull($this->ledgerFor($c)->comment);
    }

    public function test_the_card_succeeds_only_with_an_approved_ledger_row(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();

        $this->press($card, ['statement' => $this->statementFor($card)]);

        $stored = DB::table('e888_action_cards')->where('uuid', $card->uuid)->first();

        $this->assertSame('executed', $stored->consumed_result);
        $this->assertNotNull($stored->consumed_at);
        $this->assertSame('APPROVED', $this->ledgerFor($c)->state,
            'a succeeded card without an APPROVED row is the whole defect class');
    }

    public function test_a_refused_approval_leaves_no_decision_behind(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();

        $this->assertRefused($card, ['statement' => 'I approve'], 'APPROVAL_STATEMENT_MISMATCH');

        $row = $this->ledgerFor($c);

        // record() may legitimately not have run at all; what must never happen
        // is a decided row produced by a refused press.
        if ($row !== null) {
            $this->assertSame('PENDING', $row->state);
            $this->assertNull($row->approved_at);
        }
    }

    public function test_an_already_decided_candidate_refuses(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();
        $statement = $this->statementFor($card);

        $this->press($card, ['statement' => $statement]);

        // A second, independently issued card against the same decided candidate.
        $second = app(ActionCardService::class)->issue(
            $this->req(), $this->conversation, null,
            ActionCardService::APPROVE_CANDIDATE, $this->taskUuid($c), $c->uuid
        );

        $this->assertRefused($second, ['statement' => $statement], 'LEDGER_NOT_PENDING');

        $this->assertSame(1, DB::table('engineering_candidate_approvals')
            ->where('candidate_id', $c->id)->count());
    }

    // ── safety ───────────────────────────────────────────────────────

    public function test_a_duplicate_press_produces_no_second_approval(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();
        $statement = $this->statementFor($card);

        $this->press($card, ['statement' => $statement]);

        try {
            $this->press($card, ['statement' => $statement]);
            $this->fail('the same card was consumed twice');
        } catch (HttpException) {
            // Already consumed. Refused at validate(), before any domain call.
        }

        $this->assertSame(1, DB::table('engineering_candidate_approvals')
            ->where('candidate_id', $c->id)->count());
        $this->assertSame(1, DB::table('engineering_candidate_approvals')
            ->where('candidate_id', $c->id)->where('state', 'APPROVED')->count());
    }

    public function test_two_concurrent_presses_yield_exactly_one_approval(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();
        $statement = $this->statementFor($card);

        $ok = 0;

        foreach ([1, 2] as $ignored) {
            try {
                $this->press($card, ['statement' => $statement]);
                $ok++;
            } catch (HttpException) {
                // The loser of the conditional claim.
            }
        }

        $this->assertSame(1, $ok);
        $this->assertSame(1, DB::table('engineering_candidate_approvals')
            ->where('candidate_id', $c->id)->count());
    }

    public function test_a_candidate_changed_after_issuance_refuses(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();
        $statement = $this->statementFor($card);

        DB::table('engineering_candidates')->where('id', $c->id)
            ->update(['content_fingerprint' => 'fp-CHANGED']);

        try {
            $this->press($card, ['statement' => $statement]);
            $this->fail('a card whose candidate moved must not approve');
        } catch (HttpException) {
        }

        $this->assertNull($this->ledgerFor($c));
    }

    public function test_a_superseded_candidate_refuses(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();
        $statement = $this->statementFor($card);

        DB::table('engineering_candidates')->where('id', $c->id)->update(['superseded_at' => now()]);

        try {
            $this->press($card, ['statement' => $statement]);
            $this->fail('a superseded candidate must not approve');
        } catch (HttpException) {
        }

        $row = $this->ledgerFor($c);
        $this->assertTrue($row === null || $row->state !== 'APPROVED');
    }

    public function test_an_expired_card_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();
        $statement = $this->statementFor($card);

        DB::table('e888_action_cards')->where('uuid', $card->uuid)
            ->update(['expires_at' => now()->subMinute()]);

        $this->expectException(HttpException::class);
        $this->press($card, ['statement' => $statement]);
    }

    public function test_a_revoked_card_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();
        $statement = $this->statementFor($card);

        DB::table('e888_action_cards')->where('uuid', $card->uuid)->update(['revoked_at' => now()]);

        $this->expectException(HttpException::class);
        $this->press($card, ['statement' => $statement]);
    }

    public function test_the_wrong_endpoint_refuses(): void
    {
        $this->candidate();
        $card = $this->approveCard();
        $statement = $this->statementFor($card);

        $this->expectException(HttpException::class);
        app(ActionCardService::class)->consume(
            $this->req(), $card->uuid, ActionCardService::EXECUTE_TASK, 'executed',
            ['statement' => $statement]
        );
    }

    public function test_a_foreign_owner_cannot_approve(): void
    {
        $this->candidate();
        $card = $this->approveCard();
        $statement = $this->statementFor($card);

        $this->expectException(HttpException::class);
        app(ActionCardService::class)->consume(
            $this->req(990016, 'other@levelupgrowth.io'),
            $card->uuid, ActionCardService::APPROVE_CANDIDATE, 'executed',
            ['statement' => $statement]
        );
    }

    public function test_a_malformed_card_uuid_refuses(): void
    {
        $this->expectException(HttpException::class);
        app(ActionCardService::class)->consume(
            $this->req(), 'not-a-uuid', ActionCardService::APPROVE_CANDIDATE, 'executed',
            ['statement' => 'anything']
        );
    }

    // ── reconciliation ───────────────────────────────────────────────

    public function test_approval_retires_both_cards_and_offers_execution(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();

        $this->press($card, ['statement' => $this->statementFor($card)]);

        $types = collect($this->issuer()->reconcile($this->req(), $this->conversation))
            ->pluck('action_type')->all();

        $this->assertNotContains(ActionCardService::APPROVE_CANDIDATE, $types,
            'a decided candidate must not still offer approval');
        $this->assertNotContains(ActionCardService::REJECT_CANDIDATE, $types,
            'a decided candidate must not still offer rejection');
        $this->assertContains(ActionCardService::EXECUTE_TASK, $types);
    }

    public function test_reconciliation_is_idempotent_after_approval(): void
    {
        $c = $this->candidate();
        $card = $this->approveCard();

        $this->press($card, ['statement' => $this->statementFor($card)]);

        $first = collect($this->issuer()->reconcile($this->req(), $this->conversation))
            ->pluck('uuid')->sort()->values()->all();

        for ($i = 0; $i < 3; $i++) {
            $again = collect($this->issuer()->reconcile($this->req(), $this->conversation))
                ->pluck('uuid')->sort()->values()->all();
            $this->assertSame($first, $again, 'polling must not manufacture or rotate cards');
        }

        $this->assertSame(1, DB::table('engineering_candidate_approvals')
            ->where('candidate_id', $c->id)->count());
    }

    // ── the card contract ────────────────────────────────────────────

    public function test_only_approval_cards_carry_a_required_statement(): void
    {
        $this->candidate();
        $this->issuer()->reconcile($this->req(), $this->conversation);

        $visible = app(ActionCardService::class)->visibleFor($this->req(), $this->conversation);
        $this->assertNotEmpty($visible);

        foreach ($visible as $card) {
            if ($card['action_type'] === ActionCardService::APPROVE_CANDIDATE) {
                $this->assertNotNull($card['required_statement']);
                $this->assertStringStartsWith('I approve candidate ', $card['required_statement']);
                $this->assertStringEndsWith(' and the exact file changes shown.', $card['required_statement']);
            } else {
                $this->assertNull($card['required_statement'],
                    $card['action_type'] . ' must not carry an approval statement');
            }
        }
    }

    public function test_the_card_payload_never_exposes_the_content_fingerprint(): void
    {
        $this->candidate();
        $this->issuer()->reconcile($this->req(), $this->conversation);

        foreach (app(ActionCardService::class)->visibleFor($this->req(), $this->conversation) as $card) {
            $this->assertArrayNotHasKey('content_fingerprint', $card);
            $this->assertArrayNotHasKey('file_hashes', $card);
            $this->assertArrayNotHasKey('file_hashes_json', $card);
        }
    }

    // ── everything else stays shut ───────────────────────────────────

    public function test_the_remaining_actions_are_still_fail_closed(): void
    {
        $this->candidate();

        foreach ([
            ActionCardService::EXECUTE_TASK,
            ActionCardService::APPROVE_RECOVERY,
            ActionCardService::APPROVE_MIGRATION,
            ActionCardService::REVOKE_APPROVAL,
        ] as $type) {
            $card = (object) ['action_type' => $type, 'candidate_uuid' => null, 'uuid' => 'x'];
            $out = app(ActionCardExecutor::class)->execute($this->req(), $card, []);

            $this->assertFalse($out['ok'], $type . ' must not report success');
            $this->assertSame(ActionCardExecutor::NOT_IMPLEMENTED, $out['reason']);
        }
    }

    public function test_rejection_still_works_alongside_approval(): void
    {
        $c = $this->candidate();
        $this->issuer()->reconcile($this->req(), $this->conversation);
        $card = $this->cardOfType(ActionCardService::REJECT_CANDIDATE);

        app(ActionCardService::class)->consume(
            $this->req(), $card->uuid, ActionCardService::REJECT_CANDIDATE, 'executed',
            ['instruction' => 'Not this way.']
        );

        $row = $this->ledgerFor($c);
        $this->assertSame('REJECTED', $row->state);
        $this->assertSame('Not this way.', $row->comment);
    }

    // ── fixtures ─────────────────────────────────────────────────────

    private function press(object $card, array $input): object
    {
        return app(ActionCardService::class)->consume(
            $this->req(), $card->uuid, ActionCardService::APPROVE_CANDIDATE, 'executed', $input
        );
    }

    /** Press, expect refusal, and prove the reason was persisted on the card. */
    private function assertRefused(object $card, array $input, string $expectedReason): void
    {
        try {
            $this->press($card, $input);
            $this->fail("expected {$expectedReason}, but the press succeeded");
        } catch (HttpException) {
            // The refusal is thrown after the transaction rolls back.
        }

        $row = DB::table('e888_action_cards')->where('uuid', $card->uuid)->first();

        $this->assertSame($expectedReason, $row->consumed_result);
        $this->assertNull($row->consumed_at, 'a refused card must be released, not left claimed');
    }

    private function statementFor(object $card): string
    {
        $s = app(ActionCardExecutor::class)->requiredStatementFor($card);
        $this->assertNotNull($s, 'the server must generate a statement for an approval card');

        return $s;
    }

    private function fingerprintIn(string $statement): string
    {
        preg_match('/fingerprint ([0-9a-f]{64})/', $statement, $m);
        $this->assertNotEmpty($m, 'the statement must name a full 64-character fingerprint');

        return $m[1];
    }

    private function approveCard(): object
    {
        $this->issuer()->reconcile($this->req(), $this->conversation);

        return $this->cardOfType(ActionCardService::APPROVE_CANDIDATE);
    }

    private function ledgerFor(object $candidate): ?object
    {
        return DB::table('engineering_candidate_approvals')
            ->where('candidate_id', $candidate->id)->first();
    }

    private function issuer(): CardIssuanceService
    {
        return app(CardIssuanceService::class);
    }

    private function cardOfType(string $type): object
    {
        $cards = $this->issuer()->reconcile($this->req(), $this->conversation);

        foreach ($cards as $c) {
            if ($c->action_type === $type) { return $c; }
        }

        $this->fail("no {$type} card was issued");
    }

    private function candidate(): object
    {
        $taskId = DB::table('engineering_tasks')->insertGetId([
            'uuid' => (string) Str::uuid(), 'project_id' => $this->projectId,
            'title' => 'Fixture task', 'description' => 'fixture', 'kind' => 'investigation',
            'status' => 'received', 'current_stage' => 'ANALYZE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $uuid = (string) Str::uuid();
        DB::table('engineering_candidates')->insert([
            'uuid' => $uuid, 'task_id' => $taskId, 'project_id' => $this->projectId,
            'provider' => 'fixture', 'model' => 'fixture', 'status' => 'VALIDATED',
            'request_fingerprint' => 'req-fp', 'content_fingerprint' => 'fp-ORIGINAL',
            'payload' => json_encode(['files' => [['path' => 'a.php', 'content' => 'x']]]),
            'file_count' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('engineering_candidates')->where('uuid', $uuid)->first();
    }

    private function taskUuid(object $candidate): string
    {
        return (string) DB::table('engineering_tasks')->where('id', $candidate->task_id)->value('uuid');
    }

    /**
     * A request carrying a resolved user.
     *
     * The request is bound BEFORE the resolver is installed: binding fires
     * Laravel's request-rebinding handler, which would otherwise replace the
     * resolver with one that asks an unauthenticated guard and returns null.
     */
    private function req(int $id = 1, string $email = 'admin@levelupgrowth.io'): Request
    {
        $user = User::find($id);

        $r = Request::create('/api/admin/engineer888/chat/messages', 'GET');
        $r->attributes->set('auth_via', 'jwt_cookie');

        app()->instance('request', $r);
        $r->setUserResolver(fn () => $user);

        return $r;
    }

    private function seedCanonical(): void
    {
        $u = new User();
        $u->id = 1; $u->name = 'Mark'; $u->email = Engineer888Access::CANONICAL_EMAIL;
        $u->password = 'irrelevant'; $u->is_platform_admin = true; $u->status = 'active';
        $u->save();

        $o = new User();
        $o->id = 990016; $o->name = 'Other'; $o->email = 'other@levelupgrowth.io';
        $o->password = 'irrelevant'; $o->is_platform_admin = true; $o->status = 'active';
        $o->save();

        DB::table('engineering_access_grants')->where('user_id', 1)->delete();
        DB::table('engineering_access_grants')->insert([
            'user_id' => 1, 'email' => Engineer888Access::CANONICAL_EMAIL,
            'capabilities' => json_encode(Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by' => 'fixture', 'granted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
