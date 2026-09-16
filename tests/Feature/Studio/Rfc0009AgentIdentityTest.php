<?php

namespace Tests\Feature\Studio;

use App\Connectors\RuntimeClient;
use App\Core\ImageIntelligence\AgentSubjectResolver;
use App\Core\ImageIntelligence\ImagePromptCompiler;
use App\Engines\Creative\Services\ScenePlannerService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-0009 P2 (2026-09-16) — a platform agent named in a request is a canonical identity from the
 * registry (name, title, DEC-0055 portrait), never an invented person; and the prompt promises likeness
 * only when the generation path can actually carry a reference image.
 */
class Rfc0009AgentIdentityTest extends TestCase
{
    private function sarah(): object
    {
        $row = DB::table('agents')->where('slug', 'sarah')->first(['slug', 'name', 'title', 'avatar_url']);
        if (! $row) {
            DB::table('agents')->insert(['slug' => 'sarah', 'name' => 'Sarah', 'title' => 'Digital Marketing Manager', 'description' => 'Lead agent.', 'avatar_url' => '/img/agents/sarah.webp', 'created_at' => now(), 'updated_at' => now()]);
            $row = DB::table('agents')->where('slug', 'sarah')->first(['slug', 'name', 'title', 'avatar_url']);
        }
        return $row;
    }

    public function test_a_named_agent_resolves_to_registry_facts_and_portrait_never_to_an_appearance(): void
    {
        $s = $this->sarah();
        $r = AgentSubjectResolver::resolve('Create a promotional image for Level Up Growth featuring Sarah, our AI growth manager. Add a catchy headline.');
        $this->assertNotNull($r);
        $this->assertSame('platform_agent', $r['kind']);
        $this->assertSame($s->name, $r['name']);
        $this->assertSame((string) $s->title, $r['title']);
        $this->assertStringEndsWith(ltrim((string) $s->avatar_url, '/'), (string) $r['portrait_url']);
        $this->assertStringContainsString('Sarah', $r['prompt_facts']);
        foreach (['hair', 'blazer', 'female', 'smile', 'blonde', 'wears'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, json_encode($r));
        }
        $this->assertFalse($r['reference_supported'], 'the current image path cannot take a reference image');
        $this->assertNotEmpty($r['limitation']);
    }

    public function test_an_unknown_name_or_no_name_resolves_to_nothing(): void
    {
        $this->assertNull(AgentSubjectResolver::resolve('A cosy coffee shop latte on a wooden table'));
        $this->assertNull(AgentSubjectResolver::resolve('featuring Sarahnova the singer')); // whole-word match only
    }

    public function test_compiler_identifies_her_by_name_and_role_and_exposes_no_reference_when_unsupported(): void
    {
        $subj = AgentSubjectResolver::resolve('featuring Sarah, our AI growth manager');
        $bp = [
            'provider_prompt' => 'Promotional image', 'subject' => 'Sarah', 'composition' => 'centre', 'scene' => 'office', 'lighting' => 'soft', 'mood' => 'warm',
            'color_palette' => ['#6C5CE7'], 'brand_application' => 'brand colours', 'negative_constraints' => [], 'historical_or_factual_constraints' => [],
            'typography_strategy' => ['mode' => 'none'], 'dimensions' => ['width' => 1024, 'height' => 1024], 'quality' => 'medium',
            '_context' => ['has_logo' => false, 'logo_requested' => false, 'exact_text' => [], 'subject_reference' => $subj],
        ];
        $out = (new ImagePromptCompiler())->compile($bp);
        $this->assertStringStartsWith('The person shown is Sarah, Digital Marketing Manager of LevelUpGrowth — identify her by name and role only', $out['provider_prompt']);
        $this->assertStringContainsString('do not invent her appearance', $out['provider_prompt']);
        $this->assertStringNotContainsString('match the reference portrait', $out['provider_prompt'], 'no likeness promise without reference support');
        $this->assertSame([], $out['reference_images']);
        $this->assertSame('Sarah', $out['subject_identity']['name']);
        $this->assertFalse($out['subject_identity']['reference_supported']);
        $this->assertNotEmpty($out['subject_identity']['limitation']);

        // the day a connector supports references, the same compiler promises likeness and exposes the portrait
        $subj2 = array_merge($subj, ['reference_supported' => true]);
        $bp['_context']['subject_reference'] = $subj2;
        $out2 = (new ImagePromptCompiler())->compile($bp);
        $this->assertStringContainsString('match the reference portrait exactly', $out2['provider_prompt']);
        $this->assertSame([$subj['portrait_url']], $out2['reference_images']);
    }

    public function test_video_scene_planner_keeps_her_as_the_subject_by_name_and_role(): void
    {
        $captured = null;
        $rt = \Mockery::mock(RuntimeClient::class)->makePartial();
        $rt->shouldReceive('isConfigured')->andReturn(true);
        $rt->shouldReceive('chatJson')->andReturnUsing(function ($system) use (&$captured) { $captured = $system; return ['success' => true, 'parsed' => ['scenes' => [['index' => 1, 'duration' => 10, 'prompt' => 'Sarah, Digital Marketing Manager of LevelUpGrowth, presents the plan', 'camera' => 'static', 'description' => 'd']]]]; });
        $this->app->instance(RuntimeClient::class, $rt);
        $scenes = app(ScenePlannerService::class)->planScenes('Create a 10-second promotional video introducing Sarah, our AI growth manager.', ['duration' => 10]);
        $this->assertStringContainsString('SUBJECT: the concept names Sarah', $captured);
        $this->assertStringContainsString('never replace her with anonymous hands or a faceless figure', $captured);
        $this->assertStringContainsString('do not promise likeness', $captured);
        $this->assertCount(1, $scenes);
    }
}
