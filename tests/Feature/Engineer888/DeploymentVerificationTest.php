<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Deployment\BaselineComparison;
use App\Core\Engineer888\Deployment\DeploymentIntent;
use App\Core\Engineer888\Deployment\DeploymentVerifier;
use App\Core\Engineer888\Deployment\Evidence;
use App\Core\Engineer888\Deployment\ReleaseIdentity;
use App\Core\Engineer888\Deployment\SmokeChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Deployment verification.
 *
 * The central claim under test is a negative one: that a healthy application is
 * never reported as a verified release. Most of these tests exist to stop
 * HEALTHY_IDENTITY_UNPROVEN from quietly becoming a success.
 *
 * Everything runs against the Engineer888-assigned database and throwaway git
 * repositories. No test mutates production, and no smoke check mutates anything.
 */
class DeploymentVerificationTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();

        // Isolation, asserted rather than assumed.
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName(),
            'this suite must run only against the Engineer888-assigned database');

        $this->repo = sys_get_temp_dir() . '/e888-deploy-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── verdicts ────────────────────────────────────────────────────────

    public function test_a_matching_release_with_a_clean_tree_is_verified(): void
    {
        $this->makeRepo(['app/Thing.php' => "<?php\n"], commit: true);
        $head = trim($this->git('rev-parse HEAD'));

        $identity = (new ReleaseIdentity($this->repo))->collect();
        $intent = (object) ['expected_commit' => $head, 'expected_branch' => null, 'expected_artifacts' => null];

        $comparison = (new ReleaseIdentity($this->repo))->compareWithIntent($identity, $intent);

        $this->assertTrue($comparison['identity_proven'],
            'a clean tree at the expected commit is the only state where identity can be proven');
        $this->assertSame([], $comparison['mismatches']);
    }

    public function test_a_healthy_platform_without_an_intent_is_never_verified(): void
    {
        $comparison = (new ReleaseIdentity($this->repo))->compareWithIntent([], null);

        $this->assertFalse($comparison['identity_proven']);
        $this->assertFalse($comparison['intent_present']);
        $this->assertStringContainsString('no deployment intent', $comparison['reason']);
    }

    public function test_a_commit_mismatch_cannot_pass(): void
    {
        $this->makeRepo(['app/Thing.php' => "<?php\n"], commit: true);

        $identity = (new ReleaseIdentity($this->repo))->collect();
        $intent = (object) [
            'expected_commit' => str_repeat('a', 40),   // not what is deployed
            'expected_branch' => null, 'expected_artifacts' => null,
        ];

        $comparison = (new ReleaseIdentity($this->repo))->compareWithIntent($identity, $intent);

        $this->assertFalse($comparison['identity_proven']);
        $this->assertNotEmpty($comparison['mismatches']);
        $this->assertStringContainsString('expected commit', $comparison['mismatches'][0]);
    }

    /**
     * The situation this platform is actually in: HEAD matches, and production
     * is still running files no commit describes.
     */
    public function test_a_dirty_tree_prevents_identity_even_when_the_commit_matches(): void
    {
        $this->makeRepo(['app/Thing.php' => "<?php\n"], commit: true);
        $head = trim($this->git('rev-parse HEAD'));
        file_put_contents($this->repo . '/app/Uncommitted.php', "<?php\n// running in production, in no commit\n");

        $identity = (new ReleaseIdentity($this->repo))->collect();
        $comparison = (new ReleaseIdentity($this->repo))->compareWithIntent(
            $identity,
            (object) ['expected_commit' => $head, 'expected_branch' => null, 'expected_artifacts' => null]
        );

        $this->assertFalse($comparison['identity_proven'],
            'matching HEAD proves nothing while uncommitted files are being executed');
        $this->assertStringContainsString('working tree is dirty', $comparison['reason']);
        $this->assertSame([], $comparison['mismatches'], 'the commit really does match — that is the point');
    }

    public function test_dirty_production_state_is_disclosed(): void
    {
        $this->makeRepo(['app/Thing.php' => "<?php\n"], commit: true);
        file_put_contents($this->repo . '/app/Extra.php', "<?php\n");

        $identity = (new ReleaseIdentity($this->repo))->collect();

        $this->assertFalse($identity['git_tree_clean']->value);
        $this->assertSame(Evidence::PROVEN, $identity['git_tree_clean']->confidence);
        $this->assertGreaterThan(0, $identity['git_uncommitted_files']->value);
        $this->assertStringContainsString('uncommitted', (string) $identity['git_head']->caveat);
        $this->assertSame(Evidence::OBSERVED, $identity['git_head']->confidence,
            'HEAD is downgraded from PROVEN the moment the tree is dirty');
    }

    public function test_an_artifact_hash_mismatch_is_detected(): void
    {
        $this->makeRepo(['composer.json' => "{\"name\":\"real\"}\n"], commit: true);

        $identity = (new ReleaseIdentity($this->repo))->collect();
        $comparison = (new ReleaseIdentity($this->repo))->compareWithIntent($identity, (object) [
            'expected_commit' => null, 'expected_branch' => null,
            'expected_artifacts' => json_encode(['composer.json' => 'deadbeefdeadbeef']),
        ]);

        $this->assertFalse($comparison['identity_proven']);
        $this->assertStringContainsString('differs from the expected build', $comparison['mismatches'][0]);
    }

    // ── evidence discipline ─────────────────────────────────────────────

    public function test_unavailable_evidence_is_unknown_and_never_guessed(): void
    {
        // A directory that is not a git checkout.
        @mkdir($this->repo, 0775, true);

        $identity = (new ReleaseIdentity($this->repo))->collect();

        $this->assertSame(Evidence::UNKNOWN, $identity['git']->confidence);
        $this->assertNull($identity['git']->value, 'an unknown field carries no value at all');
        $this->assertNotEmpty($identity['git']->caveat);
    }

    public function test_the_runtime_version_is_never_treated_as_authoritative(): void
    {
        // Faked rather than reached over the network. The previous version
        // skipped whenever the runtime was unreachable — and phpunit.e888.xml
        // blanks RUNTIME_URL, so it skipped ALWAYS. A test that never runs is
        // not coverage of a mandatory behaviour.
        \Illuminate\Support\Facades\Http::fake([
            '*/health' => \Illuminate\Support\Facades\Http::response([
                'status'  => 'ok',
                'version' => '9.9.9-whatever-it-claims',
            ], 200),
        ]);

        putenv('RUNTIME_URL=https://runtime.invalid');
        $_ENV['RUNTIME_URL'] = 'https://runtime.invalid';

        try {
            $identity = (new ReleaseIdentity(base_path()))->collect();
        } finally {
            putenv('RUNTIME_URL');
            unset($_ENV['RUNTIME_URL']);
        }

        $this->assertTrue($identity['runtime_version']->isKnown(),
            'the faked response must be read, otherwise this test proves nothing');
        $this->assertSame('9.9.9-whatever-it-claims', $identity['runtime_version']->value);

        $this->assertNotSame(Evidence::PROVEN, $identity['runtime_version']->confidence,
            'however confidently the runtime reports a version, it is a hardcoded literal and '
            . 'must never be PROVEN build identity');
        $this->assertSame(Evidence::OBSERVED, $identity['runtime_version']->confidence);
        $this->assertStringContainsString('hardcoded', (string) $identity['runtime_version']->caveat);
    }

    // ── smoke checks ────────────────────────────────────────────────────

    public function test_no_smoke_check_mutates_data(): void
    {
        $checks = (new SmokeChecks(base_path()))->run();

        $this->assertNotEmpty($checks);
        foreach ($checks as $check) {
            $this->assertFalse($check['mutating'], $check['name'] . ' must be read-only');
        }
    }

    public function test_smoke_checks_do_not_change_the_row_count_of_anything(): void
    {
        $before = [];
        foreach (['tasks', 'users', 'workspaces'] as $table) {
            $before[$table] = DB::table($table)->count();
        }

        (new SmokeChecks(base_path()))->run();

        foreach ($before as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(),
                "verification wrote to {$table} — no check may prove health by mutating");
        }
    }

    public function test_configuration_checks_never_record_values(): void
    {
        $checks = (new SmokeChecks(base_path()))->run();

        $config = null;
        foreach ($checks as $check) {
            if ($check['name'] === 'config:required-keys') { $config = $check; break; }
        }

        $this->assertNotNull($config);

        // The real APP_KEY must appear nowhere in the recorded evidence.
        $serialised = json_encode($config);
        $appKey = (string) config('app.key');
        $this->assertNotSame('', $appKey);
        $this->assertStringNotContainsString($appKey, $serialised,
            'a verification record must never contain a secret');
        $this->assertSame(['checked', 'missing'], array_keys($config['evidence']),
            'only key NAMES are recorded');
    }

    public function test_http_checks_never_record_response_bodies(): void
    {
        $checks = (new SmokeChecks(base_path()))->run();

        foreach ($checks as $check) {
            if (! str_starts_with($check['name'], 'http:')) { continue; }
            $this->assertSame(['url', 'status', 'latency_ms'], array_keys($check['evidence']),
                'a response body can contain customer data or tokens and is never stored');
        }
    }

    /**
     * Regression: the check reported "2 pending" when 1 was pending, because it
     * grepped console output for the word "pending" and
     * create_pending_invites_table has the word in its NAME.
     */
    public function test_pending_migrations_are_computed_from_the_database_not_console_text(): void
    {
        $applied = array_flip(DB::table('migrations')->pluck('migration')->all());
        $expected = [];
        foreach (glob(base_path('database/migrations/*.php')) as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            if (! isset($applied[$name])) { $expected[] = $name; }
        }
        sort($expected);

        $check = $this->checkNamed('migrations:consistency');
        $reported = $check['evidence']['pending'] ?? [];
        sort($reported);

        $this->assertSame($expected, $reported,
            'the pending set must be files-minus-applied, not a text match on the word "pending"');

        // The specific trap: a migration whose NAME contains "pending" but which
        // has been applied must never be reported as pending.
        $namedPending = array_values(array_filter(array_keys($applied),
            fn ($m) => str_contains($m, 'pending')));
        foreach ($namedPending as $applied_but_named_pending) {
            $this->assertNotContains($applied_but_named_pending, $reported,
                $applied_but_named_pending . ' is applied; its name is not evidence');
        }
    }

    /**
     * Regression: release identity reported "no queue workers observed" in the
     * same report where the health check counted four, because `ps -C php`
     * matches the process NAME and these run as php8.3.
     */
    public function test_worker_identity_does_not_contradict_the_worker_health_check(): void
    {
        $identity = (new ReleaseIdentity(base_path()))->collect();
        $check = $this->checkNamed('workers:processes');

        $healthSaysRunning = $check['status'] === SmokeChecks::PASS;
        $identityKnowsThem = $identity['worker_processes']->isKnown();

        $this->assertSame($healthSaysRunning, $identityKnowsThem,
            'identity and health must not disagree about whether queue workers exist — '
            . 'a report that contradicts itself is worse than one that says less');

        if ($healthSaysRunning) {
            $this->assertSame(Evidence::PROVEN, $identity['worker_processes']->confidence);
            $this->assertNotEmpty($identity['worker_processes']->value);
        }
    }

    /** @return array<string,mixed> */
    private function checkNamed(string $name): array
    {
        foreach ((new SmokeChecks(base_path()))->run() as $check) {
            if ($check['name'] === $name) { return $check; }
        }

        $this->fail("check {$name} was not produced");
    }

    // ── baseline ────────────────────────────────────────────────────────

    public function test_no_baseline_reports_not_comparable_rather_than_a_trend(): void
    {
        $comparison = (new BaselineComparison())->compare([], [], null);

        $this->assertFalse($comparison['comparable']);
        $this->assertSame([], $comparison['regressions']);
        $this->assertStringContainsString('establishes the baseline', $comparison['reason']);
    }

    public function test_a_changed_collection_method_is_reported_as_not_comparable(): void
    {
        $baseline = (object) [
            'id' => 1, 'created_at' => '2026-08-01 00:00:00',
            'checks' => json_encode([]),
            // Then: HEAD was PROVEN (clean tree). Now: OBSERVED (dirty tree).
            'identity' => json_encode(['git_head' => ['value' => 'aaa', 'confidence' => Evidence::PROVEN]]),
        ];

        $comparison = (new BaselineComparison())->compare(
            ['git_head' => ['value' => 'bbb', 'confidence' => Evidence::OBSERVED]],
            [], $baseline
        );

        $notComparable = array_values(array_filter($comparison['changes'],
            fn ($c) => ($c['change'] ?? null) === BaselineComparison::NOT_COMPARABLE));

        $this->assertNotEmpty($notComparable,
            'two values collected at different confidence do not measure the same thing');
        $this->assertStringContainsString('do not measure the same thing', $notComparable[0]['detail']);
    }

    public function test_a_check_that_worsened_is_a_regression_and_one_that_improved_is_not(): void
    {
        $baseline = (object) [
            'id' => 1, 'created_at' => '2026-08-01 00:00:00',
            'identity' => json_encode([]),
            'checks' => json_encode([
                ['name' => 'a', 'status' => SmokeChecks::PASS, 'detail' => ''],
                ['name' => 'b', 'status' => SmokeChecks::FAIL, 'detail' => ''],
                ['name' => 'c', 'status' => SmokeChecks::PASS, 'detail' => ''],
            ]),
        ];

        $comparison = (new BaselineComparison())->compare([], [
            ['name' => 'a', 'status' => SmokeChecks::FAIL, 'detail' => 'broke'],
            ['name' => 'b', 'status' => SmokeChecks::PASS, 'detail' => 'fixed'],
        ], $baseline);

        $regressed = array_column($comparison['regressions'], 'check');

        $this->assertContains('a', $regressed, 'PASS -> FAIL is a regression');
        $this->assertNotContains('b', $regressed, 'FAIL -> PASS is not');
        $this->assertContains('c', $regressed, 'a check that ran before and not now is a regression');
    }

    // ── persistence and the record ──────────────────────────────────────

    public function test_a_verification_is_persisted_with_its_evidence(): void
    {
        $result = (new DeploymentVerifier(base_path()))->verify('production', null, true);

        $this->assertNotNull($result['id']);

        $row = DB::table('engineering_deployment_verifications')->find($result['id']);
        $this->assertNotNull($row);
        $this->assertSame($result['verdict'], $row->verdict);
        $this->assertIsArray(json_decode($row->identity, true));
        $this->assertIsArray(json_decode($row->checks, true));
        $this->assertSame('e888', $row->session);

        $appKey = (string) config('app.key');
        $this->assertStringNotContainsString($appKey, (string) $row->checks);
        $this->assertStringNotContainsString($appKey, (string) $row->identity);
    }

    public function test_an_intent_is_never_inferred_from_git(): void
    {
        $this->makeRepo(['app/Thing.php' => "<?php\n"], commit: true);
        file_put_contents($this->repo . '/app/Dirty.php', "<?php\n");

        $candidate = (new DeploymentIntent($this->repo))->candidateFromGit();

        $this->assertNotNull($candidate['expected_commit'], 'the candidate is offered');
        $this->assertNotNull($candidate['warning'], 'and it comes with the reason it may be wrong');
        $this->assertStringContainsString('would make a later verification lie', $candidate['warning']);

        // Nothing was recorded merely by asking.
        $this->assertSame(0, DB::table('engineering_deployment_intents')->count());
    }

    public function test_verifying_against_a_missing_intent_is_blocked_not_guessed(): void
    {
        $result = (new DeploymentVerifier(base_path()))->verify('production', 'no-such-uuid', false);

        $this->assertSame(DeploymentVerifier::BLOCKED, $result['verdict']);
        $this->assertStringContainsString('no deployment intent exists', $result['reason']);
    }

    // ── coordination still holds ────────────────────────────────────────

    public function test_the_ownership_manifest_is_still_enforced(): void
    {
        $manifest = OwnershipManifest::active(base_path());

        $this->assertNotNull($manifest, 'this sprint runs under a declared manifest');
        $this->assertSame('levelup_e888_test', $manifest->testDatabase());

        $this->assertSame(OwnershipManifest::OWNED,
            $manifest->classify('app/Core/Engineer888/Deployment/DeploymentVerifier.php')['status']);
        $this->assertSame(OwnershipManifest::UNKNOWN,
            $manifest->classify('app/Core/PlatformEvents/Outbox.php')['status'],
            'another engineer\'s file remains unownable');
    }

    public function test_unrelated_pending_migrations_are_not_executed_by_verification(): void
    {
        $pending = function (): array {
            $applied = array_flip(DB::table('migrations')->pluck('migration')->all());
            $out = [];
            foreach (glob(base_path('database/migrations/*.php')) as $path) {
                $name = pathinfo($path, PATHINFO_FILENAME);
                if (! isset($applied[$name])) { $out[] = $name; }
            }
            sort($out);

            return $out;
        };

        $before = $pending();
        (new DeploymentVerifier(base_path()))->verify('production', null, false);

        $this->assertSame($before, $pending(),
            'verification must observe migration state, never change it');
    }

    // ── helpers ─────────────────────────────────────────────────────────

    private function makeRepo(array $files, bool $commit): void
    {
        @mkdir($this->repo, 0775, true);
        foreach ($files as $path => $content) {
            $full = $this->repo . '/' . $path;
            if (! is_dir(dirname($full))) { mkdir(dirname($full), 0775, true); }
            file_put_contents($full, $content);
        }
        $this->git('init -q');
        $this->git('config user.email e888@test');
        $this->git('config user.name e888');
        if ($commit) {
            $this->git('add -A');
            $this->git('commit -q -m base');
        }
    }

    private function git(string $args): string
    {
        $out = [];
        exec('cd ' . escapeshellarg($this->repo) . ' && git ' . $args . ' 2>&1', $out);

        return implode("\n", $out);
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
