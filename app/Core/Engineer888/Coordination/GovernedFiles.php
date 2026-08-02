<?php

namespace App\Core\Engineer888\Coordination;

/**
 * Files whose blast radius crosses engineers.
 *
 * The smallest mechanism that is actually enforceable: a list in config, a
 * lookup, and a refusal at the point where it matters — staging. There is no
 * approval workflow, no lock service and no queue. An engineer who must edit a
 * governed file declares it in their sprint manifest with a reason; the commit
 * executor refuses to stage it otherwise.
 *
 * tests/TestCase.php earns the classification the hard way: it is the base class
 * for every test in the platform, and on 2026-07-30 a change to it altered the
 * behaviour of all 126 RefreshDatabase suites without any other engineer being
 * told.
 */
final class GovernedFiles
{
    /** @return array<string,array<string,mixed>> path => policy */
    public static function all(): array
    {
        $configured = function_exists('config') ? config('governed_files.files') : null;

        return is_array($configured) ? $configured : [];
    }

    /** @return array<string,mixed>|null */
    public static function policyFor(string $path): ?array
    {
        $path = ltrim(str_replace('\\', '/', $path), '/');

        return self::all()[$path] ?? null;
    }

    public static function isGoverned(string $path): bool
    {
        return self::policyFor($path) !== null;
    }

    /**
     * The checks an engineer must have performed. Returned as text so the same
     * wording appears in the command, the refusal message and the documentation
     * rather than drifting between them.
     *
     * @return array<int,string>
     */
    public static function procedure(): array
    {
        return [
            'inspect the file\'s modification time and current git diff',
            'inspect recent commits touching it',
            'confirm no other engineer is mid-run (processes AND database connections)',
            'state why the shared edit is unavoidable',
            'use an anchor that appears exactly once',
            'back the file up before writing',
            'apply the smallest patch that works',
            'verify syntax, and verify surrounding content is unchanged',
            'declare it in the sprint manifest under shared_files, with the reason',
            'report the modification explicitly in the sprint report',
        ];
    }
}
