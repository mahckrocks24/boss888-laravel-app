<?php

namespace Tests\Feature\Business;

use App\Core\Business\BusinessMemory;
use App\Core\Business\BusinessProfileResolver;
use App\Models\Business;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-0011 U1: the default business mirrors workspaces.*, the resolver's fallback order holds, the kill-switch keeps
 * today's behaviour, memory is segregated and combinable, and the backfill is idempotent.
 */
class BusinessProfileTest extends TestCase
{
    private int $ws;
    private int $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['business.profiles' => false, 'business.qa_workspaces' => []]);
        $u = new \App\Models\User(); $u->name = 'Biz Test'; $u->email = 'biz-' . uniqid() . '@example.test'; $u->password = 'Biz-Test-Password-1'; $u->status = 'active'; $u->save();
        $this->ws = (int) DB::table('workspaces')->insertGetId([
            'name' => 'biz-test', 'slug' => 'biz-test-' . uniqid(), 'timezone' => 'UTC', 'created_by' => $u->id,
            'business_name' => 'Chef Test', 'industry' => 'private chef', 'services_json' => json_encode(['Private dinners', 'Meal prep']),
            'goal' => 'more bookings', 'location' => 'Newark, NJ', 'brand_color' => '#112233',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->site = (int) DB::table('websites')->insertGetId(['workspace_id' => $this->ws, 'name' => 'Chef Test Site', 'subdomain' => 'biz-' . uniqid() . '.levelupgrowth.io', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_memory')->insert([
            ['workspace_id' => $this->ws, 'key' => 'industry', 'value_json' => json_encode('private chef + personal chef services'), 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $this->ws, 'key' => 'location', 'value_json' => json_encode('Newark, NJ (tri-state)'), 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $this->ws, 'key' => 'tone', 'value_json' => json_encode('warm and precise'), 'created_at' => now(), 'updated_at' => now()],
            ['workspace_id' => $this->ws, 'key' => 'pricing_anchor', 'value_json' => json_encode(['value' => '$75-200/hr']), 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function backfill(): void
    {
        Artisan::call('business:backfill', ['--workspace' => $this->ws]);
        app(BusinessProfileResolver::class)->forget($this->ws);
    }

    public function test_backfill_creates_one_default_business_from_the_workspace_and_memory_and_is_idempotent(): void
    {
        $before = DB::table('workspaces')->where('id', $this->ws)->first();
        $this->backfill();
        $after = DB::table('workspaces')->where('id', $this->ws)->first();
        foreach (array_keys(Business::MIRROR) as $col) { $this->assertEquals($before->$col, $after->$col, "workspaces.{$col} untouched by the FIRST backfill run"); }
        $this->assertSame(1, Business::where('workspace_id', $this->ws)->count());
        $b = Business::where('workspace_id', $this->ws)->first();
        $this->assertTrue($b->is_default);
        $this->assertSame('Chef Test', $b->name);
        $this->assertSame('private chef', $b->industry, 'the row carries the COLUMN value, not the memory value');
        $this->assertSame('Newark, NJ', $b->location);
        $this->assertSame(['Private dinners', 'Meal prep'], $b->services_json);
        $this->assertSame('warm and precise', $b->tone);
        $this->assertSame('$75-200/hr', $b->pricing_anchor);
        $this->assertSame($b->id, (int) DB::table('websites')->where('id', $this->site)->value('business_id'));

        $before = DB::table('workspaces')->where('id', $this->ws)->first();
        $this->backfill();
        $this->assertSame(1, Business::where('workspace_id', $this->ws)->count(), 'second run creates nothing');
        $after = DB::table('workspaces')->where('id', $this->ws)->first();
        foreach (array_keys(Business::MIRROR) as $col) { $this->assertEquals($before->$col, $after->$col, "workspaces.{$col} untouched by backfill"); }
    }

    public function test_resolver_returns_todays_values_with_the_switch_off_and_without_any_business_row(): void
    {
        $p = app(BusinessProfileResolver::class)->profile($this->ws);
        $this->assertSame('Chef Test', $p['name']);
        $this->assertSame('private chef + personal chef services', $p['industry'], 'memory first, as the facts block reads today');
        $this->assertSame(['Private dinners', 'Meal prep'], $p['services']);
        $this->assertSame('Newark, NJ (tri-state)', $p['location']);
        $this->assertSame('warm and precise', $p['tone']);
        $this->assertSame('$75-200/hr', $p['pricing_anchor']);
        $this->assertNull($p['business_id']);

        $this->backfill();
        $p2 = app(BusinessProfileResolver::class)->profile($this->ws);
        foreach (['name', 'industry', 'services', 'location', 'tone', 'pricing_anchor', 'goal', 'brand_color'] as $k) { $this->assertEquals($p[$k], $p2[$k], "profile.{$k} identical after backfill"); }
        $this->assertNotNull($p2['business_id']);
    }

    public function test_the_default_business_mirrors_onto_the_workspace_and_back(): void
    {
        $this->backfill();
        $b = Business::where('workspace_id', $this->ws)->first();
        $b->industry = 'personal chef'; $b->location = 'Hoboken, NJ'; $b->save();
        $ws = DB::table('workspaces')->where('id', $this->ws)->first();
        $this->assertSame('personal chef', $ws->industry);
        $this->assertSame('Hoboken, NJ', $ws->location);

        DB::table('workspaces')->where('id', $this->ws)->update(['business_name' => 'Chef Test Two', 'services_json' => json_encode(['Catering'])]);
        Business::syncDefaultFromWorkspace($this->ws);
        $b->refresh();
        $this->assertSame('Chef Test Two', $b->name);
        $this->assertSame(['Catering'], $b->services_json);
    }

    public function test_with_the_switch_on_a_second_business_has_its_own_profile_and_never_inherits_the_defaults_facts(): void
    {
        $this->backfill();
        config(['business.qa_workspaces' => [$this->ws]]);
        $gym = Business::create(['workspace_id' => $this->ws, 'name' => 'Test Gym', 'slug' => 'test-gym', 'industry' => 'fitness', 'services_json' => ['Memberships'], 'location' => 'Jersey City, NJ', 'is_default' => false, 'sort_order' => 1]);
        $r = app(BusinessProfileResolver::class); $r->forget($this->ws);
        $this->assertTrue($r->isMulti($this->ws));

        $p = $r->profile($this->ws, (int) $gym->id);
        $this->assertSame('Test Gym', $p['name']);
        $this->assertSame('fitness', $p['industry']);
        $this->assertSame(['Memberships'], $p['services']);
        $this->assertNull($p['tone'], 'the default business\'s tone must not leak into the gym');
        $this->assertNull($p['pricing_anchor']);

        app(BusinessMemory::class)->set($this->ws, (int) $gym->id, 'pricing_anchor', '$49/month');
        $r->forget($this->ws);
        $this->assertSame('$49/month', $r->profile($this->ws, (int) $gym->id)['pricing_anchor']);
        $this->assertSame('$75-200/hr', $r->profile($this->ws)['pricing_anchor'], 'the default keeps its own');

        $ws = DB::table('workspaces')->where('id', $this->ws)->first();
        $this->assertSame('Chef Test', $ws->business_name, 'a non-default business never mirrors onto workspaces.*');
        $this->assertSame('private chef', $ws->industry, 'the column keeps its own value; memory never overwrites it');
    }

    public function test_memory_is_segregated_per_business_and_combinable(): void
    {
        $this->backfill();
        config(['business.qa_workspaces' => [$this->ws]]);
        $default = Business::where('workspace_id', $this->ws)->first();
        $gym = Business::create(['workspace_id' => $this->ws, 'name' => 'Test Gym', 'slug' => 'test-gym', 'is_default' => false]);
        app(BusinessProfileResolver::class)->forget($this->ws);
        $m = app(BusinessMemory::class);
        $m->set($this->ws, (int) $gym->id, 'tone', 'punchy');

        $this->assertSame('punchy', $m->get($this->ws, (int) $gym->id, 'tone'));
        $this->assertSame('warm and precise', $m->get($this->ws, (int) $default->id, 'tone'), 'the default falls back to the portfolio value');
        $this->assertNull($m->get($this->ws, (int) $gym->id, 'pricing_anchor'), 'the gym does not see the portfolio pricing');
        $this->assertSame(['Chef Test' => 'warm and precise', 'Test Gym' => 'punchy'], $m->combined($this->ws, 'tone'));
        $this->assertSame('biz:' . $gym->id . ':tone', BusinessMemory::key((int) $gym->id, 'tone'));
        $this->assertSame(['tone' => 'punchy'], $m->segregated($this->ws, (int) $gym->id));
    }

    public function test_the_switch_off_hides_every_business_but_the_default(): void
    {
        $this->backfill();
        $gym = Business::create(['workspace_id' => $this->ws, 'name' => 'Test Gym', 'slug' => 'test-gym', 'industry' => 'fitness', 'is_default' => false]);
        $r = app(BusinessProfileResolver::class); $r->forget($this->ws);
        $this->assertFalse($r->isMulti($this->ws));
        $this->assertSame('private chef + personal chef services', $r->profile($this->ws, (int) $gym->id)['industry'], 'with the switch off every id resolves to the default (memory-first, as today)');
    }
}
