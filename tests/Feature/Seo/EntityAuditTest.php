<?php

namespace Tests\Feature\Seo;

use App\Core\Business\EntityAudit;
use Tests\TestCase;

/**
 * K10 (2026-09-25): a page must not look perfectly optimised while publishing
 * the wrong business.
 *
 * The twelve existing AEO checks are all about the shape of an answer - TLDR
 * near the top, question-style H2s, lists, meta description length. A page
 * could pass all twelve while telling Google that 127.0.0.1 published it, which
 * 8 stored articles did and 174 more named the platform.
 */
class EntityAuditTest extends TestCase
{
    private function audit(): EntityAudit
    {
        return app(EntityAudit::class);
    }

    private function page(array ...$blocks): string
    {
        $html = '<html><head>';
        foreach ($blocks as $block) {
            $html .= '<script type="application/ld+json">' . json_encode($block) . '</script>';
        }

        return $html . '</head><body>x</body></html>';
    }

    private function checkStatus(array $result, string $check): string
    {
        return $result['checks'][$check]['status'] ?? 'missing';
    }

    public function test_a_page_with_no_structured_data_fails_outright(): void
    {
        $result = $this->audit()->run('<html><body>nothing</body></html>', 'example.com', 'bakery');

        $this->assertFalse($result['entity_ok']);
        $this->assertSame(EntityAudit::FAIL, $this->checkStatus($result, 'entity_present'));
    }

    public function test_a_generic_type_is_caught_when_the_industry_is_specific(): void
    {
        $html = $this->page([
            '@context' => 'https://schema.org', '@type' => 'LocalBusiness',
            'name' => 'AMG', 'url' => 'https://example.com',
        ]);

        $result = $this->audit()->run($html, 'example.com', 'travel_agency');

        $this->assertSame(EntityAudit::FAIL, $this->checkStatus($result, 'entity_type_specific'));
        $this->assertStringContainsString('TravelAgency', $result['checks']['entity_type_specific']['detail']);
    }

    public function test_a_specific_type_passes(): void
    {
        $html = $this->page([
            '@context' => 'https://schema.org', '@type' => 'TravelAgency',
            '@id' => 'https://example.com/#organization', 'name' => 'AMG',
            'url' => 'https://example.com/', 'telephone' => '+63 1 234',
        ]);

        $result = $this->audit()->run($html, 'example.com', 'travel_agency');

        $this->assertSame(EntityAudit::PASS, $this->checkStatus($result, 'entity_type_specific'));
        $this->assertSame(EntityAudit::PASS, $this->checkStatus($result, 'entity_has_stable_id'));
        $this->assertSame(EntityAudit::PASS, $this->checkStatus($result, 'entity_contactable'));
    }

    public function test_a_missing_id_is_caught(): void
    {
        $html = $this->page(['@context' => 'https://schema.org', '@type' => 'Bakery', 'name' => 'B', 'url' => 'https://example.com']);

        $this->assertSame(EntityAudit::FAIL, $this->checkStatus($this->audit()->run($html, 'example.com', 'bakery'), 'entity_has_stable_id'));
    }

    public function test_a_foreign_host_in_the_graph_is_caught(): void
    {
        $html = $this->page([
            '@context' => 'https://schema.org', '@type' => 'Bakery',
            '@id' => 'https://levelupgrowth.io/#organization', 'url' => 'https://levelupgrowth.io/',
        ]);

        $result = $this->audit()->run($html, 'example.com', 'bakery');

        $this->assertSame(EntityAudit::FAIL, $this->checkStatus($result, 'entity_canonical_host'));
        $this->assertStringContainsString('levelupgrowth.io', $result['checks']['entity_canonical_host']['detail']);
    }

    public function test_the_platform_named_as_publisher_is_caught(): void
    {
        // The exact defect K1 closed, now visible to the audit.
        $html = $this->page([
            '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'X',
            'publisher' => ['@type' => 'Organization', 'name' => 'LevelUpGrowth', 'url' => 'https://levelupgrowth.io'],
        ]);

        $result = $this->audit()->run($html, 'example.com', 'bakery');

        $this->assertSame(EntityAudit::FAIL, $this->checkStatus($result, 'entity_publisher_correct'));
        $this->assertStringContainsString('levelupgrowth.io', $result['checks']['entity_publisher_correct']['detail']);
    }

