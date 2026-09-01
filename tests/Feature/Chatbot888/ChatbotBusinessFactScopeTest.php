<?php

namespace Tests\Feature\Chatbot888;

use App\Engines\Chatbot\Services\ChatbotContextBuilder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A visitor on one website must never be told about another website's business.
 *
 * Reported live: the chatbot on a graphic design site inside Chef Red's workspace answered with private chef
 * services, cooking classes and New Jersey. The knowledge base was scoped correctly all along — it was the
 * BUSINESS FACTS that leaked. business_name and industry had per-website overrides; location and services
 * were read straight off the workspace and handed to whichever site the visitor happened to be on.
 */
class ChatbotBusinessFactScopeTest extends TestCase
{
    /** @return array{0:int,1:int,2:int} workspace, siteA (matches the workspace), siteB (its own business) */
    private function businessWithTwoUnrelatedSites(): array
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'cb-' . uniqid() . '@example.test',
            'password' => bcrypt('x'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'Chef Example', 'slug' => 'cb-' . uniqid(), 'created_by' => $uid,
            'industry' => 'private chef', 'location' => 'New Jersey, USA',
            'services_json' => json_encode(['Private dinners', 'Cooking classes', 'Destination dining']),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $site = fn (string $name, ?string $industry, ?array $vars) => (int) DB::table('websites')->insertGetId([
            'workspace_id' => $ws, 'name' => $name, 'created_by' => $uid,
            'subdomain' => strtolower(str_replace(' ', '-', $name)) . '-' . uniqid() . '.levelupgrowth.io',
            'status' => 'published', 'type' => 'template', 'template_industry' => $industry,
            'template_variables' => $vars ? json_encode($vars) : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [
            $ws,
            $site('Chef Site', 'private chef', null),
            $site('Design Studio', 'marketing_agency', ['city' => 'Dubai', 'country' => 'AE']),
        ];
    }

    private function openSession(int $ws, int $websiteId): int
    {
        $token = (int) DB::table('chatbot_widget_tokens')->insertGetId([
            'workspace_id' => $ws, 'website_id' => $websiteId, 'token_hash' => hash('sha256', uniqid()),
            'token_prefix' => substr(uniqid(), 0, 8), 'label' => 'test', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return (int) DB::table('chatbot_sessions')->insertGetId([
            'workspace_id' => $ws, 'website_id' => $websiteId, 'widget_token_id' => $token,
            'message_count' => 0, 'created_at' => now(),
        ]);
    }

    /** The reported defect, as an assertion. */
    public function test_a_sites_chatbot_is_never_told_about_a_sibling_business(): void
    {
        [$ws, , $design] = $this->businessWithTwoUnrelatedSites();
        $ctx = app(ChatbotContextBuilder::class)->build($this->openSession($ws, $design), 'what do you offer?');

        $blob = mb_strtolower(json_encode([
            $ctx['business_name'] ?? '', $ctx['industry'] ?? '',
            $ctx['location'] ?? '', $ctx['services_csv'] ?? '',
        ]));

        foreach (['private chef', 'private dinners', 'cooking classes', 'destination dining', 'new jersey'] as $term) {
            $this->assertStringNotContainsString($term, $blob,
                "the design site's chatbot was told \"{$term}\", which belongs to a different business");
        }

        $this->assertSame('Design Studio', $ctx['business_name']);
        $this->assertSame('marketing_agency', $ctx['industry']);
        $this->assertSame('Dubai, AE', $ctx['location'], 'the site answers for its own address');
        $this->assertSame('', $ctx['services_csv'],
            'with no services of its own it must stay silent rather than borrow the workspace list');
    }

    /** The site that IS the business keeps everything — the fix must not starve the normal case. */
    public function test_a_site_matching_the_business_still_gets_the_business_facts(): void
    {
        [$ws, $chef] = $this->businessWithTwoUnrelatedSites();
        $ctx = app(ChatbotContextBuilder::class)->build($this->openSession($ws, $chef), 'what do you offer?');

        $this->assertSame('private chef', $ctx['industry']);
        $this->assertSame('New Jersey, USA', $ctx['location']);
        $this->assertStringContainsString('Cooking classes', $ctx['services_csv']);
    }

    /** A single-site workspace is the common case and must be unaffected. */
    public function test_a_one_site_business_is_unaffected(): void
    {
        [$ws, $chef] = $this->businessWithTwoUnrelatedSites();
        DB::table('websites')->where('workspace_id', $ws)->where('id', '!=', $chef)->delete();

        $ctx = app(ChatbotContextBuilder::class)->build($this->openSession($ws, $chef), 'hello');

        $this->assertSame('New Jersey, USA', $ctx['location']);
        $this->assertNotSame('', $ctx['services_csv']);
    }
}
