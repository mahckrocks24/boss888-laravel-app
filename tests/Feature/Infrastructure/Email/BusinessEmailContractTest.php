<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability;
use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapabilitySet;
use App\Connectors\Infrastructure\BusinessEmail\Values\AliasSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\CatchAllSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ForwarderSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxUsageSample;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailDnsRecord;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderInventory;
use App\Connectors\Infrastructure\BusinessEmail\Values\RemoteCatchAll;
use App\Connectors\Infrastructure\BusinessEmail\Values\RemoteMailbox;
use App\Connectors\Infrastructure\Contracts\EmailProviderConnector;
use App\Connectors\Infrastructure\Contracts\InfrastructureConnector;
use App\Connectors\Infrastructure\ProviderResult;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\Support\EmailFlightPlan;
use App\Engines\Infrastructure\Email\Support\ProviderOutcome;
use InvalidArgumentException;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * INFRA888 · E2-A/B/C — CONTRACT COMPLETENESS AND CAPABILITY FLAGS.
 *
 * Asserts that the finalised contract can express every registered capability,
 * that its shape is provider-neutral by construction, and that the typed value
 * objects refuse the inputs that would make a provider call unsafe.
 */
class BusinessEmailContractTest extends TestCase
{
    // ── contract completeness ────────────────────────────────────────────────

    public function test_every_registered_capability_has_a_contract_method(): void
    {
        $available = array_map(fn ($m) => $m->getName(), (new ReflectionClass(EmailProviderConnector::class))->getMethods());

        foreach (Registry::capabilities() as $slug => $meta) {
            $this->assertNotNull(
                $meta['connector_method'],
                "{$slug} has no contract method. E2's exit criterion is that no capability is unimplementable."
            );

            $this->assertContains(
                $meta['connector_method'],
                $available,
                "{$slug} names '{$meta['connector_method']}', which the contract does not declare."
            );
        }
    }

    public function test_the_contract_covers_every_read_and_mutation_the_product_needs(): void
    {
        $methods = array_map(fn ($m) => $m->getName(), (new ReflectionClass(EmailProviderConnector::class))->getMethods());

        // Reads.
        foreach ([
            'healthCheck', 'capabilitySet', 'supports', 'getDomainAuthStatus', 'getDnsRequirements',
            'getInventory', 'getMailboxStatus', 'getUsage', 'getCatchAll',
        ] as $expected) {
            $this->assertContains($expected, $methods, "Contract is missing the read '{$expected}'.");
        }

        // Mutations.
        foreach ([
            'onboardDomain', 'verifyDomain', 'createMailbox', 'updateMailbox', 'suspendMailbox',
            'restoreMailbox', 'deleteMailbox', 'requestPasswordReset', 'createAlias', 'deleteAlias',
            'createForwarder', 'deleteForwarder', 'configureCatchAll', 'clearCatchAll',
        ] as $expected) {
            $this->assertContains($expected, $methods, "Contract is missing the mutation '{$expected}'.");
        }
    }

    public function test_every_provider_call_returns_a_provider_result(): void
    {
        // Deliberate exceptions: none of these makes a provider call, so
        // wrapping them in a result type that models failure, retry
        // classification and verification would be dishonest about what they
        // are. `provider()` and `capability()` are inherited identity
        // declarations; `capabilitySet()` and `supports()` are local and free.
        $localDeclarations = ['capabilitySet', 'supports', 'provider', 'capability'];

        foreach ((new ReflectionClass(EmailProviderConnector::class))->getMethods() as $method) {
            if (in_array($method->getName(), $localDeclarations, true)) {
                continue;
            }

            $type = $method->getReturnType();

            $this->assertInstanceOf(ReflectionNamedType::class, $type, "{$method->getName()} declares no return type.");
            $this->assertSame(
                ProviderResult::class,
                $type->getName(),
                "{$method->getName()} must return ProviderResult so callers cannot skip verification."
            );
        }
    }

