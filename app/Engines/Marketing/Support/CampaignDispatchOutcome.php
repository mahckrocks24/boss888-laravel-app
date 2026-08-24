<?php

namespace App\Engines\Marketing\Support;

/**
 * EM-7 — the ONE campaign state contract.
 *
 * Two services send campaigns: MarketingService (flat recipient list) and
 * EmailBuilderService (templated, tracked, per-recipient log rows). Before this
 * existed they each computed their own aggregate, and each independently wrote
 * `status = 'sent'` unconditionally at the end of the loop — the same defect,
 * twice, discovered months apart. Recipient handling is legitimately different
 * between them; what a campaign's OUTCOME means is not.
 *
 * TWO DISTINCTIONS THIS TYPE EXISTS TO PROTECT
 *
 *   Loop completion is not success.
 *     A status is derived from recorded dispatch outcomes. There is deliberately
 *     no setter for "sent" — it can only be computed from what happened.
 *
 *   Acceptance is not delivery.
 *     `sent` here means the provider took responsibility for every message. It
 *     says nothing about anyone receiving them. `delivered` is therefore always
 *     0 in these stats, with its real source named, because only a terminal
 *     provider event in the Email888 ledger can establish delivery.
 *
 * A duplicate counts as dispatched and NOT as a failure: idempotency returning
 * an earlier send means the recipient has the message.
 */
final class CampaignDispatchOutcome
{
    private int $accepted  = 0;
    private int $duplicate = 0;
    private int $refused   = 0;

    /** @var list<array{recipient:string,reason:string}> */
    private array $failures = [];

    public function accepted(): void
    {
        $this->accepted++;
    }

    public function duplicated(): void
    {
        $this->duplicate++;
    }

    /** A refusal is always NAMED. A bare count cannot be diagnosed. */
    public function refused(string $recipient, string $reason): void
    {
        $this->refused++;
        $this->failures[] = ['recipient' => $recipient, 'reason' => $reason];
    }

    public function dispatched(): int
    {
        return $this->accepted + $this->duplicate;
    }

    public function refusedCount(): int
    {
        return $this->refused;
    }

    public function acceptedCount(): int
    {
        return $this->accepted;
    }

    public function duplicateCount(): int
    {
        return $this->duplicate;
    }

    /**
     * The only way a campaign status is produced.
     *
     * failed  — nothing reached the provider at all
     * partial — some recipients reached it and some did not
     * sent    — every recipient reached it (dispatch complete, NOT delivered)
     */
    public function status(): string
    {
        return match (true) {
            $this->dispatched() === 0 => 'failed',
            $this->refused > 0        => 'partial',
            default                   => 'sent',
        };
    }

    /** Null when nothing was dispatched: there is no time at which it was sent. */
    public function sentAt(): ?\Illuminate\Support\Carbon
    {
        return $this->dispatched() > 0 ? now() : null;
    }

    /** @return list<array{recipient:string,reason:string}> */
    public function failures(int $limit = 10): array
    {
        return array_slice($this->failures, 0, $limit);
    }

    /**
     * @return array<string,mixed> ledger-shaped campaign stats
     */
    public function stats(int $totalRecipients): array
    {
        return [
            'recipients' => $totalRecipients,
            'accepted'   => $this->accepted,
            'duplicate'  => $this->duplicate,
            'refused'    => $this->refused,
            'sent'       => $this->dispatched(),

            // Not knowable at dispatch. Whoever renders this must read the
            // ledger, not this number, to answer "did it arrive?".
            'delivered'  => 0,
            'opened'     => 0,
            'clicked'    => 0,
            'bounced'    => 0,
            'delivered_source' => 'email888_delivery_ledger',

            'failures'   => $this->failures(),
        ];
    }

    /**
     * Caller-facing summary. Legacy keys `sent`/`failed`/`total` are preserved
     * so existing consumers keep working; the richer keys are additive.
     *
     * @return array<string,mixed>
     */
    public function toArray(int $totalRecipients): array
    {
        return [
            'sent'      => $this->dispatched(),
            'failed'    => $this->refused,
            'total'     => $totalRecipients,
            'status'    => $this->status(),
            'accepted'  => $this->accepted,
            'duplicate' => $this->duplicate,
            'failures'  => $this->failures(),
        ];
    }
}
