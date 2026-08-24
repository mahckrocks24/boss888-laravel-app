<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\Models\WebhookReceipt;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EMAIL888 EM-6 - every webhook attempt becomes durable evidence.
 *
 * A refused request used to return 404 and vanish, so an operator could not
 * tell "nobody is calling us" from "someone is probing" from "Postmark has been
 * silently refused since a credential change".
 */
class WebhookReceiptTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'wh_9f3c1ea45b7d2086c41fa93be5d7';
    private const USER   = 'pm_hook_test';
    private const PASS   = 'pm_hook_password_value';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'email888.webhook.secret'         => self::SECRET,
            'email888.webhook.basic_user'     => self::USER,
            'email888.webhook.basic_password' => self::PASS,
        ]);
    }

    private function hook(array $payload, ?string $secret = null, bool $auth = true)
    {
        $url = '/api/webhooks/email/postmark/' . ($secret ?? self::SECRET);

        if ($auth) {
            $this->withHeaders(['Authorization' => 'Basic ' . base64_encode(self::USER . ':' . self::PASS)]);
        }

        return $this->postJson($url, $payload);
    }

    private function delivery(): EmailDelivery
    {
        return EmailDelivery::create([
            'correlation_id'      => '33333333-3333-4333-8333-333333333333',
            'purpose'             => 'notification',
            'stream_class'        => 'transactional',
            'provider'            => 'postmark',
            'provider_message_id' => 'pm-receipt-0001',
            'recipient_address'   => 'someone@example.com',
            'state'               => DeliveryState::ACCEPTED->value,
            'queued_at'           => now()->subMinute(),
            'accepted_at'         => now()->subMinute(),
        ]);
    }

    private function latest(): WebhookReceipt
    {
        $r = WebhookReceipt::orderByDesc('id')->first();
        $this->assertNotNull($r, 'no receipt was recorded');

        return $r;
    }

    // ---- every attempt is recorded --------------------------------------

    public function test_a_wrong_path_secret_is_recorded_not_merely_refused(): void
    {
        $this->hook(['RecordType' => 'Delivery'], 'wh_000000000000000000000000')->assertStatus(404);

        $r = $this->latest();
        $this->assertSame(WebhookReceipt::AUTH_BAD_SECRET, $r->authentication_result);
        $this->assertSame(WebhookReceipt::STAGE_AUTH, $r->processing_stage);
        $this->assertFalse($r->wasFullyProcessed());
        $this->assertNotEmpty($r->operator_visible_reason);
    }

    public function test_a_correct_secret_with_bad_basic_auth_is_distinguished(): void
    {
        // Which credential failed is the whole diagnosis.
        $this->hook(['RecordType' => 'Delivery'], null, false)->assertStatus(404);

        $this->assertSame(WebhookReceipt::AUTH_BAD_BASIC, $this->latest()->authentication_result);
    }

    public function test_an_unconfigured_secret_refuses_and_is_recorded(): void
    {
        config(['email888.webhook.secret' => '']);

        $this->hook(['RecordType' => 'Delivery'])->assertStatus(404);

        $this->assertSame(WebhookReceipt::AUTH_UNCONFIGURED, $this->latest()->authentication_result);
    }

    public function test_an_empty_body_is_recorded_at_the_validation_stage(): void
    {
        $this->hook([])->assertStatus(400);

        $r = $this->latest();
        $this->assertSame(WebhookReceipt::AUTH_ACCEPTED, $r->authentication_result);
        $this->assertSame(WebhookReceipt::INVALID_EMPTY, $r->validation_result);
        $this->assertSame(WebhookReceipt::STAGE_VALIDATE, $r->processing_stage);
        $this->assertFalse($r->wasFullyProcessed());
    }

    // ---- the invariant ---------------------------------------------------

    public function test_http_200_alone_is_never_full_processing(): void
    {
        // An event for a message we never recorded is kept as evidence and
        // answered 200 - but it did NOT update anything, and must not read as
        // success.
        $this->hook(['RecordType' => 'Delivery', 'MessageID' => 'pm-nobody-knows'])->assertOk();

        $r = $this->latest();
        $this->assertSame(200, (int) $r->http_status);
        $this->assertSame('unmatched', $r->processing_result);
        $this->assertFalse($r->wasFullyProcessed(), 'HTTP 200 was treated as successful processing');
    }

    public function test_a_genuinely_applied_event_is_fully_processed(): void
    {
        $this->delivery();

        $this->hook([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'pm-receipt-0001',
            'DeliveredAt' => '2026-08-13T10:00:00Z',
        ])->assertOk();

        $r = $this->latest();
        $this->assertSame('applied', $r->processing_result);
        $this->assertTrue($r->wasFullyProcessed());
        $this->assertSame(DeliveryState::DELIVERED->value, EmailDelivery::first()->state);
    }

    public function test_a_redelivered_payload_is_recorded_as_duplicate_not_reapplied(): void
    {
        $this->delivery();
        $payload = ['RecordType' => 'Delivery', 'MessageID' => 'pm-receipt-0001', 'DeliveredAt' => '2026-08-13T10:00:00Z'];

        $this->hook($payload)->assertOk();
        $this->hook($payload)->assertOk();

        $this->assertSame('duplicate', $this->latest()->processing_result);
        $this->assertSame(2, WebhookReceipt::where('processing_stage', WebhookReceipt::STAGE_COMPLETE)->count(),
            'both attempts must be recorded, even though only one changed anything');
    }

    // ---- evidence quality -------------------------------------------------

    public function test_a_receipt_never_contains_a_credential(): void
    {
        $this->hook(['RecordType' => 'Delivery', 'MessageID' => 'pm-x']);

        $blob = json_encode($this->latest()->toAdminArray());

        foreach ([self::SECRET, self::USER, self::PASS] as $secretish) {
            $this->assertStringNotContainsString($secretish, $blob);
        }
        $this->assertStringNotContainsStringIgnoringCase('authorization', $blob);
    }

    public function test_the_endpoint_path_is_recorded_without_the_secret(): void
    {
        $this->hook(['RecordType' => 'Delivery', 'MessageID' => 'pm-x']);

        $r = $this->latest();
        $this->assertStringNotContainsString(self::SECRET, (string) $r->endpoint);
        $this->assertStringContainsString('{secret}', (string) $r->endpoint);
    }

    public function test_every_attempt_carries_a_digest_and_a_request_id(): void
    {
        $this->hook(['RecordType' => 'Delivery', 'MessageID' => 'pm-x'])->assertOk();

        $r = $this->latest();
        $this->assertNotEmpty($r->request_id);
        $this->assertSame(64, strlen((string) $r->payload_digest));
        $this->assertIsInt($r->processing_latency_ms);
    }

    public function test_nothing_is_lost_across_a_mixed_sequence(): void
    {
        $this->delivery();

        $this->hook(['RecordType' => 'Delivery'], 'wh_000000000000000000000000');   // refused
        $this->hook([]);                                                            // invalid
        $this->hook(['RecordType' => 'Open', 'MessageID' => 'pm-receipt-0001']);    // ignored
        $this->hook(['RecordType' => 'Delivery', 'MessageID' => 'pm-receipt-0001', 'DeliveredAt' => '2026-08-13T10:00:00Z']);

        $this->assertSame(4, WebhookReceipt::count(), 'an attempt went unrecorded');
        $this->assertSame(1, WebhookReceipt::where('authentication_result', '!=', WebhookReceipt::AUTH_ACCEPTED)->count());
    }
}
