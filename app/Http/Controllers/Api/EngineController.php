<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use App\Core\EngineKernel\EngineRegistryService;

class EngineController
{
    public function __construct(private EngineRegistryService $service) {}

    public function index(): JsonResponse
    {
        return response()->json(['engines' => $this->service->all()]);
    }

    public function show(string $name): JsonResponse
    {
        $engine = $this->service->findBySlug($name);
        if (! $engine) {
            return response()->json(['error' => 'Engine not found'], 404);
        }
        return response()->json(['engine' => $engine]);
    }
}
