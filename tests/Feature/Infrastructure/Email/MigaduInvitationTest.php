<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Connectors\Infrastructure\Email\Migadu\MigaduClient;
use App\Connectors\Infrastructure\Email\Migadu\MigaduEmailProviderConnector;
use App\Connectors\Infrastructure\Email\Migadu\MigaduRequestFactory as Req;
use App\Connectors\Infrastructure\Email\Migadu\MigaduResponseMapper as Map;
use App\Engines\Infrastructure\Email\Customer\CustomerStatus;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\Support\EmailFlightPlan;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * INFRA888 · E7 — invitation-mode provisioning.
 *
 * THE LOCKED RULE THIS FILE EXISTS TO ENFORCE:
 * LevelUp Growth never generates, stores, transmits, displays or recovers a
 * customer mailbox password. The provider invites the owner; the owner chooses
 * their own secret; we never see it.
 *
 * Every fixture matches what the live API returned on 2026-08-06.
 */
class MigaduInvitationTest extends TestCase
{
    private const DOMAIN = 'levelupgrowth.io';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** As the live API returns an invited mailbox: exists, unclaimed. */
    private function invitedMailboxRow(string $localPart = 'newhire'): array
    {
        return [
            'local_part'              => $localPart,
            'domain_name'             => self::DOMAIN,
            'address'                 => $localPart . '@' . self::DOMAIN,
            'name'                    => 'New Hire',
            'is_active'               => false,
            'activated_at'            => null,
            'password_recovery_email' => 'owner@example.test',
            'storage_usage'           => 0.0,
            'may_send'                => true,
            'may_receive'             => true,
            'may_access_imap'         => true,
            'may_access_pop3'         => true,
            'may_access_managesieve'  => true,
            'forwardings'             => [],
        ];
    }

    /** A genuinely suspended mailbox: was activated once, now switched off. */
    private function suspendedMailboxRow(string $localPart = 'departed'): array
    {
        return array_merge($this->invitedMailboxRow($localPart), [
            'is_active'    => false,
            'activated_at' => '2026-01-01T00:00:00.000Z',
        ]);
    }

    // ═══ THE PASSWORD RULE ═══════════════════════════════════════════════════

    public function test_the_create_payload_never_contains_a_password(): void
    {
        $payload = Req::createMailbox(new MailboxSpec(
            localPart: 'newhire',
            displayName: 'New Hire',
            invitationEmail: 'owner@example.test',
        ));

        $this->assertArrayNotHasKey('password', $payload);
        $this->assertSame('invitation', $payload['password_method']);
        $this->assertSame('owner@example.test', $payload['password_recovery_email']);

        // Nothing in the payload may look like a secret.
        foreach ($payload as $key => $value) {
            $this->assertStringNotContainsStringIgnoringCase('secret', (string) $key);
        }
    }

