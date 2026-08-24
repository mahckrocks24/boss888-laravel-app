<?php

namespace App\Connectors\Infrastructure\BusinessEmail;

use InvalidArgumentException;

/**
 * INFRA888 · E2 — what one configured provider can actually do.
 *
 * Immutable, local, and makes no call. That is deliberate: `supports()` is
 * consulted on every request and by future UIs to decide whether to render an
 * action at all. If answering it required a provider round-trip, every
 * permission check would become a network call and an outage would make the
 * product look like it had lost features.
 *
 * A provider that genuinely needs to discover its own capabilities at runtime
 * caches them in its adapter and returns a set built from that cache. The
 * contract does not care how the set was produced; it cares that asking is free.
 */
final class EmailProviderCapabilitySet
{
    /** @var array<string,bool> */
    private array $flags;

    /**
     * @param array<int,string> $supported capability names from EmailProviderCapability
     *
     * @throws InvalidArgumentException on an undeclared capability name
     */
    public function __construct(array $supported)
    {
        $flags = [];

        foreach (EmailProviderCapability::all() as $capability) {
            $flags[$capability] = false;
        }

        foreach ($supported as $capability) {
            EmailProviderCapability::assertKnown($capability);
            $flags[$capability] = true;
        }

        $this->flags = $flags;
    }

    /** Everything the contract defines. For adapters that genuinely do it all. */
    public static function full(): self
    {
        return new self(EmailProviderCapability::all());
    }

    /** The minimum viable provider: required capabilities only. */
    public static function minimal(): self
    {
        return new self(EmailProviderCapability::required());
    }

    public function supports(string $capability): bool
    {
        EmailProviderCapability::assertKnown($capability);

        return $this->flags[$capability];
    }

    /** @return array<int,string> */
    public function supported(): array
    {
        return array_keys(array_filter($this->flags));
    }

    /** @return array<int,string> */
    public function unsupported(): array
    {
        return array_keys(array_filter($this->flags, fn (bool $on) => ! $on));
    }

    /**
     * Required capabilities this provider lacks. A non-empty result means the
     * provider cannot deliver Business Email and must not be activated.
     *
     * @return array<int,string>
     */
    public function missingRequired(): array
    {
        return array_values(array_filter(
            EmailProviderCapability::required(),
            fn (string $capability) => ! $this->flags[$capability]
        ));
    }

    public function isViable(): bool
    {
        return $this->missingRequired() === [];
    }

    /** Safe for an operator surface. Never customer-facing: it exposes provider limits. */
    public function toArray(): array
    {
        return [
            'supported'        => $this->supported(),
            'unsupported'      => $this->unsupported(),
            'missing_required' => $this->missingRequired(),
            'viable'           => $this->isViable(),
        ];
    }

    public function withOut(string ...$capabilities): self
    {
        return new self(array_values(array_diff($this->supported(), $capabilities)));
    }

    public function with(string ...$capabilities): self
    {
        return new self(array_values(array_unique(array_merge($this->supported(), $capabilities))));
    }
}
