<?php

namespace Tests\Feature\Catalogue;

use App\Core\Auth\RefreshTokenService;
use App\Engines\Builder\Support\CatalogueKinds;
use App\Engines\Builder\Support\CatalogueSummary;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** CAT-2: one sidebar entry per industry word; same-word companies share it; the vocabulary the Owner asked for. */
class CatalogueSummaryTest extends TestCase
{
    public function test_the_vocabulary_says_properties_and_packages_where_the_owner_asked(): void
    {
        $this->assertSame('Properties', CatalogueKinds::labels('listing', 'real_estate')['plural']);
        $this->assertSame('property', CatalogueKinds::labels('listing', 'real_estate')['singular']);
        $this->assertSame('listings', CatalogueKinds::labels('listing', 'real_estate')['page_slug'], 'live page URLs do not move');
        $this->assertSame('Packages', CatalogueKinds::labels('service', 'travel_agency')['plural']);
        $this->assertSame('trips', CatalogueKinds::labels('service', 'travel_agency')['page_slug']);
        $this->assertSame('Menu', CatalogueKinds::labels('service', 'restaurant')['plural']);
        $this->assertSame('Treatments', CatalogueKinds::labels('service', 'aesthetic_clinic')['plural']);
    }

    public function test_the_owners_rule_two_realty_companies_share_one_properties_entry_and_travel_gets_its_own(): void
    {
        $sites = [
            ['id' => 1, 'name' => 'Raymundo Realty', 'kinds' => [['kind' => 'listing', 'label' => 'Properties']]],
            ['id' => 2, 'name' => 'Coastal Homes', 'kinds' => [['kind' => 'listing', 'label' => 'Properties']]],
            ['id' => 3, 'name' => 'SG Travel', 'kinds' => [['kind' => 'service', 'label' => 'Packages']]],
            ['id' => 4, 'name' => 'Grand Hotel', 'kinds' => [['kind' => 'service', 'label' => 'Rooms & rates'], ['kind' => 'plan', 'label' => 'Packages']]],
        ];
        $g = CatalogueSummary::groups($sites);
        $this->assertSame(['properties', 'packages', 'rooms-rates'], array_column($g, 'slug'));
        $this->assertSame([1, 2], $g[0]['websites'], 'both realty companies inside one Properties entry');
        $this->assertSame([3, 4], $g[1]['websites'], 'the hotel\'s packages join the travel packages under one word');
        $this->assertSame('Rooms & rates', $g[2]['label']);
        $this->assertSame([], CatalogueSummary::groups([]));
    }

    public function test_the_summary_needs_a_bearer_and_reports_no_catalogue_for_a_workspace_without_one(): void
    {
        $u = new User(); $u->name = 'Cat Test'; $u->email = 'cat-' . uniqid() . '@example.test'; $u->password = 'Cat-Test-Password-1'; $u->status = 'active'; $u->save();
        $ws = (int) DB::table('workspaces')->insertGetId(['name' => 'cat-test', 'slug' => 'cat-test-' . uniqid(), 'timezone' => 'UTC', 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('workspace_users')->insert(['workspace_id' => $ws, 'user_id' => $u->id, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $sessionId = (int) DB::table('sessions')->insertGetId(['user_id' => $u->id, 'workspace_id' => null, 'auth_via' => 'password', 'refresh_token_hash' => hash('sha256', 'cat-' . uniqid('', true)), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        $token = app(RefreshTokenService::class)->issueAccessToken(User::findOrFail($u->id), null, 'password', $sessionId);

        $this->withHeader('Accept', 'application/json')->get('/api/catalogue/summary')->assertStatus(401);
        $r = $this->withHeader('Authorization', 'Bearer ' . $token)->withHeader('Accept', 'application/json')->get('/api/catalogue/summary');
        $r->assertOk();
        $this->assertFalse($r->json('has_catalogue'));
        $this->assertSame([], $r->json('groups'));
        $this->assertSame([], $r->json('websites'));
    }
}
