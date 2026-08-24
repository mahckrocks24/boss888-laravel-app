<?php

namespace App\Core\Email888\Contracts;

/**
 * EMAIL888 EM-8 — the provider boundary for INBOUND events.
 *
 * One implementation per vendor. This is the only place a vendor's payload
 * field names, event vocabulary and error codes are allowed to be known.
 * Everything upstream receives ProviderEvent objects and cannot tell which
 * vendor produced them.
 */
interface WebhookNormalizer
{
    /** The provider key this normalizer speaks for, e.g. 'postmark'. */
    public function provider(): string;

    /**
     * Translate one decoded provider payload into zero or more neutral events.
     *
     * Returning an EMPTY list means "nothing here we could understand", which
     * the caller reports as malformed. An event that is understood but has no
     * ledger effect must be returned with a null state instead — the two are
     * different answers and an operator needs to tell them apart.
     *
     * @param  array<string,mixed> $payload one event object, already decoded
     * @return list<ProviderEvent>
     */
    public function normalize(array $payload): array;

    /**
     * Translate a provider failure into a neutral category.
     *
     * @return array{state:\App\Core\Email888\States\DeliveryState,category:string,retryable:bool}|null
     *         null when this provider recognises nothing in the message, so the
     *         shared fallback applies
     */
    public function classifyFailure(\Throwable $e): ?array;
}
