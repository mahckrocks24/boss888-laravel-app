<?php

namespace App\Core\Engineer888\Execution;

use App\Core\Engineer888\Signals\Shell;

/**
 * The only place Engineer888 is allowed to run git.
 *
 * Every git invocation in the execution engine goes through here, and anything
 * not on the allowlist is refused before it reaches a shell. This is not
 * defence against an attacker — it is defence against ME. The safety rules for
 * this sprint are absolute (never force, never push, never rewrite history,
 * never delete branches), and a rule that lives only in a comment is a rule that
 * survives exactly until someone adds a convenient line of code.
 *
 * A remote IS configured on this repository, so `push` is a real capability
 * being withheld rather than a theoretical one.
 *
 * `restore` is permitted only with --staged and explicit paths, because that
 * unstages. `restore --worktree` would DISCARD a working-tree edit — possibly a
 * concurrent session's — and is refused.
 */
final class GitGate
{
    /** Subcommands that may run. Everything else is refused. */
    private const ALLOWED = [
        'status', 'add', 'commit', 'diff', 'log', 'rev-parse', 'ls-files',
        'symbolic-ref', 'show', 'cat-file', 'rev-list', 'config',
        'restore', 'check-ignore',
    ];

    /**
     * Refused wherever they appear. These are the verbs that lose work:
     * history rewriting, remote publication, and discarding the working tree.
     */
    private const FORBIDDEN_TOKENS = [
        '--force', '-f', '--force-with-lease', '--amend', '--hard', '--mixed',
        '--no-verify', '-D', '--delete', '--prune', '--worktree', '--all',
    ];

    public function __construct(private string $repoPath) {}

    /**
     * @param  array<int,string>  $args
     * @return array{ok:bool, out:string, code:int, refused?:string}
     */
    public function run(string $subcommand, array $args = [], int $timeoutSeconds = 120): array
    {
        $refusal = $this->refuse($subcommand, $args);
        if ($refusal !== null) {
            return ['ok' => false, 'out' => '', 'code' => 126, 'refused' => $refusal];
        }

        return Shell::run('git', array_merge([$subcommand], $args), $this->repoPath, $timeoutSeconds);
    }

    /** Why this invocation may not run, or null if it may. */
    public function refuse(string $subcommand, array $args): ?string
    {
        if (! in_array($subcommand, self::ALLOWED, true)) {
            return "git {$subcommand} is not on the Engineer888 allowlist";
        }

        foreach ($args as $arg) {
            // `--all` is harmless on `log` and catastrophic on `add`; only the
            // dangerous pairing is refused.
            if ($arg === '--all' && ! in_array($subcommand, ['add', 'restore', 'commit'], true)) {
                continue;
            }
            // `-f` on check-ignore is not force.
            if ($arg === '-f' && $subcommand === 'check-ignore') {
                continue;
            }
            if (in_array($arg, self::FORBIDDEN_TOKENS, true)) {
                return "the flag {$arg} is never permitted (git {$subcommand})";
            }
        }

        if ($subcommand === 'add') {
            // Staging must always name its files. `git add -A` is how another
            // session's half-finished work ends up in someone else's commit.
            foreach ($args as $arg) {
                if (in_array($arg, ['-A', '--all', '.', '-u', '--update'], true)) {
                    return 'git add must name its files explicitly, never ' . $arg;
                }
            }
        }

        if ($subcommand === 'restore') {
            if (! in_array('--staged', $args, true)) {
                return 'git restore is permitted only with --staged (unstaging), never against the working tree';
            }
        }

        if ($subcommand === 'config') {
            // Read-only. `git config user.name` reads; `git config user.name X`
            // writes, and is detectable as two non-flag arguments.
            $values = array_values(array_filter($args, fn ($a) => ! str_starts_with($a, '-')));
            if (count($values) > 1 || array_intersect($args, ['--unset', '--add', '--replace-all', '--edit']) !== []) {
                return 'git config may only be read, never written';
            }
        }

        return null;
    }

    /** @return array<int,string> for display: what this gate refuses */
    public function policy(): array
    {
        return [
            'allowed subcommands: ' . implode(', ', self::ALLOWED),
            'never: push, reset, rebase, merge, cherry-pick, checkout, branch, tag, stash, clean, filter-branch',
            'never: --force, --amend, --hard, --no-verify, --delete',
            'git add must name every file; -A, -u and . are refused',
            'git restore only with --staged; the working tree is never discarded',
        ];
    }
}
