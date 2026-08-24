<?php

namespace App\Engines\Marketing\Support;

/**
 * EM-7 PHASE 12 — the canonical campaign dispatch input.
 *
 * Everything the queue needs to reproduce exactly the messages the synchronous
 * path would have produced, and nothing else. It carries no provider, no sender
 * address and no stream: those are Email888's to decide from the PURPOSE, and a
 * campaign plan that named them would be the second architecture growing back
 * in a new shape.
 *
 * Marketing owns what is here — campaign, audience, personalisation, content
 * intent. Email888 owns what is deliberately absent.
 */
final class CampaignDispatchPlan
{
    /** @param list<CampaignRecipient> $recipients */
    public function __construct(
        public readonly int $campaignId,
        public readonly int $workspaceId,
        public readonly string $subject,
        /** Templated shape when set; body_html shape when null. */
        public readonly ?int $templateId,
        public readonly array $recipients,
    ) {
    }

    public function isTemplated(): bool
    {
        return $this->templateId !== null;
    }

    public function count(): int
    {
        return count($this->recipients);
    }

    /**
     * Deterministic per-recipient idempotency, shared by BOTH campaign paths.
     *
     * Workspace + campaign + recipient. The same person, in the same campaign,
     * resolves to the same key however the send was triggered — synchronously,
     * from the queue, or from a retry after a crash — so the dispatcher's unique
     * index turns a re-run into `duplicate` instead of a second email.
     *
     * Hashed, never the raw address: the key is stored on a ledger row and shown
     * on an admin screen, and a recipient's address does not need to be in two
     * places.
     */
    public function idempotencyKeyFor(string $address): string
    {
        return sprintf(
            'cmp-%d-%d-%s',
            $this->workspaceId,
            $this->campaignId,
            substr(hash('sha256', strtolower(trim($address))), 0, 32),
        );
    }

    /** Comparable form. Two plans that are equal here produce identical mail. */
    public function toArray(): array
    {
        return [
            'campaign_id' => $this->campaignId,
            'workspace_id' => $this->workspaceId,
            'subject'     => $this->subject,
            'template_id' => $this->templateId,
            'recipients'  => array_map(fn (CampaignRecipient $r) => $r->toArray(), $this->recipients),
        ];
    }
}
