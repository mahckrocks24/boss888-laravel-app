<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\EngineeringBrief;
use App\Core\Engineer888\Recommendations;
use App\Core\Engineer888\Signals\ErrorSignal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Engineer888 daily brief.
 *
 * Tests concentrate on the parts with real logic — the recommendation rules,
 * the log-offset arithmetic and snapshot persistence. The shell-based
 * collectors are exercised end to end rather than mocked: a mocked `git` proves
 * only that the mock works.
 */
class EngineeringBriefTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertNotSame('levelup_staging', DB::connection()->getDatabaseName());
    }

    // ── recommendation rules ────────────────────────────────────────────

    public function test_missing_upstream_is_critical(): void
    {
        $r = Recommendations::evaluate(['git' => ['available' => true, 'branch' => 'feature/x', 'has_upstream' => false]]);

        $ids = array_column($r, 'id');
        $this->assertContains('GIT-NO-UPSTREAM', $ids);
        $this->assertSame('critical', $this->rule($r, 'GIT-NO-UPSTREAM')['severity']);
    }

    public function test_a_healthy_repository_raises_nothing(): void
    {
        $r = Recommendations::evaluate(['git' => [
            'available' => true, 'branch' => 'main', 'commit' => 'abc',
            'has_upstream' => true, 'upstream' => 'origin/main',
            'uncommitted_total' => 0, 'uncommitted_app' => 0, 'backup_files' => 0,
        ]]);

        $this->assertSame([], array_values(array_filter($r, fn ($x) => str_starts_with($x['id'], 'GIT-'))));
    }

    public function test_dirty_app_directory_escalates_with_volume(): void
    {
        $base = ['available' => true, 'has_upstream' => true, 'uncommitted_total' => 100];

        $warn = Recommendations::evaluate(['git' => $base + ['uncommitted_app' => 25]]);
        $crit = Recommendations::evaluate(['git' => $base + ['uncommitted_app' => 60]]);

        $this->assertSame('warn', $this->rule($warn, 'GIT-DIRTY-APP')['severity']);
        $this->assertSame('critical', $this->rule($crit, 'GIT-DIRTY-APP')['severity']);
    }

    public function test_unreachable_runtime_is_critical(): void
    {
        $r = Recommendations::evaluate(['runtime' => ['available' => false, 'reason' => 'timeout']]);

        $this->assertSame('critical', $this->rule($r, 'RUNTIME-DOWN')['severity']);
    }

    public function test_a_runtime_version_change_is_reported(): void
    {
        $r = Recommendations::evaluate(
            ['runtime' => ['available' => true, 'version' => '2.37.4', 'latency_ms' => 100]],
            ['runtime' => ['version' => '2.37.3']]
        );

        $this->assertNotNull($this->rule($r, 'RUNTIME-VERSION-CHANGED'));
        $this->assertStringContainsString('2.37.3 -> 2.37.4', $this->rule($r, 'RUNTIME-VERSION-CHANGED')['evidence']);
    }

    public function test_a_downed_supervisor_program_is_critical(): void
    {
        $r = Recommendations::evaluate(['platform' => ['supervisor_down' => ['worker_01=FATAL']]]);

        $this->assertSame('critical', $this->rule($r, 'WORKERS-DOWN')['severity']);
    }

    public function test_a_stalled_scheduler_is_critical(): void
    {
        $fresh = Recommendations::evaluate(['platform' => ['available' => true, 'scheduler_heartbeat_age_seconds' => 12]]);
        $stale = Recommendations::evaluate(['platform' => ['available' => true, 'scheduler_heartbeat_age_seconds' => 4000]]);

        $this->assertNull($this->rule($fresh, 'SCHEDULER-STALLED'));
        $this->assertSame('critical', $this->rule($stale, 'SCHEDULER-STALLED')['severity']);
    }

    public function test_an_unreadable_scheduler_heartbeat_is_reported_not_assumed_healthy(): void
    {
        $r = Recommendations::evaluate(['platform' => [
            'available' => true,
            'scheduler_heartbeat_age_seconds' => null,
            'scheduler_heartbeat_reason' => 'scheduler log not found at /var/log/x.log',
        ]]);

        $rule = $this->rule($r, 'SCHEDULER-UNKNOWN');
        $this->assertNotNull($rule, 'an unmeasurable scheduler must not read as a working one');
        $this->assertNull($this->rule($r, 'SCHEDULER-STALLED'), 'unknown is not the same as stalled');
    }

    public function test_a_long_gap_between_briefs_is_reported(): void
    {
        $ok = Recommendations::evaluate(['_meta' => ['hours_since_previous' => 24.1]]);
        $bad = Recommendations::evaluate(['_meta' => ['hours_since_previous' => 72.0]]);
        $first = Recommendations::evaluate(['_meta' => ['hours_since_previous' => null]]);

        $this->assertNull($this->rule($ok, 'BRIEF-GAP'), 'a normal daily cadence is not a finding');
        $this->assertNotNull($this->rule($bad, 'BRIEF-GAP'));
        $this->assertNull($this->rule($first, 'BRIEF-GAP'), 'the first brief has no gap');
    }

    public function test_queue_backlog_only_fires_when_it_is_not_draining(): void
    {
        $draining = Recommendations::evaluate(
            ['platform' => ['queue_pending_total' => 150]],
            ['platform' => ['queue_pending_total' => 400]]
        );
        $stuck = Recommendations::evaluate(
            ['platform' => ['queue_pending_total' => 150]],
            ['platform' => ['queue_pending_total' => 120]]
        );

        $this->assertNull($this->rule($draining, 'QUEUE-BACKLOG'), 'a draining queue is not a finding');
        $this->assertNotNull($this->rule($stuck, 'QUEUE-BACKLOG'));
    }

    public function test_failed_tasks_escalate_with_volume(): void
    {
        $warn = Recommendations::evaluate(['workload' => ['available' => true, 'tasks_by_status' => ['failed' => 3]]]);
        $crit = Recommendations::evaluate(['workload' => ['available' => true, 'tasks_by_status' => ['failed' => 12]]]);

        $this->assertSame('warn', $this->rule($warn, 'TASKS-FAILED')['severity']);
        $this->assertSame('critical', $this->rule($crit, 'TASKS-FAILED')['severity']);
    }

    /**
     * Regression: the first live run reported 2,628 five-day-old log entries as
     * "genuinely new" because there was no offset to count forward from.
     */
    public function test_a_baseline_run_does_not_claim_historical_errors_are_new(): void
    {
        $r = Recommendations::evaluate(['errors' => [
            'available' => true, 'baseline' => true, 'total' => 2628,
            'bytes_scanned' => 4194304, 'partial_scan' => true,
            'counts' => ['ERROR' => 2628, 'CRITICAL' => 0],
        ]]);

        $this->assertNull($this->rule($r, 'ERRORS-NEW'), 'a baseline scan is not news');
        $this->assertNull($this->rule($r, 'ERRORS-PARTIAL'), 'the 4MB cap is expected on a baseline');

        $baseline = $this->rule($r, 'ERRORS-BASELINE');
        $this->assertNotNull($baseline);
        $this->assertSame('info', $baseline['severity']);
        $this->assertStringContainsString('historical', $baseline['evidence']);
    }

    public function test_the_second_run_does_report_errors_as_new(): void
    {
        $r = Recommendations::evaluate(['errors' => [
            'available' => true, 'baseline' => false, 'total' => 5,
            'counts' => ['ERROR' => 5],
        ]]);

        $this->assertNotNull($this->rule($r, 'ERRORS-NEW'));
        $this->assertNull($this->rule($r, 'ERRORS-BASELINE'));
    }

    public function test_trend_rules_stay_silent_without_a_prior_measurement(): void
    {
        $signals = [
            'git'      => ['available' => true, 'has_upstream' => true, 'backup_files' => 529],
            'platform' => ['queue_pending_total' => 400],
        ];

        $first = Recommendations::evaluate($signals);
        $this->assertNull($this->rule($first, 'GIT-BAK-GROWING'),
            'cannot claim growth against a baseline that does not exist');

        // The backlog is still worth reporting, but must not invent a comparison.
        $backlog = $this->rule($first, 'QUEUE-BACKLOG');
        $this->assertNotNull($backlog);
        $this->assertStringContainsString('no prior measurement', $backlog['evidence']);

        $second = Recommendations::evaluate($signals, ['git' => ['backup_files' => 500]]);
        $this->assertNotNull($this->rule($second, 'GIT-BAK-GROWING'));
        $this->assertStringContainsString('was 500', $this->rule($second, 'GIT-BAK-GROWING')['evidence']);
    }

    public function test_a_critical_log_entry_outranks_a_high_error_count(): void
    {
        $many = Recommendations::evaluate(['errors' => ['available' => true, 'total' => 40,
            'counts' => ['ERROR' => 40, 'CRITICAL' => 0, 'EMERGENCY' => 0, 'ALERT' => 0]]]);
        $one = Recommendations::evaluate(['errors' => ['available' => true, 'total' => 1,
            'counts' => ['ERROR' => 0, 'CRITICAL' => 1, 'EMERGENCY' => 0, 'ALERT' => 0]]]);

        $this->assertSame('warn', $this->rule($many, 'ERRORS-NEW')['severity']);
        $this->assertSame('critical', $this->rule($one, 'ERRORS-NEW')['severity'],
            'a single CRITICAL must outrank forty ERRORs');
    }

    public function test_recommendations_are_ordered_critical_first_and_stable(): void
    {
        $r = Recommendations::evaluate([
            'errors'   => ['available' => true, 'total' => 1, 'counts' => ['ERROR' => 1]],
            'runtime'  => ['available' => false, 'reason' => 'down'],
            'platform' => ['disk_used_percent' => 86, 'disk_free_gb' => 3],
        ]);

        $this->assertSame('critical', $r[0]['severity']);
        // Same input must produce identical output — the brief has to diff cleanly.
        $this->assertSame($r, Recommendations::evaluate([
            'errors'   => ['available' => true, 'total' => 1, 'counts' => ['ERROR' => 1]],
            'runtime'  => ['available' => false, 'reason' => 'down'],
            'platform' => ['disk_used_percent' => 86, 'disk_free_gb' => 3],
        ]));
    }

    public function test_worst_severity_is_derived_correctly(): void
    {
        $this->assertSame('ok', Recommendations::worstSeverity([]));
        $this->assertSame('warn', Recommendations::worstSeverity([['severity' => 'info'], ['severity' => 'warn']]));
        $this->assertSame('critical', Recommendations::worstSeverity([['severity' => 'warn'], ['severity' => 'critical']]));
    }

    // ── error log offset arithmetic ──────────────────────────────────────

    public function test_error_signal_counts_only_entries_after_the_offset(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'e888log');
        file_put_contents($log, "[x] local.ERROR: first\n");
        $offsetAfterFirst = filesize($log);

        file_put_contents($log, "[x] local.ERROR: second\n[x] local.CRITICAL: third\n", FILE_APPEND);

        // 'local' declared as the production channel for this fixture.
        $result = (new ErrorSignal($log, $offsetAfterFirst, false, 'local'))->collect();

        $this->assertTrue($result['available']);
        $this->assertSame(2, $result['total'], 'must not re-count entries already reported');
        $this->assertSame(1, $result['counts']['CRITICAL']);
        $this->assertSame(filesize($log), $result['offset']);

        unlink($log);
    }

    /**
     * Regression: a live run reported 58 new production errors that were another
     * session's test suite writing into the production log file.
     */
    public function test_test_channel_entries_are_excluded_from_the_production_count(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'e888log');
        file_put_contents($log, implode('', [
            "[2026-07-30 12:00:00] staging.ERROR: a real production failure\n",
            "[2026-07-30 12:00:01] testing.ERROR: a test suite failure\n",
            "[2026-07-30 12:00:02] testing.ERROR: another test suite failure\n",
            "[2026-07-30 12:00:03] staging.CRITICAL: a real critical\n",
            "[2026-07-30 12:00:04] local.ERROR: someone's dev run\n",
            "not a log line at all\n",
        ]));

        $result = (new ErrorSignal($log, 0, false, 'staging'))->collect();

        $this->assertSame(2, $result['total'], 'only staging entries are production errors');
        $this->assertSame(1, $result['counts']['ERROR']);
        $this->assertSame(1, $result['counts']['CRITICAL']);
        $this->assertSame(3, $result['foreign_total']);
        $this->assertSame(['testing' => 2, 'local' => 1], $result['foreign_counts']);
        $this->assertSame('staging', $result['channel']);

        unlink($log);
    }

    public function test_foreign_channel_entries_are_reported_rather_than_silently_dropped(): void
    {
        $r = Recommendations::evaluate(['errors' => [
            'available' => true, 'baseline' => false, 'total' => 0,
            'counts' => ['ERROR' => 0],
            'foreign_total' => 58, 'foreign_counts' => ['testing' => 58],
        ]]);

        $this->assertNull($this->rule($r, 'ERRORS-NEW'), 'no production errors means no production finding');

        $rule = $this->rule($r, 'LOG-FOREIGN-CHANNEL');
        $this->assertNotNull($rule, 'exclusion must be visible, not silent');
        $this->assertStringContainsString('testing:58', $rule['evidence']);
    }

    public function test_a_truncated_log_restarts_from_zero_rather_than_reading_garbage(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'e888log');
        file_put_contents($log, "[x] local.ERROR: only entry\n");

        // Offset larger than the file: the log was rotated or cleared.
        $result = (new ErrorSignal($log, 999999, false, 'local'))->collect();

        $this->assertTrue($result['rotated']);
        $this->assertSame(1, $result['total']);

        unlink($log);
    }

    public function test_a_missing_log_degrades_instead_of_throwing(): void
    {
        $result = (new ErrorSignal('/nonexistent/path/laravel.log'))->collect();

        $this->assertFalse($result['available']);
        $this->assertSame(0, $result['offset']);
    }

    // ── end to end ──────────────────────────────────────────────────────

    public function test_the_brief_runs_and_persists_a_snapshot(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'e888log');
        file_put_contents($log, "[x] local.ERROR: seeded\n");

        $brief = (new EngineeringBrief(base_path(), $log))->generate();

        $this->assertArrayHasKey('git', $brief['signals']);
        $this->assertArrayHasKey('runtime', $brief['signals']);
        $this->assertArrayHasKey('platform', $brief['signals']);
        $this->assertArrayHasKey('workload', $brief['signals']);
        $this->assertArrayHasKey('errors', $brief['signals']);

        $this->assertSame(1, DB::table('engineering_snapshots')->count());
        $row = DB::table('engineering_snapshots')->first();
        $this->assertSame(filesize($log), (int) $row->log_offset);
        $this->assertIsArray(json_decode($row->signals, true));

        unlink($log);
    }

    public function test_the_second_brief_compares_against_the_first(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'e888log');
        file_put_contents($log, "seed\n");

        $first = (new EngineeringBrief(base_path(), $log))->generate();
        $second = (new EngineeringBrief(base_path(), $log))->generate();

        $this->assertNotNull($second['previous'], 'the second brief must see the first');
        $this->assertSame(2, DB::table('engineering_snapshots')->count());

        $this->assertTrue($first['signals']['errors']['baseline'], 'first run is a baseline');
        $this->assertFalse($second['signals']['errors']['baseline'], 'second run is a delta');

        $this->assertNull($first['signals']['_meta']['hours_since_previous']);
        $this->assertLessThan(1, $second['signals']['_meta']['hours_since_previous']);

        unlink($log);
    }

    public function test_no_store_does_not_persist(): void
    {
        $log = tempnam(sys_get_temp_dir(), 'e888log');
        file_put_contents($log, "seed\n");

        (new EngineeringBrief(base_path(), $log))->generate(persist: false);

        $this->assertSame(0, DB::table('engineering_snapshots')->count());

        unlink($log);
    }

    public function test_the_command_exit_code_reflects_severity(): void
    {
        // Derive the expectation from the platform's actual state rather than
        // hardcoding a value that would break the day someone fixes the repo.
        $worst = (new EngineeringBrief(base_path(), storage_path('logs/laravel.log')))
            ->generate(persist: false)['worst'];

        $expected = ['critical' => 2, 'warn' => 1, 'info' => 0, 'ok' => 0][$worst];

        $this->artisan('engineering:brief', ['--no-store' => true])
            ->assertExitCode($expected);

        $this->assertSame(0, DB::table('engineering_snapshots')->count(),
            '--no-store must not write');
    }

    public function test_the_json_output_is_valid_json_and_nothing_else(): void
    {
        // The JSON mode exists so a monitor can consume it. Any stray line
        // breaks that contract silently.
        // Artisan::call, not $this->artisan(...)->run() — only the former routes
        // output into a buffer Artisan::output() can read.
        \Illuminate\Support\Facades\Artisan::call('engineering:brief', ['--no-store' => true, '--json' => true]);

        $output = \Illuminate\Support\Facades\Artisan::output();
        $decoded = json_decode(trim($output), true);

        $this->assertIsArray($decoded, 'json mode must emit parseable JSON only');
        $this->assertArrayHasKey('signals', $decoded);
        $this->assertArrayHasKey('recommendations', $decoded);
        $this->assertArrayHasKey('worst', $decoded);
    }

    /** @return array<string,mixed>|null */
    private function rule(array $recommendations, string $id): ?array
    {
        foreach ($recommendations as $r) {
            if ($r['id'] === $id) { return $r; }
        }

        return null;
    }
}
