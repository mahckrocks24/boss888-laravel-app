<?php

namespace Tests\Feature\Routes;

use App\Core\Safety\ManifestBackup;
use App\Core\Safety\SourceOwnershipLock;
use RuntimeException;
use Tests\TestCase;

/**
 * CR-22 — controlled simulation of the concurrency failures that caused
 * INC-2026-003, run against the new ownership and manifest controls.
 *
 * Each test is one of the eight required scenarios. The point is not that the
 * classes work in isolation — that is covered elsewhere — but that the specific
 * sequence of events which cost three phases of production fixes now fails
 * closed instead of succeeding silently.
 */
class ConcurrentChangeSimulationTest extends TestCase
{
    private string $locks;
    private string $backups;
    private string $seo;
    private string $studio;

    protected function setUp(): void
    {
        parent::setUp();
        $u = uniqid();
        $this->locks   = sys_get_temp_dir() . "/cr22sim-locks-{$u}";
        $this->backups = sys_get_temp_dir() . "/cr22sim-bk-{$u}";
        $this->seo     = sys_get_temp_dir() . "/cr22sim-seo-{$u}.php";
        $this->studio  = sys_get_temp_dir() . "/cr22sim-studio-{$u}.php";
        file_put_contents($this->seo, "<?php // seo module v1\n");
        file_put_contents($this->studio, "<?php // studio module v1\n");
    }

    protected function tearDown(): void
    {
        @unlink($this->seo);
        @unlink($this->studio);
        parent::tearDown();
    }

    private function lock(): SourceOwnershipLock
    {
        return new SourceOwnershipLock($this->locks);
    }

    /** @test 1 — two sessions editing DIFFERENT modules both proceed. */
    public function two_sessions_on_different_modules_both_proceed(): void
    {
        $l = $this->lock();

        $l->acquire('routes-seo', 'session-CHAT', 'P3', [$this->seo]);
        $l->acquire('routes-studio', 'session-STUDIO', 'PhaseQ', [$this->studio]);

        // Both may write their own module.
        $l->assertOwned('routes-seo', 'session-CHAT');
        $l->assertOwned('routes-studio', 'session-STUDIO');
        file_put_contents($this->seo, "<?php // seo v2 by CHAT\n");
        file_put_contents($this->studio, "<?php // studio v2 by STUDIO\n");

        $this->assertStringContainsString('CHAT', file_get_contents($this->seo));
        $this->assertStringContainsString('STUDIO', file_get_contents($this->studio));
        $this->assertCount(2, $l->all(), 'both locks should be live simultaneously');
    }

