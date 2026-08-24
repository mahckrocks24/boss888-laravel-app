<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\RawMessage;
use Tests\TestCase;

/**
 * EMAIL888 - regression: correlating MessageSending to MessageSent.
 *
 * THE DEFECT THIS PINS
 * The ledger first correlated the two mail events by object identity on the
 * Symfony Email. That works under Laravel's ArrayTransport, which hands the
 * very same instance to the SentMessage - so the whole suite passed. It does
 * NOT work against any real transport, because Symfony's AbstractTransport
 * clones the message before sending. In production every row therefore stopped
 * at 'queued', no Postmark MessageID was ever captured, and the ledger built to
 * prevent silent loss was itself silently broken.
 *
 * The test transport below extends AbstractTransport for exactly one reason:
 * to inherit that clone. A test that does not clone cannot catch this.
 */
class TransportCloneRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_state_reaches_accepted_against_a_transport_that_clones(): void
    {
        $transport = new class extends AbstractTransport {
            public function __toString(): string
            {
                return 'cloning-test://';
            }

            protected function doSend(SentMessage $message): void
            {
                // Stand in for a provider assigning its own id.
                $message->setMessageId('provider-assigned-id-0001');
            }
        };

        Mail::extend('cloning_test', fn () => $transport);
        config(['mail.mailers.cloning_test' => ['transport' => 'cloning_test'], 'mail.default' => 'cloning_test']);
        Mail::purge('cloning_test');

        Mail::mailer('cloning_test')->raw('body', function ($m) {
            $m->to('someone@example.com')->subject('Clone correlation');
        });

        $row = EmailDelivery::first();

        $this->assertNotNull($row, 'no ledger row was created');
        $this->assertSame(
            DeliveryState::ACCEPTED->value,
            $row->state,
            'the row never advanced past queued - sending/sent correlation is broken again'
        );
        $this->assertSame('provider-assigned-id-0001', $row->provider_message_id);
        $this->assertNotNull($row->accepted_at);
    }

    public function test_the_transport_under_test_really_does_clone(): void
    {
        // Guards the guard. If a future Symfony release stops cloning, the test
        // above would start passing for the wrong reason and stop protecting
        // anything - so assert the precondition explicitly.
        $seen = [];

        $transport = new class($seen) extends AbstractTransport {
            public function __construct(private array &$seen)
            {
                parent::__construct();
            }

            public function __toString(): string
            {
                return 'clone-detect://';
            }

            protected function doSend(SentMessage $message): void
            {
                $this->seen[] = spl_object_id($message->getOriginalMessage());
            }
        };

        $original = null;
        \Illuminate\Support\Facades\Event::listen(
            \Illuminate\Mail\Events\MessageSending::class,
            function ($e) use (&$original) { $original = spl_object_id($e->message); }
        );

        Mail::extend('clone_detect', fn () => $transport);
        config(['mail.mailers.clone_detect' => ['transport' => 'clone_detect'], 'mail.default' => 'clone_detect']);
        Mail::purge('clone_detect');

        Mail::mailer('clone_detect')->raw('body', fn ($m) => $m->to('x@example.com')->subject('s'));

        $this->assertNotNull($original);
        $this->assertNotEmpty($seen);
        $this->assertNotSame(
            $original,
            $seen[0],
            'the transport did not clone - this test can no longer prove what it claims'
        );
    }
}
