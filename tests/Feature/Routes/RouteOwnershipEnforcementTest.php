<?php

namespace Tests\Feature\Routes;

use App\Core\Safety\GovernedRestore;
use App\Core\Safety\GovernedWriter;
use App\Core\Safety\ManifestBackup;
use App\Core\Safety\ProtectedPaths;
use App\Core\Safety\SourceOwnershipLock;
use RuntimeException;
use Tests\TestCase;

/**
 * CR-22B — enforced ownership over the modular route tree.
 *
 * These are the eight properties the CR-22B brief requires proven. Each one is
 * exercised against the REAL lock, the REAL governed writer and the REAL
 * manifest backup implementation, using throwaway files inside the protected
 * path set — never the live route files.
 *
 * The property that matters most is #2 combined with #8: two sessions working
 * on DIFFERENT modules must both succeed (otherwise modularization bought
 * nothing), and two sessions on the SAME module must fail closed (otherwise it
 * bought nothing either). INC-2026-003 is the case where neither held.
 */
class RouteOwnershipEnforcementTest extends TestCase
{
    private SourceOwnershipLock $lock;
    private GovernedWriter $writer;

    /**
     * BASELINE SUPERSEDED — ENTERPRISE888 E1 M0 (2026-07-27)
     * One READ-ONLY endpoint was added: GET api/admin/engineering/manifest.
     * Verified: exactly 1 added route, 0 removed, 0 altered; count 982 -> 983.
     * A signature change is never waived as noise — this one is recorded and
     * justified. See BASELINE-NOTE-E1M0.md.

     * BASELINE SUPERSEDED — ENTERPRISE888 E0.5 (2026-07-27)
     * The previous value was CR-22B's c46d8a07…, which was correct until E0.5
     * applied `mfa.enrolled` to the four Bella routes as an approved security
     * containment. The diff was verified to be EXACTLY four lines, all Bella,
     * all middleware-only; method/uri/name/action/domain are byte-identical for
     * all 985 routes. See ENTERPRISE888-E0.5-IMPACT-MATRIX.md §7.
     * A signature change must never be accidental or waived — this one is
     * neither: it is recorded, justified and diff-verified.
 Throwaway module files created inside the protected tree. */
    private array $scratch = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->lock = new SourceOwnershipLock();
        $this->writer = new GovernedWriter($this->lock);

        foreach (['zz-test-alpha', 'zz-test-beta'] as $name) {
            $rel = "routes/api/authenticated/{$name}.php";
            file_put_contents(ProtectedPaths::absolute($rel), "<?php\n// {$name} v1\n");
            $this->scratch[$name] = $rel;
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->scratch as $rel) {
            @unlink(ProtectedPaths::absolute($rel));
        }
        foreach (['routes', 'routes-alpha', 'routes-beta'] as $scope) {
            $l = $this->lock->read($scope);
            if ($l !== null) {
                $this->lock->release($scope, $l['owner'], true);
            }
        }

        // E0.6: this suite creates and removes scratch files inside a protected
        // directory. Without re-sealing, drift() afterwards reports them as
        // 'removed' forever — a control that cries wolf gets ignored, so leave
        // it truthful.
        \App\Core\Safety\ProtectedPaths::seal('test-teardown', 'ownership suite cleanup');

