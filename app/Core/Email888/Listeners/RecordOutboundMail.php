<?php

namespace App\Core\Email888\Listeners;

use App\Core\Email888\DeliveryLedger;
use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\OutboundPolicy;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * EMAIL888 - instruments every Laravel Mail send in the platform.
 *
 * WHY A LISTENER AND NOT A WRAPPER
 * There are call sites in Auth, Marketing, Booking, Sequences, Notifications
 * and Infrastructure. A wrapper only observes the ones that adopt it, and the
 * ones that never adopt it are precisely the ones that will fail silently. A
 * listener observes all of them from the first deploy, including any a future
 * engine adds without knowing this exists.
 *
 * NOTHING HERE MAY THROW. A failure to record must never become a failure to
 * send, so every path is wrapped and degrades to a log line.
 */
class RecordOutboundMail
{
    /**
     * The ledger row for the send currently in flight in this process.
     *
     * WHY NOT OBJECT IDENTITY
     * The obvious correlation is spl_object_id() on the Symfony Email, since
     * both events carry "the message". They do not carry the same one:
     * Symfony's AbstractTransport::send() clones the message before handing it
     * to the transport, so MessageSent's getOriginalMessage() is a different
     * instance with a different object id. That correlation silently matched
     * nothing, and every row stayed 'queued'.
     *
     * WHY NOT A HEADER
     * A header set in sending() would survive the clone, but it would also
     * survive all the way to the recipient's inbox, which is exactly what the
     * header-stripping below exists to prevent.
     *
     * A single slot is correct because Laravel's send path is synchronous and
     * non-reentrant: sending() always immediately precedes its own sent(). A
     * send that throws leaves a stale value, which the next sending()
     * overwrites before anything can read it.
     */
    private static ?int $current = null;

    public function __construct(private readonly DeliveryLedger $ledger)
    {
    }

    public function sending(MessageSending $event): void
    {
        try {
            $message = $event->message;

            $purpose       = OutboundPolicy::takeHeader($message, OutboundPolicy::HDR_PURPOSE) ?? OutboundPolicy::UNCLASSIFIED;
            $workspaceId   = OutboundPolicy::takeHeader($message, OutboundPolicy::HDR_WORKSPACE);
            $userId        = OutboundPolicy::takeHeader($message, OutboundPolicy::HDR_USER);
            $correlationId = OutboundPolicy::takeHeader($message, OutboundPolicy::HDR_CORRELATION);
            $claimId       = OutboundPolicy::takeHeader($message, OutboundPolicy::HDR_CLAIM);
            $senderKey     = OutboundPolicy::takeHeader($message, OutboundPolicy::HDR_SENDER);
            $replyKey      = OutboundPolicy::takeHeader($message, OutboundPolicy::HDR_REPLY_TO);
            $streamClass   = OutboundPolicy::takeHeader($message, OutboundPolicy::HDR_STREAM);

            // Belt and braces: nothing internal leaves, even a header a future
            // caller adds that this method did not explicitly read.
            OutboundPolicy::stripInternalHeaders($message);

            $policy = $this->applyPolicy($message, $purpose, $senderKey, $replyKey, $streamClass);

            if (! config('email888.ledger_enabled', true)) {
                self::$current = null;

                return;
            }

            $facts = [
                'sender_address'    => $this->firstAddress($message, 'from'),
                'sender_name'       => $this->firstName($message, 'from'),
                'recipient_address' => $this->firstAddress($message, 'to') ?? 'unknown',
                'subject'           => substr((string) $message->getSubject(), 0, 255),
            ];

            // A claim means EmailDispatcher already inserted the row to win the
            // idempotency race. Reuse it - creating a second row here would
            // double-count the same message.
            if ($claimId !== null && ctype_digit($claimId)) {
                $row = EmailDelivery::find((int) $claimId);
                if ($row) {
                    $row->forceFill($facts)->save();
                    self::$current = (int) $row->id;

                    return;
                }
            }

            $row = $this->ledger->open(array_merge($facts, [
                'correlation_id'  => $this->validUuid($correlationId) ?: (string) Str::uuid(),
                'workspace_id'    => is_numeric($workspaceId) ? (int) $workspaceId : null,
                'user_id'         => is_numeric($userId) ? (int) $userId : null,
                'purpose'         => substr($purpose, 0, 64),
                'stream_class'    => $policy['stream_class'] ?? 'transactional',
                'provider_stream' => $policy['provider_stream'] ?? null,
            ]));

            self::$current = $row ? (int) $row->id : null;
        } catch (Throwable $e) {
            Log::warning('email888.listener.sending_failed', ['error' => $e->getMessage()]);
        }
    }

    public function sent(MessageSent $event): void
    {
        try {
            $id = self::$current;
            self::$current = null;

            if ($id === null) {
                return;
            }

            $providerMessageId = null;
            try {
                $providerMessageId = $event->sent->getMessageId();
            } catch (Throwable) {
                // Some transports do not set one. Acceptance still gets recorded.
            }

            $this->ledger->markAccepted($id, $providerMessageId ?: null);
        } catch (Throwable $e) {
            Log::warning('email888.listener.sent_failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Apply sender identity and message stream for a KNOWN purpose.
     *
     * An unclassified message is left completely untouched - same sender, same
     * stream, same behaviour as before this class existed. That is what makes
     * enabling stream enforcement a no-op until a call site opts in.
     */
    private function applyPolicy(
        Email $message,
        string $purpose,
        ?string $senderKey,
        ?string $replyKey,
        ?string $streamClass,
    ): array {
        if ($purpose === OutboundPolicy::UNCLASSIFIED) {
            return [];
        }

        $policy = OutboundPolicy::resolve($purpose, $senderKey, $replyKey, $streamClass);
        if ($policy === null) {
            Log::warning('email888.policy.unknown_purpose', ['purpose' => $purpose]);

            return [];
        }

        $message->from(new \Symfony\Component\Mime\Address(
            $policy['sender']['address'],
            $policy['sender']['name']
        ));

        if ($policy['reply_to']) {
            $message->replyTo(new \Symfony\Component\Mime\Address(
                $policy['reply_to']['address'],
                $policy['reply_to']['name']
            ));
        }

        if (config('email888.enforce_streams', true) && $policy['provider_stream']) {
            $this->setPostmarkStream($message, $policy['provider_stream']);
        }

        return $policy;
    }

    /**
     * The one place a Postmark type name appears outside the connector layer.
     * Guarded by class_exists so a provider swap degrades instead of fataling.
     */
    private function setPostmarkStream(Email $message, string $stream): void
    {
        $class = 'Symfony\\Component\\Mailer\\Bridge\\Postmark\\Transport\\MessageStreamHeader';

        if (! class_exists($class)) {
            Log::warning('email888.policy.stream_header_unavailable', ['stream' => $stream]);

            return;
        }

        $headers = $message->getHeaders();
        $headers->remove('X-PM-Message-Stream');
        $headers->add(new $class($stream));
    }

    private function firstAddress(Email $m, string $which): ?string
    {
        $list = $which === 'from' ? $m->getFrom() : $m->getTo();

        return isset($list[0]) ? $list[0]->getAddress() : null;
    }

    private function firstName(Email $m, string $which): ?string
    {
        $list = $which === 'from' ? $m->getFrom() : $m->getTo();
        $name = isset($list[0]) ? $list[0]->getName() : '';

        return $name !== '' ? $name : null;
    }

    private function validUuid(?string $v): ?string
    {
        return is_string($v) && Str::isUuid($v) ? $v : null;
    }
}
