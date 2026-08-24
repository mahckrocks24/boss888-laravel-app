<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Core\Tenancy\BelongsToWorkspace;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\Models\EmailAlias;
use App\Engines\Infrastructure\Email\Models\EmailCatchAll;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailForwarder;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Models\EmailUsage;
use App\Engines\Infrastructure\Email\States\EmailAliasState;
use App\Engines\Infrastructure\Email\States\EmailCatchAllState;
use App\Engines\Infrastructure\Email\States\EmailDomainState;
use App\Engines\Infrastructure\Email\States\EmailForwarderState;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailVerificationState;
use App\Engines\Infrastructure\Email\Support\EmailAddress;
use App\Engines\Infrastructure\Email\Support\ForwarderLoopSafety;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/**
 * INFRA888 · E1-D — ENTITY, RELATIONSHIP AND TENANCY PROOF.
 */
class BusinessEmailModelTest extends TestCase
{
    use RefreshDatabase;

    private const WS_A = 7301;
    private const WS_B = 7302;

    protected function tearDown(): void
    {
        WorkspaceContext::reset();

        parent::tearDown();
    }

    /** @return array<class-string,array<int,string>> model => deliberately excluded columns */
    private function models(): array
    {
        return [
            EmailDomain::class    => [],
            EmailMailbox::class   => [],
            EmailAlias::class     => [],
            EmailForwarder::class => [],
            EmailCatchAll::class  => [],
            EmailUsage::class     => [],
        ];
    }

    // ── tenancy ──────────────────────────────────────────────────────────────

    public function test_every_model_is_workspace_scoped(): void
    {
        foreach (array_keys($this->models()) as $model) {
            $this->assertContains(
                BelongsToWorkspace::class,
                class_uses_recursive($model),
                "{$model} must use BelongsToWorkspace — a workspace-owned model without it is globally unscoped."
            );
        }
    }

    public function test_a_workspace_cannot_see_another_workspaces_email(): void
    {
        WorkspaceContext::run(self::WS_A, fn () => $this->seedDomain('tenant-a.test'));
        WorkspaceContext::run(self::WS_B, fn () => $this->seedDomain('tenant-b.test'));

        $seenByA = WorkspaceContext::run(self::WS_A, fn () => EmailDomain::query()->pluck('domain')->all());
        $seenByB = WorkspaceContext::run(self::WS_B, fn () => EmailDomain::query()->pluck('domain')->all());

        $this->assertSame(['tenant-a.test'], $seenByA);
        $this->assertSame(['tenant-b.test'], $seenByB);
    }

