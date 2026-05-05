<?php

namespace App\Http\Controllers\Api;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Core\Workspaces\WorkspaceService;

class WorkspaceController
{
    public function __construct(private WorkspaceService $service) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['workspaces' => $this->service->listForUser($request->user())]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'settings' => 'nullable|array',
        ]);

        $workspace = $this->service->create($request->user(), $data);
        return response()->json(['workspace' => $workspace], 201);
    }
}
