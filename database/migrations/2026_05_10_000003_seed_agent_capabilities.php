<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase 2D seed — populates agent_capabilities from the static
 * CAPABILITY_MAP that AgentCapabilityService has shipped with since
 * 2026-04-13 (and was extended on 2026-05-09 to cover all 20 specialists).
 *
 * Idempotent: insertOrIgnore on the (agent_slug, tool_id) unique index.
 * Re-runs are safe.
 */
return new class extends Migration {
    public function up(): void
    {
        $reflection = new \ReflectionClass(\App\Core\Agent\AgentCapabilityService::class);
        $map = $reflection->getConstant('SLUG_ALIASES') ?: [];
        $capMap = $reflection->getConstants()['CAPABILITY_MAP'] ?? [];

        // Reflection on a private const requires getReflectionConstant.
        if (empty($capMap)) {
            $rc = $reflection->getReflectionConstant('CAPABILITY_MAP');
            if ($rc) {
                $capMap = $rc->getValue();
            }
        }

        if (empty($capMap)) {
            // Last-resort safety: bail without error so the migration still
            // marks "ran" — the runtime fallback in AgentCapabilityService
            // will keep using the static map.
            return;
        }

        $now = now();
        $rows = [];
        foreach ($capMap as $agentSlug => $toolIds) {
            foreach ($toolIds as $toolId) {
                $rows[] = [
                    'agent_slug' => $agentSlug,
                    'tool_id'    => $toolId,
                    'is_active'  => true,
                    'granted_at' => $now,
                    'granted_by' => 'phase2d_seed',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        // Chunked insertOrIgnore — the unique index dedups any rerun.
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('agent_capabilities')->insertOrIgnore($chunk);
        }
    }

    public function down(): void
    {
        DB::table('agent_capabilities')->where('granted_by', 'phase2d_seed')->delete();
    }
};
