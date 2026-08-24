<?php

namespace App\Jobs;

use App\Core\Email888\DeliveryLedger;
use App\Core\Email888\OutboundPolicy;
use App\Core\Email888\States\DeliveryState;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * Queued job that emails a notification to its target user.
 *
 * WHAT CHANGED AND WHY (EMAIL888, 2026-08-11)
 *
 * This job used to catch every failure, log it at ERROR, and return normally.
 * The queue therefore recorded a success, emailed_at stayed null, and nothing
 * reached an operator. Thirty-seven notifications to a suppressed address were
 * lost that way before anyone noticed — and only then because someone went
 * looking in laravel.log by hand.
 *
 * The contract now distinguishes two kinds of failure:
 *
 *   PERMANENT   the recipient is suppressed, inactive or invalid. Retrying
 *               cannot help, so the job does not fail the queue entry — but it
 *               writes a terminal state to the delivery ledger, where an
 *               operator can see it. Silence is no longer an option.
 *
 *   TRANSIENT   the provider or the network misbehaved. The job rethrows so the
 *               queue retries it, and eventually surfaces it in failed_jobs.
 *
 * emailed_at now means EXACTLY ONE THING: the provider accepted the message at
 * that time. It is not proof of delivery and must never be read as such — the
 * email_deliveries row is the authority on what actually happened.
 */
class SendNotificationEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Transient provider trouble deserves a second chance; a suppressed
     * recipient never reaches a retry because it is classified out first.
     */
    public int $tries = 3;

    /** @return array<int,int> */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function __construct(public int $notificationId) {}

    public function handle(DeliveryLedger $ledger): void
    {
        $n = Notification::find($this->notificationId);
        if (! $n) return;

        $user = User::find($n->user_id);
        if (! $user || ! $user->email) return;

        if ($n->emailed_at) return; // idempotency

        // Two workers can both pass the emailed_at check on a duplicate
        // dispatch. The lock makes the send-once guarantee real rather than
        // merely likely, and costs nothing when there is no contention.
        $lock = Cache::lock('email888:notification:' . $n->id, 120);

        if (! $lock->get()) {
            Log::info('email888.notification.already_in_flight', ['notification_id' => $n->id]);

            return;
        }

        $correlationId = (string) Str::uuid();

        try {
            $n->refresh();
            if ($n->emailed_at) return;

            Mail::send(
                'emails.notification',
                ['notification' => $n, 'user' => $user],
                function ($m) use ($user, $n, $correlationId) {
                    $m->to($user->email)
                      ->subject($n->title);

                    // Sender and message stream now come from the purpose
                    // registry, not from this call site. Email888 reads these
                    // headers and strips them before the message leaves.
                    $h = $m->getSymfonyMessage()->getHeaders();
                    $h->addTextHeader(OutboundPolicy::HDR_PURPOSE, 'notification');
                    $h->addTextHeader(OutboundPolicy::HDR_CORRELATION, $correlationId);
                    if ($n->workspace_id) {
                        $h->addTextHeader(OutboundPolicy::HDR_WORKSPACE, (string) $n->workspace_id);
                    }
                    $h->addTextHeader(OutboundPolicy::HDR_USER, (string) $user->id);
                }
            );

            $n->update(['emailed_at' => now()]);
        } catch (Throwable $e) {
            $verdict   = \App\Core\Email888\FailureClassifier::classify($e);
            $state     = $verdict["state"];
            $category  = $verdict["category"];
            $retryable = $verdict["retryable"];

            // The ledger row was opened by the Email888 listener before the
            // transport was called, so there is something to attribute this to.
            $deliveryId = DeliveryLedger::lastRecordedId();
            if ($deliveryId !== null) {
                $ledger->markFailed($deliveryId, $category, $retryable, [
                    'exception' => class_basename($e),
                    'message'   => substr($e->getMessage(), 0, 400),
                ], $state);
            }

            Log::error('SendNotificationEmail failed', [
                'notification_id' => $this->notificationId,
                'correlation_id'  => $correlationId,
                'delivery_id'     => $deliveryId,
                'category'        => $category,
                'retryable'       => $retryable,
                'error'           => $e->getMessage(),
            ]);

            if ($retryable) {
                // Let the queue own it. Three attempts, then failed_jobs —
                // visible, unlike the old silent return.
                throw $e;
            }

            // Permanent. Retrying would only repeat the refusal. The ledger row
            // carries the terminal state, so this is recorded, not swallowed.
        } finally {
            DeliveryLedger::forgetLastRecorded();
            $lock->release();
        }
    }

}
