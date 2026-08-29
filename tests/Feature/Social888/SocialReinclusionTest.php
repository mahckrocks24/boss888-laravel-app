<?php

namespace Tests\Feature\Social888;

use App\Connectors\SocialConnector;
use App\Core\Governance\ApprovalPolicyRegistry;
use App\Core\LaunchScope\LaunchScopeLanguageGuard;
use App\Core\LaunchScope\LaunchScopePolicy;
use App\Http\Middleware\LaunchScopeRoutes;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * RISK-0099 (2026-08-29) — DEC-0028 put social automation IN launch; five un-mirrored layers kept it
 * dead for customers. These tests pin every layer Laravel owns; the runtime mirror ships as v2.37.10.
 */
class SocialReinclusionTest extends TestCase
{
    public function test_route_guard_no_longer_blocks_the_social_surface_but_still_blocks_email_marketing(): void
    {
        $mw = new LaunchScopeRoutes();
        $next = fn () => response()->json(['through' => true]);
        foreach (['api/social/posts', 'api/social/ai/generate', 'api/social/oauth/facebook/connect', 'api/social/accounts'] as $path) {
            $res = $mw->handle(Request::create('/' . $path, 'GET'), $next);
            $this->assertSame(200, $res->getStatusCode(), "$path must reach the app");
        }
        foreach (['api/marketing/campaigns', 'api/mentions'] as $path) {
            $res = $mw->handle(Request::create('/' . $path, 'GET'), $next);
            $this->assertSame(404, $res->getStatusCode(), "$path stays out of the product");
        }
    }

    public function test_kernel_policy_permits_standalone_social_actions_and_marcus(): void
    {
        foreach (['social_ai_post', 'hashtag_suggestions', 'create_post', 'schedule_post', 'publish_post'] as $a) {
            $this->assertNull(LaunchScopePolicy::deniedReason('social', $a), "$a must not be launch-scope denied");
        }
        $this->assertFalse(LaunchScopePolicy::isRemovedAgent('marcus'));
        $this->assertTrue(LaunchScopePolicy::isRemovedAgent('jordan'));
        $this->assertArrayNotHasKey('social', array_flip(LaunchScopePolicy::REMOVED_PLAN_FEATURES));
    }

    public function test_language_guard_no_longer_rewrites_true_social_statements(): void
    {
        $text = 'No Instagram account is connected yet — connect your Facebook Page to publish. Marcus can draft it now.';
        $this->assertSame($text, LaunchScopeLanguageGuard::apply($text));
        $bad = 'Email marketing is not connected for this workspace.';
        $this->assertNotSame($bad, LaunchScopeLanguageGuard::apply($bad), 'email marketing framing is still corrected');
    }

    public function test_owner_may_confirm_their_own_social_publish(): void
    {
        $policy = ApprovalPolicyRegistry::forCapability('social', 'social_publish_post', 'protected');
        $this->assertTrue($policy['self_approval_allowed'], 'a single-owner workspace must be able to confirm its own publish');
        $this->assertTrue($policy['human_approval_required'], 'publishing still requires the explicit approval step');
    }

    public function test_publish_task_contract_accepts_post_id(): void
    {
        $rules = app(SocialConnector::class)->validationRules('publish_post');
        $this->assertArrayHasKey('post_id', $rules);
        $this->assertStringContainsString('required_without:post_id', $rules['draft_id']);
        $this->assertStringNotContainsString('required|', $rules['platform']);
    }
}
