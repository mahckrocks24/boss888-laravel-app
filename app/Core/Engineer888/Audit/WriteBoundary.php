<?php

namespace App\Core\Engineer888\Audit;

/**
 * Proof, not a promise, that reasoning cannot write.
 *
 * "The reasoning engine must never modify files directly" is easy to write in a
 * comment and easy to violate six months later when somebody adds a cache. So
 * it is checked mechanically instead: every file in the audited directory is
 * tokenised and any call that could reach the filesystem, a shell, or the
 * installer is a violation.
 *
 * Tokenised rather than grepped, for the reason established in Sprint 2 — a
 * regex flags the word `unlink` inside this very docblock, and a detector that
 * reports itself teaches everyone to ignore it.
 *
 * IT LIVES OUTSIDE THE NAMESPACE IT AUDITS, and that is not tidiness. Its own
 * self-test writes fixture files, so if it sat in Reasoning\ it would flag
 * itself on every run — the same self-flagging defect Sprint 2 hit three times.
 * An auditor inside the audited zone is an auditor that must be excused, and an
 * excused rule is not a rule.
 */
final class WriteBoundary
{
    /** Functions that write to, or delete from, the filesystem. */
    public const FILESYSTEM_WRITES = [
        'file_put_contents', 'fwrite', 'fputs', 'unlink', 'rename', 'copy', 'mkdir',
        'rmdir', 'touch', 'chmod', 'chown', 'symlink', 'link', 'tempnam', 'move_uploaded_file',
        'ftruncate', 'file_put_contents_atomic',
    ];

    /** Anything that starts a process. */
    public const PROCESS_CALLS = [
        'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec',
    ];

    /** Opening a stream for writing is a write, whatever the mode string says. */
    public const STREAM_OPENERS = ['fopen'];

    /** Classes whose whole purpose is to change the repository. */
    public const FORBIDDEN_CLASSES = [
        'SafeInstaller', 'CommitExecutor', 'GitGate', 'Shell', 'Process', 'Storage', 'File',
    ];

    public function __construct(private readonly string $directory) {}

    /**
     * @return array<int,array{file:string,line:int,symbol:string,kind:string}>
     */
    public function violations(): array
    {
        $violations = [];

        foreach ($this->phpFiles() as $path) {
            foreach ($this->scan($path) as $violation) {
                $violations[] = $violation;
            }
        }

        return $violations;
    }

