<?php

namespace App\Engines\CRM\Repositories;

use App\Engines\CRM\Contracts\LeadRepositoryContract;
use App\Models\Lead;
use Illuminate\Database\Eloquent\Collection;

class LeadRepository implements LeadRepositoryContract
{
    public function create(array $data): Lead
    {
        return Lead::create($data);
    }

    public function findForWorkspace(int $workspaceId, array $filters = []): Collection
    {
        $query = Lead::where('workspace_id', $workspaceId);

        if (isset($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query->orderByDesc('created_at')->limit($filters['limit'] ?? 50)->get();
    }

    public function find(int $id): ?Lead
    {
        return Lead::find($id);
    }
}
