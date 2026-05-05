<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use App\Core\Intelligence\EngineIntelligenceService;

/**
 * Seed engine intelligence data for all 11 engines on deploy.
 *
 * This migration runs EngineIntelligenceService::seedAll() which populates:
 *   - tool_blueprint rows (one per tool per engine) — ~47 total
 *   - best_practice rows (per engine)
 *   - constraint rows (per engine)
 *
 * Idempotent — uses updateOrInsert. Safe to re-run.
 *
 * Belt-and-braces with WorkspaceService::ensureIntelligenceSeeded() lazy hook:
 *   - Migration seeds on fresh installs / deploys
 *   - Lazy hook seeds on first workspace creation if migration was skipped
 *
 * Either path guarantees the table is never empty when agents try to read from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        try {
            // Verify engine_intelligence table exists (created in 2026_04_04_700001)
            if (!DB::getSchemaBuilder()->hasTable('engine_intelligence')) {
                Log::warning('Intelligence seed migration skipped — engine_intelligence table missing.');
                return;
            }

            $service = app(EngineIntelligenceService::class);
            $report = $service->seedAll();

            $totals = ['blueprints' => 0, 'practices' => 0, 'constraints' => 0];
            foreach ($report as $counts) {
                $totals['blueprints'] += $counts['blueprints'];
                $totals['practices'] += $counts['practices'];
                $totals['constraints'] += $counts['constraints'];
            }

            Log::info('Intelligence seeded via migration', [
                'engines' => count($report),
                'totals' => $totals,
            ]);
        } catch (\Throwable $e) {
            // Never fail deploy for a seed — the lazy hook in WorkspaceService
            // will pick up the slack on first workspace creation.
            Log::warning('Intelligence seed migration failed (non-fatal): ' . $e->getMessage());
        }
    }

    public function down(): void
    {
        // Clear only the seeded rows, not user-generated ones
        // (user-generated rows are those with effectiveness_score != null or auto_created in metadata)
        try {
            DB::table('engine_intelligence')
                ->whereIn('knowledge_type', ['tool_blueprint', 'best_practice', 'constraint'])
                ->whereNull('effectiveness_score')
                ->where(function ($q) {
                    $q->whereNull('metadata_json')
                      ->orWhere('metadata_json', 'NOT LIKE', '%"auto_created":true%');
                })
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('Intelligence seed rollback failed: ' . $e->getMessage());
        }
    }
};
