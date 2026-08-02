<?php
/**
 * P0.5 — installer integrity rule.
 *
 * Finds repository code that writes SOURCE FILES (php/js/xml/json under app/,
 * routes/, config/, database/, tests/, tools/, bootstrap/) without going through
 * SafeInstaller's provenance check.
 *
 * WHY THIS IS NARROW ON PURPOSE
 * This repository contains 111 file_put_contents calls, and almost all of them
 * are ordinary application data: thumbnails, exports, caches, rendered media.
 * A rule that flagged all of them would be noise, and noise gets muted. Only
 * writes whose DESTINATION is a source path can silently revert code, so only
 * those are in scope.
 *
 * Tokenizer, not grep: a write mentioned in a comment is not a write, and this
 * project has already shipped one static rule that could not tell the
 * difference.
 *
 *   php tools/install-integrity-audit.php --selftest
 *   php tools/install-integrity-audit.php .
 *
 * Exit: 0 clean · 1 unsafe writers found · 2 the tool could not complete.
 */

const WRITE_FUNCTIONS = ['file_put_contents', 'copy', 'rename', 'fwrite', 'fputs'];

/** Destination prefixes that hold source code rather than data. */
const SOURCE_ROOTS = ['app/', 'routes/', 'config/', 'database/', 'tests/', 'tools/', 'bootstrap/'];

const SOURCE_EXTENSIONS = ['.php', '.js', '.jsx', '.xml', '.json', '.blade.php'];

$options = array_slice($argv, 1);
if (in_array('--selftest', $options, true)) { exit(selftest()); }

$root = rtrim($argv[1] ?? '.', '/');
$json = in_array('--json', $options, true);

$findings = [];
foreach (phpFiles($root) as $relative) {
    foreach (auditFile($root . '/' . $relative, $relative) as $finding) { $findings[] = $finding; }
}

usort($findings, fn ($a, $b) => [$a['verdict'] === 'SAFE' ? 1 : 0, $a['file'], $a['line']]
                           <=> [$b['verdict'] === 'SAFE' ? 1 : 0, $b['file'], $b['line']]);

if ($json) {
    echo json_encode(['findings' => $findings], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    report($findings);
}

exit(count(array_filter($findings, fn ($f) => $f['verdict'] === 'UNSAFE')) > 0 ? 1 : 0);

// ── scanning ────────────────────────────────────────────────────────────

function phpFiles(string $root): array
{
    $out = [];
    $skip = ['/vendor/', '/node_modules/', '/.git/', '/storage/', '/_backup/', '/public/build/'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (! $item->isFile()) { continue; }
        $full = str_replace('\\', '/', $item->getPathname());
        if (! str_ends_with($full, '.php')) { continue; }
        if (preg_match('/\.(bak|backup|orig|old)[-.\d]*$|\.before-/i', $full)) { continue; }
        foreach ($skip as $fragment) { if (str_contains($full, $fragment)) { continue 2; } }
        $out[] = ltrim(substr($full, strlen($root)), '/');
    }

    sort($out);

    return $out;
}

function auditFile(string $full, string $relative): array
{
    $source = @file_get_contents($full);
    if ($source === false) { return []; }
    $tokens = @token_get_all($source);
    if (! is_array($tokens)) { return []; }

    // Files that ARE the safe mechanism, or that only exercise it.
    $isInstaller = str_contains($relative, 'Engineer888/Install/');
    $isTest = str_starts_with($relative, 'tests/');
    $isAudit = str_contains($relative, 'install-integrity-audit') || str_contains($relative, 'exec-safety-audit');

    $findings = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token) || $token[0] !== T_STRING) { continue; }
        if (! in_array(strtolower($token[1]), WRITE_FUNCTIONS, true)) { continue; }

        // Must be a call, not a method or a declaration.
        $next = significant($tokens, $i + 1);
        if ($next === null || $tokens[$next] !== '(') { continue; }
        $previous = significantBack($tokens, $i - 1);
        if ($previous !== null && is_array($tokens[$previous])
            && in_array($tokens[$previous][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW], true)) {
            continue;
        }

        $argument = firstArgument($tokens, $next, $count);
        $destination = literalDestination($argument);

        // Only writes we can prove target a source path are in scope.
        if ($destination === null || ! targetsSourceCode($destination)) { continue; }

        if ($isInstaller) {
            $findings[] = finding($relative, $token[2], 'SAFE', $argument,
                'this file IS the provenance mechanism');
            continue;
        }
        if ($isTest || $isAudit) {
            $findings[] = finding($relative, $token[2], 'SAFE', $argument,
                $isTest ? 'test fixture write, not an installer' : 'audit tool selftest fixture');
            continue;
        }

        $findings[] = finding($relative, $token[2], 'UNSAFE', $argument,
            'writes a source-code path without a provenance check, so it can silently replace a file '
            . 'that was fixed after installation');
    }

    return $findings;
}

/** The literal destination, if the first argument begins with one. */
function literalDestination(string $argument): ?string
{
    if (preg_match('/^\s*[\'"]([^\'"]+)/', $argument, $m)) { return $m[1]; }

    // Concatenations like $root . '/app/Core/X.php'
    if (preg_match('/[\'"]([^\'"]*\/(?:app|routes|config|database|tests|tools|bootstrap)\/[^\'"]+)/', $argument, $m)) {
        return $m[1];
    }

    return null;
}

