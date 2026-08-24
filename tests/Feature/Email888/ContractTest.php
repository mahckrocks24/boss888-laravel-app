<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\Contracts\EmailResult;
use App\Core\Email888\Contracts\SendEmailCommand;
use App\Core\Email888\EmailDispatcher;
use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\OutboundPolicy;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * EMAIL888 - the frozen outbound contract.
 *
 * These tests are the contract. Anything that would let a producer smuggle a
 * raw sender, an unregistered purpose or a vendor concept through this seam is
 * a break, not a refactor.
 */
class ContractTest extends TestCase
{
    use RefreshDatabase;

    private function cmd(array $overrides = []): SendEmailCommand
    {
        return new SendEmailCommand(...array_merge([
            'purpose'    => 'platform_diagnostic',
            'recipients' => ['someone@example.com'],
            'subject'    => 'Contract test',
            'text'       => 'body',
        ], $overrides));
    }

    private function dispatcher(): EmailDispatcher
    {
        return app(EmailDispatcher::class);
    }

    private function lastSent(): \Symfony\Component\Mime\Email
    {
        $m = Mail::getSymfonyTransport()->messages();
        $this->assertNotEmpty($m, 'transport received no message');

        return $m->last()->getOriginalMessage();
    }

    // ---- command validation --------------------------------------------

