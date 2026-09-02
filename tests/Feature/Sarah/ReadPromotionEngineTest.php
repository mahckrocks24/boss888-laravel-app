<?php

namespace Tests\Feature\Sarah;

use App\Core\Orchestration\ToolSchemaService;
use App\Core\Sarah888\ReadToolPromotion;
use Tests\TestCase;

/**
 * P2/TM-2 (2026-09-02): non-platform engine READS (crm.list_leads, seo.list_keywords, calendar.list_events,
 * builder.list_builder_pages/get_builder_page) must be promoted to inline reads from the create_tasks path so
 * their rows are returned — not routed as "I've asked X" delegated tasks. Writes must NEVER promote.
 */
class ReadPromotionEngineTest extends TestCase
{
    private array $ids;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ids = app(ToolSchemaService::class)->getAllToolIds();
    }

    /** @dataProvider reads */
    public function test_engine_reads_promote_inline(string $engine, string $action, string $expected): void
    {
        $this->assertSame($expected, ReadToolPromotion::toolIdFor($engine, $action, $this->ids));
    }

    public static function reads(): array
    {
        return [
            ['crm', 'list_leads', 'crm.list_leads'],
            ['seo', 'list_keywords', 'seo.list_keywords'],
            ['calendar', 'list_events', 'calendar.list_events'],
            ['builder', 'list_builder_pages', 'builder.list_builder_pages'],
            ['builder', 'get_builder_page', 'builder.get_builder_page'],
        ];
    }

    /** @dataProvider writes */
    public function test_writes_never_promote(string $engine, string $action): void
    {
        $this->assertNull(ReadToolPromotion::toolIdFor($engine, $action, $this->ids),
            "{$engine}.{$action} must not be promoted to an inline read");
    }

    public static function writes(): array
    {
        return [
            ['crm', 'create_lead'],
            ['crm', 'delete_lead'],
            ['builder', 'update_page'],
            ['builder', 'ai_builder_action'],
            ['builder', 'publish_builder_page'],
            ['write', 'write_article'],
        ];
    }
}
