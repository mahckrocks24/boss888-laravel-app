<?php

namespace App\Console\Commands;

use App\Core\Orchestration\ToolSchemaService;
use Illuminate\Console\Command;
use ReflectionClass;

/**
 * tools:audit — compare CapabilityMapService actions vs ToolSchemaService
 * exposure. Reports any SEO/write/social/crm/marketing/creative action that
 * exists in CapabilityMap but is NOT exposed to Sarah/agents through the
 * tool schema.
 *
 * Added 2026-05-22 as a regression guard after the keyword-tools-missing
 * bug. Until Wave 80 ships dynamic tool discovery, the two registries are
 * maintained manually — this command makes the gap visible.
 *
 * Exit code 1 if gaps found (CI-friendly); 0 if in sync.
 */
class ToolsAuditCommand extends Command
{
    protected $signature = 'tools:audit {--strict : exit 1 on any gap}';
    protected $description = 'Audit ToolSchemaService coverage vs CapabilityMapService';

    /** Engines that Sarah's tool catalog is expected to cover. */
    private const COVERED_ENGINES = ['seo', 'write', 'social', 'crm', 'marketing', 'creative'];

    public function handle(): int
    {
        $capMap = $this->loadCapabilityMap();
        $schema = $this->loadToolSchema();

        $covered = [];
        $missing = [];

        foreach ($capMap as $action => $def) {
            $engine = $def['engine'] ?? '?';
            if (!in_array($engine, self::COVERED_ENGINES, true)) continue;

            $found = false;
            foreach ($schema as $toolId => $tdef) {
                if (($tdef['engine'] ?? null) === $engine && ($tdef['action'] ?? null) === $action) {
                    $found = true;
                    $covered[] = "$engine.$action";
                    break;
                }
            }
            if (!$found) $missing[] = "$engine.$action";
        }

        $this->info(sprintf('Covered: %d   Missing: %d', count($covered), count($missing)));

        if (!empty($missing)) {
            $this->warn('Missing from ToolSchemaService.TOOL_DEFINITIONS:');
            foreach ($missing as $m) $this->line("  - $m");
            $this->newLine();
            $this->warn('Add entries to app/Core/Orchestration/ToolSchemaService.php');
            $this->warn('(or wait for Wave 80 dynamic tool discovery to replace this manual sync).');
            return $this->option('strict') ? 1 : 0;
        }

        $this->info('ToolSchemaService is in sync with CapabilityMapService.');
        return 0;
    }

    private function loadCapabilityMap(): array
    {
        $svc = app(\App\Core\EngineKernel\CapabilityMapService::class);
        return $svc->getAllCapabilities();
    }

    private function loadToolSchema(): array
    {
        $ref = new ReflectionClass(ToolSchemaService::class);
        return $ref->getConstant('TOOL_DEFINITIONS') ?: [];
    }
}
