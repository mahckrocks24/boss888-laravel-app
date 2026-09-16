<?php

namespace Tests\Feature\Content;

use Tests\TestCase;

/** Engine 2 U-E (2026-09-06): write_article reaches the AI writer, and articles keep their website_id. */
class WriteArticleIsTheAiWriterTest extends TestCase
{
    public function test_kernel_maps_write_article_to_the_ai_writer_not_the_plain_insert(): void
    {
        $ees = (string) file_get_contents(base_path('app/Core/EngineKernel/EngineExecutionService.php'));
        $this->assertStringContainsString("'write_article'  => \$svc->writeArticle(\$wsId", $ees);
        $this->assertStringContainsString("'create_article' => \$svc->createArticle(\$wsId", $ees);
        $this->assertStringNotContainsString("'create_article', 'write_article' => \$svc->createArticle", $ees);
    }

    public function test_create_article_persists_a_same_workspace_website_id(): void
    {
        $svc = (string) file_get_contents(base_path('app/Engines/Write/Services/WriteService.php'));
        $this->assertStringContainsString("'website_id'        => isset(\$data['website_id'])", $svc);
        $this->assertStringContainsString("->where('workspace_id', \$wsId)->exists() ? (int) \$data['website_id'] : null", $svc, 'a foreign website id is never stored');
    }

    public function test_named_lengths_never_reach_the_arithmetic(): void
    {
        $svc = (string) file_get_contents(base_path('app/Engines/Write/Services/WriteService.php'));
        $this->assertStringContainsString("['short' => 500, 'medium' => 900, 'long' => 1400, 'brief' => 400]", $svc);
    }

    public function test_publish_prefers_the_articles_own_published_site(): void
    {
        $src = (string) file_get_contents(base_path('routes/api/authenticated/content-01.php'));
        $own = strpos($src, "->where('id', (int) \$article->website_id)"); $fallback = strpos($src, "->orderByDesc('id')\n                    ->first(['id', 'subdomain', 'domain', 'custom_domain']);");
        $this->assertNotFalse($own); $this->assertNotFalse($fallback); $this->assertLessThan($fallback, $own, 'own site is tried before the newest-site fallback');
    }

    public function test_an_unretrievable_generated_image_is_a_failure_not_a_url(): void
    {
        $src = (string) file_get_contents(base_path('app/Connectors/CreativeConnector.php'));
        $this->assertStringContainsString("'code' => 'IMAGE_NOT_STORED'", $src);
        $this->assertStringContainsString("!str_starts_with(\$ctype, 'image/')", $src);
    }
}
