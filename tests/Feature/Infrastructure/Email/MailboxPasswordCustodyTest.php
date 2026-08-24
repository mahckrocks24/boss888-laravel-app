<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability as Cap;
use App\Connectors\Infrastructure\BusinessEmail\SecretString;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Connectors\Infrastructure\Email\Migadu\MigaduCapabilityMap as CapMap;
use App\Connectors\Infrastructure\Email\Migadu\MigaduClient;
use App\Connectors\Infrastructure\Email\Migadu\MigaduEmailProviderConnector;
use App\Connectors\Infrastructure\Email\Migadu\MigaduRequestFactory as Req;
use App\Engines\Infrastructure\Email\Onboarding\MailboxSetupToken;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use LogicException;
use Tests\TestCase;

/**
 * INFRA888 · E7.3 — the password custody rule, enforced by tests.
 *
 * THE RULE
 * LevelUp may generate one undisclosed bootstrap credential and relay a
 * customer-chosen password once, in memory. It may never display, persist,
 * log, queue, cache, audit or recover either one.
 *
 * A redaction policy is not enough — it only helps if it runs before the value
 * reaches the subsystem. So these tests assert STRUCTURAL non-observability:
 * the secret refuses to become a string, refuses to serialise, and cannot be
 * read twice.
 */
class MailboxPasswordCustodyTest extends TestCase
{
    private const DOMAIN = 'levelupgrowth.io';
    private const ROOT = '/var/www/levelup-staging';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    // ═══ THE SECRET ITSELF ═══════════════════════════════════════════════════

    public function test_a_secret_refuses_to_become_a_string(): void
    {
        // The single most common leak: string interpolation into a log line.
        $this->expectException(LogicException::class);

        $secret = SecretString::bootstrap();

        (string) $secret;
    }

    public function test_a_secret_refuses_to_serialize(): void
    {
        // Which is what stops it entering a queue, a cache, or a session.
        $this->expectException(LogicException::class);

        serialize(SecretString::bootstrap());
    }

    public function test_a_secret_json_encodes_to_a_placeholder(): void
    {
        $encoded = json_encode(['password' => SecretString::bootstrap()]);

        $this->assertSame('{"password":"[redacted]"}', $encoded);
    }

    public function test_a_secret_shows_nothing_to_a_debugger(): void
    {
        // var_dump, dd, and every exception reporter that walks properties —
        // Sentry included, which is installed on this platform.
        $debug = SecretString::bootstrap()->__debugInfo();

        $this->assertSame('[redacted]', $debug['value']);
    }

    public function test_a_secret_can_be_read_exactly_once(): void
    {
        $secret = SecretString::fromInput('correct horse battery staple');

        $this->assertSame('correct horse battery staple', $secret->reveal());
        $this->assertTrue($secret->isSpent());

        // A second read would mean a retry could resend a password.
        $this->expectException(LogicException::class);
        $secret->reveal();
    }

    public function test_a_secret_refuses_to_be_cloned(): void
    {
        $this->expectException(LogicException::class);

        $secret = SecretString::bootstrap();
        clone $secret;
    }

    public function test_a_bootstrap_credential_has_real_entropy(): void
    {
        $seen = [];

        for ($i = 0; $i < 200; $i++) {
            $seen[] = SecretString::bootstrap()->reveal();
        }

        $this->assertCount(200, array_unique($seen), 'Bootstrap credentials repeated.');

        foreach ($seen as $value) {
            // 32 random bytes, base64, plus a 4-character complexity prefix.
            $this->assertGreaterThanOrEqual(40, strlen($value));
            $this->assertMatchesRegularExpression('/[A-Z]/', $value);
            $this->assertMatchesRegularExpression('/[a-z]/', $value);
            $this->assertMatchesRegularExpression('/[0-9]/', $value);
        }
    }

    // ═══ THE PROVISIONING PAYLOAD ════════════════════════════════════════════

