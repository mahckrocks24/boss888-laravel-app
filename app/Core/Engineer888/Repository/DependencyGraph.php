<?php

namespace App\Core\Engineer888\Repository;

/**
 * Class-level dependency graph for the whole repository.
 *
 * BUILT WITH THE TOKENIZER, NOT REGEX. A regex for "what does this file use"
 * matches inside comments, strings and unrelated identifiers. The forensic work
 * in July produced a rule that passed while its target was live, for exactly
 * that reason. Structural facts come from token_get_all.
 *
 * TWO PASSES, and the second is what makes it precise:
 *   1. Collect every class/interface/trait/enum DECLARED in the repository.
 *   2. Collect every name REFERENCED in each file, then keep only those that
 *      resolve to something declared in pass 1.
 * Pass 2 over-collects deliberately — it takes every qualified name and bare
 * T_STRING — and pass 1's set filters the noise out. Function names, constants
 * and local variables never match a declared class, so they disappear without
 * needing to model PHP's grammar.
 *
 * A full pass over this repository's 1,214 non-vendor PHP files costs ~2s, so
 * nothing is cached: a cache that can go stale would make the graph lie.
 */
final class DependencyGraph
{
    /** @var array<string,string> FQCN => relative path */
    private array $declaredIn = [];

    /** @var array<string,array<int,string>> relative path => FQCNs declared */
    private array $declares = [];

    /** @var array<string,array<string,true>> relative path => FQCNs it references */
    private array $references = [];

    /** @var array<string,array<string,true>> FQCN => paths referencing it */
    private array $referencedBy = [];

    private int $filesScanned = 0;

    private int $buildMs = 0;

    public function __construct(private string $repoPath) {}

    public function build(): self
    {
        $started = microtime(true);
        $paths = $this->phpFiles();

        // Pass 1 — what exists.
        $parsed = [];
        foreach ($paths as $rel) {
            $info = $this->parse($this->repoPath . '/' . $rel);
            if ($info === null) { continue; }
            $parsed[$rel] = $info;
            foreach ($info['declares'] as $fqcn) {
                // First declaration wins; a duplicate is itself reported as a risk.
                if (! isset($this->declaredIn[$fqcn])) { $this->declaredIn[$fqcn] = $rel; }
                $this->declares[$rel][] = $fqcn;
            }
        }

        // Pass 2 — what refers to what, filtered by pass 1.
        foreach ($parsed as $rel => $info) {
            $own = array_flip($this->declares[$rel] ?? []);
            foreach ($info['candidates'] as $candidate) {
                if (! isset($this->declaredIn[$candidate])) { continue; }
                if (isset($own[$candidate])) { continue; }          // self-reference
                $this->references[$rel][$candidate] = true;
                $this->referencedBy[$candidate][$rel] = true;
            }
        }

        $this->filesScanned = count($parsed);
        $this->buildMs = (int) round((microtime(true) - $started) * 1000);

        return $this;
    }

    public function filesScanned(): int { return $this->filesScanned; }

    public function buildMs(): int { return $this->buildMs; }

    /** @return array<string,string> FQCN => path */
    public function declaredIn(): array { return $this->declaredIn; }

    /** @return array<int,string> FQCNs declared by this file */
    public function declaredBy(string $path): array { return $this->declares[$path] ?? []; }

    /**
     * Files this one imports. Returned as paths, because a commit is a set of
     * files, not a set of classes.
     *
     * @return array<int,string>
     */
    public function dependsOn(string $path): array
    {
        $out = [];
        foreach (array_keys($this->references[$path] ?? []) as $fqcn) {
            $target = $this->declaredIn[$fqcn] ?? null;
            if ($target !== null && $target !== $path) { $out[$target] = true; }
        }
        $out = array_keys($out);
        sort($out);

        return $out;
    }

    /** @return array<int,string> files that reference something declared here */
    public function usedBy(string $path): array
    {
        $out = [];
        foreach ($this->declares[$path] ?? [] as $fqcn) {
            foreach (array_keys($this->referencedBy[$fqcn] ?? []) as $referrer) {
                if ($referrer !== $path) { $out[$referrer] = true; }
            }
        }
        $out = array_keys($out);
        sort($out);

        return $out;
    }

