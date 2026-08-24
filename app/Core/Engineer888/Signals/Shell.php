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

    /** @return array{ok:bool, out:string, code:int, err:string} */
    public static function run(string $binary, array $args = [], ?string $cwd = null, int $timeoutSeconds = 15): array
    {
        // GIT AND REPOSITORY OWNERSHIP.
        //
        // The web user does not own the top level of the working tree, and
        // modern git refuses to operate in a tree whose owner it does not
        // recognise. Everything an engineer runs by hand is fine — that is
        // root — so the refusal only appeared when the workflow moved onto the
        // queue, which runs as www-data, and every git-backed stage failed at
        // once with "git status failed".
        //
        // Declared per invocation rather than in a user or system gitconfig:
        // this grants no new access (the web user already owns .git and runs
        // the whole application), leaves no state behind on the box, and is
        // scoped to the one repository Engineer888 was asked about.
        if ($binary === 'git' && $cwd !== null) {
            array_unshift($args, '-c', 'safe.directory=' . $cwd);
        }

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
        $prefix = $cwd === null ? '' : 'cd ' . escapeshellarg($cwd) . ' && ';
        $base = $prefix . self::ENV_STRIP . ' timeout ' . (int) $timeoutSeconds . ' ' . $cmd;

        $out = [];
        $code = 0;
        @exec($base . ' 2>/dev/null', $out, $code);

        if ($code === 0) {
            return ['ok' => true, 'out' => implode("\n", $out), 'code' => 0, 'err' => ''];
        }

        // ONLY ON FAILURE, ask again with stderr attached.
        //
        // stdout stays clean on the success path because callers parse it —
        // git porcelain in particular — and a warning merged into that output
        // would be read as a changed file. But a failure that says only "git
        // status failed" costs an engineer a round trip to learn anything, which
        // it did on 2026-08-02. Re-running a command that has already failed is
        // cheap; guessing is not.
        $errOut = [];
        $errCode = 0;
        @exec($base . ' 2>&1', $errOut, $errCode);

        return [
            'ok'   => false,
            'out'  => implode("\n", $out),
            'code' => $code,
            'err'  => trim(implode("\n", array_slice($errOut, 0, 8))),
        ];
    }
}
