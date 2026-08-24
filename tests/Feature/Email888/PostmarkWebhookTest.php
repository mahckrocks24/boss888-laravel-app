<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\Models\EmailDeliveryEvent;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EMAIL888 - the Postmark delivery webhook.
 *
 * The endpoint is public, so its refusals matter as much as its successes.
 */
class PostmarkWebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'wh_9f3c1ea45b7d2086c41fa93be5d7';

    protected function setUp(): void
    {
        parent::setUp();
        config(['email888.webhook.secret' => self::SECRET]);
    }

    private function delivery(array $overrides = []): EmailDelivery
    {
        return EmailDelivery::create(array_merge([
            'correlation_id'      => '22222222-2222-4222-8222-222222222222',
            'purpose'             => 'notification',
            'stream_class'        => 'transactional',
            'provider'            => 'postmark',
            'provider_message_id' => 'pm-msg-0001',
            'recipient_address'   => 'someone@example.com',
            'sender_address'      => 'hello@levelupgrowth.io',
            'state'               => DeliveryState::ACCEPTED->value,
            'queued_at'           => now()->subMinute(),
            'accepted_at'         => now()->subMinute(),
        ], $overrides));
    }

    private function hook(array $payload, ?string $secret = null)
    {
        return $this->postJson('/api/webhooks/email/postmark/' . ($secret ?? self::SECRET), $payload);
    }

    // ---- authentication ------------------------------------------------

    public function test_wrong_secret_is_refused_as_not_found(): void
    {
        $this->delivery();

        $this->hook(['RecordType' => 'Delivery', 'MessageID' => 'pm-msg-0001'], 'wh_000000000000000000000000')
            ->assertStatus(404);

        $this->assertSame(DeliveryState::ACCEPTED->value, EmailDelivery::first()->state);
    }

    public function test_endpoint_refuses_everything_while_the_secret_is_unset(): void
    {
        // The safe default before configuration is a closed door, not an open one.
        config(['email888.webhook.secret' => '']);

        $this->hook(['RecordType' => 'Delivery', 'MessageID' => 'pm-msg-0001'])
            ->assertStatus(404);
    }

    public function test_a_short_secret_is_treated_as_unconfigured(): void
    {
        config(['email888.webhook.secret' => 'tooshort']);

        $this->postJson('/api/webhooks/email/postmark/tooshortbutlongenoughforroute', [
            'RecordType' => 'Delivery', 'MessageID' => 'pm-msg-0001',
        ])->assertStatus(404);
    }

    // ---- state transitions ---------------------------------------------

    public function test_delivery_event_marks_the_row_delivered(): void
    {
        $this->delivery();

        $this->hook([
            'RecordType'  => 'Delivery',
            'MessageID'   => 'pm-msg-0001',
            'Recipient'   => 'someone@example.com',
            'DeliveredAt' => '2026-08-11T10:00:00Z',
            'Details'     => 'smtp;250 2.0.0 OK',
        ])->assertOk();

        $row = EmailDelivery::first();
        $this->assertSame(DeliveryState::DELIVERED->value, $row->state);
        $this->assertNotNull($row->delivered_at);
        $this->assertTrue($row->deliveryState()->isSuccess());
    }

    public function test_hard_bounce_is_terminal_and_not_retryable(): void
    {
        $this->delivery();

        $this->hook([
            'RecordType'  => 'Bounce',
            'Type'        => 'HardBounce',
            'TypeCode'    => 1,
            'MessageID'   => 'pm-msg-0001',
            'Email'       => 'someone@example.com',
            'BouncedAt'   => '2026-08-11T10:00:00Z',
            'Description' => 'The server was unable to deliver your message',
            'Inactive'    => true,
        ])->assertOk();

        $row = EmailDelivery::first();
        $this->assertSame(DeliveryState::BOUNCED->value, $row->state);
        $this->assertTrue($row->deliveryState()->isTerminal());
        $this->assertFalse((bool) $row->retryable);
        $this->assertNotNull($row->bounced_at);
    }

    public function test_soft_bounce_is_deferred_and_still_in_flight(): void
    {
        $this->delivery();

        $this->hook([
            'RecordType' => 'Bounce',
            'Type'       => 'SoftBounce',
            'MessageID'  => 'pm-msg-0001',
            'BouncedAt'  => '2026-08-11T10:00:00Z',
        ])->assertOk();

        $row = EmailDelivery::first();
        $this->assertSame(DeliveryState::DEFERRED->value, $row->state);
        $this->assertFalse($row->deliveryState()->isTerminal(), 'a soft bounce must not close the record');
    }

    public function test_spam_complaint_suppresses(): void
    {
        $this->delivery();

        $this->hook([
            'RecordType' => 'SpamComplaint',
            'MessageID'  => 'pm-msg-0001',
            'BouncedAt'  => '2026-08-11T10:00:00Z',
        ])->assertOk();

        $this->assertSame(DeliveryState::SUPPRESSED->value, EmailDelivery::first()->state);
    }

    // ---- replay and ordering -------------------------------------------

    public function test_identical_payload_delivered_twice_is_recorded_once(): void
    {
        $this->delivery();

        $payload = [
            'RecordType'  => 'Delivery',
            'MessageID'   => 'pm-msg-0001',
            'DeliveredAt' => '2026-08-11T10:00:00Z',
        ];

        $this->hook($payload)->assertOk()->assertJsonPath('results.0', 'applied');
        $this->hook($payload)->assertOk()->assertJsonPath('results.0', 'duplicate');

        $this->assertSame(1, EmailDeliveryEvent::count());
    }

    public function test_state_never_moves_backwards(): void
    {
        $this->delivery(['state' => DeliveryState::DELIVERED->value, 'delivered_at' => now()]);

        // A late soft bounce for an already-delivered message must not reopen it.
        $this->hook([
            'RecordType' => 'Bounce',
            'Type'       => 'SoftBounce',
            'MessageID'  => 'pm-msg-0001',
            'BouncedAt'  => '2026-08-11T09:00:00Z',
        ])->assertOk()->assertJsonPath('results.0', 'out_of_order');

        $this->assertSame(DeliveryState::DELIVERED->value, EmailDelivery::first()->state);
    }

    public function test_event_for_an_unknown_message_is_kept_as_evidence(): void
    {
        $this->hook([
            'RecordType' => 'Delivery',
            'MessageID'  => 'pm-msg-does-not-exist',
        ])->assertOk()->assertJsonPath('results.0', 'unmatched');

        // We did not invent a delivery, but we did not throw the evidence away.
        $this->assertSame(0, EmailDelivery::count());
        $this->assertSame(1, EmailDeliveryEvent::count());
    }

    public function test_batched_events_are_all_processed(): void
    {
        $this->delivery();
        $this->delivery(['provider_message_id' => 'pm-msg-0002']);

        $this->hook([
            ['RecordType' => 'Delivery', 'MessageID' => 'pm-msg-0001', 'DeliveredAt' => '2026-08-11T10:00:00Z'],
            ['RecordType' => 'Delivery', 'MessageID' => 'pm-msg-0002', 'DeliveredAt' => '2026-08-11T10:00:01Z'],
        ])->assertOk();

        $this->assertSame(2, EmailDelivery::where('state', DeliveryState::DELIVERED->value)->count());
    }

    public function test_record_types_we_do_not_act_on_are_acknowledged_not_stored(): void
    {
        $this->delivery();

        $this->hook(['RecordType' => 'Open', 'MessageID' => 'pm-msg-0001'])
            ->assertOk()->assertJsonPath('results.0', 'ignored');

        $this->assertSame(DeliveryState::ACCEPTED->value, EmailDelivery::first()->state);
        $this->assertSame(0, EmailDeliveryEvent::count());
    }

    public function test_empty_body_is_rejected_without_a_retry_invitation(): void
    {
        $this->hook([])->assertStatus(400);
    }
}
