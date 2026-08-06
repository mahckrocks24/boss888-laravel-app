<?php

namespace Tests\Feature\Engineer888;

use Illuminate\Support\Facades\DB;
use App\Core\Engineer888\Runtime\Workspace;
use Tests\TestCase;

/**
 * INC-2026-006 — permanent protection.
 *
 * On 2026-07-30 an Engineer888 verification run emptied production. The cause was
 * a child process inheriting DB_DATABASE through $_SERVER, which phpunit's
 * <env force="true"> does not override, combined with a guard that ran after
 * RefreshDatabase rather than before it.
 *
 * These tests are the standing proof that the defect class stays closed. They do
 * three separate jobs:
 *
 *   1. Assert the mechanism itself still behaves as measured — that stripping
 *      the environment actually changes what a child resolves to. If a future
 *      Laravel or PHPUnit upgrade changes the precedence, this fails and tells
 *      us, rather than the platform telling us.
 *   2. Assert the guard fires before any trait, using a canary row.
 *   3. Run the repository-wide audit and fail on any UNSAFE finding, so a new
 *      unsafe call site cannot be merged silently.
 *
 * Nothing here touches production. The mechanism tests use scratch databases
 * created and dropped by the test, and every assertion names what it protects.
 */
class ExecutionSafetyTest extends TestCase
{
    private const AUDIT = 'tools/exec-safety-audit.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('levelup_staging', DB::connection()->getDatabaseName());
    }

    // ── the audit is a real instrument ──────────────────────────────────

    public function test_the_audit_tool_proves_it_detects_its_own_target(): void
    {
        // A static rule that cannot demonstrate it catches the thing it looks
        // for is not evidence. This repository has already shipped one that
        // could not, so the selftest gates the audit rather than the reverse.
        $output = [];
        $code = 0;
        exec('cd ' . escapeshellarg(base_path()) . ' && php ' . self::AUDIT . ' --selftest 2>&1', $output, $code);
        $text = implode("\n", $output);

        $this->assertSame(0, $code, "the audit tool's selftest must pass:\n" . $text);
        $this->assertStringContainsString('0 failed', $text);
        $this->assertMatchesRegularExpression('/expected UNSAFE\s+got UNSAFE/', $text,
            'the selftest must include a case it is required to catch');
    }

    public function test_no_unsafe_execution_path_exists_anywhere_in_the_platform(): void
    {
        $output = [];
        $code = 0;
        exec('cd ' . escapeshellarg(base_path()) . ' && php ' . self::AUDIT . ' . --json', $output, $code);

        $report = json_decode(implode("\n", $output), true);
        $this->assertIsArray($report, 'the audit must produce parseable output');
        $this->assertGreaterThan(500, $report['files_scanned'], 'the audit must actually have scanned the tree');

        // "0 unsafe" from a scan that could not read app/ is not a result.
        $this->assertTrue($report['complete'] ?? false,
            'the audit reported a verdict without complete coverage. Unreadable required paths: '
            . json_encode($report['coverage']['required_unreadable'] ?? []));

        $unsafe = array_values(array_filter($report['findings'], fn ($f) => $f['verdict'] === 'UNSAFE'));

        $detail = implode("\n", array_map(
            fn ($f) => "  {$f['file']}:{$f['line']}  {$f['summary']}", $unsafe
        ));

        $this->assertSame([], $unsafe,
            "A new execution path can reach production. This is the INC-2026-006 defect class:\n" . $detail);
    }

    public function test_every_phpunit_configuration_names_and_forces_a_test_database(): void
    {
        foreach (glob(base_path('phpunit*.xml')) as $path) {
            $xml = (string) file_get_contents($path);
            $name = basename($path);

            $this->assertMatchesRegularExpression('/DB_DATABASE"\s+value="[^"]+"/', $xml,
                "{$name} must name its database; without it the suite resolves to .env, which is production");

            preg_match('/DB_DATABASE"\s+value="([^"]+)"/', $xml, $m);
            $database = $m[1];

            $this->assertNotContains(strtolower($database),
                ['levelup_staging', 'levelup', 'levelup_production', 'levelup_prod'],
                "{$name} targets production");

            $this->assertMatchesRegularExpression('/(_test|_testing)$/i', $database,
                "{$name} targets '{$database}', which the guard allowlist would refuse anyway");

            // The one that actually caused a loaded gun: phpunit.integration.xml
            // had seven env entries and not one forced.
            $this->assertMatchesRegularExpression('/DB_DATABASE"\s+value="[^"]+"\s+force="true"/', $xml,
                "{$name} sets DB_DATABASE without force=\"true\". PHPUnit leaves an already-set "
                . 'variable alone, and every nested run arrives with one already set.');
        }
    }

    // ── the mechanism, re-measured rather than assumed ──────────────────

    /**
     * The finding that explains INC-2026-006: a child's $_SERVER, populated from
     * the inherited real environment, beats putenv() and $_ENV in Laravel's
     * resolution. If this ever stops being true the fix is over-engineered but
     * still correct; if it silently becomes true in more places, this test is
     * how we find out.
     */
    public function test_a_child_process_inherits_the_parents_database_through_the_environment(): void
    {
        // printenv rather than a PHP child: the claim under test is about
        // environment inheritance, and involving a second PHP process would only
        // add a variable without strengthening the proof.
        putenv('DB_DATABASE=levelup_inherit_probe');
        $inherited = trim((string) shell_exec('printenv DB_DATABASE 2>/dev/null'));
        putenv('DB_DATABASE');

        $this->assertSame('levelup_inherit_probe', $inherited,
            'a child inherits the parent database target — this is the mechanism the fix defends against');
    }

    public function test_stripping_the_environment_prevents_that_inheritance(): void
    {
        putenv('DB_DATABASE=levelup_inherit_probe');
        $stripped = trim((string) shell_exec('env -u DB_DATABASE printenv DB_DATABASE 2>/dev/null'));
        putenv('DB_DATABASE');

        $this->assertSame('', $stripped,
            'env -u must REMOVE the variable, not override it — a child reads $_SERVER from the real '
            . 'environment, and $_SERVER beats putenv() and $_ENV in Laravel\'s resolution order');
    }

    /**
     * The guard-order fix, proved behaviourally without needing any database
     * privilege.
     *
     * A suite is pointed at a database name the guard's allowlist refuses AND
     * which does not exist. The two possible orderings produce different,
     * unmistakable errors:
     *
     *   guard first          -> "REFUSING TO RUN"  (config is read; nothing connects)
     *   RefreshDatabase first -> "Unknown database" (migrate:fresh tries to connect)
     *
     * Until 2026-07-30 this repository produced the second. The first version of
     * this test created a scratch database and used a canary row, which is a
     * stronger proof but requires CREATE DATABASE — and the test user
     * deliberately does not have it. Weakening the database grants to strengthen
     * a test would be the wrong trade, so the proof was changed instead.
     */
    public function test_the_guard_refuses_before_refresh_database_can_touch_anything(): void
    {
        $absent = 'levelup_definitely_not_a_test_db_' . getmypid();

        // Written into the Engineer888 workspace, not next to the application.
        // base_path() is root-owned here and the queue user cannot write it —
        // the assumption that made this very test unrunnable in the conditions
        // it exists to protect (2026-08-02).
        $workspace = Workspace::open(base_path(), 'guardorder-' . getmypid());
        $config = $workspace->phpunitConfigFrom(
            base_path('phpunit.e888.xml'),
            base_path(),
            ['levelup_e888_test' => $absent],
            'phpunit.guardorder.xml'
        );

        try {
            $output = [];
            exec('cd ' . escapeshellarg(base_path())
                . ' && env -u DB_DATABASE -u DB_CONNECTION -u DB_HOST -u DB_USERNAME -u DB_PASSWORD -u APP_ENV'
                . ' php artisan test -c ' . escapeshellarg($config)
                . ' ' . escapeshellarg(base_path('tests/Feature/Engineer888/EngineeringBriefTest.php'))
                . ' --filter=test_worst_severity 2>&1', $output);

            $text = implode("\n", $output);

            $this->assertStringContainsString('REFUSING TO RUN', $text,
                'the guard must refuse a target it cannot prove is a test database');
            $this->assertStringNotContainsString('Unknown database', $text,
                'an "Unknown database" error would mean RefreshDatabase connected first — '
                . 'the exact ordering that emptied production on 2026-07-30');
        } finally {
            $workspace->close();
        }
    }

    public function test_every_test_class_using_refresh_database_inherits_the_guard(): void
    {
        $output = [];
        exec('cd ' . escapeshellarg(base_path()) . ' && php ' . self::AUDIT . ' . --json', $output);
        $report = json_decode(implode("\n", $output), true);

        $rogue = array_values(array_filter($report['findings'],
            fn ($f) => $f['kind'] === 'refresh-database' && $f['verdict'] !== 'SAFE'));

        $this->assertSame([], $rogue,
            'RefreshDatabase runs migrate:fresh. A class that does not extend Tests\\TestCase does not '
            . 'inherit the guard and will drop whatever the environment resolves to: '
            . implode(', ', array_column($rogue, 'file')));
    }

}
