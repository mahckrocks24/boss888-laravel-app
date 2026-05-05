<?php

namespace App\Engines\CRM\Actions;

use App\Engines\CRM\Services\LeadService;
use App\Engines\CRM\Events\LeadCreated;
use App\Core\Audit\AuditLogService;
use Illuminate\Support\Facades\Validator;

class CreateLeadAction
{
    public function __construct(
        private LeadService $leadService,
        private AuditLogService $auditLog,
    ) {}

    /**
     * Execute from controller (manual) or task system (agent).
     * Same contract — same code path.
     */
    public function execute(int $workspaceId, array $data): array
    {
        $validated = Validator::make($data, [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'company' => 'nullable|string|max:255',
            'source' => 'nullable|string|max:100',
            'status' => 'nullable|in:new,contacted,qualified,converted,lost',
            'score' => 'nullable|integer|min:0|max:100',
            'deal_value' => 'nullable|numeric|min:0',
            'assigned_to' => 'nullable|integer|exists:users,id',
            'metadata' => 'nullable|array',
        ])->validate();

        if (isset($validated['metadata'])) {
            $validated['metadata_json'] = $validated['metadata'];
            unset($validated['metadata']);
        }

        $lead = $this->leadService->createLead($workspaceId, $validated);

        LeadCreated::dispatch($lead);

        $this->auditLog->log($workspaceId, null, 'lead.created', 'Lead', $lead->id, [
            'name' => $lead->name,
            'source' => $lead->source,
        ]);

        return [
            'lead_id' => $lead->id,
            'name' => $lead->name,
            'status' => $lead->status,
        ];
    }
}
