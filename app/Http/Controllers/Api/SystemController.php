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

    public function queue(): JsonResponse
    {
        return response()->json($this->service->queueStatus());
    }

    public function connectors(): JsonResponse
    {
        return response()->json(['connectors' => $this->service->checkConnectors()]);
    }
}
