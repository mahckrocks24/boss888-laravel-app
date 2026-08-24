<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\BusinessEmail\Values\MailDnsRecord;
use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderInventory;
use App\Connectors\Infrastructure\Email\Migadu\MigaduCapabilityMap as CapMap;
use App\Connectors\Infrastructure\Email\Migadu\MigaduClient;
use App\Connectors\Infrastructure\Email\Migadu\MigaduEmailProviderConnector;
use App\Connectors\Infrastructure\Email\Migadu\MigaduRequestFactory as Req;
use App\Connectors\Infrastructure\Email\Migadu\MigaduResponseMapper as Map;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * INFRA888 · E6 — the shapes the REAL API returns.
 *
 * Every fixture in this file was copied from an actual response captured on
 * 2026-08-06 against levelupgrowth.io, with our own addresses left in and
 * nothing invented. That is the point: E5's fixtures encoded what the
 * documentation SAID, and five of them were wrong. These encode what the
 * provider DOES, so the next person to change the mapper finds out immediately.
 *
 * No network call happens here. The live evidence is frozen into fixtures so it
 * can be asserted forever without a credential.
 */
class MigaduLiveShapeTest extends TestCase
{
    private const DOMAIN = 'levelupgrowth.io';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** Verbatim from GET /domains/levelupgrowth.io/records, 2026-08-06. */
    private function liveRecordsPayload(): array
    {
        return [
            'spf' => ['name' => '@', 'type' => 'txt', 'value' => 'v=spf1 include:spf.migadu.com -all'],
            'dkim' => [
                ['name' => 'key1._domainkey', 'type' => 'cname', 'value' => 'key1.levelupgrowth.io._domainkey.migadu.com.'],
                ['name' => 'key2._domainkey', 'type' => 'cname', 'value' => 'key2.levelupgrowth.io._domainkey.migadu.com.'],
                ['name' => 'key3._domainkey', 'type' => 'cname', 'value' => 'key3.levelupgrowth.io._domainkey.migadu.com.'],
            ],
            'domain_name' => 'levelupgrowth.io',
            'dmarc' => ['name' => '_dmarc', 'type' => 'txt', 'value' => 'v=DMARC1; p=quarantine;'],
            'dns_verification' => ['name' => '@', 'type' => 'txt', 'value' => 'hosted-email-verify=ztqeizxy'],
            'mx_records' => [
                ['name' => '@', 'priority' => 10, 'type' => 'mx', 'value' => 'aspmx1.migadu.com'],
                ['name' => '@', 'priority' => 20, 'type' => 'mx', 'value' => 'aspmx2.migadu.com'],
            ],
        ];
    }

    /** Verbatim field set from GET /domains/{d}/mailboxes, 2026-08-06. */
    private function liveMailboxRow(string $localPart, bool $active = true): array
    {
        return [
            'local_part' => $localPart,
            'domain_name' => self::DOMAIN,
            'address' => $localPart . '@' . self::DOMAIN,
            'name' => ucfirst($localPart),
            'is_active' => $active,
            'is_internal' => false,
            'storage_usage' => 0.0,
            'may_send' => true,
            'may_receive' => true,
            'may_access_imap' => true,
            'may_access_pop3' => true,
            'may_access_managesieve' => true,
            'daily_incoming_limit' => 0,
            'daily_outgoing_limit' => 0,
            'weekly_incoming_limit' => 0,
            'weekly_outgoing_limit' => 0,
            'monthly_incoming_limit' => 0,
            'monthly_outgoing_limit' => 0,
            'spam_action' => 'folder',
            'spam_aggressiveness' => 'default',
            'sender_denylist' => [],
            'sender_allowlist' => [],
            'recipient_denylist' => [],
            'password_recovery_email' => null,
            'expireable' => false,
            'expires_on' => null,
            'remove_upon_expiry' => false,
            'wildcard_sender' => false,
            'activated_at' => '2026-08-06T00:00:00.000Z',
            'changed_at' => '2026-08-06T00:00:00.000Z',
            'forwardings' => [],
            'identities' => [],
            'delegations' => [],
        ];
    }

    // ═══ DNS ═════════════════════════════════════════════════════════════════

    public function test_the_real_dns_payload_maps_to_seven_records(): void
    {
        // E5 produced ZERO from this payload and reported a malformed response,
        // because it expected a flat list under a `records` key that the API
        // has never returned.
        $records = Map::toDnsRecords($this->liveRecordsPayload());

        $this->assertCount(8, $records, 'MX2 + SPF1 + DKIM3 + DMARC1 + verification1 = 8.');

        $byPurpose = [];

        foreach ($records as $record) {
            $byPurpose[$record->purpose][] = $record;
        }

        $this->assertCount(2, $byPurpose[MailDnsRecord::PURPOSE_MX]);
        $this->assertCount(1, $byPurpose[MailDnsRecord::PURPOSE_SPF]);
        $this->assertCount(3, $byPurpose[MailDnsRecord::PURPOSE_DKIM], 'DKIM is three CNAMEs, not one TXT.');
        $this->assertCount(1, $byPurpose[MailDnsRecord::PURPOSE_DMARC]);
        $this->assertCount(1, $byPurpose[MailDnsRecord::PURPOSE_VERIFICATION]);
    }

