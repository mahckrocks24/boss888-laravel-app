<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Core\Agent\AgentCapabilityService;
use App\Core\EngineKernel\CapabilityMapService;

/**
 * b16 (2026-07-24) — CAPABILITY DRIFT GUARD.
 *
 * Three separate incidents have now been caused by the same silent drift:
 * the planner routes an action to an agent, the action is a real registered
 * engine capability, but the agent's entry in AgentCapabilityService is
 * missing it — so the task dies at runtime with AGENT_NOT_AUTHORIZED and the
 * customer just sees a failed task.
 *
 *   2026-05-09  14 specialists had zero capabilities
 *   2026-05-22  keyword/link tools missing from dmm + james
 *   2026-07-24  9 content-pipeline actions missing from priya
 *
 * The two maps are edited independently and nothing compared them. This does.
 * Run it in CI / after any capability or routing edit:
 *
 *   php artisan sarah:audit-capabilities          # report
 *   php artisan sarah:audit-capabilities --strict # non-zero exit on drift
 *
 * It reports BOTH directions:
 *   MISSING — routed to an agent, registered as real, not authorized  (breaks)
 *   PHANTOM — routed to an agent but not a registered engine action   (dead)
 */
class AuditAgentCapabilitiesCommand extends Command
{
    protected $signature = 'sarah:audit-capabilities
        {--strict : exit non-zero when drift is found (for CI)}';

    protected $description = 'Verify every planner action→agent routing is an authorized, registered capability';

    /** Files that map an action slug to an agent slug. */
    private const ROUTING_SOURCES = [
        'app/Core/Strategy/SarahDailyOrchestrator.php',
        'app/Core/Strategy/SarahWeeklyOrchestrator.php',
        'app/Core/Orchestration/SarahOrchestrator.php',
    ];

    public function handle(AgentCapabilityService $cap, CapabilityMapService $cm): int
    {
        // Authoritative registry of real engine actions.
        $rp = new \ReflectionProperty($cm, 'capabilityMap');
        $rp->setAccessible(true);
        $registered = $rp->getValue($cm);

        $agents = ['dmm', 'sarah', 'james', 'priya', 'marcus', 'elena', 'alex', 'diana',
                   'ryan', 'sofia', 'leo', 'maya', 'noah', 'ava', 'zoe', 'kai',
                   'nina', 'omar', 'tara', 'victor', 'wren', 'nora', 'max'];
        $agentAlt = implode('|', $agents);

        $routes = [];
        foreach (self::ROUTING_SOURCES as $rel) {
            $path = base_path($rel);
            if (!is_file($path)) continue;
            preg_match_all(
                "/'([a-z0-9_]+)'\s*=>\s*'({$agentAlt})'/",
                (string) file_get_contents($path),
                $m
            );
            foreach ($m[1] as $i => $action) {
                $routes[$m[2][$i] . '|' . $action] = [$m[2][$i], $action, $rel];
            }
        }

        $missing = []; $phantom = [];
        foreach ($routes as [$agent, $action, $rel]) {
            if (!isset($registered[$action])) {
                // Not a real engine action — could be a legitimate non-action
                // map entry, so report separately and never fail the build on it.
                $phantom[$agent][] = $action;
                continue;
            }
            if (!$cap->canUse($agent, $action)) {
                $missing[$agent][] = "{$action} ({$registered[$action]['engine']})";
            }
        }

        $this->info(sprintf(
            'Checked %d routing(s) against %d registered actions.',
            count($routes), count($registered)
        ));

        if ($phantom) {
            $this->newLine();
            $this->comment('PHANTOM — routed but not a registered engine action (informational):');
            foreach ($phantom as $agent => $acts) {
                $this->line("  {$agent}: " . implode(', ', array_unique($acts)));
            }
        }

        if (!$missing) {
            $this->newLine();
            $this->info('✓ No capability drift — every routed action is authorized.');
            return self::SUCCESS;
        }

        $this->newLine();
        $this->error('✗ CAPABILITY DRIFT — these will fail with AGENT_NOT_AUTHORIZED at runtime:');
        foreach ($missing as $agent => $acts) {
            $this->line("  <fg=red>{$agent}</> missing: " . implode(', ', array_unique($acts)));
        }
        $this->newLine();
        $this->line('Fix: add them to AgentCapabilityService::CAPABILITY_MAP, or stop routing them.');

        return $this->option('strict') ? self::FAILURE : self::SUCCESS;
    }
}
