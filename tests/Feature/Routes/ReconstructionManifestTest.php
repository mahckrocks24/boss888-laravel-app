<?php

namespace Tests\Feature\Routes;

use App\Core\Safety\ReconstructionManifest;
use Tests\TestCase;

/**
 * RSK-M3-1 — the CR-22B reconstruction artefact must refresh itself.
 *
 * The risk being closed: extraction-mapping.json records the hash the module
 * set must rebuild to, every approved route change moves that hash, and the
 * refresh was a manual step in each deploy script. At E1 M3 it was missed, so
 * the conformance test failed against M2's value and appeared to report a
 * defect in the modules when nothing was wrong with them.
 *
 * These tests deliberately never write the live artefact. Staleness detection
 * and rewriting are exercised against a temporary copy, so the suite can prove
 * the mechanism without mutating governed state.
 */
class ReconstructionManifestTest extends TestCase
{
    private const ART = '/root/cr22-baseline-20260727/extraction-mapping.json';

    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir() . '/rsk-m3-1-' . getmypid() . '.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->tmp);
        parent::tearDown();
    }

    /** A private copy of the live artefact, safe to mutate. */
    private function scratch(): ReconstructionManifest
    {
        $this->assertFileExists(self::ART, 'the governed CR-22B artefact is missing');
        copy(self::ART, $this->tmp);

        return new ReconstructionManifest($this->tmp);
    }

    /** @return array<string,mixed> */
    private function readScratch(): array
    {
        return json_decode((string) file_get_contents($this->tmp), true);
    }

    // ───────────────────────────────────────────────────────────────────────
    // what counts as a route source
    // ───────────────────────────────────────────────────────────────────────

    public function test_only_the_parent_and_its_modules_move_the_reconstruction(): void
    {
        $this->assertTrue(ReconstructionManifest::isRouteSource('routes/api.php'));
        $this->assertTrue(ReconstructionManifest::isRouteSource('routes/api/authenticated/seo-01.php'));
        $this->assertTrue(ReconstructionManifest::isRouteSource('routes/api/authenticated/agents-04.php'));
    }

    /**
     * These are protected paths too, but they are not part of the CR-22B
     * reconstruction. Refreshing on a write to them would be wrong.
     */
    public function test_other_protected_paths_do_not_trigger_a_refresh(): void
    {
        foreach ([
            'routes/web.php',
            'routes/exec-api.php',
            'bootstrap/app.php',
            'app/Engines/CRM/Http/Routes.php',
            'storage/app/source-locks/route-ownership.json',
        ] as $path) {
            $this->assertFalse(
                ReconstructionManifest::isRouteSource($path),
                "{$path} is not part of the reconstruction and must not refresh the artefact"
            );
        }
    }

    // ───────────────────────────────────────────────────────────────────────
    // the computation
    // ───────────────────────────────────────────────────────────────────────

    /**
     * Computed independently here, exactly as RouteModuleScopeInheritanceTest
     * does. If the class and the conformance test ever disagree, the artefact
     * would be written with a value that test rejects.
     */
    public function test_the_class_computes_the_same_reconstruction_as_the_conformance_test(): void
    {
        $base = '/var/www/levelup-staging';
        $mark = "// ==== CR-22B MODULE BODY BEGINS - verbatim from routes/api.php, do not edit ====\n";
        $cand = explode("\n", (string) file_get_contents($base . '/routes/api.php'));

        $out = [];
        foreach ($cand as $line) {
            if (preg_match("#^\s*require __DIR__ \. '/api/authenticated/([\w-]+\.php)';\s*$#", $line, $m)) {
                $body = explode($mark, (string) file_get_contents($base . '/routes/api/authenticated/' . $m[1]), 2)[1];
                $body = substr($body, -1) === "\n" ? substr($body, 0, -1) : $body;
                foreach (explode("\n", $body) as $l) {
                    $out[] = $l;
                }
            } elseif (!preg_match('#^\s*// CR-22B: [\w-]+ extracted to routes/api/authenticated/#', $line)) {
                $out[] = $line;
            }
        }

        $this->assertSame(
            hash('sha256', implode("\n", $out)),
            (new ReconstructionManifest())->reconstruct(),
            'ReconstructionManifest disagrees with the independent reconstruction'
        );
    }

    /** The live artefact must be in step with the live sources right now. */
    public function test_the_live_artifact_is_currently_fresh(): void
    {
        $m = new ReconstructionManifest();

        $this->assertSame(
            $m->reconstruct(),
            $m->stored(),
            'the governed artefact is stale — a route source changed without the refresh running'
        );
    }

    // ───────────────────────────────────────────────────────────────────────
    // the refresh itself
    // ───────────────────────────────────────────────────────────────────────

    public function test_a_stale_artifact_is_brought_up_to_date(): void
    {
        $m = $this->scratch();

        $data = $this->readScratch();
        $data['source_sha256'] = str_repeat('0', 64);          // simulate a missed refresh
        file_put_contents($this->tmp, json_encode($data, JSON_PRETTY_PRINT));

        $result = $m->refresh('unit test');

        $this->assertTrue($result['refreshed']);
        $this->assertSame(str_repeat('0', 64), $result['from']);
        $this->assertSame((new ReconstructionManifest())->reconstruct(), $result['to']);
        $this->assertSame($result['to'], $this->readScratch()['source_sha256']);
    }

    public function test_the_superseded_hash_is_kept_in_history(): void
    {
        $m = $this->scratch();
        $before = count($this->readScratch()['source_sha256_history'] ?? []);

        $data = $this->readScratch();
        $data['source_sha256'] = str_repeat('a', 64);
        file_put_contents($this->tmp, json_encode($data, JSON_PRETTY_PRINT));

        $m->refresh('unit test');

        $history = $this->readScratch()['source_sha256_history'];
        $this->assertCount($before + 1, $history);
        $this->assertSame(str_repeat('a', 64), end($history));
    }

    public function test_refreshing_preserves_every_other_field(): void
    {
        $m = $this->scratch();
        $original = $this->readScratch();

        $data = $original;
        $data['source_sha256'] = str_repeat('b', 64);
        file_put_contents($this->tmp, json_encode($data, JSON_PRETTY_PRINT));

        $m->refresh('unit test');
        $after = $this->readScratch();

        $this->assertSame($original['candidate_sha256'], $after['candidate_sha256']);
        $this->assertCount(count($original['modules']), $after['modules']);
        $this->assertSame(
            array_column($original['modules'], 'module'),
            array_column($after['modules'], 'module')
        );

        foreach ($original['modules'] as $i => $mod) {
            foreach (['file', 'domain', 'owner', 'source_start', 'source_end', 'source_lines'] as $k) {
                $this->assertSame($mod[$k], $after['modules'][$i][$k], "module field {$k} was altered");
            }
        }
    }

    public function test_refreshing_a_current_artifact_changes_nothing(): void
    {
        $m = $this->scratch();
        $before = file_get_contents($this->tmp);

        $result = $m->refresh('unit test');

        $this->assertFalse($result['refreshed']);
        $this->assertSame('already current', $result['reason']);
        $this->assertSame($before, file_get_contents($this->tmp), 'a no-op refresh must not rewrite the file');
    }

    /**
     * The write has already succeeded by the time refresh() runs, so it must
     * report failure rather than throw. Behaviour then degrades to what it was
     * before this class existed — a loud conformance-test failure — never to
     * silence.
     */
    public function test_an_unusable_artifact_reports_failure_instead_of_throwing(): void
    {
        $result = (new ReconstructionManifest('/root/does-not-exist/nothing.json'))->refresh('unit test');

        $this->assertFalse($result['refreshed']);
        $this->assertStringContainsString('artefact missing or unreadable', $result['reason']);
        $this->assertNotNull($result['to'], 'the computed value is still reported so the failure is diagnosable');
    }

    // ───────────────────────────────────────────────────────────────────────
    // the wiring
    // ───────────────────────────────────────────────────────────────────────

    /**
     * The whole point of RSK-M3-1 is that the refresh is not a step anyone has
     * to remember. If this call is ever removed the artefact silently rots
     * again, so its presence is pinned.
     */
    public function test_the_governed_write_path_performs_the_refresh(): void
    {
        $src = (string) file_get_contents('/var/www/levelup-staging/app/Core/Safety/GovernedWriter.php');

        $this->assertStringContainsString('ReconstructionManifest::isRouteSource($relPath)', $src);
        $this->assertStringContainsString('->refresh(', $src);
        $this->assertStringContainsString("'reconstruction_manifest'", $src,
            'the refresh outcome must be recorded in the ownership audit trail');
    }
}
