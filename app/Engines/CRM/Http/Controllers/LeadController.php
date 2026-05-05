<?php

namespace App\Engines\CRM\Http\Controllers;

use App\Engines\CRM\Actions\CreateLeadAction;
use App\Engines\CRM\Services\LeadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LeadController
{
    public function store(Request $request, CreateLeadAction $action): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $result = $action->execute($workspaceId, $request->all());

        return response()->json($result, 201);
    }

    public function index(Request $request, LeadService $service): JsonResponse
    {
        $workspaceId = $request->attributes->get('workspace_id');

        $leads = $service->listLeads($workspaceId, $request->only(['status', 'limit']));

        return response()->json(['leads' => $leads]);
    }
}