    /**
     * How far a change here can reach. Depth-limited: an unbounded closure in a
     * codebase this connected returns "most of the application", which is true
     * and useless.
     *
     * @return array{files:int, depth_reached:int, truncated:bool}
     */
    public function impact(string $path, int $maxDepth = 3, int $cap = 400): array
    {
        $seen = [$path => true];
        $frontier = [$path];
        $depth = 0;
        $truncated = false;

        while ($frontier !== [] && $depth < $maxDepth) {
            $depth++;
            $next = [];
            foreach ($frontier as $current) {
                foreach ($this->usedBy($current) as $referrer) {
                    if (isset($seen[$referrer])) { continue; }
                    $seen[$referrer] = true;
                    $next[] = $referrer;
                    if (count($seen) > $cap) { $truncated = true; break 3; }
                }
            }
            $frontier = $next;
        }

        return ['files' => count($seen) - 1, 'depth_reached' => $depth, 'truncated' => $truncated];
    }

    /**
     * Strongly connected components of size > 1 — genuine circular dependencies.
     * Tarjan, iterative: a recursive walk over 596 app classes can exhaust the
     * stack, and a crash in a diagnostic is not a diagnostic.
     *
     * @return array<int,array<int,string>> each a cycle, as paths
     */
    public function cycles(): array
    {
        $adjacency = [];
        foreach (array_keys($this->references) as $path) {
            $adjacency[$path] = $this->dependsOn($path);
        }

        $index = [];
        $low = [];
        $onStack = [];
        $stack = [];
        $counter = 0;
        $components = [];

        foreach (array_keys($adjacency) as $root) {
            if (isset($index[$root])) { continue; }

            // Explicit work stack: [node, position in its adjacency list]
            $work = [[$root, 0]];
            $index[$root] = $low[$root] = $counter++;
            $stack[] = $root;
            $onStack[$root] = true;

            while ($work !== []) {
                [$node, $pos] = $work[count($work) - 1];
                $neighbours = $adjacency[$node] ?? [];

                if ($pos < count($neighbours)) {
                    $work[count($work) - 1][1] = $pos + 1;
                    $next = $neighbours[$pos];
                    if (! isset($index[$next])) {
                        $index[$next] = $low[$next] = $counter++;
                        $stack[] = $next;
                        $onStack[$next] = true;
                        $work[] = [$next, 0];
                    } elseif (! empty($onStack[$next])) {
                        $low[$node] = min($low[$node], $index[$next]);
                    }
                    continue;
                }

                array_pop($work);
                if ($work !== []) {
                    $parent = $work[count($work) - 1][0];
                    $low[$parent] = min($low[$parent], $low[$node]);
                }

                if ($low[$node] === $index[$node]) {
                    $component = [];
                    do {
                        $popped = array_pop($stack);
                        unset($onStack[$popped]);
                        $component[] = $popped;
                    } while ($popped !== $node && $stack !== []);

                    if (count($component) > 1) {
                        sort($component);
                        $components[] = $component;
                    }
                }
            }
        }

        return $components;
    }

    /**
     * Nothing in the repository references this file's classes.
     *
     * Framework entry points are excluded by the caller — a command with no
     * inbound references is normal, a service with none is dead weight.
     */
    public function isUnreferenced(string $path): bool
    {
        $declared = $this->declares[$path] ?? [];
        if ($declared === []) { return false; }   // no class to be orphaned

        return $this->usedBy($path) === [];
    }

