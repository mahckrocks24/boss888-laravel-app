<?php

namespace App\Core\EngineKernel;

use App\Models\EngineRegistry;
use Illuminate\Database\Eloquent\Collection;

class EngineRegistryService
{
    public function all(): Collection
    {
        return EngineRegistry::all();
    }

    public function findBySlug(string $slug): ?EngineRegistry
    {
        return EngineRegistry::where('slug', $slug)->first();
    }

    public function register(array $manifest): EngineRegistry
    {
        return EngineRegistry::updateOrCreate(
            ['slug' => $manifest['slug']],
            [
                'name' => $manifest['name'],
                'version' => $manifest['version'] ?? '1.0.0',
                'status' => 'active',
                'capabilities_json' => $manifest['capabilities'] ?? [],
                'metadata_json' => $manifest['metadata'] ?? [],
            ]
        );
    }

    public function getCapabilities(string $slug): array
    {
        $engine = $this->findBySlug($slug);
        return $engine ? ($engine->capabilities_json ?? []) : [];
    }
}
