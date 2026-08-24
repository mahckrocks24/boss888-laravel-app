<?php

namespace App\Engines\Infrastructure\Email\Customer;

use App\Engines\Infrastructure\Models\InfraEvent;

/**
 * INFRA888 · E4 — the customer-safe timeline.
 *
 * ─── AN ALLOWLIST, NOT A FILTER ─────────────────────────────────────────────
 *
 * The obvious implementation reads `infra_events` and strips the unsafe fields.
 * That is the wrong shape: it fails OPEN. A new event type added by E5 or E9
 * would appear on a customer's screen with whatever payload it happened to
 * carry, and nobody would notice until it named a vendor.
 *
 * So this is an allowlist of event keys, each with its own customer wording.
 * An event not on the list does not appear. Adding one is a deliberate edit
 * here, which is exactly the friction that keeps this surface safe.
 *
 * ─── AND THE PAYLOAD NEVER TRAVELS ──────────────────────────────────────────
 *
 * The wording is written HERE, from the event key alone. `context_json` is
 * never read — it carries idempotency keys, failure codes, capability slugs and
 * actor diagnostics, and there is no version of "sanitise it" that stays
 * correct as new writers are added.
 */
final class CustomerTimeline
{
    /**
     * event key => [customer wording, tone]
     *
     * @return array<string,array{message:string,tone:string}>
     */
    public static function allowed(): array
    {
        return [
            'email.domain.onboarded' => [
                'message' => 'Business Email setup started for this domain.',
                'tone'    => 'progress',
            ],
            'email.domain.verification_observed' => [
                'message' => 'We checked your DNS records.',
                'tone'    => 'progress',
            ],
            'email.mailbox.created' => [
                'message' => 'Mailbox requested.',
                'tone'    => 'progress',
            ],
            'email.mailbox.updated' => [
                'message' => 'Mailbox settings updated.',
                'tone'    => 'neutral',
            ],
            'email.mailbox.suspended' => [
                'message' => 'Mailbox paused.',
                'tone'    => 'attention',
            ],
            'email.mailbox.restored' => [
                'message' => 'Mailbox resumed.',
                'tone'    => 'good',
            ],
            'email.mailbox.deleted' => [
                'message' => 'Mailbox removed.',
                'tone'    => 'attention',
            ],
            'email.mailbox.password_reset_requested' => [
                // No hint that a password exists to be shown.
                'message' => 'Password reset requested.',
                'tone'    => 'neutral',
            ],
            'email.alias.created' => [
                'message' => 'Alias created.',
                'tone'    => 'good',
            ],
            'email.alias.deleted' => [
                'message' => 'Alias removed.',
                'tone'    => 'neutral',
            ],
            'email.forwarder.created' => [
                'message' => 'Forwarding rule created.',
                'tone'    => 'good',
            ],
            'email.forwarder.deleted' => [
                'message' => 'Forwarding rule removed.',
                'tone'    => 'neutral',
            ],
            'email.catchall.configured' => [
                'message' => 'Catch-all updated.',
                'tone'    => 'neutral',
            ],
            'email.catchall.cleared' => [
                'message' => 'Catch-all turned off.',
                'tone'    => 'neutral',
            ],
            'email.confirmed' => [
                'message' => 'Change confirmed.',
                'tone'    => 'good',
            ],
        ];
    }

    /**
     * Events that exist but must NEVER reach a customer, listed explicitly so
     * the omission is visible rather than accidental.
     *
     * @return array<int,string>
     */
    public static function withheld(): array
    {
        return [
            // Operator diagnostics: failure codes, capability slugs, provider
            // correlation ids.
            'email.request_denied',
            'email.operation_unavailable',
            'email.operation_failed',
            'email.operation_accepted',
            'email.operation_ambiguous',
            'email.confirmation_failed',
            // Operator decisions and their reasons.
            'email.admin_action',
            'email.manual_review_resolved',
            // Observation internals.
            'email.health.observed',
            'email.usage.sampled',
        ];
    }

    public static function isAllowed(string $event): bool
    {
        return array_key_exists($event, self::allowed());
    }

    /**
     * Project a set of infrastructure events into the customer timeline.
     *
     * Only the event key, its own wording and the timestamp survive. Nothing is
     * read from the stored payload.
     *
     * @param  iterable<InfraEvent> $events
     * @return array<int,array{at:?string,message:string,tone:string}>
     */
    public static function project(iterable $events): array
    {
        $allowed = self::allowed();
        $out = [];

        foreach ($events as $event) {
            $key = (string) $event->event;

            if (! isset($allowed[$key])) {
                continue;
            }

            $out[] = [
                'at'      => optional($event->created_at)->toIso8601String(),
                'message' => $allowed[$key]['message'],
                'tone'    => $allowed[$key]['tone'],
            ];
        }

        return $out;
    }
}
