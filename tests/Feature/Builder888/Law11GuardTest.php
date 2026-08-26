<?php

namespace Tests\Feature\Builder888;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Tests\TestCase;

/**
 * BUILDER888 · Law 11 — Arthur never persists a Builder domain object.
 *
 * Structural, not a string grep: the file is parsed to an AST and every
 * DB::table('<builder table>')->insert|update|delete|... chain is located by
 * shape. Reads are permitted; non-Builder-domain writes are out of scope.
 */
class Law11GuardTest extends TestCase
{
    private const GUARDED_FILE = 'app/Engines/Builder/Services/ArthurService.php';

    /** Builder-owned tables Arthur may never mutate directly. */
    private const BUILDER_TABLES = [
        'websites', 'pages', 'page_versions', 'site_releases', 'builds', 'deployments',
    ];

    /** Query-builder terminators that write. */
    private const MUTATORS = [
        'insert', 'insertGetId', 'insertOrIgnore', 'update', 'updateOrInsert',
        'upsert', 'delete', 'truncate', 'increment', 'decrement', 'forceDelete',
    ];

    /**
     * @return array<int,string> human-readable violations
     */
    private function findViolations(string $relativePath): array
    {
        $path = base_path($relativePath);
        $this->assertFileExists($path, "guarded file missing: {$relativePath}");

        $code = file_get_contents($path);

        if (! class_exists(ParserFactory::class)) {
            $this->markTestSkipped('nikic/php-parser unavailable — structural guard cannot run.');
        }

        $factory = new ParserFactory();
        $parser  = method_exists($factory, 'createForNewestSupportedVersion')
            ? $factory->createForNewestSupportedVersion()
            : $factory->create(ParserFactory::PREFER_PHP7);

        $ast = $parser->parse($code);
        $this->assertNotNull($ast, 'could not parse the guarded file');

        $violations = [];

        foreach ((new NodeFinder())->findInstanceOf($ast, Node\Expr\MethodCall::class) as $call) {
            /** @var Node\Expr\MethodCall $call */
            if (! $call->name instanceof Node\Identifier) {
                continue;
            }
            if (! in_array($call->name->toString(), self::MUTATORS, true)) {
                continue;
            }

            // Walk back down the fluent chain looking for DB::table('x') / ->table('x').
            $table = null;
            $node  = $call->var;

            while ($node instanceof Node\Expr\MethodCall || $node instanceof Node\Expr\StaticCall) {
                $name = $node->name instanceof Node\Identifier ? $node->name->toString() : '';

                if ($name === 'table' && isset($node->args[0])) {
                    $arg = $node->args[0]->value;
                    if ($arg instanceof Node\Scalar\String_) {
                        $table = $arg->value;
                    }
                    break;
                }

                $node = $node instanceof Node\Expr\MethodCall ? $node->var : null;
            }

            if ($table !== null && in_array($table, self::BUILDER_TABLES, true)) {
                $violations[] = sprintf(
                    "line %d: DB::table('%s')->%s() — Builder-owned persistence",
                    $call->getStartLine(),
                    $table,
                    $call->name->toString()
                );
            }
        }

        return $violations;
    }

    public function test_arthur_service_performs_no_direct_builder_persistence(): void
    {
        $violations = $this->findViolations(self::GUARDED_FILE);

        $this->assertSame(
            [],
            $violations,
            "Law 11 violated — ArthurService writes Builder-owned tables directly:\n  "
            . implode("\n  ", $violations)
            . "\n\nGeneration must go: Arthur -> BuilderGenerationDTO -> "
            . "BuilderApplicationService -> BuilderService."
        );
    }

    public function test_the_guard_actually_detects_a_violation(): void
    {
        // A guard that cannot fail proves nothing. Verify the detector against
        // a known-bad fixture rather than trusting a green result.
        $bad = base_path('storage/framework/testing/b888_law11_probe.php');
        @mkdir(dirname($bad), 0775, true);
        file_put_contents($bad, <<<'PHP'
<?php
class Probe {
    public function bad() {
        \Illuminate\Support\Facades\DB::table('websites')->insert(['a' => 1]);
        \Illuminate\Support\Facades\DB::table('pages')->where('id', 1)->update(['b' => 2]);
        \Illuminate\Support\Facades\DB::table('workspaces')->insert(['c' => 3]); // allowed
        \Illuminate\Support\Facades\DB::table('pages')->where('id', 1)->first(); // read, allowed
    }
}
PHP);

        $found = $this->findViolations('storage/framework/testing/b888_law11_probe.php');
        @unlink($bad);

        $this->assertCount(2, $found, 'the guard must catch exactly the two Builder-table writes');
        $this->assertStringContainsString("'websites')->insert", implode(' ', $found));
        $this->assertStringContainsString("'pages')->update", implode(' ', $found));
    }

    public function test_arthur_may_still_read_builder_tables(): void
    {
        // Reads are explicitly permitted; the guard must not forbid them.
        $code = file_get_contents(base_path(self::GUARDED_FILE));
        $this->assertMatchesRegularExpression(
            "/table\('(websites|pages|media)'\)/",
            $code,
            'Arthur legitimately reads Builder/media tables; the guard governs writes only.'
        );
    }

    /**
     * TEMPLATE SLUG HYGIENE (2026-08-26) — resolveTemplateSlug() step 1 replaced only
     * whitespace/hyphens, so an industry like "../dental" leaked a slug containing ../ that
     * flows into templates/{slug}/template.html paths (declaredPlaceholders et al.). Not
     * exploitable today (the traversal target does not exist and no content is disclosed),
     * but hardened to [a-z0-9_] at the source. Assert the invariant + that legit resolution
     * is unchanged.
     */
    public function test_resolve_template_slug_never_returns_traversal_characters(): void
    {
        $svc = app(\App\Engines\Builder\Services\ArthurService::class);
        $m = new \ReflectionMethod($svc, 'resolveTemplateSlug');
        $m->setAccessible(true);

        $adversarial = [
            '../dental', '../../../../etc/passwd', '../../config/../dental',
            'dental/../../../etc', '..%2f..%2fdental', "dental\x00", 'a/b/c',
        ];
        foreach ($adversarial as $industry) {
            $slug = $m->invoke($svc, $industry);
            $this->assertMatchesRegularExpression(
                '/^[a-z0-9_]+$/', $slug,
                "resolveTemplateSlug('$industry') returned a non-clean slug: '$slug'"
            );
        }

        // Legit industries still resolve to their expected templates.
        $this->assertSame('dental', $m->invoke($svc, 'dental'));
        $this->assertSame('restaurant', $m->invoke($svc, 'restaurant'));
        $this->assertSame('cafe', $m->invoke($svc, 'a coffee shop'));
        // The traversal form of a real template collapses to the clean template.
        $this->assertSame('dental', $m->invoke($svc, '../dental'));
    }
}