    /** @return array<int,string> */
    public function phpFiles(): array
    {
        if (! is_dir($this->directory)) { return []; }

        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile() && $entry->getExtension() === 'php') { $files[] = $entry->getPathname(); }
        }
        sort($files);

        return $files;
    }

    /** @return array<int,array<string,mixed>> */
    private function scan(string $path): array
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $found = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token)) { continue; }

            // Comments and strings are text, not calls. Sprint 2 learned this
            // the hard way, three times.
            if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING,
                                     T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                continue;
            }

            // PHP 8 tokenises `\file_put_contents` as ONE token, not a separator
            // followed by a name. Matching only T_STRING would let every
            // namespaced call through — which the self-test proved on the first
            // run, having been written expecting the PHP 7 tokenisation.
            if (! in_array($token[0], [T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_QUALIFIED], true)) {
                continue;
            }

            $segments = explode('\\', ltrim($token[1], '\\'));
            $name = (string) end($segments);
            $lower = strtolower($name);

            // Only count it if it is actually being called or instantiated.
            if (! $this->isInvocation($tokens, $index)) { continue; }

            $kind = match (true) {
                in_array($lower, self::FILESYSTEM_WRITES, true) => 'filesystem write',
                in_array($lower, self::PROCESS_CALLS, true)     => 'process execution',
                in_array($lower, self::STREAM_OPENERS, true)    => 'stream open',
                in_array($name, self::FORBIDDEN_CLASSES, true)  => 'mutating collaborator',
                default                                          => null,
            };

            if ($kind === null) { continue; }

            $found[] = ['file' => $path, 'line' => $token[2], 'symbol' => $name, 'kind' => $kind];
        }

        return $found;
    }

    /**
     * Followed by `(`, or preceded by `new` — but not a method of that name.
     *
     * The exclusions run FIRST and they matter. `$archive->copy()` is a method
     * on somebody's object, not the global `copy()`, and `function unlink()` is
     * a declaration. Reading either as a filesystem write would produce exactly
     * the kind of finding that trains an engineer to ignore the tool. The
     * self-test caught this on the first live run.
     */
    private function isInvocation(array $tokens, int $index): bool
    {
        for ($i = $index - 1; $i >= 0; $i--) {
            $prev = $tokens[$i];
            if (is_array($prev) && $prev[0] === T_WHITESPACE) { continue; }
            if (is_array($prev) && $prev[0] === T_NEW) { return true; }

            // A method call, a static call on some other class, a declaration,
            // or an attribute — none of them is the global function.
            if (is_array($prev) && in_array($prev[0], [
                T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
                T_FUNCTION, T_ATTRIBUTE,
            ], true)) {
                return false;
            }

            break;
        }

        for ($i = $index + 1, $n = count($tokens); $i < $n; $i++) {
            $next = $tokens[$i];
            if (is_array($next) && $next[0] === T_WHITESPACE) { continue; }
            if ($next === '(') { return true; }
            // `Shell::run(...)` — the class name is what is forbidden here.
            if (is_array($next) && $next[0] === T_DOUBLE_COLON) { return true; }
            break;
        }

        return false;
    }

    /**
     * Self-test: the scanner must find a violation it is shown, and must not
     * invent one. A detector nobody has tested is a detector nobody should trust.
     *
     * @return array<int,array{case:string,passed:bool,detail:string}>
     */
    public static function selfTest(): array
    {
        $cases = [
            ['name' => 'flags file_put_contents',
             'code' => '<?php class A { function f() { file_put_contents("x", "y"); } }', 'expect' => 1],
            ['name' => 'flags exec',
             'code' => '<?php class A { function f() { exec("ls"); } }', 'expect' => 1],
            ['name' => 'flags new SafeInstaller',
             'code' => '<?php class A { function f() { $i = new SafeInstaller("/x"); } }', 'expect' => 1],
            ['name' => 'flags a static call to a mutating collaborator',
             'code' => '<?php class A { function f() { Shell::run("git"); } }', 'expect' => 1],
            ['name' => 'ignores the word unlink inside a comment',
             'code' => "<?php\n// never call unlink() here\nclass A {}", 'expect' => 0],
            ['name' => 'ignores the word exec inside a string',
             'code' => '<?php class A { function f() { return "do not exec(this)"; } }', 'expect' => 0],
            ['name' => 'allows reading',
             'code' => '<?php class A { function f() { return file_get_contents("x") . is_file("y"); } }', 'expect' => 0],
            ['name' => 'allows a method merely named copy',
             'code' => '<?php class A { function f($o) { return $o->copy(); } }', 'expect' => 0],
            ['name' => 'allows a nullsafe method named unlink',
             'code' => '<?php class A { function f($o) { return $o?->unlink(); } }', 'expect' => 0],
            ['name' => 'allows a static call named mkdir on some other class',
             'code' => '<?php class A { function f() { return Paths::mkdir("x"); } }', 'expect' => 0],
            ['name' => 'allows declaring a method named touch',
             'code' => '<?php class A { public function touch() { return 1; } }', 'expect' => 0],
            ['name' => 'still flags a namespaced global write',
             'code' => '<?php class A { function f() { \\file_put_contents("x", "y"); } }', 'expect' => 1],
        ];

        $dir = sys_get_temp_dir() . '/e888-writeboundary-' . getmypid();
        $results = [];

        // The self-test needs a scratch directory, which is why this method is
        // static and separate: the boundary check itself must stay write-free.
        @mkdir($dir, 0700, true);

        foreach ($cases as $i => $case) {
            $file = $dir . '/Case' . $i . '.php';
            @file_put_contents($file, $case['code']);
            $count = count((new self($dir))->scan($file));
            @unlink($file);

            $results[] = [
                'case'   => $case['name'],
                'passed' => $count === $case['expect'],
                'detail' => "expected {$case['expect']}, found {$count}",
            ];
        }

        @rmdir($dir);

        return $results;
    }
}