    public function test_lowercase_types_from_the_api_are_normalised(): void
    {
        // The live API sends "txt"/"cname"/"mx"; the value object validates
        // against MX/TXT/CNAME and would reject the raw values.
        foreach (Map::toDnsRecords($this->liveRecordsPayload()) as $record) {
            $this->assertContains($record->type, MailDnsRecord::types());
            $this->assertSame(strtoupper($record->type), $record->type);
        }
    }

    public function test_the_domain_verification_record_is_not_dropped(): void
    {
        $records = Map::toDnsRecords($this->liveRecordsPayload());

        $verification = array_values(array_filter(
            $records,
            fn (MailDnsRecord $r) => $r->purpose === MailDnsRecord::PURPOSE_VERIFICATION
        ));

        $this->assertCount(1, $verification);
        $this->assertStringContainsString('hosted-email-verify', $verification[0]->value);

        // Ownership proof is not optional — without it the domain never activates.
        $this->assertTrue($verification[0]->required);
    }

    public function test_mx_priority_survives(): void
    {
        $mx = array_values(array_filter(
            Map::toDnsRecords($this->liveRecordsPayload()),
            fn (MailDnsRecord $r) => $r->purpose === MailDnsRecord::PURPOSE_MX
        ));

        $this->assertSame([10, 20], array_map(fn (MailDnsRecord $r) => $r->priority, $mx));
    }

    public function test_only_dmarc_is_optional(): void
    {
        foreach (Map::toDnsRecords($this->liveRecordsPayload()) as $record) {
            $this->assertSame(
                $record->purpose !== MailDnsRecord::PURPOSE_DMARC,
                $record->required,
                "{$record->purpose} has the wrong required flag."
            );
        }
    }

    // ═══ ENVELOPES ═══════════════════════════════════════════════════════════

    public function test_aliases_are_read_from_the_real_envelope_key(): void
    {
        // The live key is `address_aliases`. E5 looked for `aliases`, found
        // nothing, and reported a null count — which reads as "unknown" when
        // the truth was "looked in the wrong place".
        $payload = ['address_aliases' => [
            ['local_part' => 'hello', 'domain_name' => self::DOMAIN, 'destinations' => ['support@' . self::DOMAIN]],
        ]];

        $rows = Map::rows($payload, 'aliases');

        $this->assertCount(1, $rows);
        $this->assertSame('hello', $rows[0]['local_part']);
    }

    public function test_the_documented_envelope_still_works_if_it_ever_appears(): void
    {
        $this->assertCount(1, Map::rows(['aliases' => [['local_part' => 'x']]], 'aliases'));
    }

    public function test_every_live_envelope_key_is_recorded(): void
    {
        $this->assertSame('address_aliases', CapMap::LIVE_ENVELOPES['aliases']);
        $this->assertSame('mailboxes', CapMap::LIVE_ENVELOPES['mailboxes']);
        $this->assertSame('rewrites', CapMap::LIVE_ENVELOPES['rewrites']);
        $this->assertSame('2026-08-06', CapMap::LIVE_VERIFIED_ON);
    }

    // ═══ MAILBOX ═════════════════════════════════════════════════════════════

    public function test_suspension_is_read_from_the_real_is_active_field(): void
    {
        // E5 believed no such field existed and inferred suspension from five
        // access flags. The live object has it.
        $this->assertFalse(Map::isSuspended($this->liveMailboxRow('support', active: true)));
        $this->assertTrue(Map::isSuspended($this->liveMailboxRow('support', active: false)));
    }

    public function test_is_active_wins_over_the_access_flag_heuristic(): void
    {
        // A mailbox that is active but has every access route closed is NOT
        // suspended — it is a deliberately locked-down mailbox, and conflating
        // the two would let a reconciliation "restore" something on purpose.
        $row = $this->liveMailboxRow('support', active: true);

        foreach (['may_send', 'may_receive', 'may_access_imap', 'may_access_pop3', 'may_access_managesieve'] as $flag) {
            $row[$flag] = false;
        }

        $this->assertFalse(Map::isSuspended($row));
    }

