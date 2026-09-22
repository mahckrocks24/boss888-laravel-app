<?php

namespace Tests\Feature\Business;

use App\Core\Auth\RefreshTokenService;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** RFC-0011 U5a: the businesses API — list, create, edit, default (mirrors), delete refusals, website assignment. */
class BusinessApiTest extends TestCase
{
    private int $ws; private string $token; private int $site;

    protected function setUp(): void
    {
        parent::setUp();
        $u = new User(); $u->name = 'Biz API'; $u->email = 'bizapi-' . uniqid() . '@example.test'; $u->password = 'Biz-Api-Password-1'; $u->status = 'active'; $u->save();
        $this->ws = (int) DB::table('workspaces')->insertGetId(['name' => 'bizapi', 'slug' => 'bizapi-' . uniqid(), 'timezone' => 'UTC', 'created_by' => $u->id, 'business_name' => 'Chef Api', 'industry' => 'private chef', 'location' => 'Newark, NJ', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => $this->ws, 'user_id' => $u->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $this->site = (int) DB::table('websites')->insertGetId(['workspace_id' => $this->ws, 'name' => 'Chef Api Site', 'subdomain' => 'bizapi-' . uniqid() . '.levelupgrowth.io', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        Artisan::call('business:backfill', ['--workspace' => $this->ws]);
        $sessionId = (int) DB::table('sessions')->insertGetId(['user_id' => $u->id, 'workspace_id' => null, 'auth_via' => 'password', 'refresh_token_hash' => hash('sha256', 'bizapi-' . uniqid('', true)), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $this->token = app(RefreshTokenService::class)->issueAccessToken(User::findOrFail($u->id), null, 'password', $sessionId);
    }

    private function api() { return $this->withHeader('Authorization', 'Bearer ' . $this->token)->withHeader('Accept', 'application/json'); }

    public function test_list_create_edit_default_and_delete_rules(): void
    {
        $this->withHeader('Accept', 'application/json')->getJson('/api/businesses')->assertStatus(401);
        $r = $this->api()->getJson('/api/businesses'); $r->assertOk();
        $this->assertCount(1, $r->json('businesses')); $this->assertTrue($r->json('businesses.0.is_default')); $this->assertSame('Chef Api', $r->json('businesses.0.name'));
        $this->assertSame(1, $r->json('businesses.0.counts.websites'), 'the backfilled website belongs to the default');

        $c = $this->api()->postJson('/api/businesses', ['name' => 'Api Gym', 'industry' => 'fitness', 'services' => 'Memberships, Personal training', 'location' => 'Jersey City, NJ', 'pricing_anchor' => '$49/month', 'aliases' => ['the gym']]);
        $c->assertStatus(201); $gymId = (int) $c->json('business.id');
        $this->assertSame(['Memberships', 'Personal training'], $c->json('business.services')); $this->assertFalse($c->json('business.is_default'));
        $this->assertSame('private chef', DB::table('workspaces')->where('id', $this->ws)->value('industry'), 'a second business never mirrors onto the workspace');
        $this->api()->postJson('/api/businesses', ['name' => ''])->assertStatus(422);

        $e = $this->api()->putJson('/api/businesses/' . $gymId, ['name' => 'Api Gym & Spa', 'industry' => 'fitness and wellness']);
        $e->assertOk(); $this->assertSame('Api Gym & Spa', $e->json('business.name')); $this->assertSame('api-gym-spa', $e->json('business.slug'));

        // editing the DEFAULT mirrors onto the workspace profile
        $defaultId = (int) $r->json('businesses.0.id');
        $this->api()->putJson('/api/businesses/' . $defaultId, ['industry' => 'personal chef'])->assertOk();
        $this->assertSame('personal chef', DB::table('workspaces')->where('id', $this->ws)->value('industry'));

        // the default cannot be deleted; a business owning websites cannot be deleted
        $this->api()->deleteJson('/api/businesses/' . $defaultId)->assertStatus(422);
        $this->api()->putJson('/api/businesses/' . $gymId . '/websites', ['website_ids' => [$this->site]])->assertOk();
        $this->assertSame($gymId, (int) DB::table('websites')->where('id', $this->site)->value('business_id'));
        $this->api()->deleteJson('/api/businesses/' . $gymId)->assertStatus(422);

        // making the gym the default mirrors ITS profile onto the workspace
        $this->api()->postJson('/api/businesses/' . $gymId . '/default')->assertOk();
        $this->assertSame('Api Gym & Spa', DB::table('workspaces')->where('id', $this->ws)->value('business_name'));
        $this->assertSame('fitness and wellness', DB::table('workspaces')->where('id', $this->ws)->value('industry'));
        $this->assertSame(1, Business::where('workspace_id', $this->ws)->where('is_default', true)->count());

        // unassigning the website moves it to the (new) default, then the old default can be deleted once it owns nothing
        $this->api()->putJson('/api/businesses/' . $gymId . '/websites', ['website_ids' => [$this->site]])->assertOk();
        $this->api()->deleteJson('/api/businesses/' . $defaultId)->assertOk();
        $this->assertNotNull(Business::withTrashed()->find($defaultId)->deleted_at);
    }

    public function test_a_created_website_is_stamped_with_the_named_business_else_the_default(): void
    {
        $default = Business::where('workspace_id', $this->ws)->where('is_default', true)->first();
        $gym = Business::create(['workspace_id' => $this->ws, 'name' => 'Stamp Gym', 'slug' => 'stamp-gym', 'is_default' => false]);
        $a = (int) DB::table('websites')->insertGetId(['workspace_id' => $this->ws, 'name' => 'Gym Site', 'subdomain' => 'stamp-a-' . uniqid() . '.levelupgrowth.io', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $b = (int) DB::table('websites')->insertGetId(['workspace_id' => $this->ws, 'name' => 'Plain Site', 'subdomain' => 'stamp-b-' . uniqid() . '.levelupgrowth.io', 'status' => 'draft', 'created_at' => now(), 'updated_at' => now()]);
        $other = (int) DB::table('workspaces')->insertGetId(['name' => 'other', 'slug' => 'other-' . uniqid(), 'timezone' => 'UTC', 'created_by' => DB::table('workspaces')->where('id', $this->ws)->value('created_by'), 'created_at' => now(), 'updated_at' => now()]);
        $foreign = Business::create(['workspace_id' => $other, 'name' => 'Foreign', 'slug' => 'foreign', 'is_default' => true]);
        $this->assertSame((int) $gym->id, Business::stampWebsite($this->ws, $a, (int) $gym->id));
        $this->assertSame((int) $default->id, Business::stampWebsite($this->ws, $b, null), 'no business named -> the default');
        $this->assertSame((int) $default->id, Business::stampWebsite($this->ws, $b, (int) $foreign->id), 'another workspace business is never accepted');
        $this->assertSame((int) $gym->id, (int) DB::table('websites')->where('id', $a)->value('business_id'));
        $this->assertSame((int) $default->id, (int) DB::table('websites')->where('id', $b)->value('business_id'));
    }
}
