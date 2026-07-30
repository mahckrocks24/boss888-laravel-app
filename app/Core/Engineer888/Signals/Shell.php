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
    /** @return array{ok:bool, out:string, code:int} */
    public static function run(string $binary, array $args = [], ?string $cwd = null, int $timeoutSeconds = 15): array
    {
        $cmd = escapeshellcmd($binary);
        foreach ($args as $a) {
            $cmd .= ' ' . escapeshellarg((string) $a);
        }

        // timeout(1) bounds a hung process. Without it a stuck git or
        // supervisorctl would hang the whole brief.
        $wrapped = 'timeout ' . (int) $timeoutSeconds . ' ' . $cmd . ' 2>/dev/null';
        if ($cwd !== null) {
            $wrapped = 'cd ' . escapeshellarg($cwd) . ' && ' . $wrapped;
        }

        $out = [];
        $code = 0;
        @exec($wrapped, $out, $code);

        return ['ok' => $code === 0, 'out' => implode("\n", $out), 'code' => $code];
    }
}
