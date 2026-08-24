<?php

namespace App\Core\Email888;

use App\Core\Email888\States\DeliveryState;
use Throwable;

/**
 * EMAIL888 - why a hand-off failed, and whether trying again could help.
 *
 * One implementation, shared by every caller. Two copies of this logic would
 * drift, and the copy that drifted would be the one that decided to retry a
 * permanently suppressed address forever.
 *
 * UNRECOGNISED MEANS RETRYABLE. A spurious retry costs a few seconds; treating
 * a transient outage as permanent silently abandons real customer mail, which
 * is the failure mode this whole subsystem exists to end.
 */
final class FailureClassifier
{
    /** @return array{state:DeliveryState,category:string,retryable:bool} */
    public static function classify(Throwable $e, ?string $provider = null): array
    {
        // EM-8: the PROVIDER gets first refusal on its own error codes. They
        // used to live here, in shared code, where a second vendor's codes
        // would have had to be interleaved with Postmark's - and the first
        // collision would have misclassified real customer mail.
        $key = $provider ?? \App\Core\Email888\OutboundPolicy::provider();

        try {
            $adapter = app(\App\Core\Email888\Providers\WebhookNormalizerRegistry::class)->for($key);
            if ($adapter !== null && ($verdict = $adapter->classifyFailure($e)) !== null) {
                return $verdict;
            }
        } catch (Throwable) {
            // A registry problem must never stop a message being classified.
        }

        // Below: vendor-NEUTRAL heuristics only. These read the plain language
        // most transports use, and are the shared fallback for any provider.
        $msg = strtolower($e->getMessage());

        // Postmark 406: the recipient is on a suppression list. Sending again
        // produces the identical refusal.
        if (str_contains($msg, 'inactive')
            || str_contains($msg, 'suppress')) {
            return [
                'state'     => DeliveryState::SUPPRESSED,
                'category'  => 'recipient_suppressed',
                'retryable' => false,
            ];
        }

        // Postmark 300: the address is not a valid address at all.
        if (str_contains($msg, 'invalid email')
            || str_contains($msg, 'not a valid email')) {
            return [
                'state'     => DeliveryState::FAILED,
                'category'  => 'invalid_recipient',
                'retryable' => false,
            ];
        }

        // Postmark 400/401/10: our own credential or payload is wrong. Retrying
        // will not fix a bad token, and hammering it looks like an attack.
        if (str_contains($msg, 'unauthorized')
            || str_contains($msg, 'invalid api token')) {
            return [
                'state'     => DeliveryState::FAILED,
                'category'  => 'provider_auth',
                'retryable' => false,
            ];
        }

        if (str_contains($msg, 'not allowed to send')) {
            return [
                'state'     => DeliveryState::FAILED,
                'category'  => 'sender_not_permitted',
                'retryable' => false,
            ];
        }

        return [
            'state'     => DeliveryState::FAILED,
            'category'  => 'transport_error',
            'retryable' => true,
        ];
    }
}
