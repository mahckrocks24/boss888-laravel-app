<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability;
use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapabilitySet;
use App\Connectors\Infrastructure\InfrastructureConnectorResolver;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Models\EmailAlias;
use App\Engines\Infrastructure\Email\Models\EmailCatchAll;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailForwarder;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Models\EmailUsage;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\States\EmailCatchAllState;
use App\Engines\Infrastructure\Email\States\EmailDomainState;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailRoutingRuleState;
use App\Engines\Infrastructure\Email\States\EmailVerificationState;
use App\Engines\Infrastructure\Email\Support\BusinessEmailFailure as Failure;
use App\Engines\Infrastructure\Email\Support\EmailOperationContext;
use App\Engines\Infrastructure\Email\Support\ForwarderLoopSafety;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\Models\InfraProviderResource;
use App\Engines\Infrastructure\States\OperationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Infrastructure\Email\FakeEmailProvider;
use Tests\TestCase;

/**
 * INFRA888 · E2-E/F/G/I/J — THE FLOWS, PROVEN AGAINST A FAKE.
 *
 * Every assertion here is about whether the engine tells the truth. The
 * interesting cases are not the happy paths — they are `accepted` (the provider
 * said yes and we must not believe it), ambiguity (the provider said nothing
 * and the mutation may have happened), and idempotency (the same intent must
 * never act twice).
 */
class BusinessEmailProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const WS = 9101;
    private const OTHER_WS = 9102;
    private const ACTOR = 771;
    private const APPROVER = 772;

    private FakeEmailProvider $fake;
    private BusinessEmailEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = FakeEmailProvider::make();

        // The resolver must be a singleton or the engine would receive a
        // different instance from the one the fake was injected into.
        $this->app->singleton(InfrastructureConnectorResolver::class);
        $this->app->make(InfrastructureConnectorResolver::class)->fake('email', $this->fake);

        $this->engine = $this->app->make(BusinessEmailEngine::class);
    }

    protected function tearDown(): void
    {
        WorkspaceContext::reset();

        parent::tearDown();
    }

    // ═══ E2-E — DOMAIN ONBOARDING ════════════════════════════════════════════

    public function test_the_full_domain_onboarding_chain_reaches_active(): void
    {
        $domain = $this->connectedDomain('onboard.test');

        // 1. Ask what DNS is needed. Moves connected -> verifying_dns.
        $requirements = $this->engine->dnsRequirements($this->context(Registry::DOMAIN_ONBOARD, $domain));
        $this->assertTrue($requirements->success);

        $domain->refresh();
        $this->assertSame(EmailDomainState::VERIFYING_DNS, $domain->lifecycle_state);
        $this->assertSame(EmailVerificationState::PENDING_RECORDS, $domain->verification_state);
        $this->assertFalse($domain->isDnsVerified(), 'Issuing records is not evidence they exist.');

        // 2. The governed onboarding operation.
        $onboard = $this->engine->execute($this->context(Registry::DOMAIN_ONBOARD, $domain));
        $this->assertTrue($onboard->success);
        $this->assertTrue($this->fake->wasCalled('onboardDomain'));

        // 3. Observe DNS. Only this may move verification forward.
        $verify = $this->engine->execute($this->context(Registry::DOMAIN_VERIFY, $domain));
        $this->assertTrue($verify->success);

        $domain->refresh();
        $this->assertSame(EmailVerificationState::VERIFIED, $domain->verification_state);
        $this->assertSame(EmailDomainState::DNS_VERIFIED, $domain->lifecycle_state);
        $this->assertTrue($domain->isDnsVerified());

        // 4. Confirmation completes provisioning.
        $confirm = $this->engine->confirm($this->context(Registry::DOMAIN_ONBOARD, $domain));
        $this->assertTrue($confirm->verified);

        $domain->refresh();
        $this->assertSame(EmailDomainState::ACTIVE, $domain->lifecycle_state);
        $this->assertSame(EmailDomainState::PROVISIONING, $domain->previous_state, 'The chain must pass through provisioning.');
    }

    public function test_dns_requirements_are_persisted_without_provider_branding(): void
    {
        $domain = $this->connectedDomain('records.test');
        $this->engine->dnsRequirements($this->context(Registry::DOMAIN_ONBOARD, $domain));

        $records = $domain->refresh()->settings_json['dns_requirements'] ?? [];

        $this->assertNotEmpty($records);

        foreach ($records as $record) {
            // Structured fields in our vocabulary. The VALUE may contain a
            // provider hostname — that is the accepted, unavoidable limit — but
            // nothing else may.
            //
            // Canonicalised because MySQL's JSON type does not preserve object
            // key order; the SET of fields is what matters, not their order.
            $this->assertEqualsCanonicalizing(
                ['purpose', 'type', 'name', 'value', 'priority', 'ttl', 'required'],
                array_keys($record)
            );
            $this->assertContains($record['purpose'], ['mx', 'spf', 'dkim', 'dmarc', 'verification']);
            $this->assertContains($record['type'], ['MX', 'TXT', 'CNAME']);
        }
    }

    public function test_a_domain_cannot_be_completed_before_dns_is_observed(): void
    {
        $domain = $this->connectedDomain('premature.test');
        $this->engine->dnsRequirements($this->context(Registry::DOMAIN_ONBOARD, $domain));
        $this->engine->execute($this->context(Registry::DOMAIN_ONBOARD, $domain));

        // No verification has happened.
        $result = $this->engine->confirm($this->context(Registry::DOMAIN_ONBOARD, $domain->refresh()));

        $this->assertFalse($result->success);
        $this->assertSame(Failure::DOMAIN_NOT_VERIFIED, $result->errorCode);
        $this->assertNotSame(EmailDomainState::ACTIVE, $domain->refresh()->lifecycle_state);
    }

    public function test_failed_dns_observation_moves_the_domain_to_verification_failed(): void
    {
        $domain = $this->connectedDomain('badns.test');
        $this->engine->dnsRequirements($this->context(Registry::DOMAIN_ONBOARD, $domain));

        // An accepted (not verified) answer is not evidence.
        $this->fake->willReturn('verifyDomain', FakeEmailProvider::OUTCOME_ACCEPTED);

        $result = $this->engine->execute($this->context(Registry::DOMAIN_VERIFY, $domain->refresh()));

        $this->assertFalse($result->success);
        $domain->refresh();
        $this->assertSame(EmailVerificationState::FAILED, $domain->verification_state);
        $this->assertSame(EmailDomainState::VERIFICATION_FAILED, $domain->lifecycle_state);
        $this->assertNull($domain->dns_verified_at);
    }

    public function test_onboarding_the_same_domain_twice_uses_one_operation(): void
    {
        $domain = $this->connectedDomain('idem-domain.test');
        $this->engine->dnsRequirements($this->context(Registry::DOMAIN_ONBOARD, $domain));

        $this->engine->execute($this->context(Registry::DOMAIN_ONBOARD, $domain->refresh()));
        $this->engine->execute($this->context(Registry::DOMAIN_ONBOARD, $domain->refresh()));

        $this->assertSame(1, $this->operations()->where('operation', Registry::DOMAIN_ONBOARD)->count());
        $this->assertSame(1, $this->fake->callCount('onboardDomain'));
    }

    // ═══ E2-F — MAILBOX ══════════════════════════════════════════════════════

    public function test_a_verified_create_reaches_active_and_binds_a_provider_reference(): void
    {
        $domain = $this->activeDomain('mbx.test');
        $mailbox = $this->requestedMailbox($domain, 'sales');

        $result = $this->engine->execute($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox, 'create-1'));

        $this->assertTrue($result->verified);
        $this->assertSame(EmailMailboxState::ACTIVE, $mailbox->refresh()->lifecycle_state);
        $this->assertSame(EmailMailboxState::PROVISIONING, $mailbox->previous_state, 'It must pass through provisioning.');

        $binding = $this->bindingFor($mailbox);
        $this->assertNotNull($binding, 'Losing the provider reference would orphan the mailbox.');
        $this->assertSame('fake-email', $binding->provider);
        $this->assertNotEmpty($binding->provider_resource_id);

        $this->assertSame(OperationState::SUCCEEDED, $this->operationFor('create-1')->state);
    }

    public function test_an_accepted_create_stays_provisioning_until_read_back(): void
    {
        $domain = $this->activeDomain('accepted.test');
        $mailbox = $this->requestedMailbox($domain, 'pending');

        $this->fake->alwaysAccepted();

        $result = $this->engine->execute($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox, 'acc-1'));

        $this->assertTrue($result->success);
        $this->assertFalse($result->verified, 'The provider said yes; that is not evidence.');
        $this->assertSame(EmailMailboxState::PROVISIONING, $mailbox->refresh()->lifecycle_state);
        $this->assertSame(OperationState::RUNNING, $this->operationFor('acc-1')->state);

        // The reference was still bound: the object may exist, and losing the
        // reference would orphan it.
        $this->assertNotNull($this->bindingFor($mailbox));

        // Read-back is what permits active.
        $confirm = $this->engine->confirm($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox->refresh(), 'acc-1'));

        $this->assertTrue($confirm->verified);
        $this->assertSame(EmailMailboxState::ACTIVE, $mailbox->refresh()->lifecycle_state);
        $this->assertSame(OperationState::SUCCEEDED, $this->operationFor('acc-1')->state);
    }

    public function test_an_ambiguous_create_reconciles_and_never_calls_the_provider_twice(): void
    {
        $domain = $this->activeDomain('ambiguous.test');
        $mailbox = $this->requestedMailbox($domain, 'unknown');

        $this->fake->willReturn('createMailbox', FakeEmailProvider::OUTCOME_TIMEOUT);

        $result = $this->engine->execute($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox, 'amb-1'));

        $this->assertFalse($result->success);
        $this->assertSame('provider_timeout', $result->errorCode);
        $this->assertSame('manual', $result->retryClassification, 'Ambiguity must never be auto-retryable.');

        $this->assertSame(EmailMailboxState::RECONCILING, $mailbox->refresh()->lifecycle_state);
        $this->assertSame(OperationState::COMPENSATION_PENDING, $this->operationFor('amb-1')->state);

        // Re-issuing must NOT call the provider again — this is the
        // double-provisioning path.
        $this->engine->execute($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox->refresh(), 'amb-1'));
        $this->assertSame(1, $this->fake->callCount('createMailbox'));

        // The mutation DID happen provider-side. Read-back settles it.
        $this->fake->willReturn('getInventory', FakeEmailProvider::OUTCOME_VERIFIED);
        $confirm = $this->engine->confirm($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox->refresh(), 'amb-1'));

        $this->assertTrue($confirm->verified);
        $this->assertSame(EmailMailboxState::ACTIVE, $mailbox->refresh()->lifecycle_state);

        // COMPENSATED, not SUCCEEDED: the record must show this was recovered.
        $this->assertSame(OperationState::COMPENSATED, $this->operationFor('amb-1')->state);
    }

    public function test_a_malformed_success_is_treated_as_ambiguity_not_as_completion(): void
    {
        $domain = $this->activeDomain('malformed.test');
        $mailbox = $this->requestedMailbox($domain, 'garbled');

        $this->fake->willReturn('createMailbox', FakeEmailProvider::OUTCOME_MALFORMED);

        $result = $this->engine->execute($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox, 'mal-1'));

        $this->assertFalse($result->success);
        $this->assertSame('provider_malformed_response', $result->errorCode);
        $this->assertSame(EmailMailboxState::RECONCILING, $mailbox->refresh()->lifecycle_state);

        // Nothing was bound, because there was nothing to bind to. An empty
        // reference would make the mailbox permanently unreachable.
        $this->assertNull($this->bindingFor($mailbox));

        // It IS recoverable: enumeration finds the object the provider made.
        $confirm = $this->engine->confirm($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox->refresh(), 'mal-1'));

        $this->assertTrue($confirm->verified);
        $this->assertNotNull($this->bindingFor($mailbox->refresh()), 'The reference becomes knowable at read-back.');
        $this->assertSame(EmailMailboxState::ACTIVE, $mailbox->refresh()->lifecycle_state);
    }

    public function test_a_permanent_failure_moves_the_mailbox_to_provisioning_failed(): void
    {
        $domain = $this->activeDomain('permfail.test');
        $mailbox = $this->requestedMailbox($domain, 'nope');

        $this->fake->willReturn('createMailbox', FakeEmailProvider::OUTCOME_PERMANENT);

        $result = $this->engine->execute($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox, 'perm-1'));

        $this->assertFalse($result->success);
        $this->assertSame('permanent', $result->retryClassification);
        $this->assertSame(EmailMailboxState::PROVISIONING_FAILED, $mailbox->refresh()->lifecycle_state);
        $this->assertSame(OperationState::FAILED_TERMINAL, $this->operationFor('perm-1')->state);
    }

    public function test_a_retryable_failure_leaves_the_mailbox_in_flight(): void
    {
        $domain = $this->activeDomain('retry.test');
        $mailbox = $this->requestedMailbox($domain, 'later');

        $this->fake->willReturn('createMailbox', FakeEmailProvider::OUTCOME_RETRYABLE);

        $result = $this->engine->execute($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox, 'retry-1'));

        $this->assertFalse($result->success);
        $this->assertTrue($result->isRetryable());
        // Still provisioning: the request may yet succeed, so declaring it
        // failed would be premature.
        $this->assertSame(EmailMailboxState::PROVISIONING, $mailbox->refresh()->lifecycle_state);
        $this->assertSame(OperationState::FAILED_RETRYABLE, $this->operationFor('retry-1')->state);
    }

    public function test_the_same_idempotency_key_provisions_exactly_once(): void
    {
        $domain = $this->activeDomain('once.test');
        $mailbox = $this->requestedMailbox($domain, 'single');

        for ($i = 0; $i < 4; $i++) {
            $this->engine->execute($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox->refresh(), 'same-key'));
        }

        $this->assertSame(1, $this->fake->callCount('createMailbox'));
        $this->assertSame(1, $this->operations()->where('operation', Registry::MAILBOX_CREATE)->count());
        $this->assertSame(1, $this->fake->providerMailboxCount());
    }

    public function test_suspend_and_restore_round_trip(): void
    {
        $domain = $this->activeDomain('suspend.test');
        $mailbox = $this->provisionedMailbox($domain, 'held');

        $suspend = $this->engine->execute(
            $this->mailboxContext(Registry::MAILBOX_SUSPEND, $domain, $mailbox, 'susp-1', approved: true)
        );

        $this->assertTrue($suspend->verified);
        $this->assertSame(EmailMailboxState::SUSPENDED, $mailbox->refresh()->lifecycle_state);

        $restore = $this->engine->execute(
            $this->mailboxContext(Registry::MAILBOX_RESTORE, $domain, $mailbox->refresh(), 'rest-1', approved: true)
        );

        $this->assertTrue($restore->verified);
        $this->assertSame(EmailMailboxState::ACTIVE, $mailbox->refresh()->lifecycle_state);
    }

    public function test_an_ambiguous_suspend_leaves_the_mailbox_at_its_last_confirmed_state(): void
    {
        $domain = $this->activeDomain('ambsusp.test');
        $mailbox = $this->provisionedMailbox($domain, 'maybe');

        $this->fake->willReturn('suspendMailbox', FakeEmailProvider::OUTCOME_TIMEOUT);

        $this->engine->execute($this->mailboxContext(Registry::MAILBOX_SUSPEND, $domain, $mailbox, 'as-1', approved: true));

        // The mailbox definitely EXISTS; only one attribute is unknown. Moving
        // it to `reconciling` would say we are unsure it is real, which is
        // false and scarier than the truth.
        $this->assertSame(EmailMailboxState::ACTIVE, $mailbox->refresh()->lifecycle_state);
        // The uncertainty lives on the operation instead.
        $this->assertSame(OperationState::COMPENSATION_PENDING, $this->operationFor('as-1')->state);
    }

    public function test_deleting_a_mailbox_passes_through_deleting_and_requires_separation_of_duties(): void
    {
        $domain = $this->activeDomain('delete.test');
        $mailbox = $this->provisionedMailbox($domain, 'doomed');

        // Self-approval is refused for the one irreversible operation.
        $selfApproved = $this->engine->execute($this->mailboxContext(
            Registry::MAILBOX_DELETE, $domain, $mailbox, 'del-1', approved: true, approvedBy: self::ACTOR
        ));

        $this->assertSame(Failure::APPROVAL_REQUIRED, $selfApproved->errorCode);
        $this->assertFalse($this->fake->wasCalled('deleteMailbox'));

        $result = $this->engine->execute($this->mailboxContext(
            Registry::MAILBOX_DELETE, $domain, $mailbox->refresh(), 'del-1', approved: true, approvedBy: self::APPROVER
        ));

        $this->assertTrue($result->verified);
        $this->assertSame(EmailMailboxState::DELETED, $mailbox->refresh()->lifecycle_state);
        $this->assertSame(EmailMailboxState::DELETING, $mailbox->previous_state);
        $this->assertSame(0, $this->fake->providerMailboxCount());
    }

    public function test_a_password_reset_never_persists_its_result(): void
    {
        $domain = $this->activeDomain('pwd.test');
        $mailbox = $this->provisionedMailbox($domain, 'resetme');

        $this->engine->execute($this->mailboxContext(
            Registry::PASSWORD_RESET, $domain, $mailbox, 'pwd-1', approved: true
        ));

        $operation = $this->operationFor('pwd-1');

        // Canonicalised: MySQL's JSON type does not preserve key order.
        $this->assertEqualsCanonicalizing(
            ['redacted' => true, 'outcome' => 'verified'],
            $operation->result_json,
            'A password reset result must never reach an operation record, whatever it contained.'
        );

        // The result payload is where credential material would land, so it is
        // checked alone. The request payload legitimately names the capability
        // `email.password.reset`, which is a capability slug, not a secret.
        $encoded = json_encode($operation->result_json);

        foreach (['password', 'secret', 'token', 'reset_url', 'temporary'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, (string) $encoded);
        }
    }

    public function test_updating_a_mailbox_changes_no_lifecycle_state(): void
    {
        $domain = $this->activeDomain('update.test');
        $mailbox = $this->provisionedMailbox($domain, 'renamed');

        $result = $this->engine->execute($this->mailboxContext(
            Registry::MAILBOX_UPDATE, $domain, $mailbox, 'upd-1', input: ['quota_mb' => 4096]
        ));

        $this->assertTrue($result->verified);
        // A mailbox being resized is not "provisioning".
        $this->assertSame(EmailMailboxState::ACTIVE, $mailbox->refresh()->lifecycle_state);
        $this->assertSame(1, $this->fake->callCount('updateMailbox'));
    }

    // ═══ E2-C — CAPABILITY ENFORCEMENT ═══════════════════════════════════════

    public function test_an_unsupported_capability_is_refused_before_any_operation_exists(): void
    {
        $this->fake->withoutCapability(EmailProviderCapability::CATCHALL_CONFIGURE);

        $domain = $this->activeDomain('nocatchall.test');
        $mailbox = $this->provisionedMailbox($domain, 'catch');
        $catchAll = $this->requestedCatchAll($domain, $mailbox);

        $result = $this->engine->execute($this->context(
            Registry::CATCHALL_CONFIGURE, $domain, $catchAll, 'ca-1', approved: true
        ));

        $this->assertSame(Failure::CAPABILITY_NOT_SUPPORTED, $result->errorCode);
        // Not a provider failure: it is a configuration fact, so retrying can
        // never help.
        $this->assertSame('permanent', $result->retryClassification);
        $this->assertFalse($this->fake->wasCalled('configureCatchAll'));
        $this->assertSame(0, $this->operations()->where('operation', Registry::CATCHALL_CONFIGURE)->count());
        $this->assertSame(EmailCatchAllState::DISABLED, $catchAll->refresh()->state);
    }

    public function test_a_provider_that_supports_catchall_can_configure_and_clear_it(): void
    {
        $domain = $this->activeDomain('catchall.test');
        $mailbox = $this->provisionedMailbox($domain, 'catch');
        $catchAll = $this->requestedCatchAll($domain, $mailbox);

        $configure = $this->engine->execute($this->context(
            Registry::CATCHALL_CONFIGURE, $domain, $catchAll, 'ca-on', approved: true
        ));

        $this->assertTrue($configure->verified);
        $this->assertSame(EmailCatchAllState::ENABLED, $this->reload($catchAll)->state);

        $clear = $this->engine->execute($this->context(
            Registry::CATCHALL_CLEAR, $domain, $this->reload($catchAll), 'ca-off', approved: true
        ));

        $this->assertTrue($clear->verified);
        $this->assertSame(EmailCatchAllState::DISABLED, $this->reload($catchAll)->state);
    }

    // ═══ E2-G — ALIASES AND FORWARDERS ═══════════════════════════════════════

    public function test_alias_create_and_delete_round_trip(): void
    {
        $domain = $this->activeDomain('alias.test');
        $mailbox = $this->provisionedMailbox($domain, 'inbox');

        $alias = WorkspaceContext::run(self::WS, fn () => EmailAlias::create([
            'email_domain_id'   => $domain->id,
            'source_local_part' => 'info',
            'target_type'       => EmailAlias::TARGET_MAILBOX,
            'target_mailbox_id' => $mailbox->id,
        ]));

        $create = $this->engine->execute($this->context(Registry::ALIAS_CREATE, $domain, $alias, 'al-1'));

        $this->assertTrue($create->verified);
        $this->assertSame(EmailRoutingRuleState::ACTIVE, $this->reload($alias)->lifecycle_state);
        $this->assertNotNull($this->bindingFor($alias));

        $delete = $this->engine->execute($this->context(
            Registry::ALIAS_DELETE, $domain, $this->reload($alias), 'al-2', approved: true
        ));

        $this->assertTrue($delete->verified);
        $this->assertSame(EmailRoutingRuleState::REMOVED, $this->reload($alias)->lifecycle_state);
    }

    public function test_a_forwarder_with_an_unchecked_loop_verdict_never_reaches_the_provider(): void
    {
        $domain = $this->activeDomain('loopguard.test');

        $forwarder = WorkspaceContext::run(self::WS, fn () => EmailForwarder::create([
            'email_domain_id'     => $domain->id,
            'source_local_part'   => 'relay',
            'destination_address' => 'someone@elsewhere.test',
        ]));

        $blocked = $this->engine->execute($this->context(
            Registry::FORWARDER_CREATE, $domain, $forwarder, 'fw-1', approved: true
        ));

        $this->assertSame(Failure::LOOP_RISK, $blocked->errorCode);
        $this->assertFalse($this->fake->wasCalled('createForwarder'));

        // Cleared, it proceeds.
        WorkspaceContext::run(self::WS, fn () => $forwarder->recordLoopVerdict(
            ForwarderLoopSafety::evaluate('relay@loopguard.test', 'someone@elsewhere.test', ['loopguard.test'])
        )->save());

        $allowed = $this->engine->execute($this->context(
            Registry::FORWARDER_CREATE, $domain, $forwarder->refresh(), 'fw-1', approved: true
        ));

        $this->assertTrue($allowed->verified);
        $this->assertSame(EmailRoutingRuleState::ACTIVE, $forwarder->refresh()->lifecycle_state);
    }

    public function test_a_forwarder_that_would_loop_back_into_the_domain_is_refused(): void
    {
        $domain = $this->activeDomain('selfloop.test');

        $forwarder = WorkspaceContext::run(self::WS, function () use ($domain) {
            $f = EmailForwarder::create([
                'email_domain_id'     => $domain->id,
                'source_local_part'   => 'a',
                'destination_address' => 'b@selfloop.test',
            ]);

            return $f->recordLoopVerdict(
                ForwarderLoopSafety::evaluate('a@selfloop.test', 'b@selfloop.test', ['selfloop.test'])
            );
        });

        $forwarder->save();

        $result = $this->engine->execute($this->context(
            Registry::FORWARDER_CREATE, $domain, $forwarder->refresh(), 'fw-loop', approved: true
        ));

        $this->assertSame(Failure::LOOP_RISK, $result->errorCode);
        $this->assertFalse($this->fake->wasCalled('createForwarder'));
    }

    public function test_forwarder_delete_round_trip(): void
    {
        $domain = $this->activeDomain('fwddel.test');
        $forwarder = $this->provisionedForwarder($domain, 'out', 'far@elsewhere.test');

        $result = $this->engine->execute($this->context(
            Registry::FORWARDER_DELETE, $domain, $forwarder, 'fwd-del', approved: true
        ));

        $this->assertTrue($result->verified);
        $this->assertSame(EmailRoutingRuleState::REMOVED, $forwarder->refresh()->lifecycle_state);
    }

    // ═══ E2-I/J — USAGE, BINDINGS, TENANCY ═══════════════════════════════════

    public function test_usage_sync_writes_samples_and_preserves_unreported_measures_as_null(): void
    {
        $domain = $this->activeDomain('usage.test');
        $mailbox = $this->provisionedMailbox($domain, 'measured');

        $result = $this->engine->execute($this->context(Registry::USAGE_SYNC, $domain, null, 'usage-1'));

        $this->assertTrue($result->verified);

        $sample = WorkspaceContext::run(self::WS, fn () => EmailUsage::query()->first());

        $this->assertNotNull($sample);
        $this->assertSame($mailbox->id, (int) $sample->email_mailbox_id);
        $this->assertNotNull($sample->storage_quota_mb);
        $this->assertNull($sample->messages_sent, 'An unreported counter must stay null, never become zero.');
    }

    public function test_a_provider_binding_is_opaque_and_excluded_from_customer_payloads(): void
    {
        $domain = $this->activeDomain('binding.test');
        $mailbox = $this->provisionedMailbox($domain, 'bound');

        $binding = $this->bindingFor($mailbox);
        $this->assertNotNull($binding);

        $customer = $mailbox->refresh()->toCustomerArray();

        foreach (array_keys($customer) as $key) {
            $this->assertStringNotContainsString('provider', (string) $key);
            $this->assertStringNotContainsString('ref', (string) $key);
        }

        $this->assertStringNotContainsString(
            (string) $binding->provider_resource_id,
            json_encode($customer),
            'The opaque provider reference must never appear in a customer payload.'
        );
    }

    public function test_rebinding_to_a_new_provider_object_does_not_change_the_business_identity(): void
    {
        $domain = $this->activeDomain('rebind.test');
        $mailbox = $this->provisionedMailbox($domain, 'moved');

        $originalId = $mailbox->id;
        $originalRef = $this->bindingFor($mailbox)->provider_resource_id;

        // A migration would rebind the same mailbox to a different provider
        // object. The LevelUp identity is the primary key and never moves.
        InfraProviderResource::withoutWorkspaceScope()
            ->where('owner_type', BusinessEmailEngine::RESOURCE_MAILBOX)
            ->where('owner_id', $mailbox->id)
            ->update(['provider_resource_id' => 'fake-mbx-relocated']);

        $mailbox->refresh();

        $this->assertSame($originalId, $mailbox->id);
        $this->assertSame('fake-mbx-relocated', $this->bindingFor($mailbox)->provider_resource_id);
        $this->assertNotSame($originalRef, 'fake-mbx-relocated');
        $this->assertSame(EmailMailboxState::ACTIVE, $mailbox->lifecycle_state);
    }

    public function test_another_workspace_cannot_execute_against_our_domain(): void
    {
        $domain = $this->activeDomain('tenant.test');
        $mailbox = $this->requestedMailbox($domain, 'theirs');

        $result = $this->engine->execute(new EmailOperationContext(
            workspaceId: self::OTHER_WS,
            capability: Registry::MAILBOX_CREATE,
            domain: $domain,
            subject: $mailbox,
            actorUserId: self::ACTOR,
            entitlements: $this->entitlements(),
            idempotencyKey: 'cross-1',
        ));

        $this->assertSame(Failure::CROSS_TENANT, $result->errorCode);
        $this->assertFalse($this->fake->wasCalled('createMailbox'));
    }

    public function test_a_stale_read_back_does_not_confirm_and_recovers_once_propagation_completes(): void
    {
        $domain = $this->activeDomain('stale.test');
        $mailbox = $this->requestedMailbox($domain, 'lagging');

        $this->fake->withStaleReadBack()->alwaysAccepted();

        $this->engine->execute($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox, 'stale-1'));
        $this->assertSame(EmailMailboxState::PROVISIONING, $mailbox->refresh()->lifecycle_state);

        // The provider has not caught up: read-back must not confirm.
        $tooEarly = $this->engine->confirm($this->mailboxContext(Registry::MAILBOX_CREATE, $domain, $mailbox->refresh(), 'stale-1'));
        $this->assertFalse($tooEarly->success);
        $this->assertSame(EmailMailboxState::PROVISIONING_FAILED, $mailbox->refresh()->lifecycle_state);

        // The mailbox really was created; the read simply ran too early. Once
        // propagation completes the object is visible, which is what makes the
        // premature conclusion above a lag problem rather than a lost mailbox.
        $ref = (string) $this->engine->providerRef($mailbox->refresh());
        $this->assertNotSame('', $ref, 'The reference was returned even though the read lagged.');
        $this->assertFalse($this->fake->hasMailboxRef($ref), 'Not yet visible.');

        $this->fake->applyPendingWrites();

        $this->assertTrue(
            $this->fake->hasMailboxRef($ref),
            'After propagation the object exists — the engine was right to refuse to guess earlier.'
        );
    }

    public function test_the_engine_calls_the_provider_in_the_expected_order(): void
    {
        $domain = $this->connectedDomain('sequence.test');
        $this->engine->dnsRequirements($this->context(Registry::DOMAIN_ONBOARD, $domain));
        $this->engine->execute($this->context(Registry::DOMAIN_ONBOARD, $domain->refresh()));
        $this->engine->execute($this->context(Registry::DOMAIN_VERIFY, $domain->refresh()));
        $this->engine->confirm($this->context(Registry::DOMAIN_ONBOARD, $domain->refresh()));

        $this->assertSame(
            ['getDnsRequirements', 'onboardDomain', 'verifyDomain', 'getDomainAuthStatus'],
            $this->fake->methodSequence()
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function entitlements(): array
    {
        return [
            Registry::ENTITLEMENT_ACCESS        => true,
            Registry::ENTITLEMENT_MAILBOX_LIMIT => 50,
            Registry::ENTITLEMENT_DOMAIN_LIMIT  => 5,
        ];
    }

    private function connectedDomain(string $name): EmailDomain
    {
        return WorkspaceContext::run(self::WS, fn () => EmailDomain::create([
            'domain'  => $name,
            'custody' => 'managed_by_us',
        ]));
    }

    /** A domain that has completed the whole onboarding chain. */
    private function activeDomain(string $name): EmailDomain
    {
        $domain = $this->connectedDomain($name);

        $this->engine->dnsRequirements($this->context(Registry::DOMAIN_ONBOARD, $domain));
        $this->engine->execute($this->context(Registry::DOMAIN_ONBOARD, $domain->refresh()));
        $this->engine->execute($this->context(Registry::DOMAIN_VERIFY, $domain->refresh()));
        $this->engine->confirm($this->context(Registry::DOMAIN_ONBOARD, $domain->refresh()));

        $this->fake->reset();

        return $domain->refresh();
    }

    private function requestedMailbox(EmailDomain $domain, string $localPart): EmailMailbox
    {
        return WorkspaceContext::run(self::WS, fn () => EmailMailbox::create([
            'email_domain_id' => $domain->id,
            'local_part'      => $localPart,
            'quota_mb'        => 1024,
        ]));
    }

    private function provisionedMailbox(EmailDomain $domain, string $localPart): EmailMailbox
    {
        $mailbox = $this->requestedMailbox($domain, $localPart);

        $this->engine->execute($this->mailboxContext(
            Registry::MAILBOX_CREATE, $domain, $mailbox, 'seed-' . $localPart
        ));

        return $mailbox->refresh();
    }

    private function provisionedForwarder(EmailDomain $domain, string $local, string $destination): EmailForwarder
    {
        $forwarder = WorkspaceContext::run(self::WS, function () use ($domain, $local, $destination) {
            $f = EmailForwarder::create([
                'email_domain_id'     => $domain->id,
                'source_local_part'   => $local,
                'destination_address' => $destination,
            ]);

            $f->recordLoopVerdict(ForwarderLoopSafety::evaluate(
                $local . '@' . $domain->domain, $destination, [(string) $domain->domain]
            ))->save();

            return $f;
        });

        $this->engine->execute($this->context(
            Registry::FORWARDER_CREATE, $domain, $forwarder->refresh(), 'seed-fwd-' . $local, approved: true
        ));

        return $forwarder->refresh();
    }

    private function requestedCatchAll(EmailDomain $domain, EmailMailbox $mailbox): EmailCatchAll
    {
        return WorkspaceContext::run(self::WS, fn () => EmailCatchAll::create([
            'email_domain_id'   => $domain->id,
            'target_type'       => EmailCatchAll::TARGET_MAILBOX,
            'target_mailbox_id' => $mailbox->id,
        ]));
    }

    private function context(
        string $capability,
        EmailDomain $domain,
        $subject = null,
        ?string $key = null,
        bool $approved = false,
        ?int $approvedBy = self::APPROVER,
        array $input = []
    ): EmailOperationContext {
        return new EmailOperationContext(
            workspaceId: self::WS,
            capability: $capability,
            domain: $domain,
            subject: $subject,
            actorUserId: self::ACTOR,
            source: 'admin',
            entitlements: $this->entitlements(),
            input: $input,
            idempotencyKey: $key,
            approved: $approved,
            approvedBy: $approved ? $approvedBy : null,
        );
    }

    private function mailboxContext(
        string $capability,
        EmailDomain $domain,
        EmailMailbox $mailbox,
        string $key,
        bool $approved = false,
        ?int $approvedBy = self::APPROVER,
        array $input = []
    ): EmailOperationContext {
        return $this->context($capability, $domain, $mailbox, $key, $approved, $approvedBy, $input);
    }

    /**
     * Reload a model that has a loaded workspace-scoped relationship.
     *
     * Eloquent's refresh() re-loads whatever relations are already on the
     * model, and those queries carry the workspace global scope. The engine
     * loads `targetMailbox` while resolving an alias or catch-all target, so a
     * later refresh() outside a workspace context throws. In production every
     * caller runs inside one; a test does not, so it must say so.
     */
    private function reload($model)
    {
        return WorkspaceContext::run(self::WS, fn () => $model->refresh());
    }

    private function operations()
    {
        return WorkspaceContext::run(self::WS, fn () => InfraOperation::query()->where('capability', 'email')->get());
    }

    private function operationFor(string $key): InfraOperation
    {
        $operation = WorkspaceContext::run(self::WS, fn () => InfraOperation::query()
            ->where('idempotency_key', $key)->first());

        $this->assertNotNull($operation, "No operation recorded for key '{$key}'.");

        return $operation;
    }

    private function bindingFor($subject): ?InfraProviderResource
    {
        $type = match ($subject::class) {
            EmailMailbox::class => BusinessEmailEngine::RESOURCE_MAILBOX,
            EmailAlias::class => BusinessEmailEngine::RESOURCE_ALIAS,
            EmailForwarder::class => BusinessEmailEngine::RESOURCE_FORWARDER,
            EmailCatchAll::class => BusinessEmailEngine::RESOURCE_CATCHALL,
            default => BusinessEmailEngine::RESOURCE_DOMAIN,
        };

        return InfraProviderResource::withoutWorkspaceScope()
            ->where('owner_type', $type)
            ->where('owner_id', $subject->getKey())
            ->first();
    }
}
