<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use App\Core\SystemHealth\SystemHealthService;

class SystemController
{
    public function __construct(private SystemHealthService $service) {}

    public function health(): JsonResponse
    {
        return response()->json($this->service->health());
    }

    public function engines(): JsonResponse
    {
        return response()->json(['engines' => $this->service->engines()]);
    }

    public function queue(\Illuminate\Http\Request $request): JsonResponse
    {
        // SEC-2: this route is authenticated but NOT admin, so it must answer for the caller's workspace only.
        $wsId = (int) $request->attributes->get('workspace_id');

        return response()->json($this->service->queueStatus($wsId > 0 ? $wsId : null));
    }

    public function connectors(): JsonResponse
    {
        return response()->json(['connectors' => $this->service->checkConnectors()]);
    }
}