    /** @return array<int,string> relative paths */
    private function phpFiles(): array
    {
        $out = [];
        $skip = ['/vendor/', '/node_modules/', '/storage/', '/.git/', '/public/build/'];

        // Pruned at DESCENT, not after the fact. The earlier version filtered
        // each file once the directory had already been opened, so an
        // unreadable directory threw before its own exclusion was consulted —
        // invisible under root, fatal under the queue user (2026-08-02).
        $directory = new \RecursiveDirectoryIterator($this->repoPath, \FilesystemIterator::SKIP_DOTS);

        $filtered = new \RecursiveCallbackFilterIterator($directory, function ($item) use ($skip) {
            if (! $item->isDir()) { return true; }
            $path = str_replace('\\', '/', $item->getPathname()) . '/';
            foreach ($skip as $fragment) {
                if (str_contains($path, $fragment)) { return false; }
            }

            return $item->isReadable();
        });

        // A directory that becomes unreadable between the check and the descent
        // is skipped rather than thrown. Missing one corner of the graph is a
        // worse graph; an exception is no graph at all.
        $iterator = new \RecursiveIteratorIterator(
            $filtered,
            \RecursiveIteratorIterator::SELF_FIRST,
            \RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        foreach ($iterator as $item) {
            /** @var \SplFileInfo $item */
            if (! $item->isFile()) { continue; }
            $full = str_replace('\\', '/', $item->getPathname());
            if (! str_ends_with($full, '.php')) { continue; }
            // Backup copies are not code. 529 of them exist here and they poison
            // every count, every grep and every static rule that forgets to skip them.
            if (str_contains($full, '.bak')) { continue; }
            foreach ($skip as $fragment) {
                if (str_contains($full, $fragment)) { continue 2; }
            }
            $out[] = ltrim(substr($full, strlen($this->repoPath)), '/');
        }

        sort($out);

        return $out;
    }

    /**
     * @return array{declares:array<int,string>, candidates:array<int,string>}|null
     */
    private function parse(string $full): ?array
    {
        $src = @file_get_contents($full);
        if ($src === false || $src === '') { return null; }
        if (! str_contains($src, '<?php')) { return null; }

        try {
            $tokens = @token_get_all($src);
        } catch (\Throwable) {
            return null;
        }
        if (! is_array($tokens)) { return null; }

        $namespace = '';
        $useMap = [];
        $declares = [];
        $candidates = [];

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (! is_array($token)) { continue; }
            [$id, $text] = $token;

            if ($id === T_NAMESPACE) {
                $namespace = trim($this->readName($tokens, $i + 1), '\\');
                continue;
            }

            if ($id === T_USE) {
                // Only top-level imports; a trait `use` inside a class body is a
                // reference and is picked up as a candidate below anyway.
                $imports = $this->readUseGroup($tokens, $i + 1);
                foreach ($imports as $alias => $fqcn) { $useMap[$alias] = $fqcn; }
                continue;
            }

            if ($id === T_CLASS || $id === T_INTERFACE || $id === T_TRAIT || (defined('T_ENUM') && $id === T_ENUM)) {
                // Skip anonymous classes: `new class extends X`.
                $name = $this->nextIdentifier($tokens, $i + 1);
                if ($name !== null) {
                    $declares[] = $namespace === '' ? $name : $namespace . '\\' . $name;
                }
                continue;
            }

            // Candidate references. Over-collected on purpose — pass 2 filters
            // against the set of declared classes.
            if ($id === T_NAME_FULLY_QUALIFIED) {
                $candidates[] = trim($text, '\\');
            } elseif ($id === T_NAME_QUALIFIED) {
                $candidates[] = $this->resolve($text, $namespace, $useMap);
            } elseif ($id === T_STRING) {
                $candidates[] = $this->resolve($text, $namespace, $useMap);
            }
        }

        return [
            'declares'   => array_values(array_unique($declares)),
            'candidates' => array_values(array_unique(array_filter($candidates))),
        ];
    }

    /** Resolve a written name to an FQCN using the file's imports and namespace. */
    private function resolve(string $name, string $namespace, array $useMap): string
    {
        $name = trim($name, '\\');
        if ($name === '') { return ''; }

        $head = explode('\\', $name)[0];
        if (isset($useMap[$head])) {
            $tail = substr($name, strlen($head));

            return $useMap[$head] . $tail;
        }

        // Both readings are emitted as candidates; only one can match a declared
        // class, so the wrong one costs nothing.
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /** @return array<string,string> alias => FQCN */
    private function readUseGroup(array $tokens, int $i): array
    {
        $buffer = '';
        $count = count($tokens);

        // `function () use ($x)` is a closure binding, not an import.
        for ($peek = $i; $peek < $count; $peek++) {
            $t = $tokens[$peek];
            if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
            if ($t === '(') { return []; }
            break;
        }

        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === ';' || $token === '{') { break; }
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $buffer .= ' '; continue; }
                // `use function` / `use const` are not class imports.
                if ($token[0] === T_FUNCTION || $token[0] === T_CONST) { return []; }
                $buffer .= $token[1];
            } else {
                $buffer .= $token;
            }
        }

        $buffer = trim($buffer);
        if ($buffer === '') { return []; }

        $out = [];
        foreach (explode(',', $buffer) as $clause) {
            $clause = trim($clause);
            if ($clause === '') { continue; }
            if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $clause, $m)) {
                $out[$m[2]] = trim($m[1], '\\');
            } else {
                $fqcn = trim($clause, '\\');
                $parts = explode('\\', $fqcn);
                $out[end($parts)] = $fqcn;
            }
        }

        return $out;
    }

    private function nextIdentifier(array $tokens, int $i): ?string
    {
        $count = count($tokens);
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }
                if ($token[0] === T_STRING) { return $token[1]; }
            }

            return null;
        }

        return null;
    }

    private function readName(array $tokens, int $i): string
    {
        $buffer = '';
        $count = count($tokens);
        for (; $i < $count; $i++) {
            $token = $tokens[$i];
            if ($token === ';' || $token === '{') { break; }
            if (is_array($token)) {
                if ($token[0] === T_WHITESPACE) { continue; }
                $buffer .= $token[1];
            }
        }

        return $buffer;
    }
}
