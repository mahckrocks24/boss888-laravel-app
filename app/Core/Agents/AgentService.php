<?php

namespace App\Core\Agents;

use App\Models\Agent;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;

class AgentService
{
    public function all(): Collection
    {
        return Agent::all();
    }

    public function forWorkspace(int $workspaceId): Collection
    {
        $workspace = Workspace::findOrFail($workspaceId);
        return $workspace->agents()->wherePivot('enabled', true)->get();
    }

    public function findBySlug(string $slug): ?Agent
    {
        return Agent::where('slug', $slug)->first();
    }
}
