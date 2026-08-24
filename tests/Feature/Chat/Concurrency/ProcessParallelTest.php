<?php

namespace Tests\Feature\Chat\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * P2-C — GENUINE process-parallel certification.
 *
 * P2-B's concurrency evidence was interleaved: one PHP process calling the
 * coordinator repeatedly. That exercises the logic but not the race. These
 * tests spawn INDEPENDENT OS PROCESSES that spin to a shared timestamp barrier
 * and then hit the database as close to simultaneously as the kernel allows.
 *
 * Deliberately NOT using RefreshDatabase: its transaction would be invisible to
 * child processes. Fixtures are committed, and torn down explicitly.
 */
class ProcessParallelTest extends TestCase
{
    private const WS = 987654;
    private string $worker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->worker = base_path('tests/Feature/Chat/Concurrency/bin/parallel_worker.php');

        if (!Schema::hasTable('p2c_provider_calls')) {
            Schema::create('p2c_provider_calls', function ($t) {
                $t->id();
                $t->unsignedBigInteger('pid');
                $t->timestamp('created_at')->nullable();
            });
        }
        $this->reset();
    }

    protected function tearDown(): void
    {
        $this->reset();
        parent::tearDown();
    }

    private function reset(): void
    {
        DB::table('p2c_provider_calls')->truncate();
        DB::table('chat_idempotency_records')->where('workspace_id', self::WS)->delete();
        DB::table('message_charges')->where('workspace_id', self::WS)->delete();
        DB::table('agent_messages')->where('workspace_id', self::WS)->delete();
        DB::table('credit_transactions')->where('workspace_id', self::WS)->delete();
        DB::table('credits')->where('workspace_id', self::WS)->delete();

        // credits.workspace_id carries a real FK, so the parent rows must exist
        // and must be COMMITTED — child processes cannot see a transaction.
        $userId = DB::table('users')->where('email', 'p2c-parallel@boss888.test')->value('id');
        if (!$userId) {
            $userId = DB::table('users')->insertGetId([
                'name' => 'P2C Parallel', 'email' => 'p2c-parallel@boss888.test',
                'password' => bcrypt('password'), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        if (!DB::table('workspaces')->where('id', self::WS)->exists()) {
            DB::table('workspaces')->insert([
                'id' => self::WS, 'name' => 'P2C Parallel WS', 'slug' => 'p2c-parallel-ws',
                'created_by' => $userId, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        DB::table('credits')->insert([
            'workspace_id' => self::WS, 'balance' => 100, 'reserved_balance' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Launch $n independent processes that all fire at the same instant.
     *
     * @return array<int,array> decoded worker results
     */
    private function race(int $n, string $key, string $content = 'parallel probe', string $mode = 'ok'): array
    {
        $barrier = microtime(true) + 1.2;    // enough for every process to boot Laravel
        $procs = $pipes = [];

        for ($i = 0; $i < $n; $i++) {
            $cmd = sprintf('php %s %d %s %s %s %.6f',
                escapeshellarg($this->worker), self::WS,
                escapeshellarg($key), escapeshellarg($content),
                escapeshellarg($mode), $barrier);
            $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
            $this->assertIsResource($p, 'failed to spawn worker process');
            $procs[$i] = $p;
        }

        $out = [];
        foreach ($procs as $i => $p) {
            $stdout = stream_get_contents($pipes[$i][1]);
            $stderr = stream_get_contents($pipes[$i][2]);
            fclose($pipes[$i][1]);
            fclose($pipes[$i][2]);
            proc_close($p);
            $line = trim((string) strrchr("\n" . trim($stdout), "\n"));
            $decoded = json_decode($line, true);
            $this->assertIsArray($decoded,
                "worker {$i} produced no JSON. stdout=" . substr($stdout, 0, 400) . ' stderr=' . substr($stderr, 0, 400));
            $out[] = $decoded;
        }

        return $out;
    }

    private function counts(): array
    {
        return [
            'provider'  => DB::table('p2c_provider_calls')->count(),
            'user_msgs' => DB::table('agent_messages')->where('workspace_id', self::WS)->where('role', 'user')->count(),
            'final_msgs'=> DB::table('agent_messages')->where('workspace_id', self::WS)->where('role', 'agent')->count(),
            'records'   => DB::table('chat_idempotency_records')->where('workspace_id', self::WS)->count(),
            'reserve'   => DB::table('credit_transactions')->where('workspace_id', self::WS)->where('type', 'reserve')->count(),
            'commit'    => DB::table('credit_transactions')->where('workspace_id', self::WS)->where('type', 'commit')->count(),
            'release'   => DB::table('credit_transactions')->where('workspace_id', self::WS)->where('type', 'release')->count(),
            'committed' => DB::table('message_charges')->where('workspace_id', self::WS)->where('status', 'committed')->count(),
            'balance'   => (float) DB::table('credits')->where('workspace_id', self::WS)->value('balance'),
        ];
    }

    private function assertExactlyOnce(array $c, string $ctx): void
    {
        $this->assertSame(1, $c['provider'],   "{$ctx}: expected ONE provider execution, got {$c['provider']}");
        $this->assertSame(1, $c['user_msgs'],  "{$ctx}: expected ONE user message, got {$c['user_msgs']}");
        $this->assertSame(1, $c['final_msgs'], "{$ctx}: expected ONE assistant message, got {$c['final_msgs']}");
        $this->assertSame(1, $c['records'],    "{$ctx}: expected ONE idempotency record, got {$c['records']}");
        $this->assertSame(1, $c['committed'],  "{$ctx}: expected AT MOST ONE committed charge, got {$c['committed']}");
        $this->assertSame(1, $c['commit'],     "{$ctx}: expected ONE ledger commit, got {$c['commit']}");
        $this->assertSame(99.0, $c['balance'], "{$ctx}: balance must fall by exactly 1, got {$c['balance']}");
    }

    /** @test 2 genuinely simultaneous identical requests. */
    public function two_simultaneous_processes_produce_one_of_everything(): void
    {
        $r = $this->race(2, 'par_2');

        $this->assertExactlyOnce($this->counts(), '2 processes');
        $cids = array_unique(array_filter(array_column($r, 'correlation_id')));
        $this->assertCount(1, $cids, 'all processes must converge on one correlation id');
    }

    /** @test 5 genuinely simultaneous identical requests. */
    public function five_simultaneous_processes_produce_one_of_everything(): void
    {
        $r = $this->race(5, 'par_5');

        $this->assertExactlyOnce($this->counts(), '5 processes');
        $this->assertCount(5, $r);
        $winners = array_filter($r, fn ($x) => ($x['ok'] ?? false) && !($x['replay'] ?? false));
        $this->assertLessThanOrEqual(1, count($winners), 'at most one process may be the executor');
    }

    /** @test 10 genuinely simultaneous identical requests. */
    public function ten_simultaneous_processes_produce_one_of_everything(): void
    {
        $r = $this->race(10, 'par_10');

        $this->assertExactlyOnce($this->counts(), '10 processes');
        $this->assertCount(10, $r);
        foreach ($r as $x) {
            $this->assertNotSame('EXCEPTION', $x['error_code'] ?? null,
                'no process may die with an unhandled exception: ' . ($x['exception'] ?? ''));
        }
    }

    /** @test Same key with conflicting payloads under contention. */
    public function conflicting_payloads_under_contention_do_not_both_execute(): void
    {
        // First establish the key, then race a different payload against it.
        $this->race(1, 'par_conflict', 'the original question');
        $before = $this->counts()['provider'];

        $r = $this->race(3, 'par_conflict', 'a completely different question');

        $this->assertSame($before, $this->counts()['provider'],
            'a conflicting payload must never execute the provider');
        $conflicts = array_filter($r, fn ($x) => ($x['error_code'] ?? '') === 'CHAT_CONVERSATION_CONFLICT');
        $this->assertCount(3, $conflicts, 'every conflicting request must receive the canonical conflict');
    }

    /** @test Two workspaces using the same key are independent under contention. */
    public function the_same_key_in_different_workspaces_does_not_interfere(): void
    {
        $this->race(3, 'par_ws');
        $c = $this->counts();

        $this->assertSame(1, $c['provider'], 'workspace ' . self::WS . ' must execute once');
        $this->assertSame(1, DB::table('chat_idempotency_records')
            ->where('workspace_id', self::WS)->where('idempotency_key', 'par_ws')->count());
        // A record for the same key in another workspace must be untouched.
        DB::table('chat_idempotency_records')->insert([
            'workspace_id' => 111222, 'surface' => 's8_public_chatbot', 'idempotency_key' => 'par_ws',
            'request_fingerprint' => 'other', 'correlation_id' => 'cor_other', 'status' => 'completed',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->assertSame(2, DB::table('chat_idempotency_records')->where('idempotency_key', 'par_ws')->count(),
            'the same key must coexist across workspaces');
        DB::table('chat_idempotency_records')->where('workspace_id', 111222)->delete();
    }

    /** @test Simultaneous retries WHILE the winner is still processing. */
    public function simultaneous_retries_during_processing_do_not_double_execute(): void
    {
        // 'slow' holds `processing` for 400ms so competitors genuinely observe
        // an in-flight record rather than a completed one.
        $r = $this->race(5, 'par_inflight', 'parallel probe', 'slow');

        $c = $this->counts();
        $this->assertSame(1, $c['provider'], 'an in-flight request must not be executed twice');
        $this->assertSame(1, $c['user_msgs']);
        $this->assertSame(1, $c['final_msgs']);
        $this->assertSame(1, $c['committed']);

        $inflight = array_filter($r, fn ($x) => ($x['error_code'] ?? '') === 'CHAT_CONVERSATION_CONFLICT');
        $this->assertGreaterThanOrEqual(1, count($inflight),
            'at least one competitor should have been told the request is already in progress');
    }

    /** @test Simultaneous retries AFTER completion all replay, none re-charge. */
    public function simultaneous_retries_after_completion_all_replay(): void
    {
        $this->race(1, 'par_after');
        $before = $this->counts();

        $r = $this->race(5, 'par_after');
        $after = $this->counts();

        $this->assertSame($before['provider'], $after['provider'], 'no provider call after completion');
        $this->assertSame($before['commit'], $after['commit'], 'no second commit');
        $this->assertSame($before['balance'], $after['balance'], 'a retry after completion must not charge');
        $replays = array_filter($r, fn ($x) => $x['replay'] ?? false);
        $this->assertCount(5, $replays, 'all five retries must be replays');
    }

    /** @test Provider failure under contention releases exactly once and never commits. */
    public function provider_failure_under_contention_releases_once(): void
    {
        $this->race(5, 'par_fail', 'parallel probe', 'fail');

        $c = $this->counts();
        $this->assertSame(1, $c['provider'], 'only one process may reach the provider');
        $this->assertSame(0, $c['commit'], 'a failed execution must never commit');
        $this->assertSame(1, $c['release'], 'the reservation must be released exactly once');
        $this->assertSame(0, $c['final_msgs'], 'no assistant message for a failed generation');
        $this->assertSame(100.0, $c['balance'], 'a failed generation must cost nothing');
    }

    /** @test A killed worker's record is recovered and re-executed exactly once. */
    public function a_stale_record_is_recovered_under_competing_workers(): void
    {
        // Simulate a process that died mid-flight, holding `processing`.
        DB::table('chat_idempotency_records')->insert([
            'workspace_id' => self::WS, 'surface' => 's8_public_chatbot',
            'idempotency_key' => 'par_stale', 'request_fingerprint' => hash('sha256', 'x'),
            'correlation_id' => 'cor_stale', 'status' => 'processing',
            'started_at' => now()->subSeconds(600),
            'created_at' => now()->subSeconds(600), 'updated_at' => now()->subSeconds(600),
        ]);

        $this->race(3, 'par_stale');

        $c = $this->counts();
        $this->assertLessThanOrEqual(1, $c['provider'],
            'competing workers must not all re-execute a stale record');
        $this->assertSame(1, DB::table('chat_idempotency_records')
            ->where('workspace_id', self::WS)->where('idempotency_key', 'par_stale')->count(),
            'recovery must reuse the record, not create a second');
    }

    /** @test No deadlocks and no orphan state across every scenario above. */
    public function no_deadlock_and_no_orphan_state(): void
    {
        $this->race(10, 'par_orphan');

        $orphans = DB::table('message_charges')
            ->where('workspace_id', self::WS)
            ->whereIn('status', ['pending', 'reserved', 'executing'])->count();
        $this->assertSame(0, $orphans, 'no charge may be left in a non-terminal state');

        $stuck = DB::table('chat_idempotency_records')
            ->where('workspace_id', self::WS)
            ->whereIn('status', ['acquired', 'processing'])->count();
        $this->assertSame(0, $stuck, 'no idempotency record may be left mid-flight');

        // Lock-wait/deadlock would surface as an EXCEPTION result from a worker.
        $this->assertSame(0, DB::table('credit_transactions')
            ->where('workspace_id', self::WS)->where('type', 'reserve')->count()
            - DB::table('credit_transactions')->where('workspace_id', self::WS)
                ->whereIn('type', ['commit', 'release'])->count(),
            'every reservation must have a matching commit or release');
    }
}
