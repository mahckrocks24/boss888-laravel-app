<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\Contracts\EmailProviderConnector;
use App\Connectors\Infrastructure\Contracts\InfrastructureConnector;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use ReflectionClass;
use Tests\TestCase;

/**
 * INFRA888 · E1-G — CAPABILITY REGISTRATION PROOF.
 *
 * The registry is the single declaration of what Business Email can be asked to
 * do. These tests assert that the declaration is COMPLETE (every capability
 * states every governance fact), CONSISTENT (its vocabulary is closed), and
 * HONEST (a declared connector method actually exists on the contract, and a
 * missing one is declared as a gap rather than invented).
 */
class BusinessEmailCapabilityRegistryTest extends TestCase
{
    /**
     * The capabilities E1 was required to register, plus the one E2 added.
     *
     * `email.catchall.clear` is an E2 addition. E1 modelled clearing a
     * catch-all as configuring it with no target; E2 split them because a spec
     * that means either "deliver everything here" or "deliver nothing anywhere"
     * depending on one nullable field is a routing accident waiting to happen.
     */
    private const REQUIRED = [
        'email.domain.onboard',
        'email.domain.verify',
        'email.mailbox.create',
        'email.mailbox.update',
        'email.mailbox.suspend',
        'email.mailbox.restore',
        'email.mailbox.delete',
        'email.password.reset',
        'email.alias.create',
        'email.alias.delete',
        'email.forwarder.create',
        'email.forwarder.delete',
        'email.catchall.configure',
        'email.catchall.clear',
        'email.usage.sync',
        'email.health.observe',
    ];

    public function test_every_required_capability_is_registered(): void
    {
        foreach (self::REQUIRED as $capability) {
            $this->assertTrue(Registry::has($capability), "Capability not registered: {$capability}");
        }

        $this->assertCount(count(self::REQUIRED), Registry::slugs(), 'Unexpected extra capability registered.');
    }

    public function test_every_capability_declares_every_governance_fact(): void
    {
        foreach (Registry::capabilities() as $slug => $meta) {
            foreach (Registry::requiredMetadataKeys() as $key) {
                $this->assertArrayHasKey($key, $meta, "{$slug} does not declare '{$key}'");
            }

            $this->assertSame($slug, $meta['capability'], "{$slug}: the key and the declared capability disagree");
            $this->assertNotSame('', trim((string) $meta['summary']), "{$slug} has no summary");
        }
    }

    public function test_the_governance_vocabulary_is_closed(): void
    {
        foreach (Registry::capabilities() as $slug => $meta) {
            $this->assertContains($meta['risk'], Registry::risks(), "{$slug}: unknown risk");
            $this->assertContains($meta['approval_mode'], Registry::approvalModes(), "{$slug}: unknown approval mode");
            $this->assertContains($meta['cost_class'], Registry::costClasses(), "{$slug}: unknown cost class");
            $this->assertContains($meta['idempotency'], Registry::idempotencyStrategies(), "{$slug}: unknown idempotency strategy");

            $this->assertIsBool($meta['reversible']);
            $this->assertIsBool($meta['customer_available']);
            $this->assertIsBool($meta['admin_available']);
            $this->assertIsBool($meta['requires_provider']);
            $this->assertIsBool($meta['provider_mutation']);
            $this->assertIsBool($meta['contract_gap']);
        }
    }

    public function test_every_capability_routes_through_the_generic_email_capability(): void
    {
        foreach (Registry::capabilities() as $slug => $meta) {
            $this->assertSame(
                InfraProviderConnection::CAPABILITY_EMAIL,
                $meta['connector_capability'],
                "{$slug} must resolve through the generic 'email' capability, never a vendor-specific one."
            );

            $this->assertSame(EmailProviderConnector::class, $meta['connector_contract'], "{$slug}: wrong contract");
        }
    }

    // ── honesty about the contract ───────────────────────────────────────────

