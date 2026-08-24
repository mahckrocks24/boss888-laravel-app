<?php

namespace App\Core\Email888\Providers;

use App\Core\Email888\Contracts\WebhookNormalizer;

/**
 * EMAIL888 EM-8 — resolves the adapter for an inbound provider.
 *
 * DELIBERATELY NOT CONFIG-DRIVEN.
 *
 * E2 established the rule the hard way: *"a fake reachable from configuration is
 * one env var away from being reachable in production."* A certification
 * normalizer that could be selected by setting an environment variable would be
 * exactly that. So the production set is a hardcoded list of real adapters, and
 * a certification adapter can only be added by a test calling register() on a
 * container instance that dies with the test.
 *
 * The registry is the boundary, not a plugin system.
 */
class WebhookNormalizerRegistry
{
    /** @var array<string,WebhookNormalizer> */
    private array $normalizers = [];

    public function __construct()
    {
        // Real adapters only. Nothing here may be a fake.
        $this->register(new PostmarkNormalizer());
    }

    public function register(WebhookNormalizer $n): void
    {
        $this->normalizers[strtolower($n->provider())] = $n;
    }

    public function has(string $provider): bool
    {
        return isset($this->normalizers[strtolower($provider)]);
    }

    public function for(string $provider): ?WebhookNormalizer
    {
        return $this->normalizers[strtolower($provider)] ?? null;
    }

    /** @return list<string> */
    public function providers(): array
    {
        return array_keys($this->normalizers);
    }
}
