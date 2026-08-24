<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability as Cap;
use App\Connectors\Infrastructure\BusinessEmail\Values\AliasSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\CatchAllSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ForwarderSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailDnsRecord;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderInventory;
use App\Connectors\Infrastructure\Contracts\EmailProviderConnector;
use App\Connectors\Infrastructure\Email\Migadu\MigaduCapabilityMap as CapMap;
use App\Connectors\Infrastructure\Email\Migadu\MigaduClient;
use App\Connectors\Infrastructure\Email\Migadu\MigaduEmailProviderConnector;
use App\Connectors\Infrastructure\Email\Migadu\MigaduErrorClassifier as Err;
use App\Connectors\Infrastructure\Email\Migadu\MigaduRequestFactory as Req;
use App\Connectors\Infrastructure\Email\Migadu\MigaduResponseMapper as Map;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * INFRA888 · E5 — the first real vendor adapter, proven without a vendor account.
 *
 * EVERY HTTP EXCHANGE HERE IS FAKED. No test in this file may reach the
 * internet, and one of the tests below proves that by asserting the adapter
 * cannot open a socket in its default configuration. That is what makes "no
 * unexpected network call occurred" a property of the code rather than a claim
 * in a report.
 */
class MigaduAdapterTest extends TestCase
{
    private const DOMAIN = 'adapter.test';

    protected function setUp(): void
    {
        parent::setUp();

        // Nothing in this suite is permitted to leave the process. Any request
        // the fake has no stub for raises rather than dialling out.
        Http::preventStrayRequests();
    }

    // ═══ CONTRACT AND CAPABILITY ═════════════════════════════════════════════

    public function test_the_adapter_implements_every_contract_method(): void
    {
        $contract = new ReflectionClass(EmailProviderConnector::class);
        $adapter = new ReflectionClass(MigaduEmailProviderConnector::class);

        $this->assertTrue($adapter->implementsInterface(EmailProviderConnector::class));

        foreach ($contract->getMethods() as $method) {
            $this->assertTrue(
                $adapter->hasMethod($method->getName()),
                "The adapter is missing {$method->getName()}()."
            );
        }

        // 28 since E7.3 added setMailboxPassword — the capability that makes
        // white-label onboarding possible. Asserted exactly so that any FURTHER
        // change to the shared contract is a deliberate act.
        $this->assertCount(28, $contract->getMethods(), 'The contract surface changed — re-verify the matrix.');
    }

    public function test_the_capability_matrix_covers_every_declared_capability(): void
    {
        $covered = array_merge(CapMap::supported(), CapMap::unsupported());

        sort($covered);
        $all = Cap::all();
        sort($all);

        $this->assertSame($all, $covered, 'A capability exists that the matrix does not judge.');
    }

    public function test_the_provider_is_viable_because_it_has_every_required_capability(): void
    {
        $set = CapMap::capabilitySet();

        $this->assertSame([], $set->missingRequired());
        $this->assertTrue($set->isViable());
    }

    public function test_the_five_unsupported_capabilities_are_the_verified_ones(): void
    {
        // Named individually so a future change to the matrix is a deliberate
        // edit to this list, not a silent drift.
        $this->assertEqualsCanonicalizing([
            Cap::DOMAIN_DKIM_ROTATE,
            Cap::MAILBOX_RENAME,
            Cap::MAILBOX_QUOTA,
            Cap::MAILBOX_PASSWORD_RESET,
            Cap::LAST_LOGIN_READ,
        ], CapMap::unsupported());
    }

    public function test_every_matrix_verdict_carries_documentary_evidence(): void
    {
        foreach (Cap::all() as $capability) {
            $this->assertNotSame('', CapMap::evidenceFor($capability),
                "{$capability} has a verdict but no evidence behind it.");

            if (CapMap::supports($capability)) {
                $this->assertNotSame('', CapMap::apiFor($capability),
                    "{$capability} is marked supported but names no endpoint.");
            }
        }
    }

    // ═══ THE GATES ═══════════════════════════════════════════════════════════