    public function test_an_anonymous_republished_organization_is_caught(): void
    {
        $html = $this->page([
            '@context' => 'https://schema.org', '@type' => 'Article', 'headline' => 'X',
            'publisher' => ['@type' => 'Organization', 'name' => 'Mine', 'url' => 'https://example.com'],
        ]);

        $result = $this->audit()->run($html, 'example.com', 'bakery');

        $this->assertSame(EntityAudit::PASS, $this->checkStatus($result, 'entity_publisher_correct'), 'the host is right');
        $this->assertSame(EntityAudit::FAIL, $this->checkStatus($result, 'entity_nodes_linked'), 'but it is a second anonymous node');
    }

    public function test_a_reference_into_the_same_graph_passes(): void
    {
        $html = $this->page([
            '@context' => 'https://schema.org',
            '@graph' => [
                ['@type' => 'Article', 'headline' => 'X', 'publisher' => ['@id' => 'https://example.com/#organization']],
                ['@type' => 'Bakery', '@id' => 'https://example.com/#organization', 'name' => 'B', 'url' => 'https://example.com/', 'telephone' => '+1 1'],
            ],
        ]);

        $result = $this->audit()->run($html, 'example.com', 'bakery');

        $this->assertSame(EntityAudit::PASS, $this->checkStatus($result, 'entity_nodes_linked'));
        $this->assertSame(EntityAudit::PASS, $this->checkStatus($result, 'entity_publisher_correct'));
        $this->assertTrue($result['entity_ok']);
    }

    public function test_a_dangling_reference_is_caught(): void
    {
        $html = $this->page([
            '@context' => 'https://schema.org',
            '@graph' => [['@type' => 'Article', 'headline' => 'X', 'publisher' => ['@id' => 'https://example.com/#nowhere']]],
        ]);

        $this->assertSame(EntityAudit::FAIL, $this->checkStatus($this->audit()->run($html, 'example.com', 'bakery'), 'entity_nodes_linked'));
    }

    // ---- what must NOT be penalised -------------------------------------

    public function test_a_news_organization_is_not_penalised_for_having_no_address(): void
    {
        $html = $this->page([
            '@context' => 'https://schema.org', '@type' => 'NewsMediaOrganization',
            '@id' => 'https://example.com/#organization', 'name' => 'Kabayan', 'url' => 'https://example.com/',
        ]);

        $result = $this->audit()->run($html, 'example.com', 'news_channel');

        $this->assertSame(EntityAudit::SKIP, $this->checkStatus($result, 'entity_contactable'));
        $this->assertTrue($result['entity_ok'], 'a publisher is not a storefront');
    }

    public function test_a_page_without_an_article_is_not_judged_on_publishers(): void
    {
        $html = $this->page([
            '@context' => 'https://schema.org', '@type' => 'Bakery',
            '@id' => 'https://example.com/#organization', 'name' => 'B',
            'url' => 'https://example.com/', 'telephone' => '+1 1',
        ]);

        $result = $this->audit()->run($html, 'example.com', 'bakery');

        $this->assertSame(EntityAudit::SKIP, $this->checkStatus($result, 'entity_publisher_correct'));
        $this->assertSame(EntityAudit::SKIP, $this->checkStatus($result, 'entity_nodes_linked'));
        $this->assertTrue($result['entity_ok']);
    }

    public function test_an_unmapped_industry_does_not_demand_a_specific_type(): void
    {
        $html = $this->page([
            '@context' => 'https://schema.org', '@type' => 'LocalBusiness',
            '@id' => 'https://example.com/#organization', 'name' => 'Odd', 'url' => 'https://example.com/', 'telephone' => '+1 1',
        ]);

        $result = $this->audit()->run($html, 'example.com', 'artisan cheesemonger');

        $this->assertSame(EntityAudit::PASS, $this->checkStatus($result, 'entity_type_specific'), 'LocalBusiness IS the right answer when nothing more specific is known');
    }

    public function test_a_blogposting_is_not_mistaken_for_the_business(): void
    {
        // BlogPosting does not contain the string "Article".
        $html = $this->page([
            '@context' => 'https://schema.org', '@type' => 'BlogPosting',
            'headline' => 'X', 'url' => 'https://example.com/blog/x',
        ]);

        $result = $this->audit()->run($html, 'example.com', 'bakery');

        $this->assertSame(EntityAudit::SKIP, $this->checkStatus($result, 'entity_type_specific'));
        $this->assertSame(EntityAudit::SKIP, $this->checkStatus($result, 'entity_contactable'));
    }
}