    public function test_every_mutation_accepts_a_call_context_carrying_the_idempotency_key(): void
    {
        $mutations = [
            'onboardDomain', 'verifyDomain', 'createMailbox', 'updateMailbox', 'suspendMailbox',
            'restoreMailbox', 'deleteMailbox', 'requestPasswordReset', 'createAlias', 'deleteAlias',
            'createForwarder', 'deleteForwarder', 'configureCatchAll', 'clearCatchAll',
        ];

        $reflection = new ReflectionClass(EmailProviderConnector::class);

        foreach ($mutations as $name) {
            $parameters = $reflection->getMethod($name)->getParameters();
            $types = array_map(fn ($p) => $p->getType()?->getName(), $parameters);

            $this->assertContains(
                ProviderCallContext::class,
                $types,
                "{$name} does not take a ProviderCallContext, so it has no idempotency key and cannot be "
                . 'safely retried. Every queue in this platform retries.'
            );
        }
    }

    public function test_no_contract_method_accepts_or_returns_an_untyped_payload_for_a_domain_concept(): void
    {
        // Specs and records are typed objects, not arrays. An array crossing
        // this seam is how a provider-specific key reaches the engine.
        $reflection = new ReflectionClass(EmailProviderConnector::class);

        foreach (['createMailbox' => MailboxSpec::class, 'updateMailbox' => MailboxSpec::class,
                  'createAlias' => AliasSpec::class, 'createForwarder' => ForwarderSpec::class,
                  'configureCatchAll' => CatchAllSpec::class] as $method => $expected) {
            $types = array_map(fn ($p) => $p->getType()?->getName(), $reflection->getMethod($method)->getParameters());

            $this->assertContains($expected, $types, "{$method} must accept a {$expected}, not an array.");
        }
    }

    public function test_the_contract_extends_the_base_infrastructure_contract(): void
    {
        $this->assertTrue(
            is_a(EmailProviderConnector::class, InfrastructureConnector::class, true),
            'Business Email must resolve through the same connector machinery as every other capability.'
        );
    }

    public function test_the_contract_names_no_vendor_concept(): void
    {
        $reflection = new ReflectionClass(EmailProviderConnector::class);
        $names = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        foreach ($names as $name) {
            foreach (['m' . 'igadu', 'z' . 'oho', 'f' . 'astmail', 'workspace365', 'gsuite'] as $fragment) {
                $this->assertStringNotContainsStringIgnoringCase($fragment, $name);
            }
        }
    }

    // ── capability flags ─────────────────────────────────────────────────────

    public function test_capability_flag_names_are_a_closed_set(): void
    {
        $this->assertGreaterThanOrEqual(15, count(EmailProviderCapability::all()));

        foreach (EmailProviderCapability::all() as $capability) {
            $this->assertTrue(EmailProviderCapability::isKnown($capability));
            $this->assertMatchesRegularExpression('/^[a-z_]+\.[a-z_.]+$/', $capability);
        }

        $this->expectException(InvalidArgumentException::class);
        EmailProviderCapability::assertKnown('mailbox.telepathy');
    }

    public function test_a_provider_missing_a_required_capability_is_not_viable(): void
    {
        $full = EmailProviderCapabilitySet::full();
        $this->assertTrue($full->isViable());
        $this->assertSame([], $full->missingRequired());

        // Cannot enumerate: reconciliation would be impossible and drift
        // undetectable, so it cannot deliver the product.
        $blind = $full->withOut(EmailProviderCapability::INVENTORY_LIST);

        $this->assertFalse($blind->isViable());
        $this->assertSame([EmailProviderCapability::INVENTORY_LIST], $blind->missingRequired());
    }

    public function test_optional_capabilities_may_be_absent_without_breaking_viability(): void
    {
        $limited = EmailProviderCapabilitySet::full()->withOut(
            EmailProviderCapability::CATCHALL_CONFIGURE,
            EmailProviderCapability::MAILBOX_RENAME,
            EmailProviderCapability::LAST_LOGIN_READ,
        );

        $this->assertTrue($limited->isViable());
        $this->assertFalse($limited->supports(EmailProviderCapability::CATCHALL_CONFIGURE));
        $this->assertTrue($limited->supports(EmailProviderCapability::MAILBOX_CREATE));
    }

    public function test_a_minimal_provider_supports_only_the_required_set(): void
    {
        $minimal = EmailProviderCapabilitySet::minimal();

        $this->assertTrue($minimal->isViable());

        foreach (EmailProviderCapability::optional() as $optional) {
            $this->assertFalse($minimal->supports($optional), "{$optional} should be absent from a minimal set.");
        }
    }