    public function test_every_declared_connector_method_actually_exists_on_the_contract(): void
    {
        $reflection = new ReflectionClass(EmailProviderConnector::class);

        $available = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        // Inherited from the base infrastructure contract.
        foreach ((new ReflectionClass(InfrastructureConnector::class))->getMethods() as $method) {
            $available[] = $method->getName();
        }

        foreach (Registry::capabilities() as $slug => $meta) {
            if ($meta['connector_method'] === null) {
                continue;
            }

            $this->assertContains(
                $meta['connector_method'],
                $available,
                "{$slug} declares connector method '{$meta['connector_method']}', which the contract does not have. "
                . 'A registry that names methods the contract lacks is exactly the drift INFRA888 exists to prevent.'
            );
        }
    }

    public function test_a_missing_contract_method_is_declared_as_a_gap_rather_than_invented(): void
    {
        foreach (Registry::capabilities() as $slug => $meta) {
            if ($meta['connector_method'] === null) {
                $this->assertTrue(
                    $meta['contract_gap'],
                    "{$slug} has no connector method but does not declare contract_gap. Silence here is how a "
                    . 'capability ends up registered and permanently unimplementable.'
                );

                continue;
            }

            $this->assertFalse(
                $meta['contract_gap'],
                "{$slug} declares both a connector method and a contract gap."
            );
        }
    }

    public function test_the_e2_worklist_is_closed(): void
    {
        // E1 shipped with five capabilities whose contract method did not
        // exist. They were declared as gaps rather than invented, and that list
        // WAS the E2 worklist:
        //
        //   email.mailbox.restore, email.alias.delete, email.forwarder.delete,
        //   email.catchall.configure, email.usage.sync
        //
        // E2 closed all five. This assertion is now the standing guarantee that
        // a capability can never again be registered with no way to perform it —
        // which is how an operation ends up permanently unimplementable while
        // appearing available.
        $this->assertSame(
            [],
            Registry::contractGaps(),
            'A capability is registered whose contract method does not exist. Either add the method to '
            . 'EmailProviderConnector, or do not register the capability.'
        );
    }

    public function test_every_capability_declares_the_provider_flag_it_needs(): void
    {
        // E2: the engine checks this flag BEFORE opening a governed operation,
        // so an action the provider cannot perform is refused as unsupported
        // rather than attempted and reported as a provider failure.
        foreach (Registry::capabilities() as $slug => $meta) {
            $flag = $meta['provider_capability'];

            if ($flag === null) {
                // Permitted only where the method is on the BASE infrastructure
                // contract and therefore exists on every connector.
                $this->assertSame(
                    'healthCheck',
                    $meta['connector_method'],
                    "{$slug} declares no provider capability flag but is not a base-contract method."
                );

                continue;
            }

            $this->assertTrue(
                \App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability::isKnown($flag),
                "{$slug} names an undeclared provider capability '{$flag}'."
            );
        }
    }

    // ── governance is proportionate ──────────────────────────────────────────

    public function test_deleting_a_mailbox_is_the_most_governed_operation(): void
    {
        $delete = Registry::get(Registry::MAILBOX_DELETE);

        $this->assertSame(Registry::RISK_IRREVERSIBLE, $delete['risk']);
        $this->assertFalse($delete['reversible']);
        $this->assertSame(Registry::APPROVAL_SEPARATION_OF_DUTIES, $delete['approval_mode']);
        $this->assertContains(Registry::MAILBOX_DELETE, Registry::requiringSeparationOfDuties());
    }

    public function test_every_irreversible_capability_is_approval_gated(): void
    {
        foreach (Registry::capabilities() as $slug => $meta) {
            if ($meta['risk'] !== Registry::RISK_IRREVERSIBLE && $meta['reversible'] !== false) {
                continue;
            }

            $this->assertNotSame(
                Registry::APPROVAL_AUTOMATIC,
                $meta['approval_mode'],
                "{$slug} cannot be undone and must not run on the actor's own authority."
            );
        }
    }

    public function test_every_high_risk_capability_requires_approval(): void
    {
        foreach (Registry::capabilities() as $slug => $meta) {
            if (! in_array($meta['risk'], [Registry::RISK_HIGH, Registry::RISK_IRREVERSIBLE], true)) {
                continue;
            }

            $this->assertContains($slug, Registry::requiringApproval(), "{$slug} is high risk but ungated.");
        }
    }

