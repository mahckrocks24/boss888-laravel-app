<?php

namespace App\Connectors\Infrastructure;

use App\Connectors\Infrastructure\Contracts\InfrastructureConnector;
use App\Connectors\Infrastructure\Null\NullCustomHostnameConnector;
use App\Connectors\Infrastructure\Null\NullHostingConnector;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use RuntimeException;

/**
 * Resolves a capability to a concrete connector.
 *
 * Config-driven rather than a hardcoded constructor array. The existing
 * ConnectorResolver.php:11-18 hardcodes its map, which the Phase 0 audit flagged as
 * a friction point (a new connector must edit the class). Here the map lives in
 * config/infrastructure.php so adding a provider is configuration, not a code edit.
 *
 * Defaults to the Null connectors so Phase 1A runs deterministically with no live
 * provider configured — and, critically, so an unconfigured provider degrades to an
 * honest no-op rather than a half-configured real call.
 */
class InfrastructureConnectorResolver
{
    /** @var array<string,InfrastructureConnector> */
    private array $resolved = [];

    public function resolve(string $capability): InfrastructureConnector
    {
        if (isset($this->resolved[$capability])) {
            return $this->resolved[$capability];
        }

        $class = config("infrastructure.connectors.{$capability}")
            ?? $this->defaultFor($capability);

        if (!$class || !class_exists($class)) {
            throw new RuntimeException(
                "No infrastructure connector configured for capability '{$capability}'."
            );
        }

        $connector = app($class);

        if (!$connector instanceof InfrastructureConnector) {
            throw new RuntimeException(
                "Connector {$class} must implement InfrastructureConnector."
            );
        }

        if ($connector->capability() !== $capability) {
            throw new RuntimeException(
                "Connector {$class} reports capability '{$connector->capability()}', expected '{$capability}'."
            );
        }

        return $this->resolved[$capability] = $connector;
    }

    public function isLive(string $capability): bool
    {
        try {
            return $this->resolve($capability)->provider() !== 'null';
        } catch (RuntimeException) {
            return false;
        }
    }

    private function defaultFor(string $capability): ?string
    {
        return match ($capability) {
            InfraProviderConnection::CAPABILITY_HOSTING         => NullHostingConnector::class,
            InfraProviderConnection::CAPABILITY_CUSTOM_HOSTNAME => NullCustomHostnameConnector::class,
            default => null,
        };
    }

    /** Test seam. */
    public function fake(string $capability, InfrastructureConnector $connector): void
    {
        $this->resolved[$capability] = $connector;
    }
}