    public function test_asking_about_an_unknown_capability_throws_rather_than_answering_false(): void
    {
        // A silent false would let a typo permanently disable a feature with no
        // error anywhere.
        $this->expectException(InvalidArgumentException::class);

        EmailProviderCapabilitySet::full()->supports('mailbox.does_not_exist');
    }

    // ── value objects refuse unsafe input ────────────────────────────────────

    public function test_a_call_context_without_an_idempotency_key_is_impossible(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProviderCallContext(1, 'email_mailbox', 5, '   ');
    }

    public function test_a_call_context_without_a_workspace_is_impossible(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ProviderCallContext(0, 'email_mailbox', 5, 'key-1');
    }

    public function test_a_mailbox_spec_refuses_an_unusable_local_part(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailboxSpec('not valid');
    }

    public function test_a_mailbox_spec_refuses_a_zero_quota(): void
    {
        // Zero would be a mailbox that cannot receive mail, which is a
        // suspension, not a quota.
        $this->expectException(InvalidArgumentException::class);

        new MailboxSpec('sales', null, 0);
    }

    public function test_a_mailbox_spec_distinguishes_unchanged_from_cleared(): void
    {
        $untouched = new MailboxSpec('sales');
        $this->assertFalse($untouched->changesDisplayName());
        $this->assertTrue($untouched->isEmptyUpdate());

        $cleared = new MailboxSpec('sales', '');
        $this->assertTrue($cleared->changesDisplayName(), 'An empty string clears; null leaves alone.');
        $this->assertFalse($cleared->isEmptyUpdate());
    }

    public function test_a_mailbox_spec_has_nowhere_to_put_a_credential(): void
    {
        $properties = array_map(
            fn ($p) => $p->getName(),
            (new ReflectionClass(MailboxSpec::class))->getProperties()
        );

        foreach ($properties as $property) {
            $this->assertSame(
                0,
                preg_match('/password|secret|token|credential/i', $property),
                "MailboxSpec::\${$property} could carry a credential across the provider seam."
            );
        }

        // E7 adds `invitation_sent` — a BOOLEAN, deliberately not the address.
        // The assertion above still stands: no key here may carry a credential,
        // and the recipient is personal data that stays out of the audit trail.
        $this->assertSame(
            ['local_part', 'quota_mb', 'display_name_set', 'invitation_sent'],
            array_keys((new MailboxSpec('a', 'b', 5))->toAuditArray())
        );

        $withInvitation = (new MailboxSpec('a', 'b', 5, 'owner@example.test'))->toAuditArray();

        $this->assertTrue($withInvitation['invitation_sent']);
        $this->assertNotContains('owner@example.test', $withInvitation);
    }

    public function test_routing_specs_refuse_an_unroutable_destination(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ForwarderSpec('jobs', 'not-an-address');
    }

    public function test_an_alias_spec_refuses_an_invalid_target(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new AliasSpec('info', 'nowhere');
    }

    public function test_a_catchall_spec_cannot_express_disabled(): void
    {
        // Clearing is a separate contract method with its own capability flag.
        $properties = array_map(
            fn ($p) => $p->getName(),
            (new ReflectionClass(CatchAllSpec::class))->getProperties()
        );

        $this->assertSame(['targetAddress'], $properties);
    }

    // ── DNS records stay neutral ─────────────────────────────────────────────

    public function test_a_dns_record_is_structured_rather_than_prose(): void
    {
        $record = new MailDnsRecord(MailDnsRecord::PURPOSE_MX, 'MX', '@', 'mail.example.test', 10, 3600);

        $this->assertSame(
            ['purpose', 'type', 'name', 'value', 'priority', 'ttl', 'required'],
            array_keys($record->toCustomerArray()),
            'A customer-facing DNS record must be fields, not a provider-authored sentence.'
        );
    }

    public function test_a_dns_record_purpose_is_declared_rather_than_parsed_from_its_value(): void
    {
        // Deciding "this TXT is the SPF one" by string-matching its content
        // would couple us to provider formatting.
        $spf = new MailDnsRecord(MailDnsRecord::PURPOSE_SPF, 'TXT', '@', 'v=spf1 -all');

        $this->assertSame(MailDnsRecord::PURPOSE_SPF, $spf->purpose);
    }

