<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Install\InstallDecision;
use App\Core\Engineer888\Install\InstallManifest;
use App\Core\Engineer888\Install\SafeInstaller;
use RuntimeException;
use Tests\TestCase;

/**
 * Installer integrity — the P0.5 defect class.
 *
 * The first test reproduces the 2026-08-01 incident exactly: install, patch the
 * destination, verify the patch, re-run the installer, and require that the
 * patch survives. Under the old installers it did not, and losing it broke
 * migrate:fresh, failed every test, and shipped two defects twice.
 *
 * Everything runs in a throwaway repository with its own manifest. Nothing here
 * touches the real tree.
 */
class InstallIntegrityTest extends TestCase
{
    private string $repo;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir() . '/e888-install-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);
        // Write the fixture manifest where the RUNNING configuration says
        // manifests live. Setting E888_SPRINT_MANIFEST here instead would leak
        // into every later test in the process and point the assignment guard at
        // a path that does not exist in the real repository — which is exactly
        // what happened, and the guard correctly refused.
        $this->manifestPath = $this->repo . '/' . ltrim(
            getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json', '/'
        );
        @mkdir(dirname($this->manifestPath), 0775, true);

        // A manifest owning everything in the fixture, so ownership never masks
        // the provenance behaviour under test.
        file_put_contents($this->manifestPath, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'install-integrity', 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['**'],
        ], JSON_PRETTY_PRINT));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── the exact proven failure ────────────────────────────────────────

    public function test_a_destination_patched_after_installation_is_never_silently_overwritten(): void
    {
        $installer = $this->installer();
        $target = 'app/Core/Thing.php';

        // 1. install
        $original = "<?php\n// v1\nclass Thing { const INDEX = 'a_very_long_name_that_breaks_mysql'; }\n";
        $this->assertSame(InstallDecision::INSTALL, $installer->install($target, $original, 'transport.php')->state);

        // 2. patch the destination — the index-name fix, as it happened
        $patched = "<?php\n// v1\nclass Thing { const INDEX = 'short_idx'; }\n";
        file_put_contents($this->repo . '/' . $target, $patched);

        // 3. verify the patch is there
        $this->assertStringContainsString('short_idx', file_get_contents($this->repo . '/' . $target));

        // 4. re-run the installer with the STALE transport content
        $decision = $this->installer()->install($target, $original, 'transport.php');

        // 5. it must refuse
        $this->assertSame(InstallDecision::DIVERGED, $decision->state);
        $this->assertTrue($decision->isRefusal());
        $this->assertTrue($decision->destinationChangedSinceInstall());

        // 6. the patch must survive
        $this->assertStringContainsString('short_idx', file_get_contents($this->repo . '/' . $target),
            'the fix was silently reverted — this is the exact 2026-08-01 incident');
        $this->assertStringNotContainsString('a_very_long_name_that_breaks_mysql',
            file_get_contents($this->repo . '/' . $target));

        // 7. divergence is reported with evidence
        $this->assertNotSame($decision->recordedDestinationHash, $decision->destinationHash);
        $this->assertNotEmpty($decision->diff['first_difference']);
        $this->assertNotEmpty($decision->recommendation);
    }

    public function test_stale_transport_cannot_resurrect_a_fixed_defect(): void
    {
        $installer = $this->installer();
        $target = 'app/Core/Buggy.php';

        $installer->install($target, "<?php\n// bug\n", 'stale.php');
        file_put_contents($this->repo . '/' . $target, "<?php\n// fixed\n");

        // Re-running the same stale transport twice must never win.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $decision = $this->installer()->install($target, "<?php\n// bug\n", 'stale.php');
            $this->assertSame(InstallDecision::DIVERGED, $decision->state);
        }

        $this->assertStringContainsString('fixed', file_get_contents($this->repo . '/' . $target));
    }

    // ── the safe cases ──────────────────────────────────────────────────

    public function test_an_unchanged_destination_can_be_updated_safely(): void
    {
        $installer = $this->installer();
        $target = 'app/Core/Clean.php';

        $installer->install($target, "<?php\n// v1\n", 'transport.php');

        // Classification is a question about the state BEFORE writing. After the
        // write, "destination changed since install" is true by construction,
        // because the destination is now the new content.
        $before = $this->installer()->decide($target, "<?php\n// v2\n");
        $this->assertSame(InstallDecision::UPDATE, $before->state);
        $this->assertTrue($before->sourceChangedSinceInstall());
        $this->assertFalse($before->destinationChangedSinceInstall(),
            'the destination was untouched since installation — that is what makes this safe');

        $decision = $this->installer()->install($target, "<?php\n// v2\n", 'transport.php');

        $this->assertSame(InstallDecision::UPDATE, $decision->state);
        $this->assertStringContainsString('v2', file_get_contents($this->repo . '/' . $target));
        $this->assertNotNull($decision->backup, 'an overwrite of existing content is always backed up');
    }

    public function test_an_identical_source_is_a_no_op(): void
    {
        $installer = $this->installer();
        $target = 'app/Core/Same.php';
        $content = "<?php\n// same\n";

        $installer->install($target, $content, 'transport.php');
        $decision = $this->installer()->install($target, $content, 'transport.php');

        $this->assertSame(InstallDecision::UNCHANGED_NO_OP, $decision->state);
        $this->assertFalse($decision->isWrite());
    }

    // ── unknown provenance ──────────────────────────────────────────────

    public function test_a_file_with_no_install_record_fails_closed(): void
    {
        $target = 'app/Core/Foreign.php';
        @mkdir($this->repo . '/app/Core', 0775, true);
        file_put_contents($this->repo . '/' . $target, "<?php\n// written by someone else\n");

        $decision = $this->installer()->install($target, "<?php\n// mine\n", 'transport.php');

        $this->assertSame(InstallDecision::UNKNOWN_PROVENANCE, $decision->state);
        $this->assertStringContainsString('someone else', file_get_contents($this->repo . '/' . $target));
    }

    public function test_adopting_an_existing_file_records_it_without_writing(): void
    {
        $target = 'app/Core/Adopted.php';
        @mkdir($this->repo . '/app/Core', 0775, true);
        $existing = "<?php\n// pre-existing\n";
        file_put_contents($this->repo . '/' . $target, $existing);

        $decision = $this->installer()->install($target, "<?php\n// different\n", 't.php', 'c', false, null, true);

        $this->assertSame(InstallDecision::UNCHANGED_NO_OP, $decision->state);
        $this->assertSame($existing, file_get_contents($this->repo . '/' . $target),
            'adoption establishes provenance; it must never write');
    }

    // ── override ────────────────────────────────────────────────────────

    public function test_an_override_requires_an_explicit_reason(): void
    {
        $installer = $this->installer();
        $target = 'app/Core/Over.php';
        $installer->install($target, "<?php\n// v1\n", 't.php');
        file_put_contents($this->repo . '/' . $target, "<?php\n// edited\n");

        $decision = $this->installer()->install($target, "<?php\n// v2\n", 't.php', 'c', true, null);

        $this->assertSame(InstallDecision::REFUSED, $decision->state);
        $this->assertStringContainsString('must state why', $decision->reason);
        $this->assertStringContainsString('edited', file_get_contents($this->repo . '/' . $target));
    }

    public function test_an_approved_override_writes_backs_up_and_is_recorded(): void
    {
        $installer = $this->installer();
        $target = 'app/Core/Over2.php';
        $installer->install($target, "<?php\n// v1\n", 't.php');
        file_put_contents($this->repo . '/' . $target, "<?php\n// edited\n");

        $decision = $this->installer()->install(
            $target, "<?php\n// v2\n", 't.php', 'c', true, 'superseded by review'
        );

        $this->assertSame(InstallDecision::OVERRIDDEN, $decision->state);
        $this->assertStringContainsString('v2', file_get_contents($this->repo . '/' . $target));

        // backup exists and holds the content that was replaced
        $this->assertNotNull($decision->backup);
        $backup = $this->repo . '/' . $decision->backup;
        $this->assertFileExists($backup);
        $this->assertStringContainsString('edited', file_get_contents($backup));

        // the override is recorded with its reason
        $entry = InstallManifest::load($this->repo)->entryFor($target);
        $this->assertNotEmpty($entry['overrides']);
        $this->assertSame('superseded by review', $entry['overrides'][0]['reason']);
        $this->assertNotNull($entry['overrides'][0]['backup']);
    }

    // ── manifest integrity ──────────────────────────────────────────────

    public function test_a_corrupt_manifest_fails_closed(): void
    {
        file_put_contents($this->repo . '/' . InstallManifest::PATH, 'not json at all');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not a usable install manifest/');

        InstallManifest::load($this->repo);
    }

    public function test_an_incomplete_install_record_fails_closed(): void
    {
        file_put_contents($this->repo . '/' . InstallManifest::PATH, json_encode([
            'manifest_version' => InstallManifest::VERSION,
            'entries' => ['app/X.php' => ['source_hash' => 'abc']],   // no destination_hash
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/incomplete/');

        InstallManifest::load($this->repo)->entryFor('app/X.php');
    }

    public function test_an_unsupported_manifest_version_fails_closed(): void
    {
        file_put_contents($this->repo . '/' . InstallManifest::PATH, json_encode([
            'manifest_version' => 999, 'entries' => [],
        ]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not supported/');

        InstallManifest::load($this->repo);
    }

    // ── ownership ───────────────────────────────────────────────────────

    public function test_a_file_this_sprint_does_not_own_is_refused(): void
    {
        // Narrow the fixture manifest so the target is outside it.
        file_put_contents($this->manifestPath, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'install-integrity', 'owned_paths' => ['app/Core/Engineer888/**'],
        ], JSON_PRETTY_PRINT));

        $decision = $this->installer()->install(
            'app/Core/PlatformEvents/Outbox.php', "<?php\n// mine now\n", 't.php'
        );

        $this->assertSame(InstallDecision::REFUSED, $decision->state);
        $this->assertStringContainsString('does not own', $decision->reason);
        $this->assertFileDoesNotExist($this->repo . '/app/Core/PlatformEvents/Outbox.php');
    }

    // ── distinguishing the two kinds of change ──────────────────────────

    public function test_source_change_and_destination_divergence_are_distinguished(): void
    {
        $installer = $this->installer();
        $target = 'app/Core/Both.php';
        $installer->install($target, "<?php\n// v1\n", 't.php');

        // source-only change
        $sourceOnly = $this->installer()->decide($target, "<?php\n// v2\n");
        $this->assertTrue($sourceOnly->sourceChangedSinceInstall());
        $this->assertFalse($sourceOnly->destinationChangedSinceInstall());
        $this->assertSame(InstallDecision::UPDATE, $sourceOnly->state);

        // destination-only change
        file_put_contents($this->repo . '/' . $target, "<?php\n// edited\n");
        $destinationOnly = $this->installer()->decide($target, "<?php\n// v1\n");
        $this->assertFalse($destinationOnly->sourceChangedSinceInstall());
        $this->assertTrue($destinationOnly->destinationChangedSinceInstall());
        $this->assertSame(InstallDecision::DIVERGED, $destinationOnly->state);
    }

    public function test_deciding_never_writes_anything(): void
    {
        $installer = $this->installer();
        $target = 'app/Core/Untouched.php';

        $installer->decide($target, "<?php\n// nope\n");

        $this->assertFileDoesNotExist($this->repo . '/' . $target);
        $this->assertSame(0, InstallManifest::load($this->repo)->count());
    }

    // ── helpers ─────────────────────────────────────────────────────────

    /** A fresh installer each time, so manifest state comes from disk. */
    private function installer(): SafeInstaller
    {
        return new SafeInstaller($this->repo);
    }

    private function rmrf(string $dir): void
    {
        if (! is_dir($dir)) { return; }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
