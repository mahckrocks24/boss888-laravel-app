<?php

namespace App\Core\Email888\Console;

use App\Core\Email888\DeliveryLedger;
use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\OutboundPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

/**
 * EMAIL888 - end-to-end outbound probe.
 *
 * Sends one real message through the real policy and the real transport, then
 * WAITS for the provider webhook to drive the ledger to a terminal state.
 *
 * This is the command that answers "is outbound mail actually working right
 * now?" without anyone opening a provider dashboard or reading a log by hand.
 * It is also the gate check: a production gate may only open when this reports
 * DELIVERED against a LevelUp-owned address.
 *
 * It proves the whole chain in one run:
 *   policy -> sender -> stream -> transport -> provider -> webhook -> ledger
 */
class ProbeDelivery extends Command
{
    protected $signature = 'email888:probe
                            {--to=          : Recipient. Required.}
                            {--purpose=platform_diagnostic : Registered purpose to send under}
                            {--wait=180     : Seconds to wait for a terminal state}';

    protected $description = 'EMAIL888: send one probe message and follow it to a terminal delivery state.';

    public function handle(DeliveryLedger $ledger): int
    {
        $to = (string) $this->option('to');

        if ($to === '' || ! filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $this->error('--to must be a valid email address.');

            return self::FAILURE;
        }

        $purpose = (string) $this->option('purpose');

        if (! OutboundPolicy::isKnownPurpose($purpose)) {
            $this->error("Unknown purpose '{$purpose}'. Registered: " . implode(', ', array_keys(config('email888.purposes'))));

            return self::FAILURE;
        }

        $policy        = OutboundPolicy::resolve($purpose);
        $correlationId = (string) Str::uuid();
        $stamp         = now()->toIso8601String();
        $subject       = 'LevelUp Growth delivery probe';

        $this->line('purpose        : ' . $purpose);
        $this->line('sender         : ' . $policy['sender']['address']);
        $this->line('stream class   : ' . $policy['stream_class'] . '  (provider: ' . $policy['provider_stream'] . ')');
        $this->line('recipient      : ' . $to);
        $this->line('correlation    : ' . $correlationId);

        $body = "LevelUp Growth outbound delivery probe.\n\n"
              . "correlation: {$correlationId}\n"
              . "timestamp:   {$stamp}\n\n"
              . "No action is required.";

        $t0 = microtime(true);

        try {
            Mail::raw($body, function ($m) use ($to, $subject, $purpose, $correlationId) {
                $m->to($to)->subject($subject);
                $h = $m->getSymfonyMessage()->getHeaders();
                $h->addTextHeader(OutboundPolicy::HDR_PURPOSE, $purpose);
                $h->addTextHeader(OutboundPolicy::HDR_CORRELATION, $correlationId);
            });
        } catch (Throwable $e) {
            $id = DeliveryLedger::lastRecordedId();
            if ($id) {
                $verdict = \App\Core\Email888\FailureClassifier::classify($e);
                $ledger->markFailed($id, $verdict['category'], $verdict['retryable'], [
                    'exception' => class_basename($e),
                    'message'   => substr($e->getMessage(), 0, 300),
                ], $verdict['state']);
                $this->line('classified as : ' . $verdict['state']->value . ' / ' . $verdict['category']
                    . ' (retryable: ' . var_export($verdict['retryable'], true) . ')');
            }
            $this->error('transport refused: ' . $e->getMessage());
            $this->line('ledger row     : ' . ($id ?? 'none'));

            return self::FAILURE;
        } finally {
            DeliveryLedger::forgetLastRecorded();
        }

        $this->line(sprintf('handoff        : %.0f ms', (microtime(true) - $t0) * 1000));

        $row = EmailDelivery::where('correlation_id', $correlationId)->first();

        if (! $row) {
            $this->error('no ledger row was created - the listener is not registered.');

            return self::FAILURE;
        }

        $this->line('ledger row     : #' . $row->id);
        $this->line('provider msgid : ' . ($row->provider_message_id ?: 'NOT CAPTURED'));
        $this->line('state          : ' . $row->state);

        // Now the part that matters: acceptance is not delivery, so wait for
        // the provider to tell us what actually happened.
        $deadline = time() + (int) $this->option('wait');
        $this->newLine();
        $this->line('waiting for a terminal state (webhook-driven)...');

        while (time() < $deadline) {
            sleep(5);
            $row->refresh();

            if ($row->deliveryState()->isTerminal()) {
                $this->newLine();
                $this->line('TERMINAL STATE : ' . strtoupper($row->state));
                $this->line('delivered_at   : ' . (optional($row->delivered_at)->toIso8601String() ?: '-'));
                $this->line('failure        : ' . ($row->failure_category ?: '-'));
                $this->line('evidence       : ' . json_encode($row->provider_response));
                $this->line('events         : ' . $row->events()->count());

                return $row->deliveryState()->isSuccess() ? self::SUCCESS : self::FAILURE;
            }

            $this->getOutput()->write('.');
        }

        $this->newLine();
        $this->warn('no terminal state within the wait window. Current state: ' . $row->state);
        $this->warn('If the state is "accepted", the provider took it but no webhook arrived -');
        $this->warn('check that the webhook URL is reachable from the public internet.');

        return self::FAILURE;
    }
}
