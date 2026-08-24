<?php

namespace App\Core\Engineer888\Migration;

use App\Core\Engineer888\Coordination\DatabaseAssignment;
use App\Core\Engineer888\Runtime\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Run the migration somewhere it cannot matter, before anybody approves it.
 *
 * up → verify → down → verify → up. If a migration cannot survive that against
 * an empty isolated database, it has no business being approved for one with
 * customers in it.
 *
 * NOTHING HERE TOUCHES PRODUCTION, and the guard is not a convention: the
 * target is checked against the same assignment rules that exist because a test
 * run emptied production on 2026-07-30, and a production-looking name is
 * refused before a single statement runs.
 */
final class MigrationRehearsal
{
    public const PASSED = 'PASSED';

    /**
     * One verdict per way a rehearsal can be wrong.
     *
     * A single FAILED told an engineer that something went wrong somewhere in
     * up/down/up, which is the least useful thing a five-step process can say.
     * BLOCKED_EVIDENCE in particular exists because of this sprint: the steps
     * all passed while the schema comparison was reading an empty result set,
     * and "PASSED from exit codes alone" is exactly the failure that hid it.
     */
    public const FAILED_UP = 'FAILED_UP';
    public const FAILED_DOWN = 'FAILED_DOWN';
    public const FAILED_REAPPLY = 'FAILED_REAPPLY';
    public const BLOCKED_TARGET = 'BLOCKED_TARGET';
    public const BLOCKED_EVIDENCE = 'BLOCKED_EVIDENCE';

    /** Retained so existing callers reading FAILED keep working. */
    public const FAILED = 'FAILED';
    public const REFUSED = 'BLOCKED_TARGET';

