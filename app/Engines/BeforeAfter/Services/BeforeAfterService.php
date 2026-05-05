<?php

namespace App\Engines\BeforeAfter\Services;

use App\Engines\Creative\Services\CreativeService;
use App\Connectors\DeepSeekConnector;
use Illuminate\Support\Facades\DB;

class BeforeAfterService
{
    public function __construct(
        private CreativeService $creative,
        private DeepSeekConnector $llm,
        private \App\Connectors\RuntimeClient $runtime,
    ) {}

    public function createDesign(int $wsId, array $data): array
    {
        $id = DB::table('ba_designs')->insertGetId([
            'workspace_id' => $wsId,
            'room_type' => $data['room_type'] ?? 'living_room',
            'style' => $data['style'] ?? 'modern',
            'before_image_url' => $data['before_image_url'],
            'after_image_url' => null,
            'status' => 'processing',
            'aspect_ratio' => $data['aspect_ratio'] ?? '16:9',
            'task_id' => $data['task_id'] ?? null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return ['design_id' => $id, 'status' => 'processing'];
    }

    public function generateDesign(int $wsId, int $designId): array
    {
        $design = DB::table('ba_designs')->where('id', $designId)->first();
        if (!$design) throw new \RuntimeException("Design not found");

        $prompt = "Interior design transformation. Room: {$design->room_type}. Style: {$design->style}. " .
            "Create a photorealistic redesigned version of this room maintaining the same layout and perspective.";

        // Generate via Creative engine
        $result = $this->creative->generateImage($wsId, [
            'prompt' => $prompt,
            'style' => $design->style,
            'aspect_ratio' => $design->aspect_ratio ?? '16:9',
        ]);

        if (($result['status'] ?? '') === 'completed' && !empty($result['url'])) {
            $this->completeDesign($designId, ['after_image_url' => $result['url']]);
            // Auto-generate design report
            $this->generateReport($designId, $design);
            return ['status' => 'completed', 'after_image_url' => $result['url']];
        }

        DB::table('ba_designs')->where('id', $designId)->update(['status' => 'failed', 'updated_at' => now()]);
        return ['status' => 'failed', 'error' => $result['error'] ?? 'Generation failed'];
    }

    public function completeDesign(int $designId, array $result): void
    {
        DB::table('ba_designs')->where('id', $designId)->update([
            'status' => 'completed',
            'after_image_url' => $result['after_image_url'] ?? null,
            'updated_at' => now(),
        ]);
    }

    public function getDesign(int $wsId, int $id): ?object
    {
        return DB::table('ba_designs')->where('workspace_id', $wsId)->where('id', $id)->first();
    }

    public function listDesigns(int $wsId): array
    {
        return DB::table('ba_designs')->where('workspace_id', $wsId)->orderByDesc('created_at')->get()->toArray();
    }

    /**
     * Generate a 7-section design report via LLM.
     *
     * REFACTORED 2026-04-12 (Phase 2L BA1 / doc 14): now routes through
     * RuntimeClient::aiRun('seo_content_generation', ...) instead of direct
     * DeepSeekConnector. The 7-section structure is enforced via the user
     * prompt + JSON output instruction.
     */
    public function generateReport(int $designId, ?object $design = null): void
    {
        $design = $design ?? DB::table('ba_designs')->where('id', $designId)->first();
        if (!$design) return;

        $context = [
            'task'      => 'interior_design_report',
            'room_type' => $design->room_type,
            'style'     => $design->style,
            'currency'  => 'AED',
        ];

        $userPrompt = "Generate a professional 7-section interior design report. "
                    . "Room: {$design->room_type}. Style: {$design->style}. "
                    . "Sections: "
                    . "1) Design Overview, "
                    . "2) Color Palette, "
                    . "3) Materials & Textures, "
                    . "4) Furniture Layout, "
                    . "5) Lighting Plan, "
                    . "6) Estimated Budget (in AED), "
                    . "7) Implementation Timeline. "
                    . "Output as JSON: {\"sections\":[{\"title\":\"...\",\"content\":\"...\"}]}";

        $result = $this->runtime->aiRun('seo_content_generation', $userPrompt, $context, 1000);

        if ($result['success']) {
            // Try to parse JSON from the runtime text response
            $parsed = null;
            if (!empty($result['text'])) {
                $maybe = json_decode($result['text'], true);
                if (is_array($maybe)) $parsed = $maybe;
            }
            DB::table('ba_designs')->where('id', $designId)->update([
                'report_json' => json_encode($parsed ?? ['raw' => $result['text'] ?? '']),
                'updated_at'  => now(),
            ]);
        }
    }

    public function getRoomTypes(): array
    {
        return [
            ['id' => 'living_room', 'name' => 'Living Room', 'icon' => '🛋️'],
            ['id' => 'bedroom', 'name' => 'Bedroom', 'icon' => '🛏️'],
            ['id' => 'kitchen', 'name' => 'Kitchen', 'icon' => '🍳'],
            ['id' => 'bathroom', 'name' => 'Bathroom', 'icon' => '🚿'],
            ['id' => 'office', 'name' => 'Home Office', 'icon' => '🖥️'],
            ['id' => 'dining', 'name' => 'Dining Room', 'icon' => '🍽️'],
            ['id' => 'outdoor', 'name' => 'Outdoor/Patio', 'icon' => '🌿'],
            ['id' => 'kids_room', 'name' => 'Kids Room', 'icon' => '🧸'],
        ];
    }

    public function getStyles(): array
    {
        return [
            ['id' => 'modern', 'name' => 'Modern', 'color' => '#6C5CE7'],
            ['id' => 'minimalist', 'name' => 'Minimalist', 'color' => '#636e72'],
            ['id' => 'traditional', 'name' => 'Traditional', 'color' => '#B8860B'],
            ['id' => 'industrial', 'name' => 'Industrial', 'color' => '#2d3436'],
            ['id' => 'scandinavian', 'name' => 'Scandinavian', 'color' => '#dfe6e9'],
            ['id' => 'arabic', 'name' => 'Arabic/Islamic', 'color' => '#D4A574'],
            ['id' => 'contemporary', 'name' => 'Contemporary', 'color' => '#00b894'],
            ['id' => 'luxury', 'name' => 'Luxury', 'color' => '#C9A227'],
        ];
    }

    public function getDashboard(int $wsId): array
    {
        $designs = DB::table('ba_designs')->where('workspace_id', $wsId);
        return [
            'total_designs' => (clone $designs)->count(),
            'completed' => (clone $designs)->where('status', 'completed')->count(),
            'processing' => (clone $designs)->where('status', 'processing')->count(),
            'recent' => (clone $designs)->orderByDesc('created_at')->limit(6)->get(),
        ];
    }
}
