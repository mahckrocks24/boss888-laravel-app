<?php

namespace Tests\Helpers;

use App\Models\User;
use App\Models\Workspace;
use App\Models\Credit;
use App\Models\Task;
use App\Models\Agent;
use App\Models\Plan;
use App\Models\Subscription;
use App\Core\Auth\RefreshTokenService;
use Illuminate\Support\Facades\Cache;

trait Boss888TestHelper
{
    protected User $testUser;
    protected Workspace $testWorkspace;
    protected string $accessToken;

    protected function setUpBoss888(): void
    {
        $this->seed(\Database\Seeders\PlanSeeder::class);
        $this->seed(\Database\Seeders\AgentSeeder::class);

        $this->testUser = User::create([
            'name' => 'Test User',
            'email' => 'test@boss888.test',
            'password' => bcrypt('password'),
        ]);

        $this->testWorkspace = Workspace::create([
            'name' => 'Test Workspace',
            'slug' => 'test-ws-' . uniqid(),
            'created_by' => $this->testUser->id,
        ]);

        $this->testWorkspace->users()->attach($this->testUser->id, ['role' => 'owner']);

        Credit::create([
            'workspace_id' => $this->testWorkspace->id,
            'balance' => 5000,
            'reserved_balance' => 0,
        ]);

        $plan = Plan::where('slug', 'growth')->first();
        if ($plan) {
            Subscription::create([
                'workspace_id' => $this->testWorkspace->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'starts_at' => now(),
            ]);
        }

        $agents = Agent::where('status', 'active')->get();
        foreach ($agents as $agent) {
            $this->testWorkspace->agents()->attach($agent->id, ['enabled' => true]);
        }

        $tokenService = app(RefreshTokenService::class);
        $this->accessToken = $tokenService->issueAccessToken($this->testUser, $this->testWorkspace);

        // Clear all caches for clean test state
        Cache::flush();
    }

    protected function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->accessToken];
    }

    protected function createTask(array $overrides = []): Task
    {
        return Task::create(array_merge([
            'workspace_id' => $this->testWorkspace->id,
            'engine' => 'crm',
            'action' => 'create_lead',
            'payload_json' => ['name' => 'Test Lead'],
            'status' => 'pending',
            'source' => 'manual',
            'priority' => 'normal',
            'credit_cost' => 1,
        ], $overrides));
    }

    protected function getCredit(): Credit
    {
        return Credit::where('workspace_id', $this->testWorkspace->id)->first();
    }

    protected function createAdditionalWorkspace(string $name): Workspace
    {
        $ws = Workspace::create([
            'name' => $name,
            'slug' => 'ws-' . uniqid(),
            'created_by' => $this->testUser->id,
        ]);
        $ws->users()->attach($this->testUser->id, ['role' => 'owner']);
        Credit::create(['workspace_id' => $ws->id, 'balance' => 1000, 'reserved_balance' => 0]);
        return $ws;
    }

    protected function assertCreditBalance(int $expected): void
    {
        $this->assertEquals($expected, $this->getCredit()->fresh()->balance);
    }

    protected function assertReservedBalance(int $expected): void
    {
        $this->assertEquals($expected, $this->getCredit()->fresh()->reserved_balance);
    }
}
