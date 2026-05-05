<?php

namespace App\Engines\ManualEdit\Services;

use Illuminate\Support\Facades\DB;

class ManualEditService
{
    public function createCanvas(int $wsId, array $data): array
    {
        $id = DB::table('canvas_states')->insertGetId([
            'workspace_id' => $wsId,
            'asset_id' => $data['asset_id'] ?? null,
            'name' => $data['name'] ?? 'Untitled Canvas',
            'state_json' => json_encode($data['state'] ?? $this->defaultState()),
            'operations_json' => json_encode([]),
            'status' => 'draft',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['canvas_id' => $id, 'status' => 'draft'];
    }

    public function getCanvas(int $wsId, int $id): ?object
    {
        return DB::table('canvas_states')->where('workspace_id', $wsId)->where('id', $id)->first();
    }

    public function listCanvases(int $wsId): array
    {
        return DB::table('canvas_states')->where('workspace_id', $wsId)->orderByDesc('updated_at')->get()->toArray();
    }

    /**
     * Save canvas state + append operations for undo/redo.
     * All mutations flow through apply_operation() — single dispatcher.
     */
    public function saveCanvas(int $canvasId, array $state, array $operations): void
    {
        $canvas = DB::table('canvas_states')->where('id', $canvasId)->first();
        $existingOps = json_decode($canvas->operations_json ?? '[]', true);
        $mergedOps = array_merge($existingOps, $operations);

        // Keep last 100 operations for undo/redo
        if (count($mergedOps) > 100) $mergedOps = array_slice($mergedOps, -100);

        DB::table('canvas_states')->where('id', $canvasId)->update([
            'state_json' => json_encode($state),
            'operations_json' => json_encode($mergedOps),
            'updated_at' => now(),
        ]);
    }

    /**
     * Apply a single operation to canvas state.
     *
     * SUPPORTED OPERATION TYPES (server-side):
     *   add_layer, remove_layer, move_layer, resize_layer, update_layer, reorder_layers
     *
     * NOTE 2026-04-12 (Phase 2K / doc 12): the architectural decision is that
     * fine-grained operations (text/shape/filter/crop/rotate) are FRONTEND-ONLY —
     * the canvas does its own rendering and sends `update_layer` with the new
     * state. The backend dispatcher only handles coarse layer-level operations.
     *
     * FIX 2026-04-12 (Phase 2K / doc 12): the original switch statement fell
     * through silently for unknown operation types. A frontend bug that sent
     * `type='crop'` would no-op without any error — silent data loss. Now
     * throws InvalidArgumentException so unknown operations surface immediately.
     */
    public function applyOperation(int $canvasId, array $operation): array
    {
        $canvas = DB::table('canvas_states')->where('id', $canvasId)->first();
        if (!$canvas) throw new \RuntimeException("Canvas not found");

        $state = json_decode($canvas->state_json, true);
        $layers = $state['layers'] ?? [];

        $type = $operation['type'] ?? '';

        switch ($type) {
            case 'add_layer':
                $layers[] = array_merge(['id' => uniqid('layer_'), 'visible' => true, 'opacity' => 1, 'x' => 0, 'y' => 0], $operation['data'] ?? []);
                break;
            case 'remove_layer':
                $layers = array_values(array_filter($layers, fn($l) => ($l['id'] ?? '') !== ($operation['layer_id'] ?? '')));
                break;
            case 'move_layer':
                foreach ($layers as &$l) {
                    if (($l['id'] ?? '') === ($operation['layer_id'] ?? '')) {
                        $l['x'] = $operation['x'] ?? $l['x'];
                        $l['y'] = $operation['y'] ?? $l['y'];
                    }
                }
                break;
            case 'resize_layer':
                foreach ($layers as &$l) {
                    if (($l['id'] ?? '') === ($operation['layer_id'] ?? '')) {
                        $l['width'] = $operation['width'] ?? $l['width'] ?? 100;
                        $l['height'] = $operation['height'] ?? $l['height'] ?? 100;
                    }
                }
                break;
            case 'update_layer':
                foreach ($layers as &$l) {
                    if (($l['id'] ?? '') === ($operation['layer_id'] ?? '')) {
                        $l = array_merge($l, $operation['data'] ?? []);
                    }
                }
                break;
            case 'reorder_layers':
                // Reorder based on provided ID array
                $order = $operation['order'] ?? [];
                $indexed = [];
                foreach ($layers as $l) $indexed[$l['id'] ?? ''] = $l;
                $layers = [];
                foreach ($order as $lid) {
                    if (isset($indexed[$lid])) $layers[] = $indexed[$lid];
                }
                break;
            default:
                // FIX 2026-04-12 (Phase 2K): no more silent fallthrough.
                // Unknown operation types now throw so they can be caught and reported.
                throw new \InvalidArgumentException(
                    "Unknown ManualEdit operation type: '{$type}'. " .
                    "Supported types: add_layer, remove_layer, move_layer, resize_layer, update_layer, reorder_layers. " .
                    "Fine-grained operations (text/shape/filter/crop/rotate) should be handled frontend-side via update_layer."
                );
        }

        $state['layers'] = $layers;
        $this->saveCanvas($canvasId, $state, [$operation]);
        return ['state' => $state, 'layer_count' => count($layers)];
    }

    public function exportCanvas(int $canvasId, string $format, string $exportUrl): void
    {
        DB::table('canvas_states')->where('id', $canvasId)->update([
            'status' => 'exported', 'export_url' => $exportUrl, 'export_format' => $format, 'updated_at' => now(),
        ]);
    }

    public function deleteCanvas(int $canvasId): void
    {
        DB::table('canvas_states')->where('id', $canvasId)->delete();
    }

    public function getDashboard(int $wsId): array
    {
        $canvases = DB::table('canvas_states')->where('workspace_id', $wsId);
        return [
            'total_canvases' => (clone $canvases)->count(),
            'draft' => (clone $canvases)->where('status', 'draft')->count(),
            'exported' => (clone $canvases)->where('status', 'exported')->count(),
            'recent' => (clone $canvases)->orderByDesc('updated_at')->limit(6)->get(),
        ];
    }

    private function defaultState(): array
    {
        return [
            'width' => 1080, 'height' => 1080,
            'background' => '#FFFFFF',
            'layers' => [],
        ];
    }
}
