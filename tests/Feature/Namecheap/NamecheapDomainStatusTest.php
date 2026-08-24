<?php

namespace Tests\Feature\Namecheap;

use App\Connectors\Infrastructure\Namecheap\NamecheapRegistrarConnector;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression cover for the 2026-07-29 sandbox validation failure:
 * "Status of a domain we do not own is answered, not errored" -> state=failed.
 *
 * Namecheap answers domains.getInfo for a domain outside the account with
 * Status="ERROR", Error Number 2030166 "Domain is invalid". The message is
 * misleading -- google.com is plainly a valid domain -- and 2030166 was neither
 * in the adapter's not-owned list nor classified as permanent. The result was a
 * routine "we don't own this" surfacing as an adapter failure, and being marked
 * retryable so callers would retry a condition that can never change.
 *
 * Every XML fixture below is the VERBATIM response captured from the live
 * Namecheap sandbox on 2026-07-29.
 */
class NamecheapDomainStatusTest extends TestCase
{
    /** Verbatim sandbox response for a domain not in the account. */
    private const NOT_OWNED_XML = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response">
  <Errors>
    <Error Number="2030166">Domain is invalid</Error>
  </Errors>
  <Warnings />
  <RequestedCommand>namecheap.domains.getinfo</RequestedCommand>
  <CommandResponse Type="namecheap.domains.getInfo">
    <DomainGetInfoResult ID="0" IsOwner="false" IsPremium="false">
      <DomainDetails>
        <NumYears>0</NumYears>
      </DomainDetails>
      <LockDetails />
      <Whoisguard>
        <ID>0</ID>
      </Whoisguard>
      <PremiumDnsSubscription>
        <UseAutoRenew>false</UseAutoRenew>
        <SubscriptionId>-1</SubscriptionId>
        <CreatedDate>0001-01-01T00:00:00</CreatedDate>
        <ExpirationDate>0001-01-01T00:00:00</ExpirationDate>
        <IsActive>false</IsActive>
      </PremiumDnsSubscription>
      <DnsDetails IsUsingOurDNS="false" HostCount="0" DynamicDNSStatus="false" IsFailover="false" />
      <Modificationrights />
    </DomainGetInfoResult>
  </CommandResponse>
  <Server>Server -151154064</Server>
  <GMTTimeDifference>--4:00</GMTTimeDifference>
  <ExecutionTime>0.014</ExecutionTime>
</ApiResponse>
XML;

    /** Verbatim sandbox response for an invalid API key. Must stay an ERROR. */
    private const BAD_KEY_XML = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response">
  <Errors>
    <Error Number="1011102">API Key is invalid or API access has not been enabled</Error>
  </Errors>
  <Warnings />
  <RequestedCommand />
  <Server>Server -151154064</Server>
  <GMTTimeDifference>--4:00</GMTTimeDifference>
  <ExecutionTime>0</ExecutionTime>
</ApiResponse>
XML;

    /** An un-whitelisted IP. Must stay an ERROR. */
    private const IP_BLOCKED_XML = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response">
  <Errors>
    <Error Number="1011150">Invalid request IP</Error>
  </Errors>
  <Warnings />
  <RequestedCommand />
  <Server>Server -151154064</Server>
  <GMTTimeDifference>--4:00</GMTTimeDifference>
  <ExecutionTime>0</ExecutionTime>
</ApiResponse>
XML;

    /** A domain the account genuinely owns. */
    private const OWNED_XML = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="OK" xmlns="http://api.namecheap.com/xml.response">
  <Errors />
  <Warnings />
  <RequestedCommand>namecheap.domains.getinfo</RequestedCommand>
  <CommandResponse Type="namecheap.domains.getInfo">
    <DomainGetInfoResult Status="Ok" ID="12345" DomainName="levelup-owned.com" OwnerName="BossMac" IsOwner="true" IsPremium="false">
      <DomainDetails>
        <CreatedDate>07/29/2026</CreatedDate>
        <ExpiredDate>07/29/2027</ExpiredDate>
        <NumYears>0</NumYears>
      </DomainDetails>
      <LockDetails />
      <Whoisguard Enabled="True">
        <ID>67890</ID>
      </Whoisguard>
      <DnsDetails ProviderType="CUSTOM" IsUsingOurDNS="false" HostCount="2">
        <Nameserver>ns1.digitalocean.com</Nameserver>
        <Nameserver>ns2.digitalocean.com</Nameserver>
      </DnsDetails>
      <Modificationrights All="true" />
    </DomainGetInfoResult>
  </CommandResponse>
  <Server>Server -151154064</Server>
  <GMTTimeDifference>--4:00</GMTTimeDifference>
  <ExecutionTime>0.02</ExecutionTime>
</ApiResponse>
XML;

    protected function setUp(): void
    {
        parent::setUp();

        // Credentials must be present for the client to attempt a call at all.
        // These are obviously fake and never leave the test process.
        config([
            'namecheap.environment' => 'sandbox',
            'namecheap.client_ip'   => '134.209.93.41',
            'namecheap.credentials.sandbox' => [
                'api_user' => 'testuser',
                'api_key'  => 'test-key-not-a-real-credential',
                'username' => 'testuser',
            ],
        ]);
    }