    public function test_the_adapter_cannot_reach_the_network_by_default(): void
    {
        // The most important assertion in this file, and the first version of it
        // proved nothing.
        //
        // With no stub registered, a blocked stray request and a working
        // kill-switch produce the SAME ProviderResult, so the test passed
        // whether or not the switch existed — controlled injection caught that.
        // Registering a stub that WOULD succeed means the only thing standing
        // between this call and a recorded request is the switch itself.
        Http::fake(['*' => Http::response(['domains' => []], 200)]);

        $this->assertFalse((bool) config('business_email.provider.network_enabled'));
        $this->assertFalse((bool) config('business_email.provider.mutations_enabled'));

        $result = $this->connector(networkEnabled: false)->healthCheck();

        $this->assertFalse($result->success, 'The call succeeded, so the kill-switch did not hold.');
        $this->assertSame(Err::UNAVAILABLE, $result->errorCode);

        // The decisive assertion: a stub was available and still nothing was sent.
        Http::assertNothingSent();
    }

    public function test_the_adapter_refuses_to_call_without_a_credential(): void
    {
        Http::fake(['*' => Http::response(['domains' => []], 200)]);

        $connector = new MigaduEmailProviderConnector(
            client: new MigaduClient(
                http: app(HttpFactory::class),
                accountEmail: '',
                apiKey: '',
                networkEnabled: true,
            ),
            mutationsEnabled: true,
        );

        $result = $connector->healthCheck();

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    public function test_every_mutating_method_is_refused_while_mutations_are_disabled(): void
    {
        $connector = $this->connector(networkEnabled: true, mutationsEnabled: false);
        $context = $this->context();

        $calls = [
            'onboardDomain'      => fn () => $connector->onboardDomain(self::DOMAIN, $context),
            'verifyDomain'       => fn () => $connector->verifyDomain(self::DOMAIN, $context),
            'createMailbox'      => fn () => $connector->createMailbox(self::DOMAIN, new MailboxSpec('sales'), $context),
            'updateMailbox'      => fn () => $connector->updateMailbox(self::DOMAIN . '/sales', new MailboxSpec('sales'), $context),
            'suspendMailbox'     => fn () => $connector->suspendMailbox(self::DOMAIN . '/sales', $context),
            'restoreMailbox'     => fn () => $connector->restoreMailbox(self::DOMAIN . '/sales', $context),
            'deleteMailbox'      => fn () => $connector->deleteMailbox(self::DOMAIN . '/sales', $context),
            'createAlias'        => fn () => $connector->createAlias(self::DOMAIN, new AliasSpec('info', 'sales@' . self::DOMAIN), $context),
            'deleteAlias'        => fn () => $connector->deleteAlias(self::DOMAIN . '/info', $context),
            'createForwarder'    => fn () => $connector->createForwarder(self::DOMAIN, new ForwarderSpec('sales', 'x@elsewhere.test'), $context),
            'deleteForwarder'    => fn () => $connector->deleteForwarder(self::DOMAIN . '/sales/x@elsewhere.test', $context),
            'configureCatchAll'  => fn () => $connector->configureCatchAll(self::DOMAIN, new CatchAllSpec('sales@' . self::DOMAIN), $context),
            'clearCatchAll'      => fn () => $connector->clearCatchAll(self::DOMAIN, $context),
        ];

        // A stub that WOULD succeed, so the gate is the only thing stopping these.
        Http::fake(['*' => Http::response([], 200)]);

        foreach ($calls as $name => $call) {
            $result = $call();

            $this->assertFalse($result->success, "{$name} executed while mutations were disabled.");
            $this->assertContains(
                $result->errorCode,
                ['provider_mutations_disabled', Err::UNSUPPORTED],
                "{$name} failed for the wrong reason: {$result->errorCode}"
            );
        }

        // The decisive one: not a single request was attempted.
        Http::assertNothingSent();
    }

    public function test_reads_are_permitted_while_mutations_are_disabled(): void
    {
        Http::fake([
            '*/domains' => Http::response(['domains' => [['name' => self::DOMAIN]]], 200),
        ]);

        $result = $this->connector(networkEnabled: true, mutationsEnabled: false)->healthCheck();

        $this->assertTrue($result->success);
        $this->assertTrue($result->verified);
        $this->assertSame(1, $result->data['domain_count']);
    }

    // ═══ READ METHODS ════════════════════════════════════════════════════════

    public function test_dns_requirements_are_mapped_to_neutral_records(): void
    {
        Http::fake([
            '*/domains/' . self::DOMAIN . '/records' => Http::response([
                ['type' => 'MX', 'host' => '@', 'value' => 'aspmx1.example-mail.test', 'priority' => 10, 'ttl' => 3600],
                ['type' => 'TXT', 'host' => '@', 'value' => 'v=spf1 include:spf.example-mail.test -all'],
                ['type' => 'TXT', 'host' => 'key1._domainkey', 'value' => 'v=DKIM1; k=rsa; p=AAA'],
                ['type' => 'TXT', 'host' => '_dmarc', 'value' => 'v=DMARC1; p=none'],
                ['type' => 'A', 'host' => 'www', 'value' => '1.2.3.4'],
            ], 200),
        ]);

        $result = $this->connector(networkEnabled: true)->getDnsRequirements(self::DOMAIN, $this->context());

        $this->assertTrue($result->success);

        /** @var array<int,MailDnsRecord> $records */
        $records = $result->data['records'];

        // The engine consumes these as objects. An array would fail at the
        // first property access, so the type is asserted, not assumed.
        $this->assertContainsOnlyInstancesOf(MailDnsRecord::class, $records);

        $purposes = array_map(fn (MailDnsRecord $r) => $r->purpose, $records);

        $this->assertEqualsCanonicalizing(
            [MailDnsRecord::PURPOSE_MX, MailDnsRecord::PURPOSE_SPF, MailDnsRecord::PURPOSE_DKIM, MailDnsRecord::PURPOSE_DMARC],
            $purposes,
            'The unrelated A record should have been dropped, and the four mail records kept.'
        );

        foreach ($records as $record) {
            if ($record->purpose === MailDnsRecord::PURPOSE_DMARC) {
                $this->assertFalse($record->required, 'Mail flows without DMARC; it must not be mandatory.');
            } else {
                $this->assertTrue($record->required);
            }
        }
    }

    public function test_a_domain_awaiting_dns_is_reported_as_waiting_not_failed(): void
    {
        // The provider documents 422 as "DNS checks or validation failed".
        // For a domain whose records are not published yet that is the normal
        // state, and calling it a failure would put a customer into an error
        // screen for doing nothing wrong.
        Http::fake([
            '*/diagnostics' => Http::response(['error' => 'DNS verification pending'], 422),
        ]);

        $result = $this->connector(networkEnabled: true)->getDomainAuthStatus(self::DOMAIN, $this->context());

        $this->assertTrue($result->success);
        $this->assertFalse($result->verified);
        $this->assertSame('awaiting_dns', $result->normalizedState);
    }

    public function test_inventory_maps_mailboxes_aliases_forwarders_and_catch_all(): void
    {
        Http::fake([
            '*/domains/' . self::DOMAIN . '/mailboxes' => Http::response([
                'mailboxes' => [
                    ['local_part' => 'sales', 'name' => 'Sales', 'may_send' => true, 'may_receive' => true],
                    ['local_part' => 'old', 'name' => 'Old', 'may_send' => false, 'may_receive' => false,
                     'may_access_imap' => false, 'may_access_pop3' => false, 'may_access_managesieve' => false],
                ],
            ], 200),
            '*/domains/' . self::DOMAIN . '/aliases' => Http::response([
                'aliases' => [['local_part' => 'info', 'destinations' => ['sales@' . self::DOMAIN]]],
            ], 200),
            '*/domains/' . self::DOMAIN . '/rewrites' => Http::response([
                'rewrites' => [['name' => 'levelup-catch-all', 'local_part_rule' => '*', 'destinations' => ['sales@' . self::DOMAIN]]],
            ], 200),
            '*/mailboxes/sales/forwardings' => Http::response([
                'forwardings' => [['address' => 'x@elsewhere.test', 'is_active' => true, 'confirmed_at' => '2026-01-01T00:00:00Z']],
            ], 200),
            '*/mailboxes/old/forwardings' => Http::response(['forwardings' => []], 200),
        ]);

        $result = $this->connector(networkEnabled: true)->getInventory(self::DOMAIN, $this->context());

        $this->assertTrue($result->success);

        /** @var ProviderInventory $inventory */
        $inventory = $result->data['inventory'];

        $this->assertInstanceOf(ProviderInventory::class, $inventory);
        $this->assertTrue($inventory->complete, 'A two-mailbox domain is well inside the fan-out bound.');
        $this->assertCount(2, $inventory->mailboxes);
        $this->assertCount(1, $inventory->aliases);
        $this->assertCount(1, $inventory->forwarders);
        $this->assertTrue($inventory->catchAll->enabled);

        $byLocalPart = $inventory->mailboxesByLocalPart();

        // Suspension is inferred from the access flags the adapter itself clears.
        $this->assertFalse($byLocalPart['sales']->suspended);
        $this->assertTrue($byLocalPart['old']->suspended);
    }

    public function test_a_failed_forwarding_read_makes_the_inventory_partial(): void
    {
        // An inventory that silently omits objects is worse than no inventory:
        // reconciliation would conclude they were deleted.
        Http::fake([
            '*/domains/' . self::DOMAIN . '/mailboxes' => Http::response([
                'mailboxes' => [['local_part' => 'sales']],
            ], 200),
            '*/domains/' . self::DOMAIN . '/aliases'  => Http::response(['aliases' => []], 200),
            '*/domains/' . self::DOMAIN . '/rewrites' => Http::response(['rewrites' => []], 200),
            '*/mailboxes/sales/forwardings'           => Http::response(['error' => 'boom'], 500),
        ]);

        $result = $this->connector(networkEnabled: true)->getInventory(self::DOMAIN, $this->context());

        /** @var ProviderInventory $inventory */
        $inventory = $result->data['inventory'];

        $this->assertFalse($inventory->complete);
        $this->assertNotSame('', $inventory->incompleteReason);
    }

    public function test_an_empty_rewrite_list_is_positive_evidence_of_no_catch_all(): void
    {
        // PTAA's requirement is that unknown recipients are REJECTED. "We do not
        // know" and "there is none" must not be the same answer.
        Http::fake([
            '*/rewrites' => Http::response(['rewrites' => []], 200),
        ]);

        $result = $this->connector(networkEnabled: true)->getCatchAll(self::DOMAIN, $this->context());

        $this->assertTrue($result->success);
        $this->assertSame('disabled', $result->normalizedState);
        $this->assertFalse($result->data['catch_all']->enabled);
        $this->assertTrue($result->data['catch_all']->isKnown());
    }

    public function test_usage_reports_domain_totals_and_refuses_to_invent_per_mailbox_storage(): void
    {
        Http::fake([
            '*/usage'     => Http::response(['storage_usage' => 2048, 'mailboxes' => 2], 200),
            '*/mailboxes' => Http::response(['mailboxes' => [['local_part' => 'a'], ['local_part' => 'b']]], 200),
        ]);

        $result = $this->connector(networkEnabled: true)->getUsage(self::DOMAIN, $this->context());

        $this->assertTrue($result->success);
        $this->assertFalse($result->data['per_mailbox_storage_available']);
        $this->assertSame(2048, $result->data['domain_usage']['storage_used_mb']);

        foreach ($result->data['samples'] as $sample) {
            // Null, not zero. The provider does not publish per-mailbox storage,
            // and a fabricated zero would be a lie about someone's mailbox.
            $this->assertNull($sample->storageUsedMb);
            $this->assertNull($sample->storageQuotaMb);
        }
    }

    // ═══ MUTATING METHODS, MOCKED HTTP ONLY ══════════════════════════════════

    public function test_creating_a_mailbox_is_accepted_never_verified(): void
    {
        Http::fake(['*/mailboxes' => Http::response(['local_part' => 'sales'], 201)]);

        $result = $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->createMailbox(self::DOMAIN, new MailboxSpec('sales', 'Sales'), $this->context());

        $this->assertTrue($result->success);
        $this->assertFalse($result->verified, 'The provider said yes; only a read-back may say done.');
        $this->assertSame('provisioning', $result->normalizedState);
        $this->assertSame(self::DOMAIN . '/sales', $result->providerResourceId);
    }

    public function test_mailbox_creation_sends_no_credential_unless_the_customer_supplied_one(): void
    {
        // THIS TEST ONCE ASSERTED THE OPPOSITE, and the reversal was deliberate.
        //
        // E4 forbade transmitting any mailbox secret. E7.1 then proved the only
        // alternative — the provider's own invitation email — names the vendor
        // to the customer in its sender, subject, link and footer. E7.2's
        // approved architecture therefore permits ONE undisclosed bootstrap
        // credential, so that the provider stays silent and LevelUp owns the
        // entire customer conversation.
        //
        // What must still never happen is a DISCLOSED password, or an
        // invitation. Both are asserted below.
        Http::fake(['*/mailboxes' => Http::response([], 201)]);

        $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->createMailbox(self::DOMAIN, new MailboxSpec('sales', 'Sales'), $this->context());

        Http::assertSent(function ($request) {
            $body = $request->data();

            // E7.4: no credential at all unless the CUSTOMER supplied one in the
            // same request. LevelUp generates none.
            $this->assertArrayNotHasKey('password', $body);

            // Invitation mode would put the vendor in front of the customer.
            $this->assertArrayNotHasKey('password_method', $body);

            // No address for the provider to write to.
            $this->assertSame('', $body['password_recovery_email']);

            return true;
        });
    }

    public function test_no_credential_appears_in_the_create_result(): void
    {
        Http::fake(['*/mailboxes' => Http::response([], 201)]);

        $result = $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->createMailbox(self::DOMAIN, new MailboxSpec('sales', 'Sales'), $this->context());

        // The result is persisted into infra_operations. It records that no
        // password was set FOR THE CUSTOMER, and carries no credential.
        $encoded = json_encode($result->toArray());

        $this->assertFalse($result->data['password_supplied_by_customer']);
        $this->assertSame(0, preg_match('/"password"\s*:\s*"[^"]{8,}"/', $encoded),
            'A credential reached the operation payload.');
    }

    public function test_onboarding_a_domain_is_accepted_and_awaits_dns(): void
    {
        Http::fake(['*/domains' => Http::response(['name' => self::DOMAIN], 201)]);

        $result = $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->onboardDomain(self::DOMAIN, $this->context());

        $this->assertTrue($result->success);
        $this->assertFalse($result->verified);
        $this->assertSame('awaiting_dns', $result->normalizedState);
    }

    public function test_a_forwarder_without_a_parent_mailbox_is_refused_not_improvised(): void
    {
        // The structural mismatch between the contract and this provider. The
        // adapter must never create a mailbox nobody asked for, because that is
        // a billable object appearing out of nowhere.
        Http::fake(['*/mailboxes/ghost' => Http::response(['error' => 'not found'], 404)]);

        $result = $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->createForwarder(self::DOMAIN, new ForwarderSpec('ghost', 'x@elsewhere.test'), $this->context());

        $this->assertFalse($result->success);
        $this->assertSame(Err::UNSUPPORTED, $result->errorCode);
        $this->assertSame('unsupported', $result->normalizedState);

        // Crucially, no mailbox was created on the way to that answer.
        Http::assertNotSent(fn ($request) => $request->method() === 'POST');
    }

    public function test_an_unconfirmed_forwarder_is_accepted_not_active(): void
    {
        Http::fake([
            '*/mailboxes/sales/forwardings' => Http::response([
                'address' => 'x@elsewhere.test',
                'confirmation_sent_at' => '2026-08-05T10:00:00Z',
                'confirmed_at' => null,
                'is_active' => true,
            ], 201),
            '*/mailboxes/sales' => Http::response(['local_part' => 'sales'], 200),
        ]);

        $result = $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->createForwarder(self::DOMAIN, new ForwarderSpec('sales', 'x@elsewhere.test'), $this->context());

        $this->assertTrue($result->success);
        $this->assertFalse($result->verified, 'is_active is true before confirmation — mail still does not flow.');
        $this->assertSame('awaiting_confirmation', $result->normalizedState);
        $this->assertTrue($result->data['requires_destination_confirmation']);
    }

    public function test_a_confirmed_forwarder_is_verified(): void
    {
        Http::fake([
            '*/mailboxes/sales/forwardings' => Http::response([
                'address' => 'x@elsewhere.test',
                'confirmed_at' => '2026-08-05T10:05:00Z',
                'blocked_at' => null,
                'is_active' => true,
            ], 201),
            '*/mailboxes/sales' => Http::response(['local_part' => 'sales'], 200),
        ]);

        $result = $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->createForwarder(self::DOMAIN, new ForwarderSpec('sales', 'x@elsewhere.test'), $this->context());

        $this->assertTrue($result->verified);
        $this->assertSame('active', $result->normalizedState);
    }

    public function test_an_alias_to_another_domain_is_refused(): void
    {
        $result = $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->createAlias(self::DOMAIN, new AliasSpec('info', 'someone@elsewhere.test'), $this->context());

        $this->assertFalse($result->success);
        $this->assertSame(Err::UNSUPPORTED, $result->errorCode);
        Http::assertNothingSent();
    }

    public function test_an_alias_on_the_same_domain_is_accepted(): void
    {
        Http::fake(['*/aliases' => Http::response(['local_part' => 'info'], 201)]);

        $result = $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->createAlias(self::DOMAIN, new AliasSpec('info', 'sales@' . self::DOMAIN), $this->context());

        $this->assertTrue($result->success);
        $this->assertFalse($result->verified);
    }

    public function test_deleting_something_already_gone_converges_rather_than_erroring(): void
    {
        Http::fake(['*' => Http::response(['error' => 'not found'], 404)]);

        $connector = $this->connector(networkEnabled: true, mutationsEnabled: true);

        foreach ([
            'mailbox'   => fn () => $connector->deleteMailbox(self::DOMAIN . '/gone', $this->context()),
            'alias'     => fn () => $connector->deleteAlias(self::DOMAIN . '/gone', $this->context()),
            'forwarder' => fn () => $connector->deleteForwarder(self::DOMAIN . '/sales/x@elsewhere.test', $this->context()),
            'catchall'  => fn () => $connector->clearCatchAll(self::DOMAIN, $this->context()),
        ] as $label => $call) {
            $result = $call();

            $this->assertTrue($result->success, "Deleting an absent {$label} should converge.");
            $this->assertTrue($result->verified);
        }
    }

    public function test_password_reset_is_refused_rather_than_faked(): void
    {
        $result = $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->requestPasswordReset(self::DOMAIN . '/sales', $this->context());

        $this->assertFalse($result->success);
        $this->assertSame(Err::UNSUPPORTED, $result->errorCode);

        // Saying "password reset is not available" is correct and useful. What
        // it must never do is imply a secret was generated, sent or is waiting.
        foreach (['temporary', 'new password', 'sent to', 'one-time', 'reset link'] as $implication) {
            $this->assertStringNotContainsStringIgnoringCase($implication, $result->errorSummary ?? '',
                'The refusal implies a secret exists when the provider offers no such flow.');
        }

        Http::assertNothingSent();
    }

    public function test_suspension_uses_the_native_activation_field(): void
    {
        // CORRECTED IN E6. This test previously asserted the five-flag
        // emulation E5 designed from the published schema. The live mailbox
        // object carries is_active, so suspension is one field — which is also
        // what makes restore non-lossy.
        Http::fake(['*/mailboxes/sales' => Http::response([], 200)]);

        $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->suspendMailbox(self::DOMAIN . '/sales', $this->context());

        Http::assertSent(function ($request) {
            $this->assertSame(['is_active' => false], $request->data(),
                'Suspension must touch is_active and nothing else.');

            return true;
        });
    }

    public function test_restore_sets_the_same_single_field_back(): void
    {
        Http::fake(['*/mailboxes/sales' => Http::response([], 200)]);

        $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->restoreMailbox(self::DOMAIN . '/sales', $this->context());

        Http::assertSent(function ($request) {
            // One field down, the same field up. Nothing else can be lost.
            $this->assertSame(['is_active' => true], $request->data());

            return true;
        });
    }

    // ═══ ERROR CLASSIFICATION ════════════════════════════════════════════════

    public function test_the_error_taxonomy_maps_every_documented_and_expected_status(): void
    {
        $cases = [
            [401, [], false, Err::AUTH_FAILED,       'permanent'],
            [403, [], false, Err::FORBIDDEN,         'permanent'],
            [404, [], false, Err::NOT_FOUND,         'permanent'],
            [409, [], false, Err::DUPLICATE,         'permanent'],
            [429, [], false, Err::RATE_LIMITED,      'retryable'],
            [500, [], false, Err::UNAVAILABLE,       'retryable'],
            [503, [], false, Err::UNAVAILABLE,       'retryable'],
            [422, ['error' => 'DNS check failed'], false, Err::DNS_NOT_READY,     'retryable'],
            [422, ['error' => 'local_part invalid'], false, Err::VALIDATION_FAILED, 'permanent'],
            [400, ['error' => 'address already taken'], false, Err::DUPLICATE,    'permanent'],
            [400, ['error' => 'account limit exceeded'], false, Err::LIMIT_EXCEEDED, 'permanent'],
            [400, ['error' => 'bad request'], false, Err::VALIDATION_FAILED,      'permanent'],
        ];

        foreach ($cases as [$status, $body, $mutating, $expectedCode, $expectedRetry]) {
            [$code, $retry] = Err::classifyStatus($status, $body, $mutating);

            $this->assertSame($expectedCode, $code, "HTTP {$status} classified as {$code}.");
            $this->assertSame($expectedRetry, $retry, "HTTP {$status} retry class was {$retry}.");
        }
    }

    public function test_a_timeout_on_a_mutating_call_is_ambiguous_and_never_retryable(): void
    {
        // The single rule this whole architecture exists to protect. A retried
        // create is how a customer gets four mailboxes and one invoice line.
        foreach ([408, 504] as $status) {
            [$code, $retry] = Err::classifyStatus($status, [], true);

            $this->assertSame(Err::INDETERMINATE, $code);
            $this->assertSame('ambiguous', $retry);
        }

        // The same timeout on a READ is simply retryable — nothing changed.
        [$readCode, $readRetry] = Err::classifyStatus(504, [], false);

        $this->assertSame(Err::TIMEOUT, $readCode);
        $this->assertSame('retryable', $readRetry);
    }

    public function test_an_unknown_status_on_a_mutating_call_is_never_a_safe_retry(): void
    {
        [$code, $retry] = Err::classifyStatus(418, [], true);

        $this->assertSame(Err::UNKNOWN, $code);
        $this->assertSame('ambiguous', $retry);
    }

    public function test_an_ambiguous_outcome_is_not_reported_as_retryable(): void
    {
        $result = Err::toResult(Err::INDETERMINATE, 'ambiguous', 'timed out');

        $this->assertFalse($result->success);
        $this->assertFalse($result->isRetryable(), 'An ambiguous mutation must never be auto-retried.');
        $this->assertSame('needs_reconciliation', $result->normalizedState);
    }

    public function test_a_malformed_body_on_a_mutation_is_ambiguous_not_failed(): void
    {
        Http::fake(['*/mailboxes' => Http::response('<html>not json</html>', 201)]);

        $result = $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->createMailbox(self::DOMAIN, new MailboxSpec('sales'), $this->context());

        $this->assertFalse($result->success);
        $this->assertSame(Err::MALFORMED, $result->errorCode);
        $this->assertSame('needs_reconciliation', $result->normalizedState);
        $this->assertFalse($result->isRetryable());
    }

    public function test_no_customer_facing_error_summary_names_the_vendor(): void
    {
        $summaries = [];

        // EVERY status, not a hand-picked list. The first version of this test
        // omitted 400 — and 400 was precisely the branch where an injected
        // "append the provider's message" defect survived the whole guard suite.
        // A leak test that samples statuses tests the sampling, not the leak.
        $statuses = array_merge(range(400, 431), [500, 502, 503, 504, 418, 599]);

        $bodies = [
            ['error' => 'migadu backend exploded'],
            ['message' => 'migadu says no'],
            ['errors' => ['migadu rejected this']],
            ['detail' => 'contact migadu support'],
            [],
        ];

        foreach ($statuses as $status) {
            foreach ($bodies as $body) {
                foreach ([true, false] as $mutating) {
                    [, , $summary] = Err::classifyStatus($status, $body, $mutating);
                    $summaries[] = $summary;
                }
            }
        }

        $this->assertGreaterThan(300, count($summaries));

        foreach ($summaries as $summary) {
            $this->assertStringNotContainsStringIgnoringCase('migadu', $summary);

            // These assertions earned their place. The first version of the
            // classifier appended the provider's own message when it looked
            // short and harmless — and the provider's message names the vendor.
            // No provider text is concatenated into a summary at all now.
            foreach (['backend exploded', 'says no', 'rejected this', 'contact', 'support'] as $providerText) {
                $this->assertStringNotContainsStringIgnoringCase($providerText, $summary,
                    'Provider wording reached an error summary: ' . $summary);
            }
        }
    }

    // ═══ CLIENT BEHAVIOUR ════════════════════════════════════════════════════

    public function test_a_read_retries_but_a_mutation_never_does(): void
    {
        Http::fake(['*/domains' => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push(['domains' => []], 200)]);

        $result = $this->connector(networkEnabled: true)->healthCheck();

        $this->assertTrue($result->success, 'A read should have retried past a single 500.');
        Http::assertSentCount(2);
    }

    public function test_a_failing_mutation_is_attempted_exactly_once(): void
    {
        Http::fake(['*/mailboxes' => Http::response(['error' => 'boom'], 500)]);

        $this->connector(networkEnabled: true, mutationsEnabled: true)
            ->createMailbox(self::DOMAIN, new MailboxSpec('sales'), $this->context());

        Http::assertSentCount(1);
    }

    public function test_the_request_carries_authentication_and_a_correlation_id(): void
    {
        Http::fake(['*/domains' => Http::response(['domains' => []], 200)]);

        $this->connector(networkEnabled: true)->healthCheck();

        Http::assertSent(function ($request) {
            $this->assertNotEmpty($request->header('Authorization'));
            $this->assertNotEmpty($request->header('X-Request-Id'));
            $this->assertStringContainsString('LevelUpGrowth', $request->header('User-Agent')[0]);

            return true;
        });
    }

    public function test_rate_limit_headers_are_parsed_and_absence_is_reported_honestly(): void
    {
        $this->assertSame(30, MigaduClient::retryAfterSeconds(['retry-after' => '30']));
        $this->assertSame(0, MigaduClient::retryAfterSeconds(['retry-after' => '-5']));

        // The provider publishes no rate-limit documentation. Absent means
        // unknown, not zero — a zero would be read as "retry immediately".
        $this->assertNull(MigaduClient::retryAfterSeconds([]));
    }

    public function test_a_logged_route_carries_no_domain_or_mailbox(): void
    {
        $shape = MigaduClient::routeShape('domains/acme.test/mailboxes/ceo/forwardings/x@y.test');

        $this->assertSame('domains/{domain}/mailboxes/{mailbox}/forwardings/{address}', $shape);
        $this->assertStringNotContainsString('acme.test', $shape);
        $this->assertStringNotContainsString('ceo', $shape);
    }

    public function test_redaction_removes_a_secret_and_an_auth_header(): void
    {
        $text = MigaduClient::redact(
            'failed with key sk-live-abc123 and header Authorization: Basic dXNlcjpwYXNz',
            'sk-live-abc123'
        );

        $this->assertStringNotContainsString('sk-live-abc123', $text);
        $this->assertStringNotContainsString('dXNlcjpwYXNz', $text);
        $this->assertStringContainsString('[redacted]', $text);
    }

    public function test_a_path_segment_that_could_climb_the_path_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Req::mailbox('acme.test', '../../admin');
    }

