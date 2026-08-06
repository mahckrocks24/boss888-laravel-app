<?php

namespace App\Core\Engineer888\Reasoning;

class ProviderReadiness {
    public static function report() {
        $registry = ProviderRegistry::fromConfig();
        $report = [];
        foreach ($registry->names() as $name) {
            $provider = $registry->make($name);
            $description = $provider->describe();
            $report[] = [
                'name' => $provider->name(),
                'available' => $provider->isAvailable(),
                'reason' => $provider->unavailableReason(),
                'model' => $description['model'],
                'endpoint' => $description['endpoint']
            ];
        }
        return $report;
    }
}
