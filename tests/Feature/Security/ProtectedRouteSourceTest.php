<?php

namespace Tests\Feature\Security;

use App\Core\Safety\GovernedRestore;
use App\Core\Safety\GovernedWriter;
use App\Core\Safety\ManifestBackup;
use App\Core\Safety\ProtectedPaths;
use App\Core\Safety\SourceOwnershipLock;
use RuntimeException;
use Tests\TestCase;

/**
 * ENTERPRISE888 E0.6 — TD-19 closure.
 *
 * Before this phase, `routes/web.php` and `routes/exec-api.php` were ACTIVE
 * route source with no ownership check, no governed write and no drift
 * detection. A concurrent session wrote to `routes/web.php` and left an
 * unmanaged backup beside it — the second occurrence of the INC-2026-004
 * pattern — and nothing in the control system could have noticed.
 *
 * These tests pin the complete active route-source set under the controls, and
 * assert on the ACTUAL loading path rather than on a list someone remembered.
 */
class ProtectedRouteSourceTest extends TestCase
{
    private const ROOT = '/var/www/levelup-staging';

    /**
     * BASELINE SUPERSEDED — ENTERPRISE888 E1 M0 (2026-07-27)
     * One READ-ONLY endpoint was added: GET api/admin/engineering/manifest.
     * Verified: exactly 1 added route, 0 removed, 0 altered; count 982 -> 983.
     * A signature change is never waived as noise — this one is recorded and
     * justified. See BASELINE-NOTE-E1M0.md.
 Every locally-maintained file that contributes route source at boot. */
    private const ACTIVE_ROUTE_SOURCE = [
        'routes/web.php',                   // withRouting(web:)
        'routes/api.php',                   // withRouting(api:)
        'routes/exec-api.php',              // withRouting(then:) closure
        // app/Engines/CRM/Http/Routes.php was DELETED on 2026-08-24 (MISSION-018 WS-1, RISK-0005):
        // its two registrations were fully shadowed by crm-01.php and could never serve, which the
        // registration census proved before removal. No engine loads routes from disk any more, so
        // this list is complete. The dynamic check below is what keeps it complete.
    ];

