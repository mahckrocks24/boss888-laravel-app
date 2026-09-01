<?php

namespace Tests\Feature\Routes;

use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Events\Dispatcher;
use Illuminate\Container\Container;
use Tests\TestCase;

/**
 * CR-22B — scope-inheritance conformance for extracted route modules.
 *
 * The extraction replaces a block of route registrations with
 *   require __DIR__ . '/api/authenticated/<module>.php';
 * executed INSIDE the parent group closure. That is only safe if PHP's
 * require-scope semantics hold for every construct the file actually uses, and
 * if no module depends on state defined in another module.
 *
 * These tests are deliberately empirical. Each one exercises the real semantic
 * against a real fixture rather than asserting a belief about PHP.
 *
 * One class of failure is proven here because it BIT US: a `use` alias does not
 * cross a require boundary, and the failure is SILENT — `TaskController::class`
 * evaluates to the string "TaskController" and the route registers against a
 * wrong action with no error at all. Eight routes were affected before the
 * ordered-signature gate caught it. testUseAliasDoesNotCrossRequireBoundary
 * pins the semantic; testEveryModuleDeclaresFullParentImportSet pins the fix.
 */
class RouteModuleScopeInheritanceTest extends TestCase
{
    private const BASE = '/var/www/levelup-staging';
    private const ART = '/root/cr22-baseline-20260727';
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/cr22b-scope-' . getmypid();
        if (!is_dir($this->tmp)) {
            mkdir($this->tmp, 0700, true);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . '/*') as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
        parent::tearDown();
    }

    /** Write a fixture "module" and return its path. */
    private function fixture(string $name, string $body): string
    {
        $path = $this->tmp . '/' . $name . '.php';
        file_put_contents($path, "<?php\n" . $body . "\n");
        return $path;
    }

    /** A real Router, isolated from the application's route collection. */
    private function router(): Router
    {
        $r = new Router(new Dispatcher(new Container()), new Container());
        $r->setRoutes(new RouteCollection());
        return $r;
    }

    /**
     * Look a route up by name. A freshly-built RouteCollection only populates
     * its name index on refresh, which the application does at boot.
     */
    private function byName(Router $router, string $name)
    {
        $router->getRoutes()->refreshNameLookups();
        return $router->getRoutes()->getByName($name);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. Variable-scope inheritance across `require`, by value category
    // ───────────────────────────────────────────────────────────────────────

    public function test_scalar_captured_variable_is_visible_inside_required_file(): void
    {
        $f = $this->fixture('scalar', '$seen = $scalar;');
        $scalar = 'level-up';
        require $f;
        $this->assertSame('level-up', $seen ?? null);
    }

    public function test_object_reference_is_visible_and_is_the_same_instance(): void
    {
        $f = $this->fixture('object', '$seen = $obj; $obj->touched = true;');
        $obj = new \stdClass();
        $obj->id = 7;
        require $f;
        $this->assertSame($obj, $seen ?? null, 'require must share the reference, not a copy');
        $this->assertTrue($obj->touched, 'mutation inside the required file must be visible to the parent');
    }

    public function test_service_instance_resolved_from_container_is_visible(): void
    {
        $f = $this->fixture('service', '$seen = $svc;');
        $svc = app(\App\Core\Billing\CreditService::class);
        require $f;
        $this->assertSame($svc, $seen ?? null);
        $this->assertInstanceOf(\App\Core\Billing\CreditService::class, $seen);
    }

    public function test_array_is_visible_and_is_copied_on_write_as_normal(): void
    {
        $f = $this->fixture('array', '$seen = $arr; $arr[] = "added";');
        $arr = ['a', 'b'];
        require $f;
        $this->assertSame(['a', 'b'], $seen ?? null);
        $this->assertSame(['a', 'b', 'added'], $arr, 'array writes follow ordinary scope rules');
    }

    public function test_null_value_is_visible_and_distinguishable_from_undefined(): void
    {
        $f = $this->fixture('nullable', '$definedButNull = array_key_exists("maybe", get_defined_vars());'
            . ' $seen = $maybe;');
        $maybe = null;
        require $f;
        $this->assertTrue($definedButNull ?? false, 'a null variable must still be DEFINED in the required file');
        $this->assertNull($seen);
    }

    public function test_conditionally_defined_variable_is_visible_only_when_defined(): void
    {
        $f = $this->fixture('conditional', '$wasDefined = isset($maybeDefined);');

        $flag = false;
        if ($flag) {
            $maybeDefined = 'yes';
        }
        require $f;
        $this->assertFalse($wasDefined, 'undefined stays undefined across require — it is not auto-created');

        $maybeDefined = 'yes';
        require $f;
        $this->assertTrue($wasDefined, 'once defined, the required file sees it');
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. Routing-specific inheritance
    // ───────────────────────────────────────────────────────────────────────

    public function test_nested_route_group_prefix_is_inherited_across_require(): void
    {
        $router = $this->router();
        $f = $this->fixture('nested', '$router->prefix("inner")->group(function () use ($router) {'
            . ' $router->get("/leaf", fn () => "ok")->name("leaf"); });');

        $router->prefix('outer')->group(function () use ($router, $f) {
            require $f;
        });

        $route = $this->byName($router, 'leaf');
        $this->assertNotNull($route);
        $this->assertSame('outer/inner/leaf', $route->uri(), 'prefix nesting must survive the require boundary');
    }

    public function test_middleware_group_applies_to_routes_registered_inside_a_required_file(): void
    {
        $router = $this->router();
        $f = $this->fixture('mw', '$router->get("/guarded", fn () => "ok")->name("guarded");');

        $router->middleware(['auth.jwt', 'traffic.defense', 'connector.brand'])->group(function () use ($f, $router) {
            require $f;
        });

        $route = $this->byName($router, 'guarded');
        $this->assertNotNull($route);
        $this->assertSame(
            ['auth.jwt', 'traffic.defense', 'connector.brand'],
            $route->gatherMiddleware(),
            'the middleware stack must be identical to inline registration'
        );
    }

    public function test_closure_use_capture_binds_a_value_inherited_across_require(): void
    {
        $router = $this->router();
        $f = $this->fixture('capture', '$router->get("/cap", function () use ($wsId) { return $wsId; })->name("cap");');

        $wsId = 42;
        $router->group([], function () use ($f, $router, $wsId) {
            require $f;
        });

        $route = $this->byName($router, 'cap');
        $this->assertNotNull($route);
        $this->assertSame(42, ($route->getAction('uses'))());
    }

    public function test_route_registration_order_is_preserved_across_require(): void
    {
        $router = $this->router();
        // Distinct URIs: a RouteCollection keys on method+uri, so registering the
        // same URI twice measures collision, not order.
        $a = $this->fixture('ord_a', '$router->get("/alpha", fn () => "a")->name("alpha");'
            . ' $router->get("/beta", fn () => "b")->name("beta");');
        $b = $this->fixture('ord_b', '$router->get("/gamma", fn () => "c")->name("gamma");');

        require $a;
        require $b;

        $names = array_map(fn ($r) => $r->getName(), $router->getRoutes()->getRoutes());
        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            $names,
            'require must register in source order — route order is behaviour'
        );

        // The precedence that actually matters: a static URI registered before an
        // overlapping dynamic one must keep winning the match. (For a BYTE-IDENTICAL
        // method+URI, RouteCollection replaces rather than keeps the first — that is
        // Laravel's own behaviour and is unchanged by the require boundary.)
        $r2 = $this->router();
        $c = $this->fixture('ord_c', '$r2->get("/tasks/stats", fn () => "static")->name("t_static");');
        $d = $this->fixture('ord_d', '$r2->get("/tasks/{id}", fn () => "dynamic")->name("t_dynamic");');
        require $c;
        require $d;
        $r2->getRoutes()->refreshNameLookups();

        $matched = $r2->getRoutes()->match(
            \Illuminate\Http\Request::create('/tasks/stats', 'GET')
        );
        $this->assertSame(
            't_static',
            $matched->getName(),
            'the earlier static route must still shadow the later dynamic one across a require boundary'
        );
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. The silent failure that actually occurred
    // ───────────────────────────────────────────────────────────────────────

    public function test_use_alias_does_not_cross_require_boundary_and_fails_silently(): void
    {
        $f = $this->fixture('alias', '$resolved = \Tests\Feature\Routes\AliasProbe::class;');
        require $f;

        $this->assertSame(
            'Tests\Feature\Routes\AliasProbe',
            $resolved,
            'a fully-qualified reference resolves identically in any file'
        );

        // The unqualified form is what breaks: the required file has no import,
        // so ::class yields a bare string and NO error is raised.
        $g = $this->fixture('alias_bare', '$bare = AliasProbe::class;');
        require $g;

        $this->assertSame('AliasProbe', $bare, 'unqualified ::class silently degrades to the short name');
        $this->assertNotSame($resolved, $bare, 'this is precisely why every module re-declares the parent imports');
    }

    // ───────────────────────────────────────────────────────────────────────
    // 4. Assertions against the real extracted modules
    // ───────────────────────────────────────────────────────────────────────

    private function artifact(string $name): array
    {
        $path = self::ART . '/' . $name;
        $this->assertFileExists($path, "CR-22B artefact missing: {$name}");
        return json_decode(file_get_contents($path), true);
    }

    public function test_every_module_declares_the_full_parent_import_set(): void
    {
        $parent = $this->artifact('api-imports.json')['imports'];
        $this->assertNotEmpty($parent);

        foreach ($this->artifact('extraction-mapping.json')['modules'] as $m) {
            $head = explode('// ==== CR-22B MODULE BODY BEGINS',
                file_get_contents(self::BASE . '/' . $m['file']))[0];
            foreach ($parent as $import) {
                $this->assertStringContainsString(
                    "use {$import};",
                    $head,
                    "{$m['module']} is missing `use {$import};` — class references would silently degrade"
                );
            }
        }
    }

    public function test_no_module_consumes_or_defines_a_variable_across_a_module_boundary(): void
    {
        $scope = $this->artifact('scope-map.json');
        $this->assertSame(
            [],
            $scope['violations'],
            'a module reading state defined in another module would break silently at runtime'
        );
        $this->assertSame(
            [],
            $scope['closure_scope_assignments'],
            'BLOCK17 assigns no variable at its own closure scope, so no module can inherit one'
        );
    }

    public function test_every_module_parses_standalone_and_is_structurally_balanced(): void
    {
        foreach ($this->artifact('extraction-mapping.json')['modules'] as $m) {
            $path = self::BASE . '/' . $m['file'];
            exec('php -l ' . escapeshellarg($path) . ' 2>&1', $out, $rc);
            $this->assertSame(0, $rc, "{$m['module']} does not parse standalone: " . implode(' ', $out));
            $out = [];
        }
    }


    /**
     * The expected reconstruction hash lives in the governed mapping artefact,
     * which the deploy procedure updates. Reading it keeps this test guarding
     * "modules still reconstruct their parent" without needing an edit whenever
     * an approved route is added.
     */
    private function currentReconstructionHash(): string
    {
        return $this->artifact('extraction-mapping.json')['source_sha256'];
    }

    public function test_modules_reconstruct_to_the_byte_identical_pre_extraction_file(): void
    {
        $map = $this->artifact('extraction-mapping.json');
        $cand = explode("\n", file_get_contents(self::BASE . '/routes/api.php'));
        $mark = "// ==== CR-22B MODULE BODY BEGINS - verbatim from routes/api.php, do not edit ====\n";

        $out = [];
        for ($i = 0; $i < count($cand); $i++) {
            if (preg_match("#^\s*require __DIR__ \. '/api/authenticated/([\w-]+\.php)';\s*$#", $cand[$i], $mm)) {
                $body = explode($mark, file_get_contents(self::BASE . '/routes/api/authenticated/' . $mm[1]), 2)[1];
                $body = substr($body, -1) === "\n" ? substr($body, 0, -1) : $body;
                foreach (explode("\n", $body) as $l) {
                    $out[] = $l;
                }
            } elseif (!preg_match('#^\s*// CR-22B: [\w-]+ extracted to routes/api/authenticated/#', $cand[$i])) {
                $out[] = $cand[$i];
            }
        }

        $this->assertSame(
            $this->currentReconstructionHash(),
            hash('sha256', implode("\n", $out)),
            'the module set must rebuild the pre-extraction file exactly — no line added, dropped or reordered'
        );
    }


    /**
     * The source-scanning regression tests (CR-01, CR-03, CR-18, P2-B) now read
     * RouteSource::all() rather than one filename. That helper cannot assert, so
     * the file-existence guarantee its predecessor carried is pinned here: if a
     * module ever disappears, those tests would silently scan less source and
     * keep passing while guarding nothing.
     */
    public function test_every_declared_route_source_file_exists_and_is_non_empty(): void
    {
        $rel = \Tests\Support\RouteSource::relative();
        $this->assertContains('routes/api.php', $rel);

        // The extraction mapping records what CR-22B lifted OUT of routes/api.php. It is a history, not a
        // permanent census: a module authored after CR-22B — business-email.php came in with INFRA888 E4 —
        // was never extracted from anything and correctly has no entry. Asserting equality here made every
        // future route module a test failure, which is a gate against writing code rather than against
        // losing a route source.
        //
        // What must hold is the direction that actually protects the extraction: every module CR-22B
        // recorded is still present on disk. A new module beside them is allowed, and is separately
        // governed by the ownership lock and the protected-path rules.
        foreach ($this->artifact('extraction-mapping.json')['modules'] as $module) {
            $this->assertContains(
                $module['file'],
                $rel,
                "an extracted route module has gone missing from disk: {$module['file']}"
            );
        }

        foreach ($rel as $r) {
            $path = base_path($r);
            $this->assertFileExists($path, "declared route source is missing: {$r}");
            $this->assertGreaterThan(0, filesize($path), "declared route source is empty: {$r}");
        }

        $this->assertStringContainsString(
            'Route::',
            \Tests\Support\RouteSource::all(),
            'the concatenated route source must actually contain route registrations'
        );
    }

    public function test_module_size_policy_is_respected(): void
    {
        foreach ($this->artifact('extraction-mapping.json')['modules'] as $m) {
            $this->assertLessThanOrEqual(
                5500,
                $m['source_lines'],
                "{$m['module']} exceeds the 5,500-line policy threshold and needs a written justification"
            );
        }
    }
}

/** Probe class for the alias-resolution test. */
class AliasProbe
{
}