    public function test_provisioning_without_a_password_sends_none(): void
    {
        // E7.4. This test once asserted a bootstrap WAS sent. Live evidence then
        // showed that a mailbox created with a bootstrap and updated by PUT does
        // not reliably accept the new password, so the mailbox is now created at
        // the moment the customer supplies one — and LevelUp generates nothing.
        $payload = Req::createMailbox(new MailboxSpec('newhire', 'New Hire'));

        $this->assertArrayNotHasKey('password', $payload,
            'LevelUp must never generate a mailbox credential.');

        $this->assertArrayNotHasKey('password_method', $payload,
            'Invitation mode would make the PROVIDER email the customer.');

        // Nowhere to send anything, which is what keeps the provider silent.
        $this->assertSame('', $payload['password_recovery_email']);
    }

    public function test_provisioning_carries_the_customer_password_when_supplied(): void
    {
        $payload = Req::createMailbox(new MailboxSpec('newhire', 'New Hire'), 'chosen-by-the-customer');

        $this->assertSame('chosen-by-the-customer', $payload['password']);
        $this->assertArrayNotHasKey('password_method', $payload);
    }

    public function test_the_bootstrap_credential_never_reaches_the_spec_or_its_audit_record(): void
    {
        $spec = new MailboxSpec('newhire', 'New Hire');

        // The spec is what gets persisted into operation records. It has no
        // password field at all, which is why the bootstrap is generated in the
        // request factory and never travels upward.
        $this->assertArrayNotHasKey('password', $spec->toArray());
        $this->assertArrayNotHasKey('password', $spec->toAuditArray());

        foreach (array_keys($spec->toArray()) as $key) {
            $this->assertStringNotContainsStringIgnoringCase('password', $key);
        }
    }

    // ═══ THE RELAY ═══════════════════════════════════════════════════════════

    public function test_the_relay_sends_the_password_and_returns_nothing_about_it(): void
    {
        Http::fake(['*/mailboxes/newhire' => Http::response([], 200)]);

        $result = $this->connector(mutations: true)->setMailboxPassword(
            self::DOMAIN . '/newhire',
            SecretString::fromInput('a-customer-chosen-password'),
            $this->callContext()
        );

        $this->assertTrue($result->success);
        $this->assertFalse($result->verified, 'Only a read-back may say the mailbox works.');
        $this->assertSame('activating', $result->normalizedState);

        // The result is persisted into infra_operations. Nothing about the
        // password may be in it — not the value, not a hash, not a length.
        $encoded = json_encode($result->toArray());

        $this->assertStringNotContainsString('a-customer-chosen-password', $encoded);
        $this->assertStringNotContainsString('password_hash', $encoded);
        $this->assertStringNotContainsString('password_length', $encoded);
        $this->assertTrue($result->data['password_relayed']);
    }

    public function test_the_relay_actually_transmits_the_chosen_password(): void
    {
        Http::fake(['*/mailboxes/newhire' => Http::response([], 200)]);

        $this->connector(mutations: true)->setMailboxPassword(
            self::DOMAIN . '/newhire',
            SecretString::fromInput('a-customer-chosen-password'),
            $this->callContext()
        );

        Http::assertSent(function ($request) {
            // It must genuinely arrive — a test that only proved absence would
            // pass on a relay that silently did nothing.
            $this->assertSame(['password' => 'a-customer-chosen-password'], $request->data());

            return true;
        });
    }

    public function test_a_relayed_secret_cannot_be_replayed(): void
    {
        Http::fake(['*/mailboxes/newhire' => Http::response([], 200)]);

        $secret = SecretString::fromInput('one-shot');
        $connector = $this->connector(mutations: true);

        $connector->setMailboxPassword(self::DOMAIN . '/newhire', $secret, $this->callContext());

        // The second attempt throws inside the adapter and is translated into a
        // neutral failure — it does not resend the password.
        $second = $connector->setMailboxPassword(self::DOMAIN . '/newhire', $secret, $this->callContext());

        $this->assertFalse($second->success);
        Http::assertSentCount(1);
    }

