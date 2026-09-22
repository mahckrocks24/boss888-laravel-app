<?php

namespace Tests\Feature\Business;

use App\Core\Business\BusinessHistory;
use App\Core\Business\BusinessProfileResolver;
use App\Core\Sarah888\CommitmentStore;
use App\Models\Business;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RFC-0011 U4a: history and commitments follow the business; rows from before businesses existed stay visible. */
class BusinessHistoryTest extends TestCase
{
    private int $ws; private Business $chef; private Business $gym;

    protected function setUp(): void
    {
        parent::setUp();
        $u = new \App\Models\User(); $u->name = 'Hist Test'; $u->email = 'hist-' . uniqid() . '@example.test'; $u->password = 'Hist-Test-Password-1'; $u->status = 'active'; $u->save();
        $this->ws = (int) DB::table('workspaces')->insertGetId(['name' => 'hist-test', 'slug' => 'hist-test-' . uniqid(), 'timezone' => 'UTC', 'created_by' => $u->id, 'business_name' => 'Chef Hist', 'industry' => 'private chef', 'created_at' => now(), 'updated_at' => now()]);
        Artisan::call('business:backfill', ['--workspace' => $this->ws]);
        $this->chef = Business::where('workspace_id', $this->ws)->where('is_default', true)->first();
        $this->gym = Business::create(['workspace_id' => $this->ws, 'name' => 'Hist Gym', 'slug' => 'hist-gym', 'is_default' => false, 'sort_order' => 1]);
        app(BusinessProfileResolver::class)->forget($this->ws);
        app()->forgetInstance('sarah.business_id');
    }

    protected function tearDown(): void { app()->forgetInstance('sarah.business_id'); parent::tearDown(); }

    private function audit(string $content, ?int $bizId): void
    {
        DB::table('audit_logs')->insert(['workspace_id' => $this->ws, 'action' => 'agent.direct_message', 'entity_type' => 'Agent',
            'metadata_json' => json_encode(['agent_slug' => 'sarah', 'from' => 'User', 'content' => $content, 'business_id' => $bizId]), 'created_at' => now()]);
    }

    private function history(?int $bizId): array
    {
        $q = DB::table('audit_logs')->where('workspace_id', $this->ws)->where('action', 'agent.direct_message');
        BusinessHistory::apply($q, $bizId);
        return $q->orderBy('id')->get()->map(fn ($r) => json_decode($r->metadata_json, true)['content'])->all();
    }

    public function test_history_shows_the_active_business_and_the_untagged_past_never_the_other_business(): void
    {
        $this->audit('old turn before businesses', null);
        $this->audit('chef: dinner for eight', $this->chef->id);
        $this->audit('gym: new membership tier', $this->gym->id);
        $this->assertSame(['old turn before businesses', 'chef: dinner for eight'], $this->history($this->chef->id));
        $this->assertSame(['old turn before businesses', 'gym: new membership tier'], $this->history($this->gym->id));
        $this->assertCount(3, $this->history(null), 'a portfolio or single turn sees everything');
    }

    public function test_commitments_are_stamped_with_the_bound_business_and_rendered_for_it_only(): void
    {
        $store = app(CommitmentStore::class);
        app()->instance('sarah.business_id', (int) $this->chef->id);
        $chefId = $store->record($this->ws, ['title' => 'Chef Hist: publish the winter menu', 'actor' => 'sarah', 'confidence' => 1.0]);
        app()->instance('sarah.business_id', (int) $this->gym->id);
        $gymId = $store->record($this->ws, ['title' => 'Hist Gym: launch the January offer', 'actor' => 'sarah', 'confidence' => 1.0]);
        $this->assertSame((int) $this->chef->id, (int) DB::table('sarah_commitments')->where('id', $chefId)->value('business_id'));
        $this->assertSame((int) $this->gym->id, (int) DB::table('sarah_commitments')->where('id', $gymId)->value('business_id'));

        app()->instance('sarah.business_id', (int) $this->gym->id);
        $gymView = $store->renderForPrompt($this->ws);
        $this->assertStringContainsString('January offer', $gymView);
        $this->assertStringNotContainsString('winter menu', $gymView, 'the chef commitment is not listed in the gym turn');
        $this->assertStringContainsString('Other businesses in this workspace hold 1 open commitment', $gymView);

        app()->forgetInstance('sarah.business_id');
        $all = $store->renderForPrompt($this->ws);
        $this->assertStringContainsString('January offer', $all);
        $this->assertStringContainsString('winter menu', $all, 'a portfolio turn lists every business');
        $this->assertStringNotContainsString('Other businesses in this workspace', $all);
    }
}