    /** @test 2 — two sessions on the SAME module: the second fails closed. */
    public function two_sessions_on_the_same_module_fail_closed(): void
    {
        $l = $this->lock();
        $l->acquire('routes-studio', 'session-STUDIO', 'PhaseQ', [$this->studio]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/owned by session-STUDIO/');
        $l->acquire('routes-studio', 'session-CHAT', 'P3', [$this->studio]);
    }

    /** @test 3 — restoring an old backup of ONE module is refused once that module moved on. */
    public function an_old_module_backup_cannot_revert_newer_work(): void
    {
        $b = new ManifestBackup($this->backups);
        $snap = $b->capture([$this->studio], 'studio-old', 'session-STUDIO', 'PhaseP');

        file_put_contents($this->studio, "<?php // studio v2 — three phases of fixes\n");

        $eval = $b->evaluateRestore($snap['dir'], [$this->studio]);
        $this->assertFalse($eval['allowed']);
        $this->assertStringContainsString('SOURCE_HAS_ADVANCED', $eval['reasons'][0]);

        $this->expectException(RuntimeException::class);
        $b->restore($snap['dir'], [$this->studio], 'session-STUDIO');
    }

    /** @test 4 — a session may not change a module owned by another. */
    public function a_session_cannot_change_a_module_another_owns(): void
    {
        $l = $this->lock();
        $l->acquire('routes-manifest', 'session-CR22', 'CR-22', [$this->seo, $this->studio]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not session-STUDIO|owned by session-CR22/');
        $l->assertOwned('routes-manifest', 'session-STUDIO');
    }

    /** @test 5 — a stale lock may be taken over, and the takeover is recorded. */
    public function a_stale_lock_can_be_taken_over_and_is_audited(): void
    {
        $l = $this->lock();
        $l->acquire('routes-seo', 'session-DEAD', 'P3', [$this->seo], 1);

        // Age the lock past its expiry and heartbeat window.
        $f = $this->locks . '/routes-seo.lock.json';
        $d = json_decode(file_get_contents($f), true);
        $d['heartbeat_at'] = gmdate('c', time() - (SourceOwnershipLock::STALE_SECONDS + 60));
        $d['expires_at']   = gmdate('c', time() - 10);
        file_put_contents($f, json_encode($d));

        $new = $l->acquire('routes-seo', 'session-LIVE', 'CR-22', [$this->seo]);
        $this->assertSame('session-LIVE', $new['owner']);

        $audit = (string) file_get_contents($this->locks . '/audit.log');
        $this->assertStringContainsString('stale_takeover', $audit);
        $this->assertStringContainsString('session-DEAD', $audit);
    }

    /** @test 6 — a restore with no manifest is refused outright. */
    public function a_restore_without_a_manifest_is_refused(): void
    {
        $dir = $this->backups . '/no-manifest';
        mkdir($dir, 0775, true);
        $b = new ManifestBackup($this->backups);

        $eval = $b->evaluateRestore($dir, [$this->studio]);
        $this->assertFalse($eval['allowed']);
        $this->assertStringContainsString('NO_MANIFEST', $eval['reasons'][0]);
    }

    /** @test 7 — a restore whose expected hash no longer matches is refused. */
    public function a_restore_with_a_mismatched_hash_is_refused(): void
    {
        $b = new ManifestBackup($this->backups);
        $snap = $b->capture([$this->seo], 'seo-snap', 'session-A', 'CR-22');

        file_put_contents($this->seo, "<?php // changed after capture\n");

        $eval = $b->evaluateRestore($snap['dir'], [$this->seo]);
        $this->assertFalse($eval['allowed']);
        $this->assertContains($this->seo, $eval['changed_since']);
    }

    /** @test 8 — a legitimate module restore leaves unrelated modules untouched. */
    public function a_legitimate_module_restore_preserves_unrelated_work(): void
    {
        $b = new ManifestBackup($this->backups);
        $snap = $b->capture([$this->studio], 'studio-good', 'session-STUDIO', 'PhaseQ');

        // Another session advances an UNRELATED module.
        file_put_contents($this->seo, "<?php // seo v2 — chat phase work\n");
        // The owned module is damaged and deliberately rolled back.
        file_put_contents($this->studio, "<?php // studio DAMAGED\n");

        $b->restore($snap['dir'], [$this->studio], 'session-STUDIO', true);

        $this->assertStringContainsString('studio module v1', file_get_contents($this->studio));
        $this->assertStringContainsString('chat phase work', file_get_contents($this->seo),
            'an unrelated module must never be affected by another module\'s restore — this is the '
            . 'entire failure of INC-2026-003');
    }

    /**
     * @test THE FULL INC-2026-003 REPLAY.
     *
     * Phase P takes a backup, three phases of fixes land, Phase P restores its
     * backup. Under the old model that silently reverted everything. Under the
     * new model it must fail closed at the restore.
     */
    public function the_incident_sequence_now_fails_closed(): void
    {
        $b = new ManifestBackup($this->backups);
        $l = $this->lock();

        // 1. Phase P snapshots the shared file.
        file_put_contents($this->studio, "<?php // routes v1 (pre-P1)\n");
        $phaseP = $b->capture([$this->studio], 'phaseP', 'session-PHASE-P', 'PhaseP');

        // 2. Three phases of fixes land from another session.
        $l->acquire('routes-studio', 'session-CHAT', 'P2-B', [$this->studio]);
        file_put_contents($this->studio, "<?php // routes v2 — P1 + P2-A + P2-B fixes\n");
        $l->release('routes-studio', 'session-CHAT');

        // 3. Phase P restores its snapshot — the exact incident.
        $eval = $b->evaluateRestore($phaseP['dir'], [$this->studio]);
        $this->assertFalse($eval['allowed'], 'the incident restore must now be refused');

        $threw = false;
        try {
            $b->restore($phaseP['dir'], [$this->studio], 'session-PHASE-P');
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('RESTORE REFUSED', $e->getMessage());
        }
        $this->assertTrue($threw, 'INC-2026-003 would have succeeded again');

        // 4. The three phases of fixes are intact.
        $this->assertStringContainsString('P1 + P2-A + P2-B fixes', file_get_contents($this->studio));
    }
}