function targetsSourceCode(string $destination): bool
{
    $normalised = ltrim(preg_replace('#^.*?(?=(app|routes|config|database|tests|tools|bootstrap)/)#', '', $destination) ?? $destination, '/');

    $underSourceRoot = false;
    foreach (SOURCE_ROOTS as $rootPath) {
        if (str_starts_with($normalised, $rootPath)) { $underSourceRoot = true; break; }
    }
    if (! $underSourceRoot) { return false; }

    foreach (SOURCE_EXTENSIONS as $extension) {
        if (str_ends_with($normalised, $extension)) { return true; }
    }

    return false;
}

function significant(array $tokens, int $i): ?int
{
    for ($c = count($tokens); $i < $c; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }

        return $i;
    }

    return null;
}

function significantBack(array $tokens, int $i): ?int
{
    for (; $i >= 0; $i--) {
        $t = $tokens[$i];
        if (is_array($t) && in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { continue; }

        return $i;
    }

    return null;
}

function firstArgument(array $tokens, int $open, int $count): string
{
    $depth = 0;
    $buffer = '';
    for ($i = $open; $i < $count; $i++) {
        $t = $tokens[$i];
        $text = is_array($t) ? $t[1] : $t;
        if ($text === '(' || $text === '[') { $depth++; if ($depth === 1) { continue; } }
        if ($text === ')' || $text === ']') { $depth--; if ($depth === 0) { break; } }
        if ($depth === 1 && $text === ',') { break; }
        if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) { continue; }
        $buffer .= $text;
        if (strlen($buffer) > 400) { break; }
    }

    return trim($buffer);
}

function finding(string $file, int $line, string $verdict, string $evidence, string $reason): array
{
    return ['file' => $file, 'line' => $line, 'verdict' => $verdict,
            'evidence' => trim(preg_replace('/\s+/', ' ', mb_substr($evidence, 0, 160))), 'reason' => $reason];
}

function report(array $findings): void
{
    $unsafe = array_values(array_filter($findings, fn ($f) => $f['verdict'] === 'UNSAFE'));
    $safe = count($findings) - count($unsafe);

    echo "\n  INSTALLER INTEGRITY RULE\n  " . str_repeat('=', 88) . "\n";
    printf("  %d source-code write site(s): %d UNSAFE, %d SAFE\n\n", count($findings), count($unsafe), $safe);

    foreach ($unsafe as $f) {
        printf("  UNSAFE  %s:%d\n", $f['file'], $f['line']);
        echo '          ' . $f['evidence'] . "\n";
        echo '          ' . wordwrap($f['reason'], 80, "\n          ") . "\n\n";
    }

    if ($unsafe === []) {
        echo "  No repository code writes source files without provenance.\n\n";
    }
}

// ── selftest ────────────────────────────────────────────────────────────

function selftest(): int
{
    $dir = sys_get_temp_dir() . '/e888-install-rule-' . getmypid();
    @mkdir($dir . '/app', 0775, true);
    @mkdir($dir . '/tests', 0775, true);

    $cases = [
        ['app/BadInstaller.php',
            "<?php\nclass B { function go(\$c) { file_put_contents('app/Core/Thing.php', \$c); } }\n",
            'UNSAFE', 'writes a source file directly'],
        ['app/BadConcat.php',
            "<?php\nclass B { function go(\$r,\$c) { file_put_contents(\$r . '/app/Core/X.php', \$c); } }\n",
            'UNSAFE', 'concatenated source destination'],
        ['app/DataWriter.php',
            "<?php\nclass D { function go(\$c) { file_put_contents(storage_path('thumb.png'), \$c); } }\n",
            null, 'ordinary data write must NOT be flagged'],
        ['app/CacheWriter.php',
            "<?php\nclass C { function go(\$c) { file_put_contents('/tmp/export.csv', \$c); } }\n",
            null, 'temp file write must NOT be flagged'],
        ['app/Comment.php',
            "<?php\n// do not file_put_contents('app/Core/X.php', \$c) here\nclass C {}\n",
            null, 'a comment is not a write'],
        ['tests/FixtureTest.php',
            "<?php\nclass T { function go(\$c) { file_put_contents('tests/Feature/X.php', \$c); } }\n",
            'SAFE', 'test fixtures are permitted'],
        ['app/Core/Engineer888/Install/SafeInstaller.php',
            "<?php\nclass S { function go(\$c) { file_put_contents('app/Core/X.php', \$c); } }\n",
            'SAFE', 'the mechanism itself'],
    ];

    echo "\n  SELFTEST — does the installer rule detect what it claims?\n  " . str_repeat('-', 88) . "\n";
    $pass = 0; $fail = 0;

    foreach ($cases as [$path, $source, $expected, $why]) {
        $full = $dir . '/' . $path;
        @mkdir(dirname($full), 0775, true);
        file_put_contents($full, $source);

        $verdicts = array_column(auditFile($full, $path), 'verdict');
        $ok = $expected === null ? $verdicts === [] : in_array($expected, $verdicts, true);

        printf("  %-6s %-46s expected %-8s got %-12s %s\n",
            $ok ? 'PASS' : 'FAIL', $path, $expected ?? 'nothing',
            $verdicts === [] ? '(nothing)' : implode(',', $verdicts), $why);

        $ok ? $pass++ : $fail++;
        @unlink($full);
    }

    echo '  ' . str_repeat('-', 88) . "\n";
    printf("  %d passed, %d failed\n\n", $pass, $fail);

    // best-effort cleanup
    foreach (['app/Core/Engineer888/Install', 'app/Core/Engineer888', 'app/Core', 'app', 'tests'] as $sub) {
        @rmdir($dir . '/' . $sub);
    }
    @rmdir($dir);

    return $fail === 0 ? 0 : 2;
}