    public function test_no_password_field_appears_on_the_wire(): void
    {
        Http::fake(['*/mailboxes' => Http::response($this->invitedMailboxRow(), 200)]);

        $this->connector(mutations: true)->createMailbox(
            self::DOMAIN,
            new MailboxSpec('newhire', 'New Hire', null, 'owner@example.test'),
            $this->callContext()
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            $this->assertArrayNotHasKey('password', $body);
            $this->assertArrayNotHasKey('password_hash', $body);

            // And the raw request body must not contain the word at all.
            $this->assertStringNotContainsString('"password"', $request->body());

            return true;
        });
    }

    public function test_the_contract_has_nowhere_to_put_a_password(): void
    {
        $reflection = new \ReflectionClass(MailboxSpec::class);

        foreach ($reflection->getConstructor()->getParameters() as $parameter) {
            $this->assertStringNotContainsStringIgnoringCase('password', $parameter->getName(),
                'MailboxSpec gained a password parameter. It must never have one.');
            $this->assertStringNotContainsStringIgnoringCase('secret', $parameter->getName());
        }

        // The invitation address is an ADDRESS, and is declared as such.
        $this->assertTrue($reflection->hasProperty('invitationEmail'));
    }

    public function test_the_audit_record_names_no_recipient(): void
    {
        $audit = (new MailboxSpec('newhire', 'New Hire', null, 'owner@example.test'))->toAuditArray();

        $this->assertTrue($audit['invitation_sent']);

        // THAT an invitation was sent, never TO WHOM. The recipient is personal
        // data the customer did not ask to have copied into an audit trail.
        $this->assertNotContains('owner@example.test', $audit);
        $this->assertArrayNotHasKey('invitation_email', $audit);
    }

    public function test_a_malformed_invitation_address_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailboxSpec('newhire', 'New Hire', null, 'not-an-address');
    }

    // ═══ INVITED IS NOT SUSPENDED ════════════════════════════════════════════

    public function test_an_invited_mailbox_is_not_reported_as_suspended(): void
    {
        // The single most damaging confusion available here. Before E7 this
        // mailbox would have been shown to its customer as SUSPENDED, moments
        // after they created it.
        $row = $this->invitedMailboxRow();

        $this->assertTrue(Map::isAwaitingActivation($row));
        $this->assertFalse(Map::isSuspended($row));
    }

    public function test_a_genuinely_suspended_mailbox_still_reads_as_suspended(): void
    {
        $row = $this->suspendedMailboxRow();

        $this->assertFalse(Map::isAwaitingActivation($row), 'It was activated once — activated_at proves it.');
        $this->assertTrue(Map::isSuspended($row));
    }

    public function test_an_active_mailbox_is_neither(): void
    {
        $row = array_merge($this->invitedMailboxRow(), [
            'is_active'    => true,
            'activated_at' => '2026-02-02T00:00:00.000Z',
        ]);

        $this->assertFalse(Map::isAwaitingActivation($row));
        $this->assertFalse(Map::isSuspended($row));
    }

    public function test_mailbox_status_distinguishes_all_three(): void
    {
        Http::fake([
            '*/mailboxes/newhire'  => Http::response($this->invitedMailboxRow(), 200),
            '*/mailboxes/departed' => Http::response($this->suspendedMailboxRow(), 200),
        ]);

        $connector = $this->connector();

        $this->assertSame('awaiting_activation',
            $connector->getMailboxStatus(self::DOMAIN . '/newhire', $this->callContext())->normalizedState);

        $this->assertSame('suspended',
            $connector->getMailboxStatus(self::DOMAIN . '/departed', $this->callContext())->normalizedState);
    }

    // ═══ CREATE SEMANTICS ════════════════════════════════════════════════════

    public function test_an_invited_create_is_accepted_and_awaits_the_owner(): void
    {
        Http::fake(['*/mailboxes' => Http::response($this->invitedMailboxRow(), 200)]);

        $result = $this->connector(mutations: true)->createMailbox(
            self::DOMAIN,
            new MailboxSpec('newhire', 'New Hire', null, 'owner@example.test'),
            $this->callContext()
        );

        $this->assertTrue($result->success);
        $this->assertFalse($result->verified, 'A create is never done.');
        $this->assertSame('awaiting_activation', $result->normalizedState);
        // E7.4 renamed this: the flag now records whether the CUSTOMER supplied
        // a credential, because LevelUp no longer generates one at all.
        $this->assertFalse($result->data['password_supplied_by_customer']);
        $this->assertTrue($result->data['invitation_sent']);
        $this->assertTrue($result->data['awaiting_owner_action']);
    }

    public function test_a_create_without_an_invitation_still_reports_provisioning(): void
    {
        // A provider that activates on create needs no invitation, and the
        // adapter must not force our vendor's semantics onto the contract.
        Http::fake(['*/mailboxes' => Http::response(['local_part' => 'sales'], 200)]);

        $result = $this->connector(mutations: true)->createMailbox(
            self::DOMAIN,
            new MailboxSpec('sales'),
            $this->callContext()
        );

        $this->assertSame('provisioning', $result->normalizedState);
        $this->assertFalse($result->data['invitation_sent']);
    }

    // ═══ LIFECYCLE ═══════════════════════════════════════════════════════════

    public function test_the_lifecycle_has_a_state_for_an_unclaimed_mailbox(): void
    {
        $this->assertContains(EmailMailboxState::AWAITING_ACTIVATION, EmailMailboxState::states());
    }

    public function test_an_unclaimed_mailbox_can_be_claimed_or_torn_down_but_not_reprovisioned(): void
    {
        $onward = EmailMailboxState::transitions()[EmailMailboxState::AWAITING_ACTIVATION];

        $this->assertContains(EmailMailboxState::ACTIVE, $onward, 'The owner accepts.');
        $this->assertContains(EmailMailboxState::DELETING, $onward, 'Or it is torn down unclaimed.');

        // Re-provisioning a mailbox that already exists is the duplicate-create
        // path this architecture exists to prevent.
        $this->assertNotContains(EmailMailboxState::PROVISIONING, $onward);
    }

    public function test_provisioning_can_reach_the_unclaimed_state(): void
    {
        $this->assertContains(
            EmailMailboxState::AWAITING_ACTIVATION,
            EmailMailboxState::transitions()[EmailMailboxState::PROVISIONING]
        );
    }

    public function test_the_flight_plan_offers_a_pending_success_state(): void
    {
        $plan = EmailFlightPlan::for(Registry::MAILBOX_CREATE);

        $this->assertSame(EmailMailboxState::AWAITING_ACTIVATION, $plan['success_pending']);

        // The immediate-activation path is retained for providers that do not
        // invite, so the contract stays provider-neutral.
        $this->assertSame(EmailMailboxState::ACTIVE, $plan['success']);
    }

    // ═══ WHAT THE CUSTOMER SEES ══════════════════════════════════════════════

    public function test_the_customer_is_told_their_colleague_must_act(): void
    {
        $state = CustomerStatus::forMailbox(EmailMailboxState::AWAITING_ACTIVATION);

        $this->assertSame(CustomerStatus::AWAITING_ACTIVATION, $state);

        $presentation = CustomerStatus::describe($state);

        $this->assertNotSame('', $presentation['label']);
        $this->assertStringContainsStringIgnoringCase('invitation', $presentation['detail']);
    }

    public function test_the_customer_wording_never_implies_we_hold_a_password(): void
    {
        $detail = CustomerStatus::describe(CustomerStatus::AWAITING_ACTIVATION)['detail'];

        foreach ([
            'temporary password', 'we have set', 'your password is', 'reset link',
            'one-time', 'we will send you a password',
        ] as $implication) {
            $this->assertStringNotContainsStringIgnoringCase($implication, $detail,
                'The wording implies LevelUp holds or issues a password. It never does.');
        }
    }

    public function test_the_customer_wording_names_no_vendor(): void
    {
        $detail = CustomerStatus::describe(CustomerStatus::AWAITING_ACTIVATION)['detail'];

        foreach (['migadu', 'zoho', 'fastmail'] as $vendor) {
            $this->assertStringNotContainsStringIgnoringCase($vendor, $detail);
        }
    }

    public function test_an_unclaimed_mailbox_counts_as_needing_customer_action(): void
    {
        // A mailbox nobody claims is a support ticket waiting to happen.
        $this->assertContains(CustomerStatus::AWAITING_ACTIVATION, CustomerStatus::needsCustomerAction());
    }

    public function test_every_mailbox_lifecycle_state_still_maps_to_a_customer_state(): void
    {
        foreach (EmailMailboxState::states() as $state) {
            $this->assertArrayHasKey($state, CustomerStatus::mailboxMap(),
                "{$state} has no customer-safe projection.");
        }
    }

    // ═══ HELPERS ═════════════════════════════════════════════════════════════

    private function connector(bool $mutations = false): MigaduEmailProviderConnector
    {
        return new MigaduEmailProviderConnector(
            client: new MigaduClient(
                http: app(HttpFactory::class),
                accountEmail: 'ops@levelupgrowth.io',
                apiKey: 'not-a-real-key',
                networkEnabled: true,
            ),
            mutationsEnabled: $mutations,
        );
    }

    private function callContext(): ProviderCallContext
    {
        return new ProviderCallContext(
            workspaceId: 990701,
            ownerType: 'email_mailbox',
            ownerId: null,
            idempotencyKey: 'e7-' . bin2hex(random_bytes(4)),
        );
    }
}
