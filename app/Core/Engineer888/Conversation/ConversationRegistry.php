<?php

namespace App\Core\Engineer888\Conversation;

use RuntimeException;

/**
 * Resolves the `engineering_conversation` capability to a provider.
 *
 * Deliberately boring, and a near-copy of ProviderRegistry for the reasoning
 * engine. The value is in what it refuses: a class that does not implement
 * ConversationProvider is rejected here rather than discovered later by a
 * fatal in the middle of a turn.
 */
final class ConversationRegistry
{
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('engineer888_conversation', []));
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? false);
    }

    public function defaultName(): string
    {
        return (string) ($this->config['provider'] ?? 'null');
    }

    public function names(): array
    {
        return array_keys((array) ($this->config['providers'] ?? []));
    }

    public function make(?string $name = null): ConversationProvider
    {
        $name = $name ?: $this->defaultName();
        $class = ((array) ($this->config['providers'] ?? []))[$name] ?? null;

        if ($class === null) {
            throw new RuntimeException("no conversation provider registered under '{$name}'");
        }

        $provider = new $class((array) ($this->config[$name] ?? []));

        if (! $provider instanceof ConversationProvider) {
            throw new RuntimeException("'{$name}' is not a ConversationProvider");
        }

        return $provider;
    }

    public function contextLimits(): array
    {
        return (array) ($this->config['context'] ?? []);
    }
}
