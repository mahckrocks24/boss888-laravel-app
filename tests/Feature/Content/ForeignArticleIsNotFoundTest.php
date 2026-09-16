<?php

namespace Tests\Feature\Content;

use Tests\TestCase;

/** Engine 2 U-B (2026-09-06): a foreign or missing article answers 404, never a 500; CRM assign is a real capability. */
class ForeignArticleIsNotFoundTest extends TestCase
{
    public function test_write_controller_turns_not_found_into_404(): void
    {
        $src = (string) file_get_contents(base_path('app/Engines/Write/Http/Controllers/WriteController.php'));
        $this->assertSame(3, substr_count($src, "catch (\\RuntimeException \$e) { return response()->json(['success' => false, 'error' => 'not_found'"), 'update, delete and restore all map not-found to 404');
    }

    public function test_assign_lead_is_a_mapped_capability(): void
    {
        $map = (string) file_get_contents(base_path('app/Core/EngineKernel/CapabilityMapService.php'));
        $this->assertStringContainsString("'assign_lead'         => ['engine'=>'crm'", $map);
    }

    public function test_every_crm_kernel_arm_is_a_mapped_capability(): void
    {
        $map = (string) file_get_contents(base_path('app/Core/EngineKernel/CapabilityMapService.php'));
        foreach (['merge_contacts', 'generate_followup', 'assign_lead', 'score_lead', 'import_leads', 'create_deal', 'update_deal_stage', 'log_activity', 'add_note'] as $a) {
            $this->assertStringContainsString("'" . $a . "'", $map, "crm/$a must be mapped or its route answers Unknown action");
        }
    }
}
