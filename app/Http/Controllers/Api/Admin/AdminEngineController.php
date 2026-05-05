<?php

namespace App\Http\Controllers\Api\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Core\EngineKernel\CapabilityMapService;

class AdminEngineController
{
    /**
     * GET /api/admin/engines/registry
     * Returns all 11 engines with status, route counts, task stats, error counts.
     */
    public function registry(Request $r): JsonResponse
    {
        // 1. Read engine.json from each engine directory
        $enginesPath = app_path('Engines');
        $engineDirs = is_dir($enginesPath) ? array_filter(scandir($enginesPath), fn($d) => $d !== '.' && $d !== '..' && is_dir("{$enginesPath}/{$d}")) : [];

        $engines = [];
        foreach ($engineDirs as $dir) {
            $jsonPath = "{$enginesPath}/{$dir}/engine.json";
            $meta = file_exists($jsonPath) ? json_decode(file_get_contents($jsonPath), true) : [];

            $slug = $meta['slug'] ?? strtolower($dir);
            $name = $meta['name'] ?? $dir;
            $version = $meta['version'] ?? '0.0.0';
            $status = $meta['status'] ?? 'unknown';

            $engines[] = [
                'directory' => $dir,
                'slug' => $slug,
                'name' => $name,
                'version' => $version,
                'status' => $status,
            ];
        }

        // 2. Count routes per engine — match route URIs containing the engine directory name (lowercase)
        try {
            $routes = app('router')->getRoutes();
            $routeCounts = [];
            foreach ($routes as $route) {
                $uri = $route->uri();
                foreach ($engines as $e) {
                    $prefix = strtolower($e['directory']);
                    if (str_contains($uri, "/{$prefix}/") || str_starts_with($uri, "{$prefix}/") || str_starts_with($uri, "api/{$prefix}/")) {
                        $routeCounts[$e['slug']] = ($routeCounts[$e['slug']] ?? 0) + 1;
                    }
                }
            }
        } catch (\Throwable) {
            $routeCounts = [];
        }

        // 3. Task counts per engine (total + failed)
        $taskStats = DB::table('tasks')
            ->select('engine', DB::raw('COUNT(*) as total'), DB::raw("SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed"))
            ->groupBy('engine')
            ->get()
            ->keyBy('engine');

        // 4. Error counts — failed tasks in last 24h per engine
        //    (audit_logs doesn't have level/engine columns, so we use tasks table)
        $errorCounts = DB::table('tasks')
            ->select('engine', DB::raw('COUNT(*) as errors'))
            ->where('status', 'failed')
            ->where('updated_at', '>=', now()->subDay())
            ->groupBy('engine')
            ->get()
            ->keyBy('engine');

        // 5. Last execution per engine
        $lastExec = DB::table('tasks')
            ->select('engine', DB::raw('MAX(updated_at) as last_execution'))
            ->groupBy('engine')
            ->get()
            ->keyBy('engine');

        // Build response
        $result = [];
        foreach ($engines as $e) {
            $slug = $e['slug'];
            $stats = $taskStats->get($slug);
            $errors = $errorCounts->get($slug);
            $last = $lastExec->get($slug);

            $result[] = [
                'slug' => $slug,
                'name' => $e['name'],
                'version' => $e['version'],
                'status' => $e['status'],
                'route_count' => $routeCounts[$slug] ?? 0,
                'tasks_total' => $stats->total ?? 0,
                'tasks_failed' => $stats->failed ?? 0,
                'errors_24h' => $errors->errors ?? 0,
                'last_execution' => $last->last_execution ?? null,
            ];
        }

        return response()->json(['engines' => $result]);
    }

    /**
     * GET /api/admin/engines/capabilities
     * Returns full capability map from CapabilityMapService.
     */
    public function capabilities(Request $r): JsonResponse
    {
        $capMap = app(CapabilityMapService::class);
        $all = $capMap->getAllCapabilities();

        $result = [];
        foreach ($all as $action => $entry) {
            $result[] = [
                'action' => $action,
                'engine' => $entry['engine'] ?? null,
                'credit_cost' => $entry['credit_cost'] ?? 0,
                'approval_mode' => $entry['approval_mode'] ?? 'review',
                'connector' => $entry['connector'] ?? null,
            ];
        }

        return response()->json(['capabilities' => $result, 'total' => count($result)]);
    }

    /**
     * PUT /api/admin/engines/capabilities
     * Update credit_cost or approval_mode for an action.
     * Currently returns 501 — CapabilityMap is a static array, needs DB-backing first.
     */
    public function updateCapability(Request $r): JsonResponse
    {
        return response()->json([
            'error' => 'Not implemented — CapabilityMap is currently a static PHP array. DB-backed capability overrides required before this endpoint can work.',
            'action' => $r->input('action'),
        ], 501);
    }
}
