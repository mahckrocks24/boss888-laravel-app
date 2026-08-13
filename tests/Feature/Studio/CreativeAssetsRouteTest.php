<?php

namespace Tests\Feature\Studio;

use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use ReflectionFunction;
use Tests\Helpers\Boss888TestHelper;
use Tests\TestCase;

/**
 * STUDIO888 WAVE 2 — the creative assets endpoint must not be stubbed.
 *
 * routes/api.php carried a hardcoded placeholder:
 *
 *     Route::get("/creative/assets", fn() => response()->json(["assets" => []]));
 *
 * The genuine route lives in routes/api/authenticated/studio-01.php, which is
 * required from api.php much earlier — so the later stub overwrote it in the
 * RouteCollection and every caller received {"assets":[]}. ws2 had 398 assets
 * the whole time; Studio's Assets surface was starved by a placeholder, not by
 * any data or model problem.
 *
 * The tell was the response SHAPE: listAssets() always returns `assets` AND
 * `total`, so a body with no `total` key could not have come from the service.
 */
class CreativeAssetsRouteTest extends TestCase
{
    use RefreshDatabase, Boss888TestHelper;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpBoss888();
    }

    /** @test */
    public function exactly_one_route_serves_the_creative_assets_listing(): void
    {
        $matches = 0;
        foreach (Route::getRoutes() as $route) {
            if ($route->uri() === 'api/creative/assets' && in_array('GET', $route->methods(), true)) {
                $matches++;
            }
        }
        $this->assertSame(1, $matches, 'duplicate registrations silently overwrite each other');
    }

    /** @test */
    public function the_assets_route_is_served_by_the_real_service_not_a_stub(): void
    {
        $route = Route::getRoutes()->match(
            \Illuminate\Http\Request::create('/api/creative/assets', 'GET')
        );

        $uses = $route->getAction()['uses'] ?? null;
        $this->assertInstanceOf(Closure::class, $uses, 'expected the studio-01 closure');

        $ref  = new ReflectionFunction($uses);
        $src  = implode('', array_slice(
            file($ref->getFileName()),
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1
        ));

        $this->assertStringContainsString('listAssets', $src,
            'the assets route must call CreativeService::listAssets');
        $this->assertStringNotContainsString('"assets" => []', $src,
            'the assets route must not be a hardcoded empty stub');
        $this->assertStringContainsString('studio-01.php', $ref->getFileName(),
            'the canonical route lives in studio-01.php');
    }

    /** @test */
    public function the_endpoint_returns_the_workspaces_real_assets_with_a_total(): void
    {
        $ws = (int) $this->testWorkspace->id;
        foreach ([['type' => 'image', 'n' => 3], ['type' => 'video', 'n' => 2]] as $spec) {
            for ($i = 0; $i < $spec['n']; $i++) {
                DB::table('assets')->insert([
                    'workspace_id' => $ws,
                    'type'         => $spec['type'],
                    'title'        => $spec['type'] . '-' . $i,
                    'status'       => 'completed',
                    'url'          => 'https://example.test/' . $spec['type'] . $i . '.bin',
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
            }
        }

        $res = $this->withHeaders($this->authHeaders())->getJson('/api/creative/assets');
        $res->assertOk();

        // A stub could satisfy `assets`; only the real service also returns `total`.
        $res->assertJsonStructure(['assets', 'total']);
        $this->assertSame(5, (int) $res->json('total'));
        $this->assertCount(5, $res->json('assets'));
    }

    /** @test */
    public function the_endpoint_honours_the_type_filter(): void
    {
        $ws = (int) $this->testWorkspace->id;
        foreach (['image', 'image', 'video'] as $i => $type) {
            DB::table('assets')->insert([
                'workspace_id' => $ws,
                'type'         => $type,
                'title'        => 't' . $i,
                'status'       => 'completed',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $res = $this->withHeaders($this->authHeaders())->getJson('/api/creative/assets?type=video');
        $res->assertOk();

        $this->assertSame(1, (int) $res->json('total'), 'the filter must reach the service');
        $this->assertSame('video', $res->json('assets.0.type'));
    }

    /** @test */
    public function assets_are_scoped_to_the_requesting_workspace(): void
    {
        $other = $this->createAdditionalWorkspace('Other Tenant');

        DB::table('assets')->insert([
            'workspace_id' => (int) $other->id,
            'type'         => 'image',
            'title'        => 'foreign',
            'status'       => 'completed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
        DB::table('assets')->insert([
            'workspace_id' => (int) $this->testWorkspace->id,
            'type'         => 'image',
            'title'        => 'mine',
            'status'       => 'completed',
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders())->getJson('/api/creative/assets');
        $res->assertOk();

        $this->assertSame(1, (int) $res->json('total'), 'another tenant\'s assets must not leak');
        $this->assertSame('mine', $res->json('assets.0.title'));
    }

    /** @test */
    public function soft_deleted_assets_are_excluded(): void
    {
        $ws = (int) $this->testWorkspace->id;
        DB::table('assets')->insert([
            'workspace_id' => $ws, 'type' => 'image', 'title' => 'live',
            'status' => 'completed', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('assets')->insert([
            'workspace_id' => $ws, 'type' => 'image', 'title' => 'gone',
            'status' => 'completed', 'deleted_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->withHeaders($this->authHeaders())->getJson('/api/creative/assets');
        $res->assertOk();

        $this->assertSame(1, (int) $res->json('total'));
        $this->assertSame('live', $res->json('assets.0.title'));
    }
}