    private function fake(string $xml): void
    {
        Http::fake([
            '*namecheap.com*' => Http::response($xml, 200, ['Content-Type' => 'text/xml']),
        ]);
    }

    /** THE REGRESSION: a domain we do not own must be ANSWERED, not errored. */
    public function test_domain_not_in_account_is_answered_not_errored(): void
    {
        $this->fake(self::NOT_OWNED_XML);

        $r = NamecheapRegistrarConnector::make()->getDomainStatus('google.com');

        $this->assertTrue($r->success, 'A non-owned domain must be a successful ANSWER, not a failure');
        $this->assertSame('not_owned', $r->normalizedState);
        $this->assertFalse($r->data['owned']);
        $this->assertFalse($r->data['found']);
        $this->assertFalse($r->data['managed_by_us']);
        $this->assertNull($r->errorCode);

        // The provider's own words are retained for operators, since the message
        // ("Domain is invalid") does not mean what it appears to mean.
        $this->assertSame('2030166', $r->data['provider_code']);
        $this->assertSame('Domain is invalid', $r->data['provider_message']);
    }

    /** 2030166 must be permanent: retrying can never make us own the domain. */
    public function test_not_owned_is_classified_permanent_not_transient(): void
    {
        $this->fake(self::NOT_OWNED_XML);

        $r = NamecheapRegistrarConnector::make()->getDomainStatus('google.com');

        $this->assertSame('permanent', $r->retryClassification);
        $this->assertFalse($r->isRetryable(), '2030166 must never drive a retry loop');
    }

    /** An owned domain still reports as owned, with the same shape. */
    public function test_owned_domain_reports_owned(): void
    {
        $this->fake(self::OWNED_XML);

        $r = NamecheapRegistrarConnector::make()->getDomainStatus('levelup-owned.com');

        $this->assertTrue($r->success);
        $this->assertSame('active', $r->normalizedState);
        $this->assertTrue($r->data['owned']);
        $this->assertTrue($r->data['found']);
        $this->assertTrue($r->data['managed_by_us']);
        $this->assertSame(['ns1.digitalocean.com', 'ns2.digitalocean.com'], $r->data['nameservers']);
    }

    /** An authentication failure must NOT be laundered into "not owned". */
    public function test_invalid_api_key_remains_an_error(): void
    {
        $this->fake(self::BAD_KEY_XML);

        $r = NamecheapRegistrarConnector::make()->getDomainStatus('anything.com');

        $this->assertFalse($r->success, 'Bad credentials must never be normalized to not_owned');
        $this->assertSame('1011102', $r->errorCode);
        $this->assertNotSame('not_owned', $r->normalizedState);
    }

    /** An un-whitelisted IP must NOT be laundered into "not owned". */
    public function test_ip_not_whitelisted_remains_an_error(): void
    {
        $this->fake(self::IP_BLOCKED_XML);

        $r = NamecheapRegistrarConnector::make()->getDomainStatus('anything.com');

        $this->assertFalse($r->success, 'An un-whitelisted IP must never be normalized to not_owned');
        $this->assertSame('1011150', $r->errorCode);
        $this->assertNotSame('not_owned', $r->normalizedState);
    }

    /** A transport failure must NOT be laundered into "not owned". */
    public function test_transport_failure_remains_an_error(): void
    {
        Http::fake(['*namecheap.com*' => Http::response('not xml at all', 500)]);

        $r = NamecheapRegistrarConnector::make()->getDomainStatus('anything.com');

        $this->assertFalse($r->success);
        $this->assertNotSame('not_owned', $r->normalizedState);
    }

    /**
     * The ownership decision must rest on IsOwner, not on the error number
     * alone. A 2030166 with no CommandResponse is not evidence of anything.
     */
    public function test_2030166_without_a_command_response_is_not_normalized(): void
    {
        $this->fake(<<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<ApiResponse Status="ERROR" xmlns="http://api.namecheap.com/xml.response">
  <Errors><Error Number="2030166">Domain is invalid</Error></Errors>
  <Warnings />
  <RequestedCommand>namecheap.domains.getinfo</RequestedCommand>
  <Server>Server -1</Server>
</ApiResponse>
XML);

        $r = NamecheapRegistrarConnector::make()->getDomainStatus('anything.com');

        $this->assertFalse($r->success, 'Without IsOwner evidence there is nothing to normalize');
        $this->assertSame('2030166', $r->errorCode);
    }

    /** Registration must treat "not owned" as clear to proceed, not as an error. */
    public function test_not_owned_does_not_block_a_registration_precheck(): void
    {
        $this->fake(self::NOT_OWNED_XML);

        $r = NamecheapRegistrarConnector::make()->getDomainStatus('free-domain.com');

        $this->assertTrue($r->success);
        $this->assertFalse($r->data['managed_by_us'], 'registerDomain() reads this key to decide whether to buy');
    }
}
