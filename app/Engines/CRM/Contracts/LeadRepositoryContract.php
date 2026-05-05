<?php

namespace App\Engines\CRM\Contracts;

use App\Models\Lead;
use Illuminate\Database\Eloquent\Collection;

interface LeadRepositoryContract
{
    public function create(array $data): Lead;
    public function findForWorkspace(int $workspaceId, array $filters = []): Collection;
    public function find(int $id): ?Lead;
}