    public function test_the_relay_is_refused_while_mutations_are_disabled(): void
    {
        Http::fake(['*' => Http::response([], 200)]);

        $result = $this->connector(mutations: false)->setMailboxPassword(
            self::DOMAIN . '/newhire',
            SecretString::fromInput('nope'),
            $this->callContext()
        );

        $this->assertFalse($result->success);
        Http::assertNothingSent();
    }

    // ═══ NON-PERSISTENCE, STRUCTURALLY ═══════════════════════════════════════

    public function test_no_source_file_writes_a_password_to_a_persistence_surface(): void
    {
        $sources = [
            'app/Engines/Infrastructure/Email/Onboarding/MailboxSetupService.php',
            'app/Engines/Infrastructure/Email/Onboarding/MailboxSetupToken.php',
            'app/Http/Controllers/MailboxSetupController.php',
            'app/Connectors/Infrastructure/Email/Migadu/MigaduEmailProviderConnector.php',
            'app/Connectors/Infrastructure/Email/Migadu/MigaduRequestFactory.php',
        ];

        // Every way a value reaches somewhere it can be read later.
        $forbidden = [
            'queue/job'   => '/dispatch\s*\(|->onQueue\s*\(|ShouldQueue/',
            'cache'       => '/Cache::(put|forever|add|remember)/',
            'session'     => '/session\s*\(\s*\[|->session\(\)->put/',
            'cookie'      => '/Cookie::(make|queue)/',
            'log-with-pw' => '/Log::[a-z]+\([^)]*\$password/i',
        ];

        foreach ($sources as $path) {
            $source = (string) file_get_contents(self::ROOT . '/' . $path);

            foreach ($forbidden as $label => $pattern) {
                $this->assertSame(0, preg_match($pattern, $source),
                    "{$path} reaches a persistence surface ({$label}) on the password path.");
            }
        }
    }

    public function test_the_token_table_has_no_column_that_could_hold_a_password(): void
    {
        $columns = \Illuminate\Support\Facades\Schema::getColumnListing('email_mailbox_setup_tokens');

        $this->assertNotEmpty($columns);

        foreach ($columns as $column) {
            $this->assertStringNotContainsStringIgnoringCase('password', $column,
                "email_mailbox_setup_tokens.{$column} could hold a mailbox password.");
            $this->assertStringNotContainsStringIgnoringCase('secret', $column);
        }
    }

    public function test_the_migration_stores_a_hash_and_never_a_raw_token(): void
    {
        $migration = (string) file_get_contents(
            self::ROOT . '/database/migrations/2026_08_07_090001_create_email_mailbox_setup_tokens_table.php'
        );

        $this->assertStringContainsString('token_hash', $migration);
        $this->assertSame(0, preg_match('/[\'"]token[\'"]\s*[,)]/', $migration),
            'A raw token column would make a database disclosure replayable.');
    }

    public function test_a_token_record_never_serialises_its_hash(): void
    {
        $token = new MailboxSetupToken(['token_hash' => str_repeat('a', 64)]);

        $this->assertArrayNotHasKey('token_hash', $token->toArray());
        $this->assertArrayNotHasKey('token_hash', $token->toSafeArray());
    }

    // ═══ CAPABILITY ══════════════════════════════════════════════════════════

    public function test_the_password_set_capability_is_declared_and_supported(): void
    {
        $this->assertContains(Cap::MAILBOX_PASSWORD_SET, Cap::all());
        $this->assertTrue(CapMap::supports(Cap::MAILBOX_PASSWORD_SET));

        $this->assertStringContainsStringIgnoringCase('2026-08-07',
            CapMap::evidenceFor(Cap::MAILBOX_PASSWORD_SET),
            'The verdict must carry the date it was proven live.');
    }

    public function test_provider_driven_password_reset_remains_unsupported(): void
    {
        // The provider's own reset is vendor-branded. LevelUp reuses its own
        // relay flow instead, so this must stay off.
        $this->assertFalse(CapMap::supports(Cap::MAILBOX_PASSWORD_RESET));
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
            workspaceId: 990730,
            ownerType: 'email_mailbox',
            ownerId: null,
            idempotencyKey: 'e73-' . bin2hex(random_bytes(4)),
        );
    }
}
