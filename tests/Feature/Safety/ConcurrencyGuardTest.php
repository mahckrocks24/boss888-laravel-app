<?php

namespace Tests\Feature\Safety;

use App\Core\Safety\ConcurrencyGuard;
use App\Core\Safety\EngineeringOperation;
use App\Core\Safety\SourceOwnershipLock;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * RSK-M4-1 — concurrent-operation detection and prevention.
 *
 * Every test runs against a temporary lock directory, so the suite exercises
 * the real mechanism without touching the live locks or the live audit trail.
 */
class ConcurrencyGuardTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir() . '/rskm41-' . getmypid() . '-' . uniqid();
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function locks(): SourceOwnershipLock
    {
        return new SourceOwnershipLock($this->dir);
    }

    private function guard(): ConcurrencyGuard
    {
        return new ConcurrencyGuard($this->locks());
    }

    private function operation(): EngineeringOperation
    {
        $l = $this->locks();

        return new EngineeringOperation($l, new ConcurrencyGuard($l));
    }

    /** Pull one named check out of an assessment. */
    private function check(array $assessment, string $name): array
    {
        foreach ($assessment['checks'] as $c) {
            if ($c['check'] === $name) {
                return $c;
            }
        }

        $this->fail("assessment has no check named {$name}");
    }

    // ───────────────────────────────────────────────────────────────────────
    // shape and honesty
    // ───────────────────────────────────────────────────────────────────────

    public function test_an_assessment_reports_every_check_with_its_evidence(): void
    {
        $a = $this->guard()->assess('unit-test');

        $this->assertNotEmpty($a['checks']);
        $this->assertContains($a['verdict'], [ConcurrencyGuard::CLEAR, ConcurrencyGuard::WARNING, ConcurrencyGuard::BLOCKING]);
        $this->assertNotEmpty($a['observed_at']);

        foreach ($a['checks'] as $c) {
            $this->assertArrayHasKey('check', $c);
            $this->assertArrayHasKey('severity', $c);
            $this->assertNotEmpty($c['summary'], "{$c['check']} reports no summary");
            $this->assertArrayHasKey('evidence', $c);
            $this->assertContains($c['severity'],
                [ConcurrencyGuard::CLEAR, ConcurrencyGuard::WARNING, ConcurrencyGuard::BLOCKING]);
        }
    }

    public function test_every_expected_check_is_performed(): void
    {
        $names = array_column($this->guard()->assess('unit-test')['checks'], 'check');

        foreach ([
            'foreign_ownership_locks',
            'unapplied_migrations',
            'foreign_processes',
            'recent_source_writes',
            'protected_path_drift',
        ] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    /**
     * The limit that matters most: this cannot stop a session that never takes
     * a lock. Claiming otherwise would be worse than the gap it closes.
     */
    public function test_the_guard_states_that_it_cannot_prevent_non_participants(): void
    {
        $limits = strtolower(implode(' ', $this->guard()->assess()['limits']));

        $this->assertStringContainsString('cannot prevent', $limits);
        $this->assertStringContainsString('lock', $limits);
        $this->assertStringContainsString('instant', $limits);
    }

    public function test_the_verdict_is_consistent_with_the_checks(): void
    {
        $a = $this->guard()->assess('unit-test');

        $hasBlocking = array_filter($a['checks'], fn ($c) => $c['severity'] === ConcurrencyGuard::BLOCKING) !== [];
        $hasWarning = array_filter($a['checks'], fn ($c) => $c['severity'] === ConcurrencyGuard::WARNING) !== [];

        if ($hasBlocking) {
            $this->assertSame(ConcurrencyGuard::BLOCKING, $a['verdict']);
        } elseif ($hasWarning) {
            $this->assertSame(ConcurrencyGuard::WARNING, $a['verdict']);
        } else {
            $this->assertSame(ConcurrencyGuard::CLEAR, $a['verdict']);
            $this->assertTrue($a['clear']);
        }

        $this->assertSame($a['clear'], $a['conflicts'] === []);
    }

    // ───────────────────────────────────────────────────────────────────────
    // foreign ownership locks
    // ───────────────────────────────────────────────────────────────────────

    public function test_a_live_lock_held_by_another_owner_blocks(): void
    {
        $this->locks()->acquire('some-other-scope', 'other-session', 'their work', [], 600);

        $c = $this->check($this->guard()->assess('me'), 'foreign_ownership_locks');

        $this->assertSame(ConcurrencyGuard::BLOCKING, $c['severity']);
        $this->assertSame('other-session', $c['evidence'][0]['owner']);
        $this->assertSame('their work', $c['evidence'][0]['phase']);
    }

    public function test_our_own_lock_is_not_a_conflict(): void
    {
        $this->locks()->acquire('some-scope', 'me', 'my work', [], 600);

        $c = $this->check($this->guard()->assess('me'), 'foreign_ownership_locks');

        $this->assertSame(ConcurrencyGuard::CLEAR, $c['severity']);
    }

    public function test_a_stale_lock_is_not_reported_as_live_work(): void
    {
        $this->locks()->acquire('stale-scope', 'gone', 'abandoned', [], 600);

        // age it past the staleness threshold
        $f = $this->dir . '/stale-scope.lock.json';
        $lock = json_decode((string) file_get_contents($f), true);
        $old = gmdate('c', time() - (SourceOwnershipLock::STALE_SECONDS + 60));
        $lock['heartbeat_at'] = $old;
        $lock['acquired_at'] = $old;
        $lock['expires_at'] = $old;
        file_put_contents($f, json_encode($lock));

        $c = $this->check($this->guard()->assess('me'), 'foreign_ownership_locks');

        $this->assertSame(ConcurrencyGuard::CLEAR, $c['severity'],
            'a stale lock is a separate concern and must not masquerade as live work');
    }

    // ───────────────────────────────────────────────────────────────────────
    // unapplied migrations — the check that would have caught M4
    // ───────────────────────────────────────────────────────────────────────

    public function test_unapplied_migrations_are_computed_from_real_state(): void
    {
        $c = $this->check($this->guard()->assess(), 'unapplied_migrations');

        if (!in_array($c['severity'], [ConcurrencyGuard::CLEAR, ConcurrencyGuard::BLOCKING], true)) {
            $this->markTestSkipped('migrations table not readable in this environment');
        }

        $files = array_map(
            fn ($f) => basename($f, '.php'),
            glob(base_path('database/migrations/*.php')) ?: []
        );
        $applied = DB::table('migrations')->pluck('migration')->all();
        $expected = array_values(array_diff($files, $applied));

        if ($expected === []) {
            $this->assertSame(ConcurrencyGuard::CLEAR, $c['severity']);
        } else {
            $this->assertSame(ConcurrencyGuard::BLOCKING, $c['severity']);
            $this->assertEqualsCanonicalizing($expected, $c['evidence']['pending']);
        }
    }

    // ───────────────────────────────────────────────────────────────────────
    // the operation lock
    // ───────────────────────────────────────────────────────────────────────

    public function test_a_second_operation_is_refused(): void
    {
        $this->operation()->begin('session-a', 'first operation', force: true);

        $this->expectException(RuntimeException::class);
        $this->operation()->begin('session-b', 'second operation', force: true);
    }

    public function test_the_refusal_names_the_holder(): void
    {
        $this->operation()->begin('session-a', 'first operation', force: true);

        try {
            $this->operation()->begin('session-b', 'second operation', force: true);
            $this->fail('a second operation was allowed to start');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('session-a', $e->getMessage());
            $this->assertStringContainsString('first operation', $e->getMessage());
        }
    }

    public function test_the_same_operation_may_re_enter(): void
    {
        $this->operation()->begin('session-a', 'op', force: true);
        $again = $this->operation()->begin('session-a', 'op', force: true);

        $this->assertSame('session-a', $again['owner']);
    }

    public function test_a_blocking_conflict_refuses_the_start(): void
    {
        $this->locks()->acquire('their-scope', 'other-session', 'their migration', [], 600);

        try {
            $this->operation()->begin('mine', 'my deploy');
            $this->fail('the operation started despite a blocking conflict');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('concurrent engineering work', $e->getMessage());
            $this->assertStringContainsString('other-session', $e->getMessage());
        }
    }

    public function test_a_refusal_is_recorded_rather_than_silent(): void
    {
        $this->locks()->acquire('their-scope', 'other-session', 'their migration', [], 600);

        try {
            $this->operation()->begin('mine', 'my deploy');
        } catch (RuntimeException) {
            // expected
        }

        $audit = (string) file_get_contents($this->dir . '/audit.log');
        $this->assertStringContainsString('operation_refused', $audit);
        $this->assertStringContainsString('mine', $audit);
    }

    public function test_a_forced_start_proceeds_and_is_recorded_as_forced(): void
    {
        $this->locks()->acquire('their-scope', 'other-session', 'their work', [], 600);

        $op = $this->operation()->begin('mine', 'my deploy', force: true);

        $this->assertTrue($op['forced']);
        $this->assertNotSame(ConcurrencyGuard::CLEAR, $op['assessment']['verdict']);

        $audit = (string) file_get_contents($this->dir . '/audit.log');
        $this->assertStringContainsString('operation_begin', $audit);
        $this->assertStringContainsString('"forced":true', $audit);
    }

    public function test_ending_an_operation_releases_it_and_records_a_closing_assessment(): void
    {
        $op = $this->operation();
        $op->begin('session-a', 'op', force: true);

        $this->assertNotNull($op->current());

        $end = $op->end('session-a');

        $this->assertArrayHasKey('assessment', $end);
        $this->assertNull($op->current(), 'the lock must be released');

        $audit = (string) file_get_contents($this->dir . '/audit.log');
        $this->assertStringContainsString('operation_end', $audit);

        // released, so the next operation may start
        $this->operation()->begin('session-b', 'next op', force: true);
        $this->assertSame('session-b', $this->operation()->current()['owner']);
    }

    // ───────────────────────────────────────────────────────────────────────
    // the guard must not mutate anything
    // ───────────────────────────────────────────────────────────────────────

    public function test_assessing_writes_nothing(): void
    {
        $this->locks()->acquire('a-scope', 'someone', 'work', [], 600);

        $before = [];
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            $before[$f] = [filemtime($f), md5_file($f)];
        }

        $this->guard()->assess('me');
        $this->guard()->assess(null);

        $after = [];
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            $after[$f] = [filemtime($f), md5_file($f)];
        }

        $this->assertSame($before, $after, 'assess() modified state — it must be a pure observation');
    }

    /** The existing route protections must be untouched by this work. */
    public function test_the_route_ownership_mechanism_is_unchanged(): void
    {
        $src = (string) file_get_contents(base_path('app/Core/Safety/SourceOwnershipLock.php'));

        $this->assertStringContainsString('public function assertOwned(', $src);
        $this->assertStringContainsString('INC-2026-003', $src);

        $writer = (string) file_get_contents(base_path('app/Core/Safety/GovernedWriter.php'));
        $this->assertStringContainsString('write_refused_hash_mismatch', $writer,
            'the anti-INC-2026-003 re-hash check must still be present');
        $this->assertStringContainsString('ReconstructionManifest::isRouteSource', $writer,
            'the RSK-M3-1 refresh must still be present');
    }

    // ───────────────────────────────────────────────────────────────────────
    // anti-laundering: a governed write must not absorb someone else's
    // ungoverned change into the governed baseline
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Regression for a defect RSK-M3-1 introduced and RSK-M4-1 found.
     *
     * The automatic reconstruction refresh recomputes from whatever is on disk.
     * If another session edited a module outside governed tooling, the next
     * governed write silently recorded that content as the governed baseline.
     * The write path must now withhold the refresh instead.
     */
    public function test_the_write_path_withholds_the_refresh_when_foreign_drift_exists(): void
    {
        $src = (string) file_get_contents(base_path('app/Core/Safety/GovernedWriter.php'));

        $this->assertStringContainsString('$foreignDrift', $src,
            'the write path must sample foreign drift');
        $this->assertStringContainsString('reconstruction_refresh_withheld', $src,
            'withholding must be recorded, not silent');
        $this->assertStringContainsString('withheld: ungoverned drift in', $src,
            'the reason must name the drifted paths');
    }

    /**
     * Drift must be sampled BEFORE seal(), because seal() re-seals every
     * protected path and destroys the evidence. Order is the whole fix.
     */
    public function test_drift_is_sampled_before_the_seal_not_after(): void
    {
        $src = (string) file_get_contents(base_path('app/Core/Safety/GovernedWriter.php'));

        $driftPos = strpos($src, '$foreignDrift = array_keys(');
        $sealPos = strpos($src, 'ProtectedPaths::seal($owner, "governed write:');

        $this->assertNotFalse($driftPos);
        $this->assertNotFalse($sealPos);
        $this->assertLessThan($sealPos, $driftPos,
            'drift is sampled after the seal, which means it can never be detected');
    }

    /** The path being written is our own change and must not count as foreign. */
    public function test_the_written_path_is_excluded_from_foreign_drift(): void
    {
        $src = (string) file_get_contents(base_path('app/Core/Safety/GovernedWriter.php'));

        $this->assertStringContainsString("array_diff_key(\$d['drifted'] ?? [], [\$relPath => true])", $src,
            'the file we just wrote must be excluded, or every write would withhold');
    }
}
