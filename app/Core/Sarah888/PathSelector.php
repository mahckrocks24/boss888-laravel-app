<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH888 — WHICH SARAH ANSWERS THIS WORKSPACE.
 *
 * NOT WIRED. Nothing calls this yet, and `routes/api/authenticated/agents-01.php` is
 * unmodified. It exists so that when Boss authorises a controlled cutover, the change is a
 * single reviewed line in the route rather than a design decision made under pressure —
 * and so the ROLLBACK path can be exercised and tested before anything is ever enabled.
 *
 * A flag whose off-path has never been run is not a rollback plan.
 *
 * THREE RULES, IN THIS ORDER:
 *
 *   1. THE KILL SWITCH WINS.  `SARAH_RUNTIME_NATIVE_KILL=true` forces legacy for every
 *      workspace regardless of any per-workspace opt-in. This is the rollback primitive:
 *      one value, no deploy, no per-workspace edits, no reasoning about who was enrolled.
 *      It is checked FIRST so that it cannot be defeated by anything below it.
 *
 *   2. OPT-IN IS EXPLICIT AND PER WORKSPACE.  A workspace answers on the Runtime-native
 *      path only if its own `settings_json` says so. There is no "enable for everyone"
 *      value, deliberately: the blast radius of a mistake is then one workspace.
 *
 *   3. ANYTHING UNEXPECTED MEANS LEGACY.  Missing workspace, malformed JSON, database
 *      error, unreadable flag — every one of them returns legacy. The failure mode of this
 *      class must be "the owner gets the Sarah they had yesterday", never "the owner gets
 *      an experimental path because a query threw".
 *
 * Uses `workspaces.settings_json` because that is already how Experience888 enrollment
 * works here — same mechanism, flippable without a deploy, and visible in one place.
 */
final class PathSelector
{
    /** The per-workspace opt-in key inside `workspaces.settings_json`. */
    public const FLAG = 'sarah_runtime_native';

    /** Env kill switch. When true, NOTHING may use the Runtime-native path. */
    public const KILL_ENV = 'SARAH_RUNTIME_NATIVE_KILL';

    public const PATH_LEGACY = 'legacy';
    public const PATH_RUNTIME_NATIVE = 'runtime_native';

    /**
     * @return array{path:string, reason:string}
     *         `reason` is for the turn log — when a cutover misbehaves, the first question
     *         is always "was this workspace even on the new path?", and it should be
     *         answerable from the log rather than by re-deriving the flag state.
     */
    public function decide(int $wsId): array
    {
        $legacy = fn (string $why) => ['path' => self::PATH_LEGACY, 'reason' => $why];

        if ($this->killed()) {
            return $legacy('kill switch is on — Runtime-native disabled platform-wide');
        }
        if ($wsId <= 0) {
            return $legacy('no workspace resolved');
        }

        try {
            $raw = DB::table('workspaces')->where('id', $wsId)->value('settings_json');
        } catch (\Throwable $e) {
            Log::warning('[Sarah888] PathSelector could not read settings; using legacy', [
                'ws' => $wsId, 'error' => $e->getMessage(),
            ]);
            return $legacy('settings unreadable');
        }

        if ($raw === null) {
            return $legacy('workspace not found');
        }

        $settings = json_decode((string) $raw, true);
        if (!is_array($settings)) {
            return $legacy('settings_json is not valid JSON');
        }

        $flag = $settings[self::FLAG] ?? null;
        // Experience888 stores its settings as a JSON STRING inside settings_json, so the
        // same shape is tolerated here rather than assuming one encoding.
        if (is_string($flag)) {
            $decoded = json_decode($flag, true);
            $flag = is_array($decoded) ? ($decoded['enabled'] ?? null) : $flag;
        }
        if (is_array($flag)) {
            $flag = $flag['enabled'] ?? null;
        }

        if ($flag === true || $flag === 1 || $flag === '1' || $flag === 'true') {
            return ['path' => self::PATH_RUNTIME_NATIVE,
                    'reason' => 'workspace opted in'];
        }

        return $legacy('workspace has not opted in');
    }

    /** Convenience for a call site that only needs the boolean. */
    public function useRuntimeNative(int $wsId): bool
    {
        return $this->decide($wsId)['path'] === self::PATH_RUNTIME_NATIVE;
    }

    /**
     * Is the platform-wide kill switch engaged?
     *
     * Read from env directly rather than through a cached config key: this value must take
     * effect without `config:cache` being rebuilt, because the moment it is needed is the
     * moment something is going wrong.
     */
    public function killed(): bool
    {
        // PRECEDENCE: process environment first, then .env.
        //
        // Not cosmetic. Once SARAH_RUNTIME_NATIVE_KILL exists in .env, Laravel's env()
        // returns the file's value and `putenv()` can no longer override it — which
        // silently disarmed the switch for anything setting it at the process level, and
        // was caught 2026-08-14 by the rollback suite failing AFTER the value was first
        // written to .env. The mechanism it is disarmed for matters: a container env var,
        // a systemd override, or an operator exporting it in a shell during an incident.
        //
        // Reading getenv() first means the fastest thing to reach for in an emergency also
        // wins. `env()` remains the documented no-deploy path and still works.
        $raw = getenv(self::KILL_ENV);
        if ($raw === false || $raw === '') {
            $v = env(self::KILL_ENV);
            if (is_bool($v)) return $v;
            $raw = (string) $v;
        }
        return in_array(strtolower(trim((string) $raw)), ['1', 'true', 'yes', 'on'], true);
    }

    /** Every workspace currently on the Runtime-native path. For audit and for rollback. */
    public function enrolled(): array
    {
        if ($this->killed()) return [];
        $out = [];
        try {
            foreach (DB::table('workspaces')->select('id')->get() as $w) {
                if ($this->useRuntimeNative((int) $w->id)) $out[] = (int) $w->id;
            }
        } catch (\Throwable $e) {
            return [];
        }
        return $out;
    }
}
