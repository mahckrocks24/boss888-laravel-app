<?php

namespace App\Core\Engineer888\Reasoning;

use RuntimeException;

/**
 * Provider selection, by configuration.
 *
 * The whole point of this class is that it is boring. Swapping DeepSeek for GPT
 * is a different string in one config file; nothing in the workflow, the stages,
 * the validator or the tests knows which provider ran. If replacing a provider
 * ever requires editing anything outside this map, the abstraction has failed.
 */
final class ProviderRegistry
{
    public function __construct(private readonly array $config) {}

    public static function fromConfig(): self
    {
        return new self((array) config('engineer888_reasoning', []));
    }

    /** @return array<int,string> */
    public function names(): array
    {
        return array_keys((array) ($this->config['providers'] ?? []));
    }

    public function defaultName(): string
    {
        return (string) ($this->config['provider'] ?? 'null');
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function make(?string $name = null): ReasoningProvider
    {
        $name ??= $this->defaultName();
        $class = ($this->config['providers'] ?? [])[$name] ?? null;

        if ($class === null) {
            throw new RuntimeException(
                "unknown reasoning provider '{$name}'; registered: " . implode(', ', $this->names())
            );
        }

        $provider = new $class((array) ($this->config[$name] ?? []));

        if (! $provider instanceof ReasoningProvider) {
            // A class that is not a provider would be handed engineering context
            // with no contract governing what it does with it.
            throw new RuntimeException("{$class} does not implement " . ReasoningProvider::class);
        }

        return $provider;
    }

    /**
     * Every registered provider and whether it could run right now.
     *
     * @return array<int,array<string,mixed>>
     */
    public function inventory(): array
    {
        $rows = [];
        foreach ($this->names() as $name) {
            try {
                $provider = $this->make($name);
                $rows[] = [
                    'name'      => $name,
                    'default'   => $name === $this->defaultName(),
                    'available' => $provider->isAvailable(),
                    'reason'    => $provider->unavailableReason(),
                ] + $provider->describe();
            } catch (\Throwable $e) {
                $rows[] = ['name' => $name, 'default' => false, 'available' => false,
                           'reason' => $e->getMessage(), 'model' => '?', 'endpoint' => '?',
                           'deterministic' => false, 'notes' => 'failed to construct'];
            }
        }

        return $rows;
    }
}
