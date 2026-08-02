<?php

namespace App\Core\Engineer888\Signals;

/**
 * Minimal, safe shell runner shared by the signal collectors.
 *
 * Exists because several signals can only be read by running a process
 * (git, df, supervisorctl). Kept deliberately tiny: no pipes, no shell
 * metacharacters, arguments escaped, output captured, failures returned rather
 * than thrown.
 */
final class Shell
{
    /**
     * Removed from the environment of every child process.
     *
     * `env -u` deletes the variable rather than overriding it, which
     * matters: a child's $_SERVER is populated from the real environment,
     * and $_SERVER beats putenv() and $_ENV in Laravel's resolution order.
     * Overriding is not enough; the variable has to be absent.
     */
    private const ENV_STRIP = 'env -u DB_CONNECTION -u DB_HOST -u DB_PORT -u DB_DATABASE'
        . ' -u DB_USERNAME -u DB_PASSWORD -u APP_ENV -u REDIS_HOST -u REDIS_PORT';

    /** @return array{ok:bool, out:string, code:int} */
    public static function run(string $binary, array $args = [], ?string $cwd = null, int $timeoutSeconds = 15): array
    {
        $cmd = escapeshellcmd($binary);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg((string) $a);
        }

        // timeout(1) bounds a hung process. Without it a stuck git or
        // supervisorctl would hang the whole brief.
        //
        // The environment is stripped unconditionally. This runner is a choke
        // point — every signal collector spawns through it — and on 2026-07-31
        // the safety sweep classified it UNKNOWN because its command is built at
        // runtime and therefore unprovable. Rather than argue that today's
        // callers happen to be harmless, the runner is made incapable of leaking
        // a database target to any child, whatever the command turns out to be.
        // A child that boots Laravel with DB_DATABASE inherited from here is
        // exactly INC-2026-006.
        $wrapped = self::ENV_STRIP . ' timeout ' . (int) $timeoutSeconds . ' ' . $cmd . ' 2>/dev/null';
        if ($cwd !== null) {
            $wrapped = 'cd ' . escapeshellarg($cwd) . ' && ' . $wrapped;
        }

        $out = [];
        $code = 0;
        @exec($wrapped, $out, $code);

        return ['ok' => $code === 0, 'out' => implode("\n", $out), 'code' => $code];
    }
}