        parent::tearDown();
    }

    private function acquire(string $scope, string $owner, array $paths): array
    {
        return $this->lock->acquire($scope, $owner, 'CR-22B-TEST', $paths, 600);
    }

    // ───────────────────────────────────────────────────────────────────────
    // 1. two agents can safely edit DIFFERENT modules
    // ───────────────────────────────────────────────────────────────────────

    public function test_two_agents_can_concurrently_edit_different_modules(): void
    {
        $alpha = $this->scratch['zz-test-alpha'];
        $beta = $this->scratch['zz-test-beta'];

        $this->acquire('routes-alpha', 'agent-a', [$alpha]);
        $this->acquire('routes-beta', 'agent-b', [$beta]);

        $ra = $this->writer->write($alpha, "<?php\n// alpha v2 by agent-a\n", 'agent-a', 'routes-alpha');
        $rb = $this->writer->write($beta, "<?php\n// beta v2 by agent-b\n", 'agent-b', 'routes-beta');

        $this->assertNotSame($ra['before'], $ra['after']);
        $this->assertNotSame($rb['before'], $rb['after']);

        // neither write disturbed the other — the whole point of modularization
        $this->assertStringContainsString('alpha v2', file_get_contents(ProtectedPaths::absolute($alpha)));
        $this->assertStringContainsString('beta v2', file_get_contents(ProtectedPaths::absolute($beta)));
    }

    // ───────────────────────────────────────────────────────────────────────
    // 2. two agents CANNOT edit the same module
    // ───────────────────────────────────────────────────────────────────────

    public function test_a_second_agent_cannot_acquire_a_module_another_agent_holds(): void
    {
        $alpha = $this->scratch['zz-test-alpha'];
        $this->acquire('routes-alpha', 'agent-a', [$alpha]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/owned by agent-a/');
        $this->acquire('routes-alpha', 'agent-b', [$alpha]);
    }

    public function test_a_second_agent_cannot_write_a_module_another_agent_holds(): void
    {
        $alpha = $this->scratch['zz-test-alpha'];
        $this->acquire('routes-alpha', 'agent-a', [$alpha]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/owned by agent-a|not agent-b/');
        $this->writer->write($alpha, "<?php\n// hostile\n", 'agent-b', 'routes-alpha');
    }

    public function test_a_write_is_refused_when_the_file_changed_after_acquisition(): void
    {
        $alpha = $this->scratch['zz-test-alpha'];
        $this->acquire('routes-alpha', 'agent-a', [$alpha]);

        // another session writes out of band — exactly the INC-2026-003 window
        file_put_contents(ProtectedPaths::absolute($alpha), "<?php\n// written by someone else\n");

        try {
            $this->writer->write($alpha, "<?php\n// agent-a would clobber it\n", 'agent-a', 'routes-alpha');
            $this->fail('the write should have been refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('changed since ownership was acquired', $e->getMessage());
        }

        // the other session's work survived
        $this->assertStringContainsString(
            'written by someone else',
            file_get_contents(ProtectedPaths::absolute($alpha))
        );
    }

    public function test_a_lock_does_not_authorise_paths_outside_its_scope(): void
    {
        $alpha = $this->scratch['zz-test-alpha'];
        $beta = $this->scratch['zz-test-beta'];
        $this->acquire('routes-alpha', 'agent-a', [$alpha]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not cover/');
        $this->writer->write($beta, "<?php\n// out of scope\n", 'agent-a', 'routes-alpha');
    }

    // ───────────────────────────────────────────────────────────────────────
    // 3. one module can be restored without modifying another
    // ───────────────────────────────────────────────────────────────────────

    public function test_one_module_can_be_restored_without_touching_another(): void
    {
        $alpha = $this->scratch['zz-test-alpha'];
        $beta = $this->scratch['zz-test-beta'];
        $backups = new ManifestBackup();
        $restore = new GovernedRestore($this->lock, $backups, $this->writer);

        $this->acquire('routes', 'agent-a', [$alpha, $beta]);

        $b = $backups->capture([$alpha], 'cr22b-test-alpha', 'agent-a', 'CR-22B-TEST');

        $this->writer->write($alpha, "<?php\n// alpha CHANGED\n", 'agent-a');
        $this->writer->write($beta, "<?php\n// beta UNTOUCHED marker\n", 'agent-a');
        $betaBefore = hash_file('sha256', ProtectedPaths::absolute($beta));

        // A restore of newer content is refused by design; this is a deliberate
        // authorised rollback of alpha only, so touch the mtime back.
        touch(ProtectedPaths::absolute($alpha), strtotime('-1 hour'));

        $restore->restore($b['dir'], [$alpha], 'agent-a');

        $this->assertStringContainsString('alpha v1', file_get_contents(ProtectedPaths::absolute($alpha)));
        $this->assertSame(
            $betaBefore,
            hash_file('sha256', ProtectedPaths::absolute($beta)),
            'restoring one module must not modify another'
        );
    }

    // ───────────────────────────────────────────────────────────────────────
    // 4-6. backup refusals
    // ───────────────────────────────────────────────────────────────────────

    public function test_a_backup_without_a_manifest_is_refused(): void
    {
        $alpha = $this->scratch['zz-test-alpha'];
        $dir = sys_get_temp_dir() . '/cr22b-nomanifest-' . getmypid();
        @mkdir($dir, 0700, true);
        copy(ProtectedPaths::absolute($alpha), $dir . '/routes__api__authenticated__zz-test-alpha.php');

        $this->acquire('routes', 'agent-a', [$alpha]);
        $decision = (new GovernedRestore($this->lock))->preview($dir, [$alpha], 'agent-a');

        $this->assertFalse($decision['allowed']);
        $this->assertStringContainsString('no MANIFEST.json', $decision['reasons'][0]);

        @unlink($dir . '/routes__api__authenticated__zz-test-alpha.php');
        @rmdir($dir);
    }

    public function test_a_stale_backup_is_refused(): void
    {
        $alpha = $this->scratch['zz-test-alpha'];
        $backups = new ManifestBackup();
        $this->acquire('routes', 'agent-a', [$alpha]);

        $b = $backups->capture([$alpha], 'cr22b-test-stale', 'agent-a', 'CR-22B-TEST');

        // the file moves on AFTER the backup was captured
        $this->writer->write($alpha, "<?php\n// newer work that must not be deleted\n", 'agent-a');
        touch(ProtectedPaths::absolute($alpha), time() + 10);

        $decision = (new GovernedRestore($this->lock))->preview($b['dir'], [$alpha], 'agent-a');

        $this->assertFalse($decision['allowed'], 'a manifest predating current source must be refused');
        $this->assertStringContainsString('modified since this backup was captured', implode(' ', $decision['reasons']));
        $this->assertStringContainsString('Reconstruct instead', implode(' ', $decision['reasons']));
    }

    public function test_an_outdated_monolithic_backup_cannot_overwrite_modular_routes(): void
    {
        // The real artefact: the pre-extraction monolith. It is authoritative
        // evidence, but it must never be restorable over the modular tree by
        // ordinary means — that would revert CR-22B and orphan 13 modules.
        $legacy = '/root/cr22b-legacy/api.php.pre-extraction';
        $this->assertFileExists($legacy, 'the pre-extraction monolith must be retained as rollback evidence');

        $this->assertFalse(
            str_starts_with(realpath($legacy) ?: $legacy, ProtectedPaths::ROOT . '/routes'),
            'the legacy monolith must live OUTSIDE the active routes directory'
        );

        // it carries no manifest of its own, so the governed restore refuses it
        $this->acquire('routes', 'agent-a', ['routes/api.php']);
        $decision = (new GovernedRestore($this->lock))
            ->preview(dirname($legacy), ['routes/api.php'], 'agent-a');

        $this->assertFalse($decision['allowed']);
        $this->assertStringContainsString('no MANIFEST.json', implode(' ', $decision['reasons']));

        // and nothing monolithic is sitting beside the modules pretending to be live
        $this->assertSame(
            [],
            glob(ProtectedPaths::ROOT . '/routes/*.bak*') ?: [],
            'no active-looking monolithic backup may sit beside the route modules'
        );
    }

    // ───────────────────────────────────────────────────────────────────────
    // 7. manifest changes require exclusive ownership
    // ───────────────────────────────────────────────────────────────────────

    public function test_manifest_and_registry_changes_require_exclusive_ownership(): void
    {
        $registry = 'storage/app/source-locks/route-ownership.json';
        $this->assertTrue(ProtectedPaths::isProtected($registry), 'the ownership registry must be a protected path');

        $original = file_get_contents(ProtectedPaths::absolute($registry));

        // no lock at all → refused
        try {
            $this->writer->write($registry, $original, 'agent-a');
            $this->fail('writing the registry without ownership should be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('REFUSING', $e->getMessage());
        }

        // held by someone else → refused
        $this->acquire('routes', 'agent-a', [$registry]);
        try {
            $this->writer->write($registry, $original, 'agent-b');
            $this->fail('writing the registry as a non-owner should be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('REFUSING', $e->getMessage());
        }

        // held by us → allowed, and recorded
        $r = $this->writer->write($registry, $original, 'agent-a');
        $this->assertSame($r['before'], $r['after'], 'rewriting identical content leaves the hash unchanged');
        $this->assertSame($original, file_get_contents(ProtectedPaths::absolute($registry)));
    }

    // ───────────────────────────────────────────────────────────────────────
    // 8. route order remains stable after module loading
    // ───────────────────────────────────────────────────────────────────────

    public function test_route_order_remains_stable_after_module_loading(): void
    {
        // Compared against the GOVERNED baseline rather than a literal, so an
        // approved route change needs no edit here but an ungoverned one fails.
        foreach ([
            '/var/www/levelup-staging/storage/app/source-locks/route-baseline.ordered.txt',
            '/root/cr22-baseline-20260727/canonical.ordered.txt',
        ] as $f) {
            if (!is_readable($f)) {
                continue;
            }

            $expected = rtrim((string) file_get_contents($f), "\n");

            exec('php /tmp/cr22/route_signature.php /tmp/rosig 2>&1', $out, $rc);
            $this->assertSame(0, $rc, 'the route signature generator must run');

            $live = rtrim((string) file_get_contents('/tmp/rosig.ordered.txt'), "\n");

            $this->assertSame(
                hash('sha256', $expected),
                hash('sha256', $live),
                "the ORDERED route signature differs from the governed baseline ({$f}). "
                . 'Order is behaviour — a difference here is never waived as noise.'
            );

            return;
        }

        $this->markTestSkipped('the governed route baseline is not readable on this host');
    }

    // ───────────────────────────────────────────────────────────────────────
    // Out-of-band write detection
    // ───────────────────────────────────────────────────────────────────────

    public function test_a_write_outside_governed_tooling_is_detected_as_drift(): void
    {
        $alpha = $this->scratch['zz-test-alpha'];
        ProtectedPaths::seal('test', 'baseline for drift detection');

        $clean = ProtectedPaths::drift();
        $this->assertSame([], $clean['drifted'], 'a freshly sealed tree must show no drift');

        // an ungoverned write — the thing the lock cannot prevent
        file_put_contents(ProtectedPaths::absolute($alpha), "<?php\n// ungoverned\n");

        $dirty = ProtectedPaths::drift();
        $this->assertArrayHasKey(
            $alpha,
            $dirty['drifted'],
            'an ungoverned write to a protected path must be DETECTED even though it cannot be prevented'
        );
    }
}
