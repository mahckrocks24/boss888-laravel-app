<?php

namespace App\Connectors\Infrastructure\BusinessEmail;

use LogicException;

/**
 * INFRA888 · E7.3 — a secret that is structurally hard to leak.
 *
 * WHY A WRAPPER RATHER THAN A STRING
 *
 * A plain string is leaked by accident, not by malice: it gets var_dumped into
 * a log, serialised into a queue payload, JSON-encoded into an operation
 * record, or captured by Sentry inside an exception trace. Every one of those
 * is a default behaviour of some subsystem, and a redaction policy only helps
 * if it runs BEFORE the value reaches the subsystem.
 *
 * So this object makes the wrong thing impossible rather than discouraged:
 *
 *   · __toString() throws — it cannot be concatenated into a log line
 *   · __debugInfo() returns a placeholder — var_dump and Sentry see nothing
 *   · jsonSerialize() returns a placeholder — it cannot ride in a JSON payload
 *   · __serialize() throws — it cannot enter a queue, a cache or a session
 *   · __clone() throws — no quiet second copy
 *   · reveal() is single-use and greppable — the one deliberate door
 *
 * After reveal() the value is overwritten and gone. A second call throws, so a
 * retry cannot resend a password that was already used.
 */
final class SecretString implements \JsonSerializable
{
    private const PLACEHOLDER = '[redacted]';

    private ?string $value;

    private bool $revealed = false;

    private function __construct(string $value)
    {
        $this->value = $value;
    }

    /**
     * Wrap a value that arrived from outside — a customer's chosen password.
     */
    public static function fromInput(#[\SensitiveParameter] string $value): self
    {
        return new self($value);
    }

    /**
     * A bootstrap credential nobody will ever see.
     *
     * Its only purpose is satisfying a provider that refuses to create a
     * mailbox without one. 32 bytes from the CSPRNG, rendered in a character
     * set every provider accepts, then discarded. Because it is never
     * disclosed, the mailbox it creates cannot be signed into by anyone —
     * including us — until the customer sets their own password.
     */
    public static function bootstrap(): self
    {
        // 32 random bytes ≈ 256 bits. base64 then trimmed of padding, with a
        // fixed prefix guaranteeing upper, lower, digit and symbol so that a
        // provider-side complexity rule can never reject it.
        $body = rtrim(strtr(base64_encode(random_bytes(32)), '+/', 'Az'), '=');

        return new self('Lg1!' . $body);
    }

    /**
     * The one deliberate door out.
     *
     * Single use: the value is overwritten immediately, so it cannot be read
     * twice, retried, or captured by a later handler. Greppable by design —
     * every call site should be obvious in review.
     *
     * @throws LogicException on a second read
     */
    public function reveal(): string
    {
        if ($this->revealed || $this->value === null) {
            throw new LogicException(
                'SecretString: already revealed. A secret is read once and never replayed.'
            );
        }

        $value = $this->value;

        $this->revealed = true;
        $this->value = null;

        return $value;
    }

    /** Length only — enough to validate, never enough to reconstruct. */
    public function length(): int
    {
        return $this->value === null ? 0 : strlen($this->value);
    }

    public function isSpent(): bool
    {
        return $this->revealed || $this->value === null;
    }

    // ── the doors that are nailed shut ──────────────────────────────────────

    public function __toString(): string
    {
        throw new LogicException(
            'SecretString: refusing to become a string. Use reveal() at the single '
            . 'point where the value is transmitted.'
        );
    }

    public function __debugInfo(): array
    {
        // var_dump, dd, and every exception reporter that inspects properties.
        return ['value' => self::PLACEHOLDER, 'spent' => $this->isSpent()];
    }

    public function jsonSerialize(): mixed
    {
        return self::PLACEHOLDER;
    }

    public function __serialize(): array
    {
        throw new LogicException(
            'SecretString: refusing to serialize. A secret may not enter a queue, a cache, '
            . 'a session or a database.'
        );
    }

    public function __unserialize(array $data): void
    {
        throw new LogicException('SecretString: refusing to unserialize.');
    }

    public function __sleep(): array
    {
        throw new LogicException('SecretString: refusing to sleep into storage.');
    }

    public function __clone()
    {
        throw new LogicException('SecretString: refusing to clone. One secret, one copy.');
    }
}
