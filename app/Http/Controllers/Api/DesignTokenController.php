<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Core\DesignTokens\DesignTokenService;

class DesignTokenController
{
    public function __construct(private DesignTokenService $service) {}

    public function index(Request $request): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');
        $tokens = $workspaceId
            ? $this->service->getForWorkspace($workspaceId)
            : $this->service->getDefaults();

        return response()->json(['tokens' => $tokens]);
    }
}
