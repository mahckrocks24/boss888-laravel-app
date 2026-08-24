<?php

namespace Tests\Feature\Namecheap;

use App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector;
use App\Services\Domains\DomainContactResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Cover for the 2026-07-29 purchase-validation failure:
 * CONTACT_INCOMPLETE -- every registrant field was empty, because the validator
 * read namecheap.default_contact, whose NAMECHEAP_CONTACT_* env vars are unset.
 *
 * The fix introduces a gated sandbox identity. These tests exist mainly to
 * prove that gate cannot be crossed in production.
 */
class NamecheapContactTest extends TestCase
{
    private const VALID = [
        'first_name'  => 'LevelUp',
        'last_name'   => 'Sandbox',
        'address1'    => '123 Test Street',
        'city'        => 'New York',
        'state'       => 'NY',
        'postal_code' => '10001',
        'country'     => 'US',
        'phone'       => '+1.2125550100',
        'email'       => 'sandbox-domain-test@levelupgrowth.io',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'namecheap.environment' => 'sandbox',
            'namecheap.client_ip'   => '134.209.93.41',
            'namecheap.endpoints.sandbox'    => 'https://api.sandbox.namecheap.com/xml.response',
            'namecheap.endpoints.production' => 'https://api.namecheap.com/xml.response',
            'namecheap.sandbox_test_contact' => self::VALID,
            'namecheap.credentials.sandbox'  => [
                'api_user' => 'testuser',
                'api_key'  => 'test-key-not-a-real-credential',
                'username' => 'testuser',
            ],
        ]);
    }

    // ------------------------------------------------ local validation ----

    public function test_missing_contact_fails_locally_with_no_api_call(): void
    {
        Http::fake();

        $r = NamecheapRegistrarConnector::make()->registerDomain('example-test.com', 1, [], 'idem-1');

        $this->assertFalse($r->success);
        $this->assertSame('CONTACT_INCOMPLETE', $r->errorCode);

        // The point of validating locally is that nothing billable is attempted.
        Http::assertNothingSent();
    }

    public function test_partially_missing_contact_names_every_absent_field(): void
    {
        $partial = self::VALID;
        unset($partial['city'], $partial['postal_code']);

        $problems = DomainContactResolver::problems($partial);

        $this->assertNotEmpty($problems);
        $this->assertStringContainsString('city', $problems[0]);
        $this->assertStringContainsString('postal_code', $problems[0]);
    }

    public function test_a_complete_valid_contact_has_no_problems(): void
    {
        $this->assertSame([], DomainContactResolver::problems(self::VALID));
        $this->assertTrue(DomainContactResolver::isValid(self::VALID));
    }

    /** @dataProvider malformedContacts */
    public function test_malformed_contact_data_is_rejected(string $field, string $value, string $expect): void
    {
        $c = self::VALID;
        $c[$field] = $value;

        $problems = DomainContactResolver::problems($c);

        $this->assertNotEmpty($problems, "{$field}='{$value}' should have been rejected");
        $this->assertStringContainsString($expect, implode(' | ', $problems));
    }

    public static function malformedContacts(): array
    {
        return [
            'email without @'        => ['email', 'not-an-email', 'email'],
            'email without domain'   => ['email', 'user@', 'email'],
            'phone national only'    => ['phone', '2125550100', 'phone'],
            'phone missing dot'      => ['phone', '+12125550100', 'phone'],
            'phone with letters'     => ['phone', '+1.212555ABCD', 'phone'],
            'country full name'      => ['country', 'United States', 'country'],
            'country three letters'  => ['country', 'USA', 'country'],
            'country numeric'        => ['country', '01', 'country'],
            'postal with symbols'    => ['postal_code', '100@1!', 'postal_code'],
            'postal too long'        => ['postal_code', '1234567890123', 'postal_code'],
            'first name with digits' => ['first_name', 'LevelUp3', 'first_name'],
            'city with digits'       => ['city', 'New York 2', 'city'],
            'address too short'      => ['address1', 'ab', 'address1'],
        ];
    }

    public function test_malformed_contact_reaches_the_connector_as_contact_invalid(): void
    {
        Http::fake();

        $c = self::VALID;
        $c['phone'] = '2125550100';   // present, but wrong format

        $r = NamecheapRegistrarConnector::make()->registerDomain('example-test.com', 1, $c, 'idem-2');

        $this->assertFalse($r->success);
        $this->assertSame('CONTACT_INVALID', $r->errorCode, 'A malformed field is invalid, not merely incomplete');
        Http::assertNothingSent();
    }

    // ------------------------------------------------ sandbox gating ------

    public function test_sandbox_contact_is_available_in_sandbox(): void
    {
        $c = DomainContactResolver::sandboxTestContact(true);

        $this->assertIsArray($c);
        $this->assertSame('LevelUp', $c['first_name']);
        $this->assertSame('US', $c['country']);
        $this->assertTrue(DomainContactResolver::isValid($c));
        $this->assertTrue(DomainContactResolver::sandboxContactPermitted());
    }

    public function test_sandbox_contact_requires_an_explicit_request(): void
    {
        // A default-valued call site must never receive it by accident.
        $this->assertNull(DomainContactResolver::sandboxTestContact());
        $this->assertNull(DomainContactResolver::sandboxTestContact(false));
    }

    // ---------------------------------------------- PRODUCTION ISOLATION --

    public function test_sandbox_contact_is_refused_in_production(): void
    {
        config(['namecheap.environment' => 'production']);

        $this->assertNull(
            DomainContactResolver::sandboxTestContact(true),
            'The sandbox identity must be unreachable in production'
        );
        $this->assertFalse(DomainContactResolver::sandboxContactPermitted());
    }

    public function test_sandbox_contact_is_refused_when_endpoint_is_not_sandbox(): void
    {
        // Environment claims sandbox, but the endpoint has been pointed at the
        // real API. Both locks must hold independently.
        config(['namecheap.endpoints.sandbox' => 'https://api.namecheap.com/xml.response']);

        $this->assertNull(DomainContactResolver::sandboxTestContact(true));
    }

    public function test_incomplete_sandbox_config_is_refused_rather_than_half_used(): void
    {
        $broken = self::VALID;
        unset($broken['email']);
        config(['namecheap.sandbox_test_contact' => $broken]);

        $this->assertNull(
            DomainContactResolver::sandboxTestContact(true),
            'A half-populated identity must be refused outright'
        );
    }

    public function test_production_registration_still_requires_supplied_contact(): void
    {
        config([
            'namecheap.environment' => 'production',
            'namecheap.production_purchases_enabled' => true,
            'namecheap.credentials.production' => [
                'api_user' => 'produser', 'api_key' => 'prod-key-fake', 'username' => 'produser',
            ],
        ]);
        Http::fake();

        // No contact supplied -- production must NOT fall back to sandbox data.
        $r = NamecheapRegistrarConnector::make('production')->registerDomain('example-test.com', 1, [], 'idem-3');

        $this->assertFalse($r->success);
        $this->assertSame('CONTACT_INCOMPLETE', $r->errorCode);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------- log hygiene --

    public function test_contact_redaction_keeps_personal_data_out_of_logs(): void
    {
        $redacted = DomainContactResolver::redact(self::VALID);
        $blob = json_encode($redacted);

        foreach (['LevelUp', 'Sandbox', '123 Test Street', 'New York', '10001', '+1.2125550100', 'sandbox-domain-test@levelupgrowth.io'] as $personal) {
            $this->assertStringNotContainsString($personal, $blob, "'{$personal}' must not survive redaction");
        }

        // The shape is retained so an operator can still diagnose a problem.
        $this->assertTrue($redacted['complete']);
        $this->assertTrue($redacted['valid']);
        $this->assertSame('US', $redacted['country']);
        $this->assertTrue($redacted['fields_present']['email']);
    }

    public function test_contact_data_is_not_written_to_the_log_on_a_failed_registration(): void
    {
        Http::fake();

        $written = [];
        Log::listen(function ($message) use (&$written) {
            $written[] = json_encode([$message->message, $message->context]);
        });

        NamecheapRegistrarConnector::make()->registerDomain('example-test.com', 1, self::VALID, 'idem-4');

        $blob = implode("\n", $written);

        foreach (['123 Test Street', '+1.2125550100', 'sandbox-domain-test@levelupgrowth.io'] as $personal) {
            $this->assertStringNotContainsString($personal, $blob);
        }
    }

    public function test_api_key_is_never_written_to_the_log(): void
    {
        Http::fake();

        $written = [];
        Log::listen(function ($message) use (&$written) {
            $written[] = json_encode([$message->message, $message->context]);
        });

        NamecheapRegistrarConnector::make()->registerDomain('example-test.com', 1, self::VALID, 'idem-5');

        $this->assertStringNotContainsString(
            'test-key-not-a-real-credential',
            implode("\n", $written),
            'The API key must never reach a log record'
        );
    }
}