    public function test_a_dns_record_rejects_a_non_standard_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailDnsRecord(MailDnsRecord::PURPOSE_MX, 'VENDORTYPE', '@', 'x');
    }

    public function test_an_mx_record_without_a_priority_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailDnsRecord(MailDnsRecord::PURPOSE_MX, 'MX', '@', 'mail.example.test');
    }

    public function test_dns_records_compare_by_fingerprint(): void
    {
        $a = new MailDnsRecord(MailDnsRecord::PURPOSE_MX, 'MX', '@', 'Mail.Example.Test', 10, 3600);
        $b = new MailDnsRecord(MailDnsRecord::PURPOSE_MX, 'MX', '@.', 'mail.example.test', 10, 60);

        $this->assertTrue($a->matches($b), 'TTL and trailing dots must not make identical records look different.');
    }

    // ── inventory honesty ────────────────────────────────────────────────────

    public function test_a_partial_inventory_is_marked_and_carries_its_reason(): void
    {
        $partial = ProviderInventory::partial('example.test', now(), 'rate limited on page 2');

        $this->assertFalse($partial->complete);
        $this->assertSame('rate limited on page 2', $partial->incompleteReason);
    }

    public function test_an_inventory_detects_a_duplicated_provider_identity(): void
    {
        $inventory = new ProviderInventory('example.test', now(), [
            new RemoteMailbox('ref-1', 'a'),
            new RemoteMailbox('ref-1', 'b'),
        ]);

        $this->assertSame(['ref-1'], $inventory->duplicateProviderRefs());
    }

    public function test_a_catchall_the_provider_cannot_report_is_unknown_not_disabled(): void
    {
        $unknown = RemoteCatchAll::unknown();
        $disabled = RemoteCatchAll::disabled();

        $this->assertFalse($unknown->isKnown(), 'Null means "cannot tell", which is not "off".');
        $this->assertTrue($disabled->isKnown());
        $this->assertFalse((bool) $disabled->enabled);
    }

    public function test_a_usage_sample_keeps_unreported_measures_null(): void
    {
        $sample = new MailboxUsageSample('sales', now(), 100, 1024);

        $this->assertNull($sample->messagesSent, 'An unreported counter must never become zero.');
        $this->assertSame(100, $sample->storageUsedMb);
        $this->assertFalse($sample->isEmpty());
        $this->assertTrue((new MailboxUsageSample('x', now()))->isEmpty());
    }

    public function test_a_usage_sample_refuses_a_negative_measure(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new MailboxUsageSample('sales', now(), -1);
    }

    // ── outcome classification ───────────────────────────────────────────────

    public function test_provider_outcomes_classify_correctly(): void
    {
        $this->assertSame(ProviderOutcome::VERIFIED, ProviderOutcome::classify(ProviderResult::verified('active')));
        $this->assertSame(ProviderOutcome::ACCEPTED, ProviderOutcome::classify(ProviderResult::accepted('provisioning')));
        $this->assertSame(ProviderOutcome::RETRYABLE, ProviderOutcome::classify(
            ProviderResult::failed('provider_unavailable', 'x', 'retryable')
        ));
        $this->assertSame(ProviderOutcome::PERMANENT, ProviderOutcome::classify(
            ProviderResult::failed('provider_rejected', 'x', 'permanent')
        ));
        $this->assertSame(ProviderOutcome::AMBIGUOUS, ProviderOutcome::classify(
            ProviderResult::failed('provider_timeout', 'x', 'manual')
        ));
    }

    public function test_ambiguity_is_never_auto_retryable(): void
    {
        // The rule the whole design rests on: re-issuing a mutation whose
        // outcome is unknown is how a mailbox gets created twice.
        $this->assertFalse(ProviderOutcome::isAutoRetryable(ProviderOutcome::AMBIGUOUS));
        $this->assertTrue(ProviderOutcome::isAutoRetryable(ProviderOutcome::RETRYABLE));
    }

    public function test_accepted_and_ambiguous_are_both_unconfirmed(): void
    {
        $this->assertTrue(ProviderOutcome::isUnconfirmed(ProviderOutcome::ACCEPTED));
        $this->assertTrue(ProviderOutcome::isUnconfirmed(ProviderOutcome::AMBIGUOUS));
        $this->assertFalse(ProviderOutcome::isUnconfirmed(ProviderOutcome::VERIFIED));
    }

    public function test_a_success_with_no_reference_is_treated_as_unbindable(): void
    {
        $noRef = ProviderResult::accepted('provisioning', null);

        $this->assertTrue(ProviderOutcome::isUnbindableSuccess($noRef, referenceRequired: true));
        $this->assertFalse(ProviderOutcome::isUnbindableSuccess($noRef, referenceRequired: false));
    }

    // ── flight plans agree with the state machines ───────────────────────────

    public function test_every_flight_plan_declares_only_legal_transitions(): void
    {
        $machines = [
            Registry::MAILBOX_CREATE => \App\Engines\Infrastructure\Email\States\EmailMailboxState::class,
            Registry::MAILBOX_DELETE => \App\Engines\Infrastructure\Email\States\EmailMailboxState::class,
            Registry::MAILBOX_SUSPEND => \App\Engines\Infrastructure\Email\States\EmailMailboxState::class,
            Registry::MAILBOX_RESTORE => \App\Engines\Infrastructure\Email\States\EmailMailboxState::class,
            Registry::ALIAS_CREATE => \App\Engines\Infrastructure\Email\States\EmailAliasState::class,
            Registry::ALIAS_DELETE => \App\Engines\Infrastructure\Email\States\EmailAliasState::class,
            Registry::FORWARDER_CREATE => \App\Engines\Infrastructure\Email\States\EmailForwarderState::class,
            Registry::FORWARDER_DELETE => \App\Engines\Infrastructure\Email\States\EmailForwarderState::class,
            Registry::CATCHALL_CONFIGURE => \App\Engines\Infrastructure\Email\States\EmailCatchAllState::class,
            Registry::CATCHALL_CLEAR => \App\Engines\Infrastructure\Email\States\EmailCatchAllState::class,
        ];

        foreach ($machines as $capability => $machine) {
            $plan = EmailFlightPlan::for($capability);

            $this->assertNotNull($plan, "{$capability} has no flight plan.");

            foreach (['in_flight', 'success', 'failure', 'ambiguous'] as $slot) {
                if ($plan[$slot] === null) {
                    continue;
                }

                $this->assertContains(
                    $plan[$slot],
                    $machine::states(),
                    "{$capability}.{$slot} names '{$plan[$slot]}', which is not a state of " . class_basename($machine)
                );
            }

            // The in-flight state must be reachable from the machine's start
            // for a create, and the success state from the in-flight one.
            if ($plan['in_flight'] !== null && $plan['success'] !== null) {
                $this->assertTrue(
                    $machine::canTransition($plan['in_flight'], $plan['success']),
                    "{$capability}: '{$plan['in_flight']}' cannot legally reach '{$plan['success']}'."
                );
            }
        }
    }

    public function test_only_existence_uncertainty_moves_the_subject_to_reconciling(): void
    {
        // Create and delete put the object's existence in doubt; suspend and
        // restore do not. Moving a mailbox to `reconciling` because a suspend
        // timed out would tell a customer we are unsure the mailbox is real.
        foreach ([Registry::MAILBOX_CREATE, Registry::MAILBOX_DELETE, Registry::ALIAS_CREATE,
                  Registry::ALIAS_DELETE, Registry::FORWARDER_CREATE, Registry::FORWARDER_DELETE] as $capability) {
            $this->assertNotNull(
                EmailFlightPlan::for($capability)['ambiguous'],
                "{$capability} puts an object's existence in doubt and needs a reconciling state."
            );
        }

        foreach ([Registry::MAILBOX_SUSPEND, Registry::MAILBOX_RESTORE, Registry::MAILBOX_UPDATE,
                  Registry::PASSWORD_RESET, Registry::USAGE_SYNC] as $capability) {
            $this->assertNull(
                EmailFlightPlan::for($capability)['ambiguous'],
                "{$capability} does not put existence in doubt; the uncertainty belongs on the operation."
            );
        }
    }

    public function test_only_creates_bind_a_provider_reference(): void
    {
        $this->assertSame(
            [
                Registry::MAILBOX_CREATE,
                Registry::ALIAS_CREATE,
                Registry::FORWARDER_CREATE,
                Registry::CATCHALL_CONFIGURE,
            ],
            EmailFlightPlan::binding()
        );
    }
}
