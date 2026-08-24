<?php

namespace App\Connectors\Infrastructure\BusinessEmail\Values;

use DateTimeInterface;

/**
 * INFRA888 · E2 — everything the provider says exists for one domain.
 *
 * THE `complete` FLAG IS THE MOST IMPORTANT FIELD HERE.
 *
 * Reconciliation draws two kinds of conclusion: "we have a record the provider
 * does not" (missing) and "the provider has an object we have no record of"
 * (unexpected). BOTH are only valid against a COMPLETE enumeration.
 *
 * If a provider paginates and the adapter stopped early — rate limit, timeout,
 * a page that failed to parse — then an incomplete list makes every unlisted
 * mailbox look missing. Acting on that would mean recreating mailboxes that
 * already exist, or worse, an operator deleting "unexpected" objects that were
 * simply on page two.
 *
 * So an incomplete inventory is not a lesser inventory; it is a different thing.
 * The reconciliation service refuses to draw absence-based conclusions from one
 * and says so explicitly rather than degrading quietly.
 */
final class ProviderInventory
{
    /**
     * @param array<int,RemoteMailbox>   $mailboxes
     * @param array<int,RemoteAlias>     $aliases
     * @param array<int,RemoteForwarder> $forwarders
     * @param bool   $complete  false when the enumeration was truncated for ANY reason
     * @param string $incompleteReason operator-readable; empty when complete
     */
    public function __construct(
        public readonly string $domain,
        public readonly DateTimeInterface $observedAt,
        public readonly array $mailboxes = [],
        public readonly array $aliases = [],
        public readonly array $forwarders = [],
        public readonly ?RemoteCatchAll $catchAll = null,
        public readonly bool $complete = true,
        public readonly string $incompleteReason = '',
    ) {
    }

    public static function partial(
        string $domain,
        DateTimeInterface $observedAt,
        string $reason,
        array $mailboxes = [],
        array $aliases = [],
        array $forwarders = [],
        ?RemoteCatchAll $catchAll = null,
    ): self {
        return new self($domain, $observedAt, $mailboxes, $aliases, $forwarders, $catchAll, false, $reason);
    }

    /** @return array<string,RemoteMailbox> keyed by normalized local part */
    public function mailboxesByLocalPart(): array
    {
        $out = [];

        foreach ($this->mailboxes as $mailbox) {
            $out[$mailbox->normalizedLocalPart()] = $mailbox;
        }

        return $out;
    }

    /** @return array<string,RemoteAlias> keyed by normalized source local part */
    public function aliasesBySourceLocalPart(): array
    {
        $out = [];

        foreach ($this->aliases as $alias) {
            $out[$alias->normalizedSourceLocalPart()] = $alias;
        }

        return $out;
    }

    /**
     * Forwarders are keyed by source AND destination: one source may legitimately
     * fan out to several destinations, so the source alone is not an identity.
     *
     * @return array<string,RemoteForwarder>
     */
    public function forwardersByRoute(): array
    {
        $out = [];

        foreach ($this->forwarders as $forwarder) {
            $key = $forwarder->normalizedSourceLocalPart() . '->' . (string) $forwarder->normalizedDestination();
            $out[$key] = $forwarder;
        }

        return $out;
    }

    /**
     * Provider references that appear more than once. A provider returning the
     * same object identity twice means either a provider defect or a paging bug
     * in the adapter; either way, binding to it would be unsafe.
     *
     * @return array<int,string>
     */
    public function duplicateProviderRefs(): array
    {
        $seen = [];

        foreach ([...$this->mailboxes, ...$this->aliases, ...$this->forwarders] as $object) {
            $ref = $object->providerRef;
            $seen[$ref] = ($seen[$ref] ?? 0) + 1;
        }

        return array_keys(array_filter($seen, fn (int $count) => $count > 1));
    }

    public function objectCount(): int
    {
        return count($this->mailboxes) + count($this->aliases) + count($this->forwarders);
    }

    /** Operator surface only — it is full of provider references. */
    public function toAdminArray(): array
    {
        return [
            'domain'            => $this->domain,
            'observed_at'       => $this->observedAt->format(DATE_ATOM),
            'complete'          => $this->complete,
            'incomplete_reason' => $this->incompleteReason,
            'mailboxes'         => array_map(fn (RemoteMailbox $m) => $m->toAdminArray(), $this->mailboxes),
            'aliases'           => array_map(fn (RemoteAlias $a) => $a->toAdminArray(), $this->aliases),
            'forwarders'        => array_map(fn (RemoteForwarder $f) => $f->toAdminArray(), $this->forwarders),
            'catchall'          => $this->catchAll?->toAdminArray(),
        ];
    }
}
