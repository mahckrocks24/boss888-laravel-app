<?php

namespace App\Core\Governance;

use App\Core\EngineKernel\CapabilityMapService;

/**
 * MANDATE-1 (2026-09-25): the governance actions the platform itself performs — today one, `sarah/execute_plan`, the
 * gate that turns an approved Plan of Action into its tasks. They are not engine capabilities (no connector, no credits)
 * and the Orchestrator runs them itself before any engine dispatch, but TaskService refuses to create a task whose
 * action is unmapped, so they are registered here on top of the engine map without editing it.
 */
class GovernedCapabilityMap extends CapabilityMapService
{
    public const GOVERNANCE = [
        'execute_plan' => ['engine' => 'sarah', 'connector' => null, 'action' => 'execute_plan', 'approval_mode' => 'review', 'credit_cost' => 0],
    ];

    public function resolve(string $action): ?array
    {
        return parent::resolve($action) ?? (self::GOVERNANCE[$action] ?? null);
    }

    public function getAllCapabilities(): array
    {
        return parent::getAllCapabilities() + self::GOVERNANCE;
    }
}