    public function __construct(
        private readonly string $repoPath,
        private readonly string $database,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function rehearse(string $migrationPath, string $phpunitConfig): array
    {
        $started = microtime(true);

        // 1. The target must be provably a test database.
        $refusal = $this->refuseUnsafeTarget();
        if ($refusal !== null) {
            return $this->result(self::BLOCKED_TARGET, $migrationPath, [], $refusal, 0);
        }

        $absolute = rtrim($this->repoPath, '/') . '/' . ltrim($migrationPath, '/');
        if (! is_file($absolute)) {
            return $this->result(self::BLOCKED_TARGET, $migrationPath, [],
                ['the migration file does not exist at ' . $migrationPath], 0);
        }

        $workspace = Workspace::open($this->repoPath, 'rehearse-' . substr(md5($migrationPath), 0, 10));
        $steps = [];

        try {
            $credentials = $this->credentialsFrom($phpunitConfig);

            // The target is proven on the connection that will actually be
            // used, not asserted from configuration. Sprint 10 checked the NAME
            // and never asked the server which database it was talking to.
            $proof = $this->proveTarget($credentials);
            if (! $proof['proven']) {
                return $this->result(self::BLOCKED_TARGET, $migrationPath, [], $proof['problems'], 0,
                    [], $proof);
            }

            $before = $this->schemaFingerprint($credentials);

            // An empty fingerprint is not a baseline. It is the absence of one,
            // and it is what let every comparison in Sprint 10 succeed vacuously.
            if ($before === hash('sha256', '')) {
                return $this->result(self::BLOCKED_EVIDENCE, $migrationPath, [],
                    ['the baseline schema fingerprint is empty: the connection can see no tables in '
                     . $this->database, 'no comparison built on it would mean anything'], 0, [], $proof);
            }

            $steps[] = $this->run('up (first)', 'migrate --path=' . escapeshellarg($migrationPath) . ' --force', $credentials);
            $afterUp = $this->schemaFingerprint($credentials);

            if ($afterUp === $before) {
                $steps[] = ['step' => 'schema changed by up', 'passed' => false,
                            'detail' => 'the schema fingerprint is unchanged, so up() did nothing'];
            } else {
                $steps[] = ['step' => 'schema changed by up', 'passed' => true,
                            'detail' => substr($before, 0, 12) . ' → ' . substr($afterUp, 0, 12)];
            }

            $steps[] = $this->run('down', 'migrate:rollback --path=' . escapeshellarg($migrationPath) . ' --force', $credentials);
            $afterDown = $this->schemaFingerprint($credentials);

            $restored = $afterDown === $before;
            $steps[] = ['step' => 'schema restored by down', 'passed' => $restored,
                        'detail' => $restored
                            ? 'the schema returned to its baseline'
                            : 'down() left the schema at ' . substr($afterDown, 0, 12)
                              . ', not the baseline ' . substr($before, 0, 12)];

            $steps[] = $this->run('up (again)', 'migrate --path=' . escapeshellarg($migrationPath) . ' --force', $credentials);
            $afterSecond = $this->schemaFingerprint($credentials);

            $steps[] = ['step' => 're-applied cleanly', 'passed' => $afterSecond === $afterUp,
                        'detail' => $afterSecond === $afterUp
                            ? 'the second up() produced the same schema as the first'
                            : 'the second up() produced a different schema'];

            // The verdict names WHICH relationship failed. Deriving it from the
            // step exit codes alone is what allowed "up() did nothing" to sit
            // next to three passing artisan calls.
            $byStep = [];
            foreach ($steps as $step) { $byStep[$step['step']] = $step['passed']; }

            $verdict = self::PASSED;
            $problems = [];

            if (($byStep['up (first)'] ?? false) === false || ($byStep['schema changed by up'] ?? false) === false) {
                $verdict = self::FAILED_UP;
                $problems[] = 'up() failed, or produced no schema change';
            } elseif (($byStep['down'] ?? false) === false || ($byStep['schema restored by down'] ?? false) === false) {
                $verdict = self::FAILED_DOWN;
                $problems[] = 'down() failed, or did not restore the baseline';
            } elseif (($byStep['up (again)'] ?? false) === false || ($byStep['re-applied cleanly'] ?? false) === false) {
                $verdict = self::FAILED_REAPPLY;
                $problems[] = 'the second up() failed, or produced a different schema from the first';
            }

            // LEAVE THE DATABASE AS WE FOUND IT.
            //
            // The sequence ends with up() applied, so a second rehearsal of the
            // same migration failed with "table already exists" — a fact about
            // the previous run, reported as a fact about the migration. Rolling
            // back restores the baseline the next rehearsal will measure against.
            $this->run('cleanup', 'migrate:rollback --path=' . escapeshellarg($migrationPath) . ' --force', $credentials);
            $steps[] = ['step' => 'isolated database restored', 'passed' =>
                $this->schemaFingerprint($credentials) === $before,
                'detail' => 'the rehearsal leaves no schema behind'];

            return $this->result(
                $verdict,
                $migrationPath,
                $steps,
                $problems,
                (int) round((microtime(true) - $started) * 1000),
                ['baseline' => $before, 'after_up' => $afterUp, 'after_down' => $afterDown,
                 'after_second_up' => $afterSecond],
                $proof
            );
        } catch (\Throwable $e) {
            return $this->result(self::BLOCKED_EVIDENCE, $migrationPath, $steps,
                [get_class($e) . ': ' . $e->getMessage()],
                (int) round((microtime(true) - $started) * 1000));
        } finally {
            $workspace->close();
        }
    }

    /** @return array<int,string>|null */
    private function refuseUnsafeTarget(): ?array
    {
        $reasons = [];

        if (in_array($this->database, DatabaseAssignment::PRODUCTION_NAMES, true)) {
            $reasons[] = "{$this->database} is a production database and will never be a rehearsal target";
        }

        if (in_array($this->database, DatabaseAssignment::SHARED_NAMES, true)) {
            $reasons[] = "{$this->database} is shared with other sessions; a rehearsal must not be able "
                       . 'to break somebody else\'s run';
        }

        if (! str_ends_with($this->database, '_test')) {
            $reasons[] = "{$this->database} is not named as a test database";
        }

        return $reasons === [] ? null : $reasons;
    }

    /**
     * A hash of the schema, so "restored" means something checkable.
     *
     * Column names and types across every table in the target, ordered. Row
     * data is deliberately absent: this proves structural reversal, and saying
     * it proves data reversal would be the claim this sprint refuses to make.
     */
    public function schemaFingerprint(array $credentials = []): string
    {
        return hash('sha256', implode("\n", $this->schemaRows($credentials)));
    }

    /**
     * The schema, as ordered text.
     *
     * Columns with their types, nullability and defaults, plus indexes. Ordered
     * explicitly, because MySQL's natural order is not a promise and a
     * fingerprint that changes when the server feels like it proves nothing.
     *
     * Deliberately excluded: AUTO_INCREMENT counters, table row estimates and
     * timestamps, which move without the schema meaning anything different.
     *
     * @return array<int,string>
     */
    public function schemaRows(array $credentials = []): array
    {
        $connection = $this->connection($credentials);

        $columns = $connection->select(
            'select table_name as t, column_name as c, column_type as ty,
                    is_nullable as n, ifnull(column_default, \'~E888_NULL~\') as d
             from information_schema.columns where table_schema = ?
             order by table_name, column_name',
            [$this->database]
        );

        $indexes = $connection->select(
            'select table_name as t, index_name as i, non_unique as u,
                    group_concat(column_name order by seq_in_index) as cols
             from information_schema.statistics where table_schema = ?
             group by table_name, index_name, non_unique
             order by table_name, index_name',
            [$this->database]
        );

        $parts = [];
        foreach ($columns as $row) {
            $parts[] = 'col:' . implode(':', [$row->t, $row->c, $row->ty, $row->n, (string) $row->d]);
        }
        foreach ($indexes as $row) {
            $parts[] = 'idx:' . implode(':', [$row->t, $row->i, (string) $row->u, (string) $row->cols]);
        }

        sort($parts);

        return $parts;
    }

    /**
     * A connection whose selected database IS the rehearsal target.
     *
     * THE DEFECT THIS CLOSES. Sprint 10 read information_schema through the
     * application's own connection, which is authenticated as the production
     * user and cannot see the isolated database at all. It returned zero rows,
     * so the baseline and every later fingerprint were sha256('') — identical,
     * and the comparison reported "up() changed nothing" while up() had in fact
     * created a table. The steps passed; the evidence was empty.
     */
    private function connection(array $credentials): \Illuminate\Database\Connection
    {
        $name = 'e888_rehearsal';

        config(['database.connections.' . $name => array_merge(
            (array) config('database.connections.mysql', []),
            [
                'database' => $this->database,
                'username' => $credentials['DB_USERNAME'] ?? config('database.connections.mysql.username'),
                'password' => $credentials['DB_PASSWORD'] ?? config('database.connections.mysql.password'),
                'host'     => $credentials['DB_HOST'] ?? config('database.connections.mysql.host'),
                'port'     => $credentials['DB_PORT'] ?? config('database.connections.mysql.port'),
            ]
        )]);

        DB::purge($name);

        return DB::connection($name);
    }

    /**
     * Ask the server which database it is talking to, before anything runs.
     *
     * Configuration is an intention; SELECT DATABASE() is a fact. Sprint 10
     * checked the intention.
     *
     * @return array<string,mixed>
     */
    public function proveTarget(array $credentials = []): array
    {
        $problems = [];
        $selected = null;
        $tables = null;

        try {
            $connection = $this->connection($credentials);
            $selected = $connection->selectOne('select database() as d')->d ?? null;
            $tables = (int) $connection->selectOne(
                'select count(*) as c from information_schema.tables where table_schema = ?',
                [$this->database]
            )->c;
        } catch (\Throwable $e) {
            $problems[] = 'could not connect to ' . $this->database . ': ' . $e->getMessage();
        }

        if ($selected === null) {
            $problems[] = 'the connection reports no selected database';
        } elseif ($selected !== $this->database) {
            $problems[] = "the server reports the selected database as '{$selected}', not '{$this->database}'";
        }

        foreach ([DatabaseAssignment::PRODUCTION_NAMES, DatabaseAssignment::SHARED_NAMES] as $forbidden) {
            if (in_array((string) $selected, $forbidden, true)) {
                $problems[] = "the connection resolved to {$selected}, which is never a rehearsal target";
            }
        }

        if ($tables === 0) {
            $problems[] = $this->database . ' contains no tables visible to this connection, so no '
                        . 'schema comparison built on it could mean anything';
        }

        return [
            'proven'            => $problems === [],
            'configured'        => $this->database,
            'selected_database' => $selected,
            'visible_tables'    => $tables,
            'assigned'          => str_ends_with($this->database, '_test'),
            'problems'          => $problems,
        ];
    }

    /**
     * The database credentials the project's own test config declares.
     *
     * Not .env, which names the production user. Reading them from the
     * phpunit config keeps one source of truth about what a test run may
     * reach, and means a rehearsal cannot connect anywhere that config does
     * not already permit.
     *
     * @return array<string,string>
     */
    private function credentialsFrom(string $phpunitConfig): array
    {
        $path = str_starts_with($phpunitConfig, '/')
            ? $phpunitConfig : rtrim($this->repoPath, '/') . '/' . $phpunitConfig;

        if (! is_file($path)) { return []; }

        $xml = @simplexml_load_file($path);
        if ($xml === false || ! isset($xml->php->env)) { return []; }

        $wanted = ['DB_USERNAME', 'DB_PASSWORD', 'DB_HOST', 'DB_PORT', 'DB_CONNECTION'];
        $credentials = [];

        foreach ($xml->php->env as $env) {
            $name = (string) $env['name'];
            if (in_array($name, $wanted, true)) { $credentials[$name] = (string) $env['value']; }
        }

        return $credentials;
    }

    /** @param array<string,string> $credentials */
    private function run(string $step, string $artisan, array $credentials = []): array
    {
        // The environment is stripped for the same reason it is everywhere else:
        // a child that inherits DB_DATABASE from this process is INC-2026-006.
        $command = 'cd ' . escapeshellarg($this->repoPath)
            . ' && env -u DB_CONNECTION -u DB_HOST -u DB_PORT -u DB_DATABASE -u DB_USERNAME'
            . ' -u DB_PASSWORD -u APP_ENV APP_ENV=testing DB_DATABASE=' . escapeshellarg($this->database);

        foreach ($credentials as $name => $value) {
            if ($name === 'DB_DATABASE') { continue; }   // the target is set above, never overridden
            $command .= ' ' . $name . '=' . escapeshellarg($value);
        }

        $command .= ' php artisan ' . $artisan . ' 2>&1';

        $out = [];
        $code = 0;
        @exec($command, $out, $code);

        return ['step' => $step, 'passed' => $code === 0,
                'detail' => trim(implode(' ', array_slice($out, -4))) ?: 'ok'];
    }

    /** @return array<string,mixed> */
    private function result(string $status, string $path, array $steps, array $problems, int $ms, array $schema = [], array $proof = []): array
    {
        return [
            'status'      => $status,
            'passed'      => $status === self::PASSED,
            'migration'   => $path,
            'database'    => $this->database,
            'target_proof' => $proof,
            'steps'       => $steps,
            'problems'    => $problems,
            'duration_ms' => $ms,
            'schema'      => $schema,
            'at'          => gmdate('Y-m-d\TH:i:s\Z'),
        ];
    }
}