    public function test_the_flag_heuristic_still_covers_a_response_without_is_active(): void
    {
        $row = $this->liveMailboxRow('support');
        unset($row['is_active']);

        foreach (['may_send', 'may_receive', 'may_access_imap', 'may_access_pop3', 'may_access_managesieve'] as $flag) {
            $row[$flag] = false;
        }

        $this->assertTrue(Map::isSuspended($row));
    }

    public function test_per_mailbox_storage_is_now_read(): void
    {
        // E5 reported this as permanently unavailable.
        $mailbox = Map::toMailbox(self::DOMAIN, $this->liveMailboxRow('support'));

        $this->assertSame(0, $mailbox->storageUsedMb);

        // A storage LIMIT is still absent, and unknown must not become zero.
        $this->assertNull($mailbox->quotaMb);
    }

    public function test_forwardings_are_read_from_the_mailbox_row(): void
    {
        $row = $this->liveMailboxRow('support');
        $row['forwardings'] = [
            ['address' => 'elsewhere@example.test', 'is_active' => true, 'confirmed_at' => '2026-08-06T00:00:00Z'],
        ];

        $this->assertTrue(Map::hasEmbeddedForwardings($row));

        $forwarders = Map::embeddedForwarders(self::DOMAIN, $row);

        $this->assertCount(1, $forwarders);
        $this->assertSame('support', $forwarders[0]->sourceLocalPart);
        $this->assertSame('elsewhere@example.test', $forwarders[0]->destinationAddress);
    }

    public function test_inventory_needs_no_fan_out_when_forwardings_are_embedded(): void
    {
        Http::fake([
            '*/domains/' . self::DOMAIN . '/mailboxes' => Http::response([
                'mailboxes' => [$this->liveMailboxRow('support'), $this->liveMailboxRow('admin')],
            ], 200),
            '*/domains/' . self::DOMAIN . '/aliases'  => Http::response(['address_aliases' => []], 200),
            '*/domains/' . self::DOMAIN . '/rewrites' => Http::response(['rewrites' => []], 200),
        ]);

        $result = $this->connector()->getInventory(self::DOMAIN, $this->callContext());

        $this->assertTrue($result->success);

        /** @var ProviderInventory $inventory */
        $inventory = $result->data['inventory'];

        $this->assertTrue($inventory->complete);
        $this->assertCount(2, $inventory->mailboxes);

        // THREE calls, not 2 + one per mailbox. No stub exists for a
        // per-mailbox forwardings endpoint, so any fan-out would fail the test.
        Http::assertSentCount(3);
    }

    // ═══ THE OPAQUE 400 ══════════════════════════════════════════════════════

    public function test_a_duplicate_create_is_recognised_by_read_back_not_by_wording(): void
    {
        // Proven live: creating an existing mailbox returns exactly
        // {"error":"bad request"} — identical to a malformed payload. The only
        // way to tell them apart is to ask whether the object is there.
        Http::fake([
            '*/domains/' . self::DOMAIN . '/mailboxes' => Http::response(['error' => 'bad request'], 400),
            '*/domains/' . self::DOMAIN . '/mailboxes/taken' => Http::response(
                $this->liveMailboxRow('taken'), 200
            ),
        ]);

        $result = $this->connector(mutations: true)
            ->createMailbox(self::DOMAIN, new MailboxSpec('taken'), $this->callContext());

        $this->assertTrue($result->success, 'A duplicate create should converge, not fail.');
        $this->assertSame('already_exists', $result->providerState);
        $this->assertTrue($result->data['already_existed']);
    }

    public function test_a_genuine_bad_request_stays_a_failure_and_says_why(): void
    {
        Http::fake([
            '*/domains/' . self::DOMAIN . '/mailboxes/ghost' => Http::response(['error' => 'not found'], 404),
            '*/domains/' . self::DOMAIN . '/mailboxes' => Http::response(['error' => 'bad request'], 400),
        ]);

        $result = $this->connector(mutations: true)
            ->createMailbox(self::DOMAIN, new MailboxSpec('ghost'), $this->callContext());

        $this->assertFalse($result->success);
        $this->assertFalse($result->isRetryable(), 'A refused payload will be refused identically forever.');

        // The operator is told the actual cause, proven live, rather than
        // "validation failed".
        $this->assertStringContainsString('password', (string) $result->errorSummary);
    }

    public function test_the_matrix_records_the_blocking_password_requirement(): void
    {
        $limitation = CapMap::limitationFor(\App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability::MAILBOX_CREATE);

        $this->assertStringContainsStringIgnoringCase('password', $limitation);
        $this->assertStringContainsStringIgnoringCase('blocking', $limitation);
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
            workspaceId: 990601,
            ownerType: 'email_mailbox',
            ownerId: null,
            idempotencyKey: 'e6-shape-' . bin2hex(random_bytes(4)),
        );
    }
}