    public function test_an_oversized_response_is_treated_as_malformed(): void
    {
        Http::fake(['*/domains' => Http::response(str_repeat('a', 3_000_000), 200)]);

        $result = $this->connector(networkEnabled: true)->healthCheck();

        $this->assertFalse($result->success);
        $this->assertSame(Err::MALFORMED, $result->errorCode);
    }

    // ═══ MAPPING ═════════════════════════════════════════════════════════════

    public function test_a_provider_reference_round_trips(): void
    {
        $ref = Map::mailboxRef('acme.test', 'ceo');

        $this->assertSame(['acme.test', 'ceo'], Map::splitRef($ref));
    }

    public function test_a_malformed_reference_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Map::splitRef('no-slash-here');
    }

    public function test_a_mailbox_with_no_access_flags_reports_unknown_suspension(): void
    {
        // Unknown must stay unknown. Guessing "not suspended" would let a
        // reconciliation quietly re-enable something an operator disabled.
        $this->assertNull(Map::isSuspended(['local_part' => 'x']));
        $this->assertFalse(Map::isSuspended(['may_send' => true, 'may_receive' => true]));
        $this->assertTrue(Map::isSuspended(['may_send' => false, 'may_receive' => false]));
    }

    public function test_destinations_accept_both_documented_shapes(): void
    {
        // The provider documents destinations as "Array or CSV String".
        $this->assertSame(['a@x.test', 'b@x.test'], Map::destinations(['destinations' => ['a@x.test', 'b@x.test']]));
        $this->assertSame(['a@x.test', 'b@x.test'], Map::destinations(['destinations' => 'a@x.test, b@x.test']));
        $this->assertSame([], Map::destinations([]));
    }

    // ═══ WHITE LABEL ═════════════════════════════════════════════════════════

    public function test_no_neutral_return_value_names_the_vendor(): void
    {
        Http::fake([
            '*/domains' => Http::response(['domains' => [['name' => self::DOMAIN]]], 200),
        ]);

        $result = $this->connector(networkEnabled: true)->healthCheck();

        $encoded = json_encode($result->toArray());

        $this->assertStringNotContainsStringIgnoringCase('migadu', $encoded);
    }

    public function test_the_provider_key_is_an_identifier_not_a_customer_string(): void
    {
        $connector = $this->connector();

        // It must match the registry row, and it must never be rendered. The
        // customer-surface guards in E4 already prove no customer payload
        // carries a provider concept at all.
        $this->assertSame('migadu', $connector->provider());
        $this->assertSame('email', $connector->capability());
    }

    // ═══ HELPERS ═════════════════════════════════════════════════════════════

    private function connector(bool $networkEnabled = false, bool $mutationsEnabled = false): MigaduEmailProviderConnector
    {
        return new MigaduEmailProviderConnector(
            client: new MigaduClient(
                http: app(HttpFactory::class),
                accountEmail: 'ops@levelupgrowth.io',
                apiKey: 'test-key-not-real',
                networkEnabled: $networkEnabled,
            ),
            mutationsEnabled: $mutationsEnabled,
        );
    }

    private function context(): ProviderCallContext
    {
        return new ProviderCallContext(
            workspaceId: 990501,
            ownerType: 'email_mailbox',
            ownerId: null,
            idempotencyKey: 'e5-test-' . bin2hex(random_bytes(4)),
        );
    }
}