    public function test_every_mutating_capability_has_a_real_idempotency_strategy(): void
    {
        foreach (Registry::capabilities() as $slug => $meta) {
            if ($meta['provider_mutation'] !== true) {
                continue;
            }

            $this->assertNotSame(
                Registry::IDEMPOTENCY_READ_ONLY,
                $meta['idempotency'],
                "{$slug} mutates a provider and cannot be classed read-only — a retried job would act twice."
            );
        }
    }

    public function test_read_only_capabilities_that_write_rows_still_carry_a_key(): void
    {
        // Usage sync reads the provider but WRITES email_usage rows. Treating
        // it as read-only because it does not mutate the provider is how a
        // retried job duplicates a measurement.
        $sync = Registry::get(Registry::USAGE_SYNC);

        $this->assertFalse($sync['provider_mutation']);
        $this->assertSame(Registry::IDEMPOTENCY_CALLER_KEY, $sync['idempotency']);
    }

    public function test_every_capability_emits_at_least_one_event(): void
    {
        foreach (Registry::capabilities() as $slug => $meta) {
            $this->assertNotEmpty($meta['events'], "{$slug} emits nothing and would be invisible in the audit trail.");

            foreach ($meta['events'] as $event) {
                $this->assertMatchesRegularExpression(
                    '/^email\.[a-z_]+\.[a-z_]+$/',
                    $event,
                    "{$slug} declares a malformed event name '{$event}'"
                );
            }
        }
    }

    public function test_event_names_are_unique_across_capabilities(): void
    {
        $seen = [];

        foreach (Registry::capabilities() as $slug => $meta) {
            foreach ($meta['events'] as $event) {
                $this->assertArrayNotHasKey(
                    $event,
                    $seen,
                    "'{$event}' is emitted by more than one capability — {$slug} duplicates it. An ambiguous "
                    . 'event cannot be traced back to what caused it.'
                );

                $seen[$event] = $slug;
            }
        }

        $this->assertCount(count($seen), Registry::events());
    }

    public function test_every_capability_requires_an_entitlement(): void
    {
        foreach (Registry::capabilities() as $slug => $meta) {
            $this->assertNotSame('', (string) $meta['entitlement'], "{$slug} requires no entitlement.");

            $this->assertContains(
                $meta['entitlement'],
                [Registry::ENTITLEMENT_ACCESS, Registry::ENTITLEMENT_MAILBOX_LIMIT, Registry::ENTITLEMENT_DOMAIN_LIMIT],
                "{$slug} names an undeclared entitlement key."
            );
        }
    }

    public function test_password_reset_is_marked_never_to_log_its_result(): void
    {
        $reset = Registry::get(Registry::PASSWORD_RESET);

        $this->assertTrue(
            $reset['never_log_result'] ?? false,
            'The password reset result must be marked so no operation record, event payload or log line can '
            . 'ever carry credential material.'
        );
    }

    public function test_forwarder_creation_requires_a_loop_check(): void
    {
        $this->assertTrue(Registry::get(Registry::FORWARDER_CREATE)['requires_loop_check'] ?? false);
    }

    public function test_no_capability_is_customer_facing_without_being_admin_visible(): void
    {
        // An action a customer can take that an operator cannot see or perform
        // is an operator dead end the moment it goes wrong.
        foreach (Registry::capabilities() as $slug => $meta) {
            if ($meta['customer_available'] !== true) {
                continue;
            }

            $this->assertTrue($meta['admin_available'], "{$slug} is customer-facing but invisible to operators.");
        }
    }

    public function test_this_registry_is_separate_from_the_executable_operation_registry(): void
    {
        // Business Email has no handlers yet. Merging these entries into the
        // registry of operations INFRA888 can execute would make it claim
        // fifteen working operations that fail at the first call.
        $executable = \App\Engines\Infrastructure\Registry\InfrastructureCapabilityRegistry::actions();

        foreach (Registry::slugs() as $slug) {
            $this->assertNotContains(
                $slug,
                $executable,
                "{$slug} appears in the executable operation registry but has no handler."
            );
        }
    }
}
