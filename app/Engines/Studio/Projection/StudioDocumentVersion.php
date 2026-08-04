<?php

namespace App\Engines\Studio\Projection;

use App\Engines\Studio\Projection\Support\ArrayGuard;

/**
 * STUDIO888 · Projection — renderer-neutral document version token.
 *
 * An opaque version token used for optimistic concurrency. It carries no
 * renderer semantics — a renderer may back it with a revision counter, an ETag,
 * a CRDT vector, or a hash. Callers only compare tokens for equality.
 */
final class StudioDocumentVersion
{
    public const SCHEMA_VERSION = 1;

    public function __construct(public readonly string $token)
    {
    }

    public static function of(string $token): self
    {
        return new self($token);
    }

    public function equals(self $other): bool
    {
        return $this->token === $other->token;
    }

    public function toArray(): array
    {
        return ['schema_version' => self::SCHEMA_VERSION, 'token' => $this->token];
    }

    public static function fromArray(array $a): self
    {
        ArrayGuard::requireSchema($a, self::SCHEMA_VERSION);

        return new self(ArrayGuard::str($a, 'token'));
    }
}
