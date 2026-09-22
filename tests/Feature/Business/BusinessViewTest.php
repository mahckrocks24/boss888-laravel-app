<?php

namespace Tests\Feature\Business;

use App\Core\Business\BusinessProfileResolver;
use App\Models\Business;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RFC-0011 U2: the workspace VIEW a reader gets from the resolver equals Workspace::find / the workspaces row for every
 * workspace while the switch is off; with the switch on, a website attached to a second business sees that business's
 * profile; a view can never be saved.
 */
class BusinessViewTest extends TestCase
{
    private const COLS = ['business_name', 'industry', 'services_json', 'goal', 'location', 'brand_color', 'logo_url'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['business.profiles' => false, 'business.qa_workspaces' => []]);
    }

    public function test_every_workspace_view_equals_the_raw_workspace_with_the_switch_off(): void
    {
        $r = app(BusinessProfileResolver::class);
        $ids = DB::table('workspaces')->orderBy('id')->pluck('id');
        $this->assertGreaterThan(0, $ids->count());
        foreach ($ids as $id) {
            $raw = Workspace::find($id); $view = $r->workspaceFor((int) $id); $row = $r->workspaceRowFor((int) $id);
            foreach (self::COLS as $c) {
                $this->assertEquals($raw->getAttributes()[$c] ?? null, $view->getAttributes()[$c] ?? null, "ws {$id} model view {$c}");
                $this->assertEquals(DB::table('workspaces')->where('id', $id)->value($c), $row->$c, "ws {$id} row view {$c}");
            }
            $this->assertFalse($view->isBusinessView, "ws {$id}: no overlay with the switch off");
        }
    }

    public function test_a_website_of_a_second_business_sees_that_business_with_the_switch_on_and_the_view_cannot_be_saved(): void
    {
        $u = new \App\Models\User(); $u->name = 'View Test'; $u->email = 'view-' . uniqid() . '@example.test'; $u->password = 'View-Test-Password-1'; $u->status = 'active'; $u->save();
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'view-test', 'slug' => 'view-test-' . uniqid(), 'timezone' => 'UTC', 'created_by' => $u->id, 'business_name' => 'Chef View', 'industry' => 'private chef', 'location' => 'Newark, NJ', 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\Artisan::call('business:backfill', ['--workspace' => $ws]);
        $gym = Business::create(['workspace_id' => $ws, 'name' => 'View Gym', 'slug' => 'view-gym', 'industry' => 'fitness', 'services_json' => ['Memberships'], 'location' => 'Jersey City, NJ', 'is_default' => false, 'sort_order' => 1]);
        $site = (int) DB::table('websites')->insertGetId(['workspace_id' => $ws, 'business_id' => $gym->id, 'name' => 'Gym Site', 'subdomain' => 'view-gym-' . uniqid() . '.levelupgrowth.io', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $r = app(BusinessProfileResolver::class); $r->forget($ws);

        // switch off: the gym's website still sees the workspace columns (today)
        $off = $r->workspaceForWebsite($site);
        $this->assertSame('private chef', $off->industry);
        $this->assertFalse($off->isBusinessView);

        config(['business.qa_workspaces' => [$ws]]);
        $on = $r->workspaceForWebsite($site);
        $this->assertSame('View Gym', $on->business_name);
        $this->assertSame('fitness', $on->industry);
        $this->assertSame(['Memberships'], $on->services_json);
        $this->assertSame('Jersey City, NJ', $on->location);
        $this->assertTrue($on->isBusinessView);
        $row = $r->workspaceRowForWebsite($site, ['business_name', 'industry', 'location']);
        $this->assertSame('fitness', $row->industry);
        $this->assertSame('View Gym', $row->business_name);

        // the default business's own website sees the columns
        $default = Business::where('workspace_id', $ws)->where('is_default', true)->first();
        $site2 = (int) DB::table('websites')->insertGetId(['workspace_id' => $ws, 'business_id' => $default->id, 'name' => 'Chef Site', 'subdomain' => 'view-chef-' . uniqid() . '.levelupgrowth.io', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('private chef', $r->workspaceForWebsite($site2)->industry);

        // a view is never persisted
        $on->industry = 'should not land';
        $this->assertFalse($on->save());
        $this->assertSame('private chef', DB::table('workspaces')->where('id', $ws)->value('industry'));
    }
}