    public function test_tenancy_scoping_reaches_every_child_entity(): void
    {
        $ids = WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('children-a.test');
            $mailbox = $this->seedMailbox($domain, 'sales');
            $this->seedAliasToMailbox($domain, $mailbox, 'info');
            $this->seedForwarder($domain, 'jobs', 'careers@elsewhere.test');
            $this->seedCatchAll($domain, $mailbox);
            $this->seedUsage($domain, $mailbox);

            return [$domain->id, $mailbox->id];
        });

        $this->assertNotEmpty($ids);

        WorkspaceContext::run(self::WS_B, function () {
            $this->assertSame(0, EmailDomain::query()->count());
            $this->assertSame(0, EmailMailbox::query()->count());
            $this->assertSame(0, EmailAlias::query()->count());
            $this->assertSame(0, EmailForwarder::query()->count());
            $this->assertSame(0, EmailCatchAll::query()->count());
            $this->assertSame(0, EmailUsage::query()->count());
        });

        WorkspaceContext::run(self::WS_A, function () {
            $this->assertSame(1, EmailMailbox::query()->count());
            $this->assertSame(1, EmailAlias::query()->count());
            $this->assertSame(1, EmailForwarder::query()->count());
            $this->assertSame(1, EmailCatchAll::query()->count());
            $this->assertSame(1, EmailUsage::query()->count());
        });
    }

    public function test_a_workspace_id_cannot_be_reassigned_after_creation(): void
    {
        $domain = WorkspaceContext::run(self::WS_A, fn () => $this->seedDomain('immutable.test'));

        $this->expectException(RuntimeException::class);

        WorkspaceContext::run(self::WS_A, function () use ($domain) {
            $domain->workspace_id = self::WS_B;
            $domain->save();
        });
    }

    public function test_workspace_id_is_populated_from_context_when_not_supplied(): void
    {
        $domain = WorkspaceContext::run(self::WS_A, fn () => EmailDomain::create([
            'domain'  => 'ambient.test',
            'custody' => 'managed_by_us',
        ]));

        $this->assertSame(self::WS_A, (int) $domain->workspace_id);
    }

    // ── relationships ────────────────────────────────────────────────────────

    public function test_the_domain_owns_every_child_entity(): void
    {
        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('graph.test');
            $mailbox = $this->seedMailbox($domain, 'hello');
            $this->seedAliasToMailbox($domain, $mailbox, 'hi');
            $this->seedForwarder($domain, 'out', 'somebody@elsewhere.test');
            $this->seedCatchAll($domain, $mailbox);
            $this->seedUsage($domain, $mailbox);

            $fresh = EmailDomain::query()->with(['mailboxes', 'aliases', 'forwarders', 'catchAll', 'usageSamples'])
                ->findOrFail($domain->id);

            $this->assertCount(1, $fresh->mailboxes);
            $this->assertCount(1, $fresh->aliases);
            $this->assertCount(1, $fresh->forwarders);
            $this->assertNotNull($fresh->catchAll);
            $this->assertCount(1, $fresh->usageSamples);

            $this->assertSame($domain->id, $fresh->mailboxes->first()->domain->id);
            $this->assertSame($mailbox->id, $fresh->aliases->first()->targetMailbox->id);
            $this->assertSame($mailbox->id, $fresh->catchAll->targetMailbox->id);
            $this->assertSame($mailbox->id, $fresh->usageSamples->first()->mailbox->id);
        });
    }

    public function test_a_mailbox_address_is_derived_rather_than_stored(): void
    {
        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('derived.test');
            $mailbox = $this->seedMailbox($domain, 'Sales');

            $this->assertSame('sales@derived.test', $mailbox->address());
            $this->assertFalse(Schema::hasColumn('email_mailboxes', 'address'));
        });
    }

    public function test_deleting_a_domain_removes_its_children(): void
    {
        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('cascade.test');
            $mailbox = $this->seedMailbox($domain, 'gone');
            $this->seedUsage($domain, $mailbox);
            $this->seedForwarder($domain, 'fwd', 'x@elsewhere.test');

            $domain->forceDelete();

            $this->assertSame(0, EmailMailbox::query()->count());
            $this->assertSame(0, EmailUsage::query()->count());
            $this->assertSame(0, EmailForwarder::query()->count());
        });
    }

    public function test_a_mailbox_an_alias_delivers_into_cannot_be_hard_deleted(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('protected.test');
            $mailbox = $this->seedMailbox($domain, 'target');
            $this->seedAliasToMailbox($domain, $mailbox, 'points-here');

            $mailbox->forceDelete();
        });
    }

    // ── mass assignment drift ────────────────────────────────────────────────

    public function test_every_column_is_mass_assignable(): void
    {
        // This defect class has bitten the project three times: a migration adds
        // a column, the code writes it, $fillable does not list it, and Laravel
        // discards the value in silence.
        //
        // `active_flag` is excluded because MySQL REJECTS writes to a generated
        // column. Making it fillable would be an error, not a fix.
        $always = ['id', 'created_at', 'updated_at', 'deleted_at', 'active_flag'];
        $problems = [];

        foreach ($this->models() as $modelClass => $excluded) {
            /** @var \Illuminate\Database\Eloquent\Model $model */
            $model = new $modelClass();
            $missing = array_diff(Schema::getColumnListing($model->getTable()), $model->getFillable(), $always, $excluded);

            if ($missing !== []) {
                $problems[] = class_basename($modelClass) . ': ' . implode(', ', $missing);
            }
        }

        $this->assertSame([], $problems, "Columns exist but are not mass-assignable:\n  " . implode("\n  ", $problems));
    }

    public function test_no_model_declares_a_column_that_does_not_exist(): void
    {
        $problems = [];

        foreach (array_keys($this->models()) as $modelClass) {
            /** @var \Illuminate\Database\Eloquent\Model $model */
            $model = new $modelClass();
            $phantom = array_diff($model->getFillable(), Schema::getColumnListing($model->getTable()));

            if ($phantom !== []) {
                $problems[] = class_basename($modelClass) . ': ' . implode(', ', $phantom);
            }
        }

        $this->assertSame([], $problems, "Fillable names a non-existent column:\n  " . implode("\n  ", $problems));
    }

    public function test_casts_produce_the_declared_types(): void
    {
        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('casts.test');
            $domain->settings_json = ['a' => 1];
            $domain->dns_verified_at = now();
            $domain->save();

            $fresh = EmailDomain::query()->findOrFail($domain->id);

            $this->assertIsArray($fresh->settings_json);
            $this->assertInstanceOf(\Illuminate\Support\Carbon::class, $fresh->dns_verified_at);

            $mailbox = $this->seedMailbox($domain, 'cast');
            $this->assertIsInt(EmailMailbox::query()->findOrFail($mailbox->id)->quota_mb);
        });
    }

    // ── lifecycle behaviour on the models ────────────────────────────────────

    public function test_a_freshly_created_model_already_knows_its_initial_state(): void
    {
        // Eloquent does not read database defaults back after an insert. Before
        // the models declared $attributes, a just-created entity held an EMPTY
        // lifecycle_state in memory while the row on disk held the right one,
        // and the first transitionTo() failed with "unknown current state ''".
        // Nothing about the code looked wrong; only this assertion showed it.
        WorkspaceContext::run(self::WS_A, function () {
            // Created with nothing but its name: every other value must come
            // from the model's own declared defaults.
            $domain = EmailDomain::create(['domain' => 'initial-state.test']);

            $this->assertSame(EmailDomainState::initial(), $domain->lifecycle_state);
            $this->assertSame(EmailVerificationState::initial(), $domain->verification_state);
            $this->assertSame('unknown', $domain->custody, 'Custody must default to the honest answer, not a flattering one.');
            $this->assertSame('unknown', $domain->health_state);

            $mailbox = $this->seedMailbox($domain, 'initial');
            $this->assertSame(EmailMailboxState::initial(), $mailbox->lifecycle_state);
            $this->assertSame(EmailMailbox::SUSPENSION_NONE, $mailbox->suspension_reason_code);

            $alias = $this->seedAliasToMailbox($domain, $mailbox, 'initial-alias');
            $this->assertSame(EmailAliasState::initial(), $alias->lifecycle_state);

            $forwarder = $this->seedForwarder($domain, 'initial-fwd', 'x@elsewhere.test');
            $this->assertSame(EmailForwarderState::initial(), $forwarder->lifecycle_state);
            $this->assertSame(ForwarderLoopSafety::UNCHECKED, $forwarder->loop_check_state);

            $catchAll = $this->seedCatchAll($domain, $mailbox);
            $this->assertSame(EmailCatchAllState::initial(), $catchAll->state);
        });
    }

    public function test_a_freshly_created_model_can_immediately_transition(): void
    {
        // The behaviour the missing defaults actually broke.
        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('immediate.test');

            $domain->transitionTo(EmailDomainState::VERIFYING_DNS);

            $this->assertSame(EmailDomainState::VERIFYING_DNS, $domain->lifecycle_state);
        });
    }

    public function test_model_defaults_agree_with_the_state_machines(): void
    {
        // Two declarations of "where things start" — the model and the machine —
        // must not drift apart.
        $expected = [
            [EmailDomain::class,    'lifecycle_state',    EmailDomainState::class],
            [EmailDomain::class,    'verification_state', EmailVerificationState::class],
            [EmailMailbox::class,   'lifecycle_state',    EmailMailboxState::class],
            [EmailAlias::class,     'lifecycle_state',    EmailAliasState::class],
            [EmailForwarder::class, 'lifecycle_state',    EmailForwarderState::class],
            [EmailCatchAll::class,  'state',              EmailCatchAllState::class],
        ];

        foreach ($expected as [$modelClass, $attribute, $machine]) {
            /** @var \Illuminate\Database\Eloquent\Model $model */
            $model = new $modelClass();

            $this->assertSame(
                $machine::initial(),
                $model->getAttribute($attribute),
                class_basename($modelClass) . "::\${$attribute} disagrees with " . class_basename($machine) . '::initial()'
            );
        }
    }

    public function test_a_model_refuses_an_illegal_transition(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WorkspaceContext::run(self::WS_A, function () {
            $this->seedDomain('illegal.test')->transitionTo(EmailDomainState::ACTIVE);
        });
    }

    public function test_a_legal_transition_records_the_previous_state_and_when(): void
    {
        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('legal.test');
            $domain->transitionTo(EmailDomainState::VERIFYING_DNS)->save();

            $fresh = EmailDomain::query()->findOrFail($domain->id);

            $this->assertSame(EmailDomainState::VERIFYING_DNS, $fresh->lifecycle_state);
            $this->assertSame(EmailDomainState::CONNECTED, $fresh->previous_state);
            $this->assertNotNull($fresh->state_changed_at);
        });
    }

    public function test_dns_verification_requires_both_the_state_and_the_evidence_timestamp(): void
    {
        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('evidence.test');

            $domain->transitionVerificationTo(EmailVerificationState::PENDING_RECORDS);
            $domain->transitionVerificationTo(EmailVerificationState::CHECKING);
            $domain->transitionVerificationTo(EmailVerificationState::VERIFIED);
            $domain->save();

            // State alone is not enough: the timestamp is the evidence.
            $this->assertFalse($domain->isDnsVerified());

            $domain->dns_verified_at = now();
            $domain->save();

            $this->assertTrue($domain->isDnsVerified());
        });
    }

    public function test_a_suspended_mailbox_reports_who_may_restore_it(): void
    {
        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('suspend.test');
            $mailbox = $this->seedMailbox($domain, 'held');

            $mailbox->transitionTo(EmailMailboxState::PROVISIONING)
                ->transitionTo(EmailMailboxState::ACTIVE)
                ->transitionTo(EmailMailboxState::SUSPENDED);
            $mailbox->suspension_reason_code = EmailMailbox::SUSPENSION_BILLING_HOLD;
            $mailbox->save();

            $this->assertTrue($mailbox->isSuspended());
            $this->assertFalse($mailbox->isCustomerRestorable(), 'A billing hold is not customer-reversible.');

            $mailbox->suspension_reason_code = EmailMailbox::SUSPENSION_CUSTOMER_REQUESTED;
            $this->assertTrue($mailbox->isCustomerRestorable());
        });
    }

    public function test_an_unsampled_mailbox_reports_no_usage_rather_than_zero(): void
    {
        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('unsampled.test');
            $mailbox = $this->seedMailbox($domain, 'fresh');

            $this->assertNull($mailbox->storage_used_mb);
            $this->assertNull($mailbox->quotaUsedPercent(), 'Never-measured must not render as 0%.');

            $mailbox->storage_used_mb = 512;
            $mailbox->quota_mb = 1024;

            $this->assertSame(50.0, $mailbox->quotaUsedPercent());
        });
    }

    // ── target exclusivity ───────────────────────────────────────────────────

    public function test_an_alias_cannot_declare_two_targets(): void
    {
        $this->expectException(RuntimeException::class);

        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('twotargets.test');
            $mailbox = $this->seedMailbox($domain, 'real');

            EmailAlias::create([
                'email_domain_id'   => $domain->id,
                'source_local_part' => 'confused',
                'target_type'       => EmailAlias::TARGET_MAILBOX,
                'target_mailbox_id' => $mailbox->id,
                'target_address'    => 'elsewhere@example.test',
            ]);
        });
    }

    public function test_an_alias_cannot_declare_no_target(): void
    {
        $this->expectException(RuntimeException::class);

        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('notarget.test');

            EmailAlias::create([
                'email_domain_id'   => $domain->id,
                'source_local_part' => 'nowhere',
                'target_type'       => EmailAlias::TARGET_ADDRESS,
            ]);
        });
    }

    public function test_an_enabled_catchall_must_have_a_target(): void
    {
        $this->expectException(RuntimeException::class);

        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('catchall-empty.test');

            EmailCatchAll::create([
                'email_domain_id' => $domain->id,
                'state'           => EmailCatchAllState::ENABLED,
            ]);
        });
    }

    public function test_one_catchall_per_domain_is_enforced(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('one-catchall.test');
            $mailbox = $this->seedMailbox($domain, 'catch');

            $this->seedCatchAll($domain, $mailbox);
            $this->seedCatchAll($domain, $mailbox);
        });
    }

    // ── address handling ─────────────────────────────────────────────────────

    public function test_address_normalisation_accepts_and_rejects_the_right_things(): void
    {
        $this->assertSame('sales@example.test', EmailAddress::normalize('  Sales@Example.TEST '));
        $this->assertSame('a.b+c-d_e@sub.example.test', EmailAddress::normalize('A.B+C-D_E@Sub.Example.Test'));

        foreach (['', 'nope', '@example.test', 'user@', 'user@localhost', '.lead@example.test',
                  'trail.@example.test', 'double..dot@example.test', 'sp ace@example.test'] as $bad) {
            $this->assertNull(EmailAddress::normalize($bad), "'{$bad}' should not normalise");
        }

        $this->assertFalse(EmailAddress::isValidLocalPart(str_repeat('a', 65)));
        $this->assertTrue(EmailAddress::isValidLocalPart(str_repeat('a', 64)));
    }

    public function test_privileged_local_parts_are_recognised(): void
    {
        foreach (['postmaster', 'ADMIN', 'hostmaster', 'abuse'] as $reserved) {
            $this->assertTrue(EmailAddress::isReservedLocalPart($reserved), "{$reserved} must be reserved");
        }

        $this->assertFalse(EmailAddress::isReservedLocalPart('sales'));
    }

    // ── forwarding loop safety ───────────────────────────────────────────────

    public function test_a_forwarder_pointing_at_itself_is_refused(): void
    {
        $verdict = ForwarderLoopSafety::evaluate('a@example.test', 'a@example.test');

        $this->assertSame(ForwarderLoopSafety::SELF_REFERENCE, $verdict['state']);
        $this->assertTrue(ForwarderLoopSafety::isBlocking($verdict['state']));
    }

    public function test_a_forwarder_pointing_back_into_an_operated_domain_is_refused(): void
    {
        $verdict = ForwarderLoopSafety::evaluate('a@example.test', 'b@example.test', ['example.test']);

        $this->assertSame(ForwarderLoopSafety::SELF_REFERENCE, $verdict['state']);
    }

    public function test_a_chain_that_returns_to_the_source_is_refused(): void
    {
        $verdict = ForwarderLoopSafety::evaluate(
            'a@example.test',
            'b@other.test',
            ['example.test'],
            ['b@other.test' => 'c@third.test', 'c@third.test' => 'a@example.test']
        );

        $this->assertSame(ForwarderLoopSafety::CHAIN_DETECTED, $verdict['state']);
    }

    public function test_a_terminating_chain_is_reported_as_safe_with_its_hop_count(): void
    {
        $verdict = ForwarderLoopSafety::evaluate(
            'a@example.test',
            'b@other.test',
            ['example.test'],
            ['b@other.test' => 'c@third.test']
        );

        $this->assertSame(ForwarderLoopSafety::SAFE, $verdict['state']);
        $this->assertFalse(ForwarderLoopSafety::isBlocking($verdict['state']));
        $this->assertGreaterThan(0, $verdict['hops']);
    }

    public function test_an_unparseable_address_yields_no_conclusion_rather_than_a_clearance(): void
    {
        $verdict = ForwarderLoopSafety::evaluate('a@example.test', 'not-an-address');

        $this->assertSame(ForwarderLoopSafety::UNDETERMINED, $verdict['state']);
        $this->assertTrue(ForwarderLoopSafety::isBlocking($verdict['state']));
    }

    public function test_a_new_forwarder_is_blocked_until_it_has_been_checked(): void
    {
        WorkspaceContext::run(self::WS_A, function () {
            $domain = $this->seedDomain('unchecked.test');
            $forwarder = $this->seedForwarder($domain, 'new', 'someone@elsewhere.test');

            $this->assertSame(ForwarderLoopSafety::UNCHECKED, $forwarder->loop_check_state);
            $this->assertTrue($forwarder->isLoopBlocked(), 'An unevaluated forwarder must not be assumed safe.');

            $forwarder->recordLoopVerdict(
                ForwarderLoopSafety::evaluate('new@unchecked.test', 'someone@elsewhere.test', ['unchecked.test'])
            )->save();

            $this->assertFalse($forwarder->fresh()->isLoopBlocked());
        });
    }

    // ── seeding helpers ──────────────────────────────────────────────────────

    private function seedDomain(string $domain): EmailDomain
    {
        return EmailDomain::create([
            'domain'  => $domain,
            'custody' => 'managed_by_us',
        ]);
    }

    private function seedMailbox(EmailDomain $domain, string $localPart): EmailMailbox
    {
        return EmailMailbox::create([
            'email_domain_id' => $domain->id,
            'local_part'      => strtolower($localPart),
            'quota_mb'        => 1024,
        ]);
    }

    private function seedAliasToMailbox(EmailDomain $domain, EmailMailbox $mailbox, string $localPart): EmailAlias
    {
        return EmailAlias::create([
            'email_domain_id'   => $domain->id,
            'source_local_part' => $localPart,
            'target_type'       => EmailAlias::TARGET_MAILBOX,
            'target_mailbox_id' => $mailbox->id,
        ]);
    }

    private function seedForwarder(EmailDomain $domain, string $localPart, string $destination): EmailForwarder
    {
        return EmailForwarder::create([
            'email_domain_id'     => $domain->id,
            'source_local_part'   => $localPart,
            'destination_address' => $destination,
        ]);
    }

    private function seedCatchAll(EmailDomain $domain, EmailMailbox $mailbox): EmailCatchAll
    {
        return EmailCatchAll::create([
            'email_domain_id'   => $domain->id,
            'state'             => EmailCatchAllState::DISABLED,
            'target_type'       => EmailCatchAll::TARGET_MAILBOX,
            'target_mailbox_id' => $mailbox->id,
        ]);
    }

    private function seedUsage(EmailDomain $domain, EmailMailbox $mailbox): EmailUsage
    {
        return EmailUsage::create([
            'email_domain_id'  => $domain->id,
            'scope'            => EmailUsage::SCOPE_MAILBOX,
            'email_mailbox_id' => $mailbox->id,
            'storage_used_mb'  => 12,
            'storage_quota_mb' => 1024,
            'source'           => EmailUsage::SOURCE_PROVIDER,
            'confidence'       => 'observed',
            'observed_at'      => now(),
            'idempotency_key'  => 'usage-' . uniqid('', true),
        ]);
    }
}
