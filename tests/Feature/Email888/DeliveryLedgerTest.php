<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\Models\EmailDeliveryEvent;
use App\Core\Email888\OutboundPolicy;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * EMAIL888 — the delivery ledger and outbound policy.
 *
 * These tests exist because "Mail::send returned" was once treated as proof a
 * customer received something. Each one pins a specific way that belief failed.
 */
class DeliveryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private function send(string $to, string $subject, ?callable $decorate = null): void
    {
        Mail::raw('body', function ($m) use ($to, $subject, $decorate) {
            $m->to($to)->subject($subject);
            if ($decorate) {
                $decorate($m);
            }
        });
    }

    /** The last message the array transport actually handed over. */
    private function lastSentMessage(): \Symfony\Component\Mime\Email
    {
        $messages = Mail::getSymfonyTransport()->messages();
        $this->assertNotEmpty($messages, 'transport received no message');

        return $messages->last()->getOriginalMessage();
    }

    // ── recording ────────────────────────────────────────────────────────

    public function test_every_send_creates_a_ledger_row_reaching_accepted(): void
    {
        $this->send('someone@example.com', 'Hello there');

        $this->assertSame(1, EmailDelivery::count());

        $row = EmailDelivery::first();
        $this->assertSame('someone@example.com', $row->recipient_address);
        $this->assertSame('Hello there', $row->subject);
        $this->assertSame(DeliveryState::ACCEPTED->value, $row->state);
        $this->assertNotNull($row->queued_at);
        $this->assertNotNull($row->accepted_at);
        $this->assertNotNull($row->correlation_id);
    }

    public function test_accepted_is_not_delivered(): void
    {
        $this->send('someone@example.com', 'Subject');

        $row = EmailDelivery::first();

        // The distinction the incident turned on: the provider taking a message
        // is not the recipient receiving it.
        $this->assertFalse($row->deliveryState()->isTerminal());
        $this->assertFalse($row->deliveryState()->isSuccess());
        $this->assertNull($row->delivered_at);
    }

    public function test_ledger_can_be_switched_off_without_affecting_the_send(): void
    {
        config(['email888.ledger_enabled' => false]);

        $this->send('someone@example.com', 'Subject');

        $this->assertSame(0, EmailDelivery::count());
        $this->assertNotEmpty(Mail::getSymfonyTransport()->messages(), 'the message must still be sent');
    }

    // ── purpose / sender / stream policy ─────────────────────────────────

    public function test_unclassified_mail_is_left_exactly_as_it_was(): void
    {
        $this->send('someone@example.com', 'Subject');

        $row = EmailDelivery::first();
        $this->assertSame('unclassified', $row->purpose);

        $sent = $this->lastSentMessage();
        // No purpose means no policy: sender untouched, no stream imposed.
        $this->assertSame(config('mail.from.address'), $sent->getFrom()[0]->getAddress());
        $this->assertFalse($sent->getHeaders()->has('X-PM-Message-Stream'));
    }

    public function test_declared_purpose_sets_sender_reply_to_and_stream(): void
    {
        $this->send('someone@example.com', 'Set up your Business Email account', function ($m) {
            $m->getSymfonyMessage()->getHeaders()
                ->addTextHeader(OutboundPolicy::HDR_PURPOSE, 'mailbox_onboarding');
        });

        $row = EmailDelivery::first();
        $this->assertSame('mailbox_onboarding', $row->purpose);
        $this->assertSame('transactional', $row->stream_class);
        $this->assertSame(config('email888.senders.support.address'), $row->sender_address);

        $sent = $this->lastSentMessage();
        $this->assertSame(config('email888.senders.support.address'), $sent->getFrom()[0]->getAddress());
        $this->assertSame(config('email888.senders.support.address'), $sent->getReplyTo()[0]->getAddress());
        $this->assertSame('outbound', $sent->getHeaders()->get('X-PM-Message-Stream')->getBodyAsString());
    }

    public function test_marketing_purpose_travels_on_the_broadcast_stream(): void
    {
        $this->send('someone@example.com', 'Newsletter', function ($m) {
            $m->getSymfonyMessage()->getHeaders()
                ->addTextHeader(OutboundPolicy::HDR_PURPOSE, 'campaign');
        });

        $row = EmailDelivery::first();
        $this->assertSame('broadcast', $row->stream_class);

        $sent = $this->lastSentMessage();
        $this->assertSame('broadcast', $sent->getHeaders()->get('X-PM-Message-Stream')->getBodyAsString());
    }

    public function test_security_mail_never_travels_on_the_broadcast_stream(): void
    {
        // A recipient who unsubscribes from marketing must not thereby stop
        // receiving password resets.
        foreach (['password_reset', 'account_security', 'mailbox_onboarding', 'billing'] as $purpose) {
            $resolved = OutboundPolicy::resolve($purpose);
            $this->assertNotNull($resolved, "purpose {$purpose} is not registered");
            $this->assertSame('transactional', $resolved['stream_class'], "{$purpose} must be transactional");
        }
    }

    public function test_internal_routing_headers_never_reach_the_recipient(): void
    {
        $this->send('someone@example.com', 'Subject', function ($m) {
            $h = $m->getSymfonyMessage()->getHeaders();
            $h->addTextHeader(OutboundPolicy::HDR_PURPOSE, 'notification');
            $h->addTextHeader(OutboundPolicy::HDR_WORKSPACE, '42');
            $h->addTextHeader(OutboundPolicy::HDR_USER, '7');
            $h->addTextHeader(OutboundPolicy::HDR_CORRELATION, '11111111-1111-4111-8111-111111111111');
        });

        $sent = $this->lastSentMessage();
        foreach (OutboundPolicy::INTERNAL_HEADERS as $header) {
            $this->assertFalse($sent->getHeaders()->has($header), "{$header} leaked to the recipient");
        }

        // …but the ledger captured them.
        $row = EmailDelivery::first();
        $this->assertSame(42, $row->workspace_id);
        $this->assertSame(7, $row->user_id);
        $this->assertSame('11111111-1111-4111-8111-111111111111', $row->correlation_id);
    }

    public function test_unknown_purpose_falls_back_rather_than_sending_from_nowhere(): void
    {
        $this->send('someone@example.com', 'Subject', function ($m) {
            $m->getSymfonyMessage()->getHeaders()
                ->addTextHeader(OutboundPolicy::HDR_PURPOSE, 'not_a_registered_purpose');
        });

        $sent = $this->lastSentMessage();
        $this->assertSame(config('mail.from.address'), $sent->getFrom()[0]->getAddress());

        // Recorded under its declared name so the gap is visible, not hidden
        // behind 'unclassified'.
        $this->assertSame('not_a_registered_purpose', EmailDelivery::first()->purpose);
    }

    public function test_no_registered_purpose_sends_from_an_unverified_noreply_address(): void
    {
        foreach (array_keys(config('email888.purposes')) as $purpose) {
            $resolved = OutboundPolicy::resolve($purpose);
            $this->assertNotNull($resolved, "purpose {$purpose} resolves to nothing");
            $this->assertStringNotContainsString('noreply@', $resolved['sender']['address']);
            $this->assertNotSame('', $resolved['sender']['address']);
        }
    }
}