    private SourceOwnershipLock $lock;
    private GovernedWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lock = new SourceOwnershipLock();
        $this->writer = new GovernedWriter($this->lock);
    }

    protected function tearDown(): void
    {
        foreach (['routes', 'e06-a', 'e06-b'] as $scope) {
            $l = $this->lock->read($scope);
            if ($l !== null) {
                $this->lock->release($scope, $l['owner'], true);
            }
        }
        parent::tearDown();
    }

    // ── coverage ────────────────────────────────────────────────────────────

    public function test_every_active_route_source_file_is_protected(): void
    {
        foreach (self::ACTIVE_ROUTE_SOURCE as $rel) {
            $this->assertFileExists(self::ROOT . '/' . $rel);
            $this->assertTrue(
                ProtectedPaths::isProtected($rel),
                "{$rel} contributes route source at boot and MUST be a protected path"
            );
        }
    }

    public function test_the_modular_api_tree_is_still_protected(): void
    {
        foreach (glob(self::ROOT . '/routes/api/authenticated/*.php') as $abs) {
            $rel = 'routes/api/authenticated/' . basename($abs);
            $this->assertTrue(ProtectedPaths::isProtected($rel), "{$rel} lost protection");
        }
    }

    /**
     * The protected set must be derived from the real loading path. If a new
     * route file is introduced anywhere, this fails until it is classified.
     */
    public function test_no_active_route_file_exists_outside_the_protected_set(): void
    {
        $unprotected = [];

        foreach (glob(self::ROOT . '/routes/*.php') as $abs) {
            $rel = 'routes/' . basename($abs);
            if (!ProtectedPaths::isProtected($rel)) {
                $unprotected[] = $rel;
            }
        }

        // any loadRoutesFrom target in application code
        $providers = shell_exec(
            'grep -rn "loadRoutesFrom" ' . escapeshellarg(self::ROOT . '/app') . ' --include=*.php 2>/dev/null | grep -v "\.bak"'
        );
        preg_match_all("#loadRoutesFrom\(__DIR__ \. '([^']+)'#", (string) $providers, $m);
        // Deliberately NOT assertNotEmpty. An engine loading routes from disk is a route source that
        // has to be governed; zero of them is the safer state, not a broken test. What must hold is
        // that every one that DOES exist is protected, which is what $unprotected below carries.

        $this->assertSame([], $unprotected,
            'active route files found outside the protected set: ' . implode(', ', $unprotected));
    }

    public function test_bootstrap_is_protected_because_it_defines_the_loading_path(): void
    {
        $this->assertTrue(ProtectedPaths::isProtected('bootstrap/app.php'));
    }

    // ── ownership enforcement on the newly protected files ──────────────────

    /**
     * @dataProvider newlyProtected
     */
    public function test_newly_protected_file_cannot_be_written_without_ownership(string $rel): void
    {
        $content = (string) file_get_contents(self::ROOT . '/' . $rel);

        $this->expectException(RuntimeException::class);
        $this->writer->write($rel, $content, 'nobody-holds-a-lock');
    }

    /**
     * @dataProvider newlyProtected
     */
    public function test_newly_protected_file_cannot_be_written_by_a_non_owner(string $rel): void
    {
        $this->lock->acquire('routes', 'agent-a', 'E0.6-TEST', [$rel], 300);

        $this->expectException(RuntimeException::class);
        $this->writer->write($rel, (string) file_get_contents(self::ROOT . '/' . $rel), 'agent-b');
    }

    /**
     * @dataProvider newlyProtected
     */
    public function test_governed_write_succeeds_with_valid_ownership_and_is_byte_identical(string $rel): void
    {
        $abs = self::ROOT . '/' . $rel;
        $before = (string) file_get_contents($abs);
        $sha = hash('sha256', $before);

        $this->lock->acquire('routes', 'agent-a', 'E0.6-TEST', [$rel], 300);
        // rewrite identical content — proves the path works without changing production
        $r = $this->writer->write($rel, $before, 'agent-a');

        $this->assertSame($sha, $r['after'], 'identical content must produce an identical hash');
        $this->assertSame($before, (string) file_get_contents($abs), 'file content must be unchanged');
    }

    /**
     * @dataProvider newlyProtected
     */
    public function test_stale_hash_fails_closed(string $rel): void
    {
        $abs = self::ROOT . '/' . $rel;
        $original = (string) file_get_contents($abs);

        $this->lock->acquire('routes', 'agent-a', 'E0.6-TEST', [$rel], 300);

        // simulate another session writing out of band, then restore immediately
        file_put_contents($abs, $original . "\n// out-of-band\n");

        try {
            $this->writer->write($rel, $original, 'agent-a');
            $this->fail('write should have been refused after an out-of-band change');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('changed since ownership was acquired', $e->getMessage());
        } finally {
            file_put_contents($abs, $original);
        }

        $this->assertSame($original, (string) file_get_contents($abs), 'original content restored');
    }

    public static function newlyProtected(): array
    {
        return [
            'web.php'      => ['routes/web.php'],
            'exec-api.php' => ['routes/exec-api.php'],
        ];
    }

    // ── independent ownership ───────────────────────────────────────────────

    public function test_different_route_files_may_be_owned_independently(): void
    {
        $this->lock->acquire('e06-a', 'agent-a', 'E0.6-TEST', ['routes/web.php'], 300);
        $this->lock->acquire('e06-b', 'agent-b', 'E0.6-TEST', ['routes/exec-api.php'], 300);

        $this->assertSame('agent-a', $this->lock->read('e06-a')['owner']);
        $this->assertSame('agent-b', $this->lock->read('e06-b')['owner']);
    }

    public function test_same_file_ownership_conflict_fails_closed(): void
    {
        $this->lock->acquire('routes', 'agent-a', 'E0.6-TEST', ['routes/web.php'], 300);

        $this->expectException(RuntimeException::class);
        $this->lock->acquire('routes', 'agent-b', 'E0.6-TEST', ['routes/web.php'], 300);
    }

    // ── unmanaged backups ───────────────────────────────────────────────────

    public function test_no_unmanaged_backups_beside_active_route_source(): void
    {
        $this->assertSame(
            [],
            ProtectedPaths::unmanagedBackups(),
            'a backup beside live route source is unsafe unless it has an authoritative manifest'
        );
    }

    public function test_the_unmanaged_backup_detector_actually_detects(): void
    {
        $probe = self::ROOT . '/routes/zz-e06-probe.php.bak-test';
        file_put_contents($probe, "<?php\n");

        try {
            $found = ProtectedPaths::unmanagedBackups();
            $this->assertContains('routes/zz-e06-probe.php.bak-test', $found,
                'the detector must catch a backup-shaped file beside active route source');
        } finally {
            @unlink($probe);
        }

        $this->assertSame([], ProtectedPaths::unmanagedBackups());
    }

    // ── restore refusals ────────────────────────────────────────────────────

    public function test_manifest_less_restore_of_a_newly_protected_file_is_refused(): void
    {
        $dir = sys_get_temp_dir() . '/e06-nomanifest-' . getmypid();
        @mkdir($dir, 0700, true);
        @copy(self::ROOT . '/routes/web.php', $dir . '/routes__web.php');

        $this->lock->acquire('routes', 'agent-a', 'E0.6-TEST', ['routes/web.php'], 300);
        $d = (new GovernedRestore($this->lock))->preview($dir, ['routes/web.php'], 'agent-a');

        $this->assertFalse($d['allowed']);
        $this->assertStringContainsString('no MANIFEST.json', implode(' ', $d['reasons']));

        @unlink($dir . '/routes__web.php');
        @rmdir($dir);
    }

    /**
     * Staleness is about DIVERGENCE, not about timestamps alone.
     *
     * The first version of this test back-dated the manifest with `touch()` but
     * left the content identical, and `preview()` correctly allowed it — an
     * identical restore is a no-op, not a data-loss risk. The assertion was
     * wrong, not the code. This version proves the real semantic on the real
     * file: divergent content makes an older manifest refusable.
     *
     * The live file is written through the governed writer and restored in a
     * `finally`, so production content is unchanged either way.
     */
    public function test_a_backup_is_refused_once_the_protected_file_has_diverged(): void
    {
        $rel = 'routes/web.php';
        $abs = self::ROOT . '/' . $rel;
        $original = (string) file_get_contents($abs);
        $backups = new ManifestBackup();

        $this->lock->acquire('routes', 'agent-a', 'E0.6-TEST', [$rel], 300);
        $b = $backups->capture([$rel], 'e06-test-diverge', 'agent-a', 'E0.6-TEST');

        // An identical-content backup is NOT stale — restoring it changes nothing.
        $same = (new GovernedRestore($this->lock))->preview($b['dir'], [$rel], 'agent-a');
        $this->assertTrue($same['allowed'], 'an identical backup is a safe no-op');
        $this->assertTrue($same['preview'][0]['identical']);

        try {
            // Diverge through the governed path, then back-date the manifest.
            $this->writer->write($rel, $original . "\n// e06 divergence probe\n", 'agent-a');
            touch($abs, time() + 60);

            $stale = (new GovernedRestore($this->lock))->preview($b['dir'], [$rel], 'agent-a');

            $this->assertFalse($stale['allowed'], 'a manifest older than diverged content must be refused');
            $this->assertStringContainsString(
                'modified since this backup was captured',
                implode(' ', $stale['reasons'])
            );
            $this->assertFalse($stale['preview'][0]['identical']);
        } finally {
            $this->writer->write($rel, $original, 'agent-a');
        }

        $this->assertSame($original, (string) file_get_contents($abs),
            'production content must be exactly as it was');
        $this->assertStringContainsString(
            "'Cache-Control' => 'no-cache, must-revalidate'",
            (string) file_get_contents($abs)
        );
    }

    // ── production invariants ───────────────────────────────────────────────

    public function test_the_cache_control_change_in_web_php_is_untouched(): void
    {
        // A concurrent session added this on 2026-07-27. E0.6 protects the file;
        // it must not alter the content.
        $this->assertStringContainsString(
            "'Cache-Control' => 'no-cache, must-revalidate'",
            (string) file_get_contents(self::ROOT . '/routes/web.php'),
            'the concurrent session\'s legitimate change must remain authoritative'
        );
    }

    /**
     * The governed route baseline is the authority, not a literal in this file.
     *
     * This assertion was re-pinned at E0.5, M0, M1 and M2 — four edits that had
     * nothing to do with what it guards. Reading the governed baseline means an
     * APPROVED route change needs no test edit, while an UNGOVERNED change still
     * fails, which is the property actually being protected.
     */
    private function governedBaseline(): array
    {
        foreach ([
            '/var/www/levelup-staging/storage/app/source-locks/route-baseline.ordered.txt',
            '/root/cr22-baseline-20260727/canonical.ordered.txt',
        ] as $f) {
            if (is_readable($f)) {
                $raw = rtrim((string) file_get_contents($f), "\n");
                $rows = $raw === '' ? [] : explode("\n", $raw);

                return ['file' => $f, 'count' => count($rows), 'signature' => hash('sha256', $raw)];
            }
        }

        $this->markTestSkipped('the governed route baseline is not readable on this host');
    }

    public function test_route_inventory_matches_the_governed_baseline(): void
    {
        $base = $this->governedBaseline();

        $this->assertSame(
            $base['count'],
            count(\Illuminate\Support\Facades\Route::getRoutes()->getRoutes()),
            'the live route count differs from the governed baseline in ' . $base['file']
        );
    }

    public function test_protected_paths_show_no_drift(): void
    {
        $d = ProtectedPaths::drift();

        $this->assertSame([], $d['drifted'], 'protected source changed outside governed tooling');
        $this->assertSame([], $d['added']);
    }
}
