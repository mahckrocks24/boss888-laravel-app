<?php

namespace App\Core\EngineKernel;

use Illuminate\Support\Facades\File;

class EngineManifestLoader
{
    public function loadAll(): array
    {
        $manifests = [];
        $enginesPath = app_path('Engines');

        if (! File::isDirectory($enginesPath)) {
            return $manifests;
        }

        foreach (File::directories($enginesPath) as $dir) {
            $manifestFile = $dir . '/engine.json';
            if (File::exists($manifestFile)) {
                $manifest = json_decode(File::get($manifestFile), true);
                if ($manifest) {
                    $manifests[] = $manifest;
                }
            }
        }

        return $manifests;
    }

    public function load(string $enginePath): ?array
    {
        $manifestFile = $enginePath . '/engine.json';
        if (! File::exists($manifestFile)) {
            return null;
        }
        return json_decode(File::get($manifestFile), true);
    }
}