    public function test_unregistered_purpose_is_refused_at_construction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/unknown purpose/');
        $this->cmd(['purpose' => 'whatever_i_feel_like']);
    }

    public function test_a_raw_address_cannot_be_passed_as_sender_identity(): void
    {
        // The whole point of the registry is that call sites cannot choose an
        // address. An override names a key.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/registry key, not an address/');
        $this->cmd(['senderIdentity' => 'marketing@levelupgrowth.io']);
    }

    public function test_an_unregistered_sender_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not in the sender registry/');
        $this->cmd(['senderIdentity' => 'ghost_identity']);
    }

    public function test_at_least_one_recipient_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cmd(['recipients' => []]);
    }

    public function test_invalid_recipient_addresses_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cmd(['recipients' => ['not-an-address']]);
        }

    public function test_a_body_is_required(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cmd(['text' => null, 'html' => null, 'template' => null]);
    }

    public function test_template_and_html_are_mutually_exclusive(): void
    {
        // Two sources for one part.
        $this->expectException(InvalidArgumentException::class);
        $this->cmd(['template' => 'emails.notification', 'html' => '<p>body</p>']);
    }

    public function test_a_template_may_carry_a_plain_text_alternative(): void
    {
        // Changed deliberately in E8 step 7. Forbidding this left every
        // templated message HTML-only, which filters penalise and a text-mode
        // client cannot read at all.
        $cmd = $this->cmd(['template' => 'emails.notification', 'text' => 'plain alternative']);

        $this->assertSame('emails.notification', $cmd->template);
        $this->assertSame('plain alternative', $cmd->text);
    }

    public function test_metadata_must_be_scalar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/not a document store/');
        $this->cmd(['metadata' => ['nested' => ['a' => 1]]]);
    }

    public function test_stream_class_override_is_constrained(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->cmd(['streamClass' => 'whatever']);
    }

    public function test_the_contract_exposes_no_provider_specific_field(): void
    {
        $forbidden = ['messageStream', 'postmark', 'serverToken', 'apiKey', 'token', 'provider'];
        $props = array_map(
            fn ($p) => strtolower($p->getName()),
            (new \ReflectionClass(SendEmailCommand::class))->getProperties()
        );

        foreach ($forbidden as $f) {
            $this->assertNotContains(strtolower($f), $props, "SendEmailCommand leaks '{$f}'");
        }
    }

    // ---- dispatch --------------------------------------------------------

    public function test_send_records_a_ledger_row_and_returns_its_id(): void
    {
        $result = $this->dispatcher()->send($this->cmd());

        $this->assertInstanceOf(EmailResult::class, $result);
        $this->assertTrue($result->accepted);
        $this->assertFalse($result->deduplicated);
        $this->assertNotNull($result->deliveryRecordId);
        $this->assertSame(1, EmailDelivery::count());

        $row = EmailDelivery::find($result->deliveryRecordId);
        $this->assertSame(DeliveryState::ACCEPTED->value, $row->state);
        $this->assertSame('someone@example.com', $row->recipient_address);
        $this->assertSame($result->correlationId, $row->correlation_id);
    }

    public function test_accepted_does_not_claim_delivery(): void
    {
        $result = $this->dispatcher()->send($this->cmd());
        $row    = EmailDelivery::find($result->deliveryRecordId);

        $this->assertTrue($result->accepted);
        $this->assertFalse($row->deliveryState()->isSuccess());
        $this->assertNull($row->delivered_at);
    }

    public function test_the_dispatcher_applies_the_purpose_policy(): void
    {
        $this->dispatcher()->send($this->cmd(['purpose' => 'mailbox_onboarding']));

        $sent = $this->lastSent();
        $this->assertSame(config('email888.senders.support.address'), $sent->getFrom()[0]->getAddress());
        $this->assertSame('outbound', $sent->getHeaders()->get('X-PM-Message-Stream')->getBodyAsString());
    }

    public function test_a_sender_override_is_honoured_by_key(): void
    {
        $this->dispatcher()->send($this->cmd(['senderIdentity' => 'support']));

        $this->assertSame(
            config('email888.senders.support.address'),
            $this->lastSent()->getFrom()[0]->getAddress()
        );
    }

    public function test_no_internal_header_survives_dispatch(): void
    {
        $this->dispatcher()->send($this->cmd([
            'workspaceId' => 9,
            'actorId'     => 4,
            'replyTo'     => 'support',
        ]));

        $sent = $this->lastSent();
        foreach (OutboundPolicy::INTERNAL_HEADERS as $h) {
            $this->assertFalse($sent->getHeaders()->has($h), "{$h} leaked");
        }

        $row = EmailDelivery::first();
        $this->assertSame(9, $row->workspace_id);
        $this->assertSame(4, $row->user_id);
    }

    public function test_cc_and_bcc_are_carried(): void
    {
        $this->dispatcher()->send($this->cmd([
            'cc'  => ['cc@example.com'],
            'bcc' => ['bcc@example.com'],
        ]));

        $sent = $this->lastSent();
        $this->assertSame('cc@example.com', $sent->getCc()[0]->getAddress());
        $this->assertSame('bcc@example.com', $sent->getBcc()[0]->getAddress());
    }

    public function test_raw_text_becomes_the_body_and_is_not_treated_as_a_view_name(): void
    {
        $this->dispatcher()->send($this->cmd(['text' => 'a distinctive body string']));

        $this->assertStringContainsString('a distinctive body string', $this->lastSent()->getTextBody());
    }

    // ---- idempotency -----------------------------------------------------

    public function test_the_same_idempotency_key_sends_exactly_once(): void
    {
        $first  = $this->dispatcher()->send($this->cmd(['idempotencyKey' => 'invoice-42-reminder']));
        $second = $this->dispatcher()->send($this->cmd(['idempotencyKey' => 'invoice-42-reminder']));

        $this->assertTrue($first->accepted);
        $this->assertFalse($first->deduplicated);

        $this->assertTrue($second->accepted);
        $this->assertTrue($second->deduplicated, 'the second send was not deduplicated');

        $this->assertSame($first->deliveryRecordId, $second->deliveryRecordId);
        $this->assertSame(1, EmailDelivery::count(), 'a duplicate ledger row was created');
        $this->assertCount(1, Mail::getSymfonyTransport()->messages(), 'the message was sent twice');
    }

    public function test_different_idempotency_keys_both_send(): void
    {
        $this->dispatcher()->send($this->cmd(['idempotencyKey' => 'key-a']));
        $this->dispatcher()->send($this->cmd(['idempotencyKey' => 'key-b']));

        $this->assertSame(2, EmailDelivery::count());
        $this->assertCount(2, Mail::getSymfonyTransport()->messages());
    }

    public function test_absent_idempotency_key_does_not_deduplicate(): void
    {
        $this->dispatcher()->send($this->cmd());
        $this->dispatcher()->send($this->cmd());

        $this->assertSame(2, EmailDelivery::count());
    }
}
