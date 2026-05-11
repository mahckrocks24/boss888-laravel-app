<?php

namespace App\Core\Workspaces;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Credit;
use App\Models\Agent;
use App\Core\Audit\AuditLogService;
use App\Core\Intelligence\EngineIntelligenceService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WorkspaceService
{
    public function __construct(
        private AuditLogService $auditLogService,
        private EngineIntelligenceService $engineIntel,
    ) {}

    public function listForUser(User $user): array
    {
        return $user->workspaces()
            ->with('subscription.plan')
            ->get()
            ->map(fn ($ws) => [
                'id' => $ws->id,
                'name' => $ws->name,
                'slug' => $ws->slug,
                'role' => $ws->pivot->role,
                'plan' => $ws->subscription?->plan?->slug ?? 'free',
            ])
            ->toArray();
    }

    public function create(User $user, array $data): Workspace
    {
        $workspace = Workspace::create([
            'name' => $data['name'],
            'slug' => Str::slug($data['name'] . '-' . Str::random(4)),
            'settings_json' => $data['settings'] ?? null,
            'created_by' => $user->id,
        ]);

        $workspace->users()->attach($user->id, ['role' => 'owner']);

        // 2026-05-12: seed initial credit balance from monthly_credit_allowance
        // when set. Free signups (allowance=0) get balance:0 as before.
        $workspace->refresh();
        $initialBalance = (int) ($workspace->monthly_credit_allowance ?? 0);
        Credit::create([
            'workspace_id'     => $workspace->id,
            'balance'          => $initialBalance,
            'reserved_balance' => 0,
        ]);

        $agents = Agent::where('status', 'active')->get();
        foreach ($agents as $agent) {
            $workspace->agents()->attach($agent->id, ['enabled' => true]);
        }

        // ─── Lazy intelligence seed ─────────────────────────────
        // Intelligence data is global (not per-workspace). Seeding on first
        // workspace creation guarantees blueprints exist regardless of whether
        // the deployment migration ran. hasBeenSeeded() makes this idempotent
        // and cheap on subsequent calls.
        $this->ensureIntelligenceSeeded();

        $this->auditLogService->log($workspace->id, $user->id, 'workspace.created', 'Workspace', $workspace->id);

        return $workspace;
    }

    /**
     * Ensure engine intelligence has been seeded at least once.
     * Safe to call repeatedly — hasBeenSeeded() short-circuits after first success.
     */
    private function ensureIntelligenceSeeded(): void
    {
        try {
            if (!$this->engineIntel->hasBeenSeeded()) {
                $this->engineIntel->seedAll();
                Log::info('Intelligence layer seeded lazily from WorkspaceService.create');
            }
        } catch (\Throwable $e) {
            // Intelligence seeding must never block workspace creation.
            Log::warning('Intelligence lazy-seed failed: ' . $e->getMessage());
        }
    }
}
