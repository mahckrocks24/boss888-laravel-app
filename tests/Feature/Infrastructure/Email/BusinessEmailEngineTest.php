<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\InfrastructureConnectorResolver;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailForwarder;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\States\EmailDomainState;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailVerificationState;
use App\Engines\Infrastructure\Email\Support\BusinessEmailFailure as Failure;
use App\Engines\Infrastructure\Email\Support\EmailOperationContext;
use App\Engines\Infrastructure\Email\Support\ForwarderLoopSafety;
use App\Engines\Infrastructure\Models\InfraEvent;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\States\OperationState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * INFRA888 · E1-F — ENGINE PROOF.
 *
 * What these tests are really asserting is that the engine is HONEST. With no
 * adapter configured there are exactly two acceptable behaviours for a mutating
 * request: refuse with a typed reason, or record the request and say it cannot
 * proceed. Anything that looks like success is a defect, and several of these
 * tests exist only to make that defect impossible to introduce quietly.
 */
class BusinessEmailEngineTest extends TestCase
{
    use RefreshDatabase;

    private const WS = 8801;
    private const OTHER_WS = 8802;
    private const ACTOR = 991;
    private const APPROVER = 992;

    private BusinessEmailEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine = app(BusinessEmailEngine::class);
    }

    protected function tearDown(): void
    {
        WorkspaceContext::reset();

        parent::tearDown();
    }

    // ── provider availability ────────────────────────────────────────────────

    public function test_the_email_capability_is_deliberately_unconfigured(): void
    {
        // config/infrastructure.php registers no `email` connector, and there is
        // no Null email connector by design: one that "succeeded" at creating a
        // mailbox would produce a portal showing an address that receives no mail.
        $this->expectException(RuntimeException::class);

        app(InfrastructureConnectorResolver::class)->resolve('email');
    }

    public function test_the_engine_turns_the_missing_connector_into_a_typed_answer(): void
    {
        $this->assertNull($this->engine->connector());
        $this->assertFalse($this->engine->isAvailable());

        $result = $this->engine->providerAvailability();

        $this->assertFalse($result->success);
        $this->assertFalse($result->verified);
        $this->assertSame(Failure::PROVIDER_NOT_CONFIGURED, $result->errorCode);
        $this->assertSame('unavailable', $result->normalizedState);
        // Resolvable, but only by a human. Neither a permanent dead end nor
        // something a queue should keep retrying.
        $this->assertSame('manual', $result->retryClassification);
        $this->assertFalse($result->isRetryable());
    }

    public function test_the_customer_message_says_nothing_about_a_provider(): void
    {
        $result = $this->engine->providerAvailability();

        $this->assertSame('Business Email is not available on this account yet.', $result->errorSummary);
    }

    // ── the mutating path ────────────────────────────────────────────────────

    public function test_a_valid_mutating_request_is_recorded_and_then_honestly_refused(): void
    {
        $domain = $this->verifiedDomain('recorded.test');

        $result = $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'idempotencyKey' => 'create-sales-1',
            'input'          => ['local_part' => 'sales'],
        ]));

        $this->assertFalse($result->success, 'With no provider there is nothing that could have succeeded.');
        $this->assertSame(Failure::PROVIDER_NOT_CONFIGURED, $result->errorCode);

        $operation = $this->operations()->first();

        $this->assertNotNull($operation, 'A legitimate request must leave a recoverable record.');
        $this->assertSame(Registry::MAILBOX_CREATE, $operation->operation);
        $this->assertSame('email', $operation->capability);
        $this->assertSame('create-sales-1', $operation->idempotency_key);
        $this->assertSame(Failure::PROVIDER_NOT_CONFIGURED, $operation->failure_code);
        $this->assertSame(self::ACTOR, (int) $operation->actor_user_id);

        // No provider is bound, and naming one would be a fiction.
        $this->assertNull($operation->provider);
    }

    public function test_an_unavailable_operation_is_not_closed_so_it_can_resume_later(): void
    {
        $domain = $this->verifiedDomain('resumable.test');

        $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'idempotencyKey' => 'resumable-1',
            'input'          => ['local_part' => 'later'],
        ]));

        $operation = $this->operations()->first();

        $this->assertSame(OperationState::REQUESTED, $operation->state);
        $this->assertFalse(
            OperationState::isTerminal($operation->state),
            'Closing this as terminal would make every request made before a provider exists replay its '
            . 'refusal forever.'
        );
    }

    public function test_the_same_idempotency_key_never_opens_a_second_operation(): void
    {
        $domain = $this->verifiedDomain('idem.test');

        for ($i = 0; $i < 3; $i++) {
            $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
                'idempotencyKey' => 'the-same-key',
                'input'          => ['local_part' => 'sales'],
            ]));
        }

        $this->assertSame(1, $this->operations()->count(), 'A retried request must not act twice.');
    }

    public function test_distinct_intents_open_distinct_operations(): void
    {
        $domain = $this->verifiedDomain('distinct.test');

        foreach (['first', 'second'] as $key) {
            $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
                'idempotencyKey' => $key,
                'input'          => ['local_part' => $key],
            ]));
        }

        $this->assertSame(2, $this->operations()->count());
    }

    public function test_a_mutating_capability_without_a_key_is_refused_rather_than_given_one(): void
    {
        $domain = $this->verifiedDomain('nokey.test');

        // An invented key looks like idempotency protection and is not.
        $this->expectException(RuntimeException::class);

        $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'input' => ['local_part' => 'keyless'],
        ]));
    }

    public function test_a_derived_key_collapses_repeat_submissions(): void
    {
        $domain = $this->domain('derived-key.test');

        // Onboarding derives its key from the domain's identity, so submitting
        // the same onboarding twice is one operation.
        for ($i = 0; $i < 2; $i++) {
            $this->engine->authorize($this->context(Registry::DOMAIN_ONBOARD, $domain));
        }

        $this->assertSame(1, $this->operations()->count());
        $this->assertSame(
            Registry::DOMAIN_ONBOARD . ':' . $domain->id,
            $this->operations()->first()->idempotency_key
        );
    }

    // ── read-only capabilities ───────────────────────────────────────────────

    public function test_a_read_only_capability_opens_no_operation(): void
    {
        $domain = $this->domain('readonly.test');

        $result = $this->engine->authorize($this->context(Registry::HEALTH_OBSERVE, $domain, [
            'source' => 'system',
        ]));

        $this->assertFalse($result->success);
        $this->assertSame(Failure::PROVIDER_NOT_CONFIGURED, $result->errorCode);
        $this->assertSame(0, $this->operations()->count(), 'Observation mutates nothing and needs no operation.');
    }

    // ── denials ──────────────────────────────────────────────────────────────

    public function test_an_unknown_capability_is_refused(): void
    {
        $result = $this->engine->authorize($this->context('email.mailbox.teleport', $this->domain('unknown.test')));

        $this->assertSame(Failure::UNKNOWN_CAPABILITY, $result->errorCode);
        $this->assertSame(0, $this->operations()->count());
    }

    public function test_a_domain_from_another_workspace_is_refused(): void
    {
        $foreign = WorkspaceContext::run(self::OTHER_WS, fn () => EmailDomain::create([
            'domain'  => 'foreign.test',
            'custody' => 'managed_by_us',
        ]));

        $result = $this->engine->authorize(new EmailOperationContext(
            workspaceId: self::WS,
            capability: Registry::DOMAIN_ONBOARD,
            domain: $foreign,
            entitlements: $this->entitlements(),
        ));

        $this->assertSame(Failure::CROSS_TENANT, $result->errorCode);
        $this->assertSame(0, $this->operations()->count());
    }

    public function test_a_subject_from_another_workspace_is_refused(): void
    {
        $domain = $this->verifiedDomain('subject-tenant.test');

        $foreignMailbox = WorkspaceContext::run(self::OTHER_WS, function () {
            $other = EmailDomain::create(['domain' => 'other-tenant.test', 'custody' => 'managed_by_us']);

            return EmailMailbox::create([
                'email_domain_id' => $other->id,
                'local_part'      => 'theirs',
                'quota_mb'        => 512,
            ]);
        });

        $result = $this->engine->authorize($this->context(Registry::MAILBOX_SUSPEND, $domain, [
            'subject'        => $foreignMailbox,
            'idempotencyKey' => 'x-tenant',
            'approved'       => true,
        ]));

        $this->assertSame(Failure::CROSS_TENANT, $result->errorCode);
    }

    public function test_a_workspace_without_the_entitlement_is_refused(): void
    {
        $domain = $this->verifiedDomain('unentitled.test');

        $result = $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'entitlements'   => [],
            'idempotencyKey' => 'unentitled',
            'input'          => ['local_part' => 'nope'],
        ]));

        $this->assertSame(Failure::NOT_ENTITLED, $result->errorCode);
        $this->assertSame(0, $this->operations()->count());
    }

    public function test_a_mailbox_limit_of_zero_is_no_access_rather_than_unlimited(): void
    {
        $domain = $this->verifiedDomain('zero-limit.test');

        $result = $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'entitlements'   => [Registry::ENTITLEMENT_ACCESS => true, Registry::ENTITLEMENT_MAILBOX_LIMIT => 0],
            'idempotencyKey' => 'zero',
            'input'          => ['local_part' => 'nope'],
        ]));

        $this->assertSame(Failure::NOT_ENTITLED, $result->errorCode);
    }

    public function test_the_plan_mailbox_limit_is_enforced(): void
    {
        $domain = $this->verifiedDomain('limit.test');

        WorkspaceContext::run(self::WS, function () use ($domain) {
            foreach (['a', 'b'] as $local) {
                EmailMailbox::create([
                    'email_domain_id' => $domain->id,
                    'local_part'      => $local,
                    'quota_mb'        => 512,
                    'lifecycle_state' => EmailMailboxState::ACTIVE,
                ]);
            }
        });

        $result = $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'entitlements'   => [Registry::ENTITLEMENT_ACCESS => true, Registry::ENTITLEMENT_MAILBOX_LIMIT => 2],
            'idempotencyKey' => 'over-limit',
            'input'          => ['local_part' => 'third'],
        ]));

        $this->assertSame(Failure::QUOTA_EXCEEDED, $result->errorCode);
    }

    public function test_a_protected_capability_without_approval_is_refused(): void
    {
        $domain = $this->verifiedDomain('needs-approval.test');
        $mailbox = $this->mailbox($domain, 'held');

        $result = $this->engine->authorize($this->context(Registry::MAILBOX_SUSPEND, $domain, [
            'subject'        => $mailbox,
            'idempotencyKey' => 'suspend-1',
        ]));

        $this->assertSame(Failure::APPROVAL_REQUIRED, $result->errorCode);
        $this->assertSame('pending_approval', $result->normalizedState);
    }

    public function test_a_requester_cannot_approve_their_own_irreversible_action(): void
    {
        $domain = $this->verifiedDomain('sod.test');
        $mailbox = $this->mailbox($domain, 'doomed');

        $selfApproved = $this->engine->authorize($this->context(Registry::MAILBOX_DELETE, $domain, [
            'subject'        => $mailbox,
            'idempotencyKey' => 'delete-1',
            'approved'       => true,
            'approvedBy'     => self::ACTOR,
        ]));

        $this->assertSame(Failure::APPROVAL_REQUIRED, $selfApproved->errorCode);
        $this->assertSame(0, $this->operations()->count());

        $properlyApproved = $this->engine->authorize($this->context(Registry::MAILBOX_DELETE, $domain, [
            'subject'        => $mailbox,
            'idempotencyKey' => 'delete-1',
            'approved'       => true,
            'approvedBy'     => self::APPROVER,
        ]));

        // Still refused — there is no provider — but refused for the RIGHT
        // reason, which proves separation of duties was satisfied.
        $this->assertSame(Failure::PROVIDER_NOT_CONFIGURED, $properlyApproved->errorCode);
        $this->assertSame(1, $this->operations()->count());
    }

    public function test_nothing_may_be_provisioned_beneath_an_unverified_domain(): void
    {
        $domain = $this->domain('unverified.test');

        $result = $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'idempotencyKey' => 'premature',
            'input'          => ['local_part' => 'early'],
        ]));

        $this->assertSame(Failure::DOMAIN_NOT_VERIFIED, $result->errorCode);
        $this->assertSame(0, $this->operations()->count());
    }

    public function test_a_domain_we_cannot_place_may_not_be_provisioned_but_may_be_observed(): void
    {
        $domain = $this->domain('nocustody.test', custody: 'unknown');

        $provision = $this->engine->authorize($this->context(Registry::DOMAIN_ONBOARD, $domain));
        $this->assertSame(Failure::CUSTODY_INSUFFICIENT, $provision->errorCode);

        // Looking is safe under any custody, and refusing to look is how a
        // misattributed domain stays misattributed.
        $observe = $this->engine->authorize($this->context(Registry::HEALTH_OBSERVE, $domain, ['source' => 'system']));
        $this->assertSame(Failure::PROVIDER_NOT_CONFIGURED, $observe->errorCode);
    }

    public function test_an_invalid_address_is_refused_before_anything_is_recorded(): void
    {
        $domain = $this->verifiedDomain('badaddress.test');

        $result = $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'idempotencyKey' => 'bad',
            'input'          => ['local_part' => 'not valid'],
        ]));

        $this->assertSame(Failure::INVALID_ADDRESS, $result->errorCode);
        $this->assertSame(0, $this->operations()->count());
    }

    public function test_a_privileged_address_cannot_be_created_through_self_service(): void
    {
        $domain = $this->verifiedDomain('reserved.test');

        $result = $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'idempotencyKey' => 'reserved',
            'input'          => ['local_part' => 'postmaster'],
            'source'         => 'manual',
        ]));

        $this->assertSame(Failure::RESERVED_LOCAL_PART, $result->errorCode);

        // An operator may still assign it deliberately.
        $asAdmin = $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'idempotencyKey' => 'reserved-admin',
            'input'          => ['local_part' => 'postmaster'],
            'source'         => 'admin',
        ]));

        $this->assertSame(Failure::PROVIDER_NOT_CONFIGURED, $asAdmin->errorCode);
    }

    public function test_an_illegal_lifecycle_move_is_refused_with_a_typed_reason(): void
    {
        $domain = $this->domain('illegal-move.test');

        $result = $this->engine->authorize($this->context(Registry::DOMAIN_ONBOARD, $domain, [
            // connected -> active skips verification entirely.
            'targetState' => EmailDomainState::ACTIVE,
        ]));

        $this->assertSame(Failure::ILLEGAL_TRANSITION, $result->errorCode);
    }

    public function test_a_terminated_domain_accepts_nothing_further(): void
    {
        $domain = $this->domain('terminated.test');

        WorkspaceContext::run(self::WS, function () use ($domain) {
            $domain->lifecycle_state = EmailDomainState::TERMINATED;
            $domain->save();
        });

        $result = $this->engine->authorize($this->context(Registry::DOMAIN_ONBOARD, $domain));

        $this->assertSame(Failure::ILLEGAL_TRANSITION, $result->errorCode);
    }

    public function test_an_unchecked_forwarder_cannot_be_provisioned(): void
    {
        $domain = $this->verifiedDomain('loop.test');

        $forwarder = WorkspaceContext::run(self::WS, fn () => EmailForwarder::create([
            'email_domain_id'     => $domain->id,
            'source_local_part'   => 'relay',
            'destination_address' => 'somebody@elsewhere.test',
        ]));

        $result = $this->engine->authorize($this->context(Registry::FORWARDER_CREATE, $domain, [
            'subject'        => $forwarder,
            'idempotencyKey' => 'fwd-1',
            'approved'       => true,
        ]));

        $this->assertSame(Failure::LOOP_RISK, $result->errorCode);
        $this->assertSame(0, $this->operations()->count());

        // Once evaluated and cleared, the loop check stops being the blocker.
        WorkspaceContext::run(self::WS, function () use ($forwarder) {
            $forwarder->recordLoopVerdict(ForwarderLoopSafety::evaluate(
                'relay@loop.test',
                'somebody@elsewhere.test',
                ['loop.test']
            ))->save();
        });

        $cleared = $this->engine->authorize($this->context(Registry::FORWARDER_CREATE, $domain, [
            'subject'        => $forwarder->fresh(),
            'idempotencyKey' => 'fwd-1',
            'approved'       => true,
        ]));

        $this->assertSame(Failure::PROVIDER_NOT_CONFIGURED, $cleared->errorCode);
    }

    // ── audit ────────────────────────────────────────────────────────────────

    public function test_every_denial_is_written_to_the_infrastructure_audit_trail(): void
    {
        $domain = $this->verifiedDomain('audited.test');

        $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'entitlements'   => [],
            'idempotencyKey' => 'denied',
            'input'          => ['local_part' => 'nope'],
        ]));

        $event = WorkspaceContext::run(
            self::WS,
            fn () => InfraEvent::query()->where('event', 'email.request_denied')->first()
        );

        $this->assertNotNull($event, 'A denial that leaves no trace cannot be investigated.');
        $this->assertSame(InfraEvent::SEVERITY_WARNING, $event->severity);
        $this->assertSame(Failure::NOT_ENTITLED, $event->context_json['failure_code']);
        $this->assertSame(self::WS, (int) $event->workspace_id);
        $this->assertNull($event->provider, 'No provider is involved; naming one would be the first vendor leak.');
    }

    public function test_an_unavailable_operation_is_also_audited(): void
    {
        $domain = $this->verifiedDomain('audited-unavailable.test');

        $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'idempotencyKey' => 'unavailable',
            'input'          => ['local_part' => 'sales'],
        ]));

        $event = WorkspaceContext::run(
            self::WS,
            fn () => InfraEvent::query()->where('event', 'email.operation_unavailable')->first()
        );

        $this->assertNotNull($event);
        $this->assertSame(Failure::PROVIDER_NOT_CONFIGURED, $event->context_json['failure_code']);
        $this->assertNotNull($event->operation_id);
    }

    public function test_the_audit_payload_records_input_keys_but_not_input_values(): void
    {
        $domain = $this->verifiedDomain('payload.test');

        $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'idempotencyKey' => 'payload',
            'input'          => ['local_part' => 'sales', 'display_name' => 'Jordan Okonkwo'],
        ]));

        $operation = $this->operations()->first();
        $encoded = json_encode($operation->request_json);

        $this->assertStringContainsString('local_part', (string) $encoded);
        $this->assertStringNotContainsString(
            'Jordan Okonkwo',
            (string) $encoded,
            'An operator log must not carry values the customer did not expect to appear there by default.'
        );
    }

    // ── honesty ──────────────────────────────────────────────────────────────

    public function test_no_engine_path_ever_returns_a_verified_result(): void
    {
        $domain = $this->verifiedDomain('never-verified.test');

        $results = [
            $this->engine->providerAvailability(),
            $this->engine->authorize($this->context(Registry::DOMAIN_ONBOARD, $domain)),
            $this->engine->authorize($this->context(Registry::HEALTH_OBSERVE, $domain, ['source' => 'system'])),
            $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
                'idempotencyKey' => 'nv-1',
                'input'          => ['local_part' => 'sales'],
            ])),
            $this->engine->authorize($this->context('email.does.not.exist', $domain)),
        ];

        foreach ($results as $index => $result) {
            $this->assertFalse($result->verified, "Result #{$index} claims independent confirmation of nothing.");
        }
    }

    public function test_no_mutating_request_reports_success_while_no_provider_exists(): void
    {
        $domain = $this->verifiedDomain('no-success.test');

        foreach (Registry::mutating() as $index => $capability) {
            $meta = Registry::get($capability);

            $result = $this->engine->authorize($this->context($capability, $domain, [
                'subject'        => $this->subjectFor($capability, $domain),
                'idempotencyKey' => "sweep-{$index}",
                'approved'       => true,
                'approvedBy'     => self::APPROVER,
                'source'         => 'admin',
                'input'          => ['local_part' => 'sweep' . $index],
            ]));

            $this->assertFalse(
                $result->success,
                "{$capability} reported success with no provider configured (approval mode {$meta['approval_mode']})."
            );
            $this->assertFalse($result->verified);
            $this->assertNotNull($result->errorCode);
            $this->assertTrue(
                Failure::isKnown($result->errorCode),
                "{$capability} returned untyped error code '{$result->errorCode}'."
            );
        }
    }

    public function test_the_engine_records_no_provider_name_anywhere(): void
    {
        $domain = $this->verifiedDomain('noname.test');

        $this->engine->authorize($this->context(Registry::MAILBOX_CREATE, $domain, [
            'idempotencyKey' => 'noname',
            'input'          => ['local_part' => 'sales'],
        ]));

        $rows = DB::table('infra_operations')->get()->concat(DB::table('infra_events')->get());

        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            $encoded = strtolower((string) json_encode($row));

            foreach (['m' . 'igadu', 'z' . 'oho', 'f' . 'astmail', 'g' . 'oogle', 'm' . 'icrosoft'] as $fragment) {
                $this->assertStringNotContainsString($fragment, $encoded);
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function operations()
    {
        return WorkspaceContext::run(
            self::WS,
            fn () => InfraOperation::query()->where('capability', 'email')->orderBy('id')->get()
        );
    }

    private function entitlements(): array
    {
        return [
            Registry::ENTITLEMENT_ACCESS        => true,
            Registry::ENTITLEMENT_MAILBOX_LIMIT => 25,
            Registry::ENTITLEMENT_DOMAIN_LIMIT  => 5,
        ];
    }

    private function domain(string $name, string $custody = 'managed_by_us'): EmailDomain
    {
        return WorkspaceContext::run(self::WS, fn () => EmailDomain::create([
            'domain'  => $name,
            'custody' => $custody,
        ]));
    }

    private function verifiedDomain(string $name): EmailDomain
    {
        return WorkspaceContext::run(self::WS, function () use ($name) {
            $domain = EmailDomain::create(['domain' => $name, 'custody' => 'managed_by_us']);

            $domain->verification_state = EmailVerificationState::VERIFIED;
            $domain->dns_verified_at = now();
            $domain->lifecycle_state = EmailDomainState::ACTIVE;
            $domain->save();

            return $domain;
        });
    }

    private function mailbox(EmailDomain $domain, string $localPart): EmailMailbox
    {
        return WorkspaceContext::run(self::WS, fn () => EmailMailbox::create([
            'email_domain_id' => $domain->id,
            'local_part'      => $localPart,
            'quota_mb'        => 1024,
            'lifecycle_state' => EmailMailboxState::ACTIVE,
        ]));
    }

    /** A subject of the right kind for capabilities that need one. */
    private function subjectFor(string $capability, EmailDomain $domain): ?object
    {
        if (str_starts_with($capability, 'email.forwarder')) {
            return WorkspaceContext::run(self::WS, function () use ($domain, $capability) {
                $forwarder = EmailForwarder::create([
                    'email_domain_id'     => $domain->id,
                    'source_local_part'   => 'sweep-' . substr(md5($capability), 0, 6),
                    'destination_address' => 'sweep@elsewhere.test',
                ]);

                return $forwarder->recordLoopVerdict(ForwarderLoopSafety::evaluate(
                    'sweep@' . $domain->domain,
                    'sweep@elsewhere.test',
                    [$domain->domain]
                ));
            });
        }

        return null;
    }

    private function context(string $capability, EmailDomain $domain, array $overrides = []): EmailOperationContext
    {
        return new EmailOperationContext(
            workspaceId: self::WS,
            capability: $capability,
            domain: $domain,
            subject: $overrides['subject'] ?? null,
            actorUserId: $overrides['actorUserId'] ?? self::ACTOR,
            source: $overrides['source'] ?? 'manual',
            entitlements: $overrides['entitlements'] ?? $this->entitlements(),
            input: $overrides['input'] ?? [],
            idempotencyKey: $overrides['idempotencyKey'] ?? null,
            targetState: $overrides['targetState'] ?? null,
            approved: $overrides['approved'] ?? false,
            approvedBy: $overrides['approvedBy'] ?? null,
        );
    }
}
