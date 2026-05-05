<?php

namespace App\Connectors;

use App\Connectors\Contracts\ConnectorInterface;

class ConnectorResolver
{
    private array $registry = [];

    public function __construct()
    {
        $this->registry = [
            'creative' => CreativeConnector::class,
            'email' => EmailConnector::class,
            'social' => SocialConnector::class,
        ];
    }

    public function resolve(string $connectorName): ConnectorInterface
    {
        $class = $this->registry[$connectorName] ?? null;

        if (! $class) {
            throw new \InvalidArgumentException("Unknown connector: {$connectorName}");
        }

        return app($class);
    }

    public function has(string $connectorName): bool
    {
        return isset($this->registry[$connectorName]);
    }

    public function all(): array
    {
        return array_keys($this->registry);
    }

    public function healthCheckAll(): array
    {
        $results = [];
        foreach ($this->registry as $name => $class) {
            try {
                $connector = app($class);
                $results[$name] = $connector->healthCheck();
            } catch (\Throwable) {
                $results[$name] = false;
            }
        }
        return $results;
    }
}
