<?php

namespace Tests\Feature\Chat\Support;

use Illuminate\Foundation\Testing\TestCase;

/**
 * Everything a surface adapter needs to drive a real request inside the test
 * environment. Carries the PHPUnit test case so adapters can use Laravel's HTTP
 * testing client, plus the fixture identifiers.
 */
class ChatTestContext
{
    public function __construct(
        public TestCase $test,
        public int $workspaceId,
        public int $userId,
        public string $accessToken,
        public string $sourceRoot,
    ) {}

    public function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Accept'        => 'application/json',
        ];
    }

    /** Read a production source file as text. Returns null when absent. */
    public function source(string $relativePath): ?string
    {
        $path = rtrim($this->sourceRoot, '/') . '/' . ltrim($relativePath, '/');

        return is_file($path) ? (string) file_get_contents($path) : null;
    }

    /** Concatenated text of every source file a surface declares. */
    public function sourceOf(ChatSurface $surface): string
    {
        $buf = '';
        foreach ($surface->sourceFiles() as $f) {
            $buf .= "\n/* ==== {$f} ==== */\n" . ($this->source($f) ?? '');
        }

        return $buf;
    }

    /**
     * Clear rate-limiter state so the harness measures the contract rather than
     * the throttle. The test environment uses the array cache driver, so this
     * touches nothing outside the current process.
     */
    public function relaxRateLimits(): void
    {
        try {
            \Illuminate\Support\Facades\Cache::flush();
        } catch (\Throwable $e) {
            // A cache that cannot be flushed is not fatal; affected cases will
            // record `blocked` rather than a misleading pass.
        }
    }

    /**
     * Force the platform into a genuine insufficient-credit state.
     *
     * A zero balance alone is NOT enough: meterChat() batches, charging one
     * credit on every tenth chat and returning sufficient=true for the nine in
     * between. Only when the counter is about to roll over does the balance
     * matter. Draining the balance without advancing the counter therefore
     * measures nothing — the surface answers normally and the case silently
     * proves the opposite of what it claims.
     *
     * @return callable restore function
     */
    public function induceCreditRefusal(): callable
    {
        $balance = \Illuminate\Support\Facades\DB::table('credits')
            ->where('workspace_id', $this->workspaceId)->value('balance');
        $meter = \Illuminate\Support\Facades\DB::table('workspaces')
            ->where('id', $this->workspaceId)->value('chat_meter');

        \Illuminate\Support\Facades\DB::table('credits')
            ->where('workspace_id', $this->workspaceId)->update(['balance' => 0]);
        \Illuminate\Support\Facades\DB::table('workspaces')
            ->where('id', $this->workspaceId)->update(['chat_meter' => 9]);

        return function () use ($balance, $meter) {
            \Illuminate\Support\Facades\DB::table('credits')
                ->where('workspace_id', $this->workspaceId)->update(['balance' => $balance]);
            \Illuminate\Support\Facades\DB::table('workspaces')
                ->where('id', $this->workspaceId)->update(['chat_meter' => $meter]);
        };
    }

    /**
     * Insert a fixture row using only the columns the table actually has.
     * The five message stores share no schema, so a fixed column list fails on
     * most of them.
     */
    public function insertFixture(string $table, array $wanted): bool
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable($table)) {
            return false;
        }
        $cols = \Illuminate\Support\Facades\Schema::getColumnListing($table);
        $row = array_intersect_key($wanted, array_flip($cols));
        if ($row === []) {
            return false;
        }
        try {
            \Illuminate\Support\Facades\DB::table($table)->insert($row);

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Locate 1-indexed line numbers matching a regex within a source file. */
    public function grep(string $relativePath, string $regex): array
    {
        $text = $this->source($relativePath);
        if ($text === null) {
            return [];
        }
        $hits = [];
        foreach (explode("\n", $text) as $i => $line) {
            if (preg_match($regex, $line)) {
                $hits[] = ['line' => $i + 1, 'text' => trim($line)];
            }
        }

        return $hits;
    }
}
