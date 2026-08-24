<?php

namespace Tests\Fakes\Email888;

use App\Core\Email888\Contracts\ProviderEvent;
use App\Core\Email888\Contracts\WebhookNormalizer;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * EM-8 — a deliberately ADVERSARIAL second provider.
 *
 * It lives in tests/ and is registered only by a test, so it can never be one
 * environment variable away from production — the rule E2 established when a
 * fake provider reachable from configuration was rejected.
 *
 * IT DOES NOT IMITATE POSTMARK, ON PURPOSE.
 * A fake that mirrors the incumbent's payload proves only that the code can
 * read the incumbent's payload. Every shape here is different:
 *
 *   field names     kind / ref / at / to / note      (not RecordType / MessageID)
 *   event names     msg.delivered, msg.rejected.hard (not Delivery, Bounce)
 *   timestamps      unix epoch INTEGERS              (not ISO-8601 strings)
 *   message ids     AM-XXXXXX                        (not a UUID)
 *   error codes     AMX-nnn                          (not "error code: nnn")
 *   batching        {"events":[...]}                 (not a bare JSON array)
 *
 * If Email888 can normalise this into the same neutral events, the boundary is
 * real. If anything upstream had to change to accommodate it, it was not.
 */
final class CertificationMailNormalizer implements WebhookNormalizer
{
    public const PROVIDER = 'certification-mail';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function normalize(array $payload): array
    {
        // This vendor wraps a batch in an envelope rather than sending an array.
        $events = isset($payload['events']) && is_array($payload['events'])
            ? $payload['events']
            : [$payload];

        $out = [];

        foreach ($events as $e) {
            if (! is_array($e) || ! isset($e['kind'])) {
                continue;   // unparseable -> caller reports malformed
            }

            $kind = (string) $e['kind'];
            $ref  = isset($e['ref']) && is_string($e['ref']) && $e['ref'] !== '' ? $e['ref'] : null;

            $out[] = new ProviderEvent(
                provider:          $this->provider(),
                providerMessageId: $ref,
                state:             $this->state($kind),
                rawEventType:      $kind,
                occurredAt:        isset($e['at']) && is_numeric($e['at'])
                                      ? Carbon::createFromTimestampUTC((int) $e['at'])
                                      : null,
                recipient:         isset($e['to']) && is_string($e['to']) ? $e['to'] : null,
                evidence:          $this->evidence($e),
            );
        }

        return $out;
    }

    private function state(string $kind): ?DeliveryState
    {
        return match ($kind) {
            'msg.delivered'      => DeliveryState::DELIVERED,
            'msg.rejected.hard'  => DeliveryState::BOUNCED,
            'msg.rejected.soft'  => DeliveryState::DEFERRED,
            'msg.blocked'        => DeliveryState::SUPPRESSED,
            // Understood, deliberately not actionable - the same distinction
            // Postmark's SubscriptionChange-without-suppression carries.
            'msg.opened',
            'msg.clicked'        => null,
            default              => null,
        };
    }

    private function evidence(array $e): array
    {
        $out = [];
        foreach (['kind', 'to', 'note', 'code', 'attempt'] as $k) {
            if (array_key_exists($k, $e) && (is_scalar($e[$k]) || $e[$k] === null)) {
                $out[$k] = $e[$k];
            }
        }

        return $out;
    }

    public function classifyFailure(Throwable $e): ?array
    {
        $msg = strtolower($e->getMessage());

        return match (true) {
            str_contains($msg, 'amx-552') => ['state' => DeliveryState::SUPPRESSED, 'category' => 'recipient_suppressed', 'retryable' => false],
            str_contains($msg, 'amx-550') => ['state' => DeliveryState::FAILED, 'category' => 'invalid_recipient', 'retryable' => false],
            str_contains($msg, 'amx-401') => ['state' => DeliveryState::FAILED, 'category' => 'provider_auth', 'retryable' => false],
            str_contains($msg, 'amx-429') => ['state' => DeliveryState::DEFERRED, 'category' => 'rate_limited', 'retryable' => true],
            default                       => null,
        };
    }
}
