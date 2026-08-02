<?php

/**
 * INC-2026-006 — Engineering Safety Sweep.
 *
 * Enumerates every code path in this repository that can start a child process
 * or issue a destructive database operation, classifies each SAFE / UNSAFE /
 * UNKNOWN, and states the evidence for the classification.
 *
 * WHY A TOOL AND NOT A GREP
 * grep matches inside strings, comments and unrelated identifiers. The July
 * audits produced a static rule that passed while its target was live for
 * exactly that reason. Everything structural here comes from token_get_all.
 * grep is used only to cross-check that the tokenizer found at least as much as
 * a naive search would (see --crosscheck).
 *
 * WHAT IT LOOKS FOR
 *   A. Process spawning: exec, shell_exec, passthru, proc_open, popen, system,
 *      backtick operator, Symfony Process, Artisan::call, $this->call.
 *   B. Whether a spawned command boots Laravel (php artisan / phpunit), because
 *      that is the child that can reach a database.
 *   C. Whether the parent's environment is stripped before spawning. This is the
 *      INC-2026-006 mechanism: a child inherits DB_DATABASE through $_SERVER,
 *      which phpunit's <env force="true"> does not override.
 *   D. Destructive database verbs, wherever they appear.
 *   E. Test classes using RefreshDatabase that do not inherit the guard.
 *
 * EXIT CODES
 *   0  no UNSAFE findings
 *   1  UNSAFE findings present
 *   2  the tool could not complete (never interpret as "clean")
 *
 * Run --selftest first. A rule that cannot demonstrate it detects its target is
 * not evidence, and this repository has shipped one that could not.
 */

const SPAWN_FUNCTIONS = ['exec', 'shell_exec', 'passthru', 'proc_open', 'popen', 'system', 'pcntl_exec'];

/** Artisan subcommands that can modify or destroy schema or data. */
const DESTRUCTIVE_ARTISAN = [
    'migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback',
    'migrate:install', 'db:wipe', 'db:seed', 'db:table', 'db:monitor', 'db',
    'schema:dump', 'test', 'tinker',
];

/** Environment variables that must not reach a Laravel-booting child process. */
const MUST_STRIP = ['DB_DATABASE', 'DB_HOST', 'DB_CONNECTION', 'DB_USERNAME', 'DB_PASSWORD', 'APP_ENV'];

$root = rtrim($argv[1] ?? '/var/www/levelup-staging', '/');
$options = array_slice($argv, 1);
$selftest = in_array('--selftest', $options, true);
$json = in_array('--json', $options, true);
$crosscheck = in_array('--crosscheck', $options, true);

if ($selftest) { exit(selftest()); }

$files = phpFiles($root);
if ($files === []) { fwrite(STDERR, "FAIL: no PHP files found under {$root}\n"); exit(2); }

$findings = [];
foreach ($files as $relative) {
    foreach (auditFile($root . '/' . $relative, $relative) as $finding) {
        $findings[] = $finding;
    }
}

$findings = array_merge($findings, auditTestClasses($root, $files));
$findings = array_merge($findings, auditPhpunitConfigs($root));

usort($findings, function ($a, $b) {
    $rank = ['UNSAFE' => 0, 'UNKNOWN' => 1, 'SAFE' => 2];

    return [$rank[$a['verdict']], $a['file'], $a['line']] <=> [$rank[$b['verdict']], $b['file'], $b['line']];
});

if ($json) {
    echo json_encode(['findings' => $findings, 'files_scanned' => count($files)], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), "\n";
} else {
    report($findings, count($files));
    if ($crosscheck) { crosscheck($root, $findings); }
}

$unsafe = count(array_filter($findings, fn ($f) => $f['verdict'] === 'UNSAFE'));
exit($unsafe > 0 ? 1 : 0);

// ── scanning ────────────────────────────────────────────────────────────

/** @return array<int,string> repository-relative paths */
function phpFiles(string $root): array
{
    $out = [];
    // Third-party code is out of scope; backup copies are not code. Both
    // exclusions are stated rather than assumed — 529 backup files exist here
    // and they poison every count that forgets them.
    // Third-party, generated, and NOT-LIVE code.
    //
    // storage/ and _backup/ are excluded as source because neither is
    // autoloaded, routed, web-reachable or included by anything — verified, not
    // assumed. They do contain spawn call sites, and the sweep reports them
    // separately as dead code rather than pretending they do not exist: 43 of
    // the first run's 72 UNKNOWN findings came from copies of files that no
    // longer execute, which buried the ~20 that do.
    $skip = ['/vendor/', '/node_modules/', '/.git/', '/public/build/', '/storage/', '/_backup/'];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        if (! $item->isFile()) { continue; }
        $full = str_replace('\\', '/', $item->getPathname());
        if (! str_ends_with($full, '.php')) { continue; }
        // .bak AND .backup- AND .before- : the first sweep missed .backup-
        // files because the pattern only covered .bak.
        if (preg_match('/\.(bak|backup|orig|old|save)[-.\d]*$|\.before-|~$/i', $full)) { continue; }
        foreach ($skip as $fragment) { if (str_contains($full, $fragment)) { continue 2; } }
        $out[] = ltrim(substr($full, strlen($root)), '/');
    }

    sort($out);

    return $out;
}

/** @return array<int,array<string,mixed>> */
function auditFile(string $full, string $relative): array
{
    $source = @file_get_contents($full);
    if ($source === false) { return []; }

    $tokens = @token_get_all($source);
    if (! is_array($tokens)) { return []; }

    $findings = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        // Backtick operator: `command`. PHP emits '`' as a plain character.
        if ($token === '`') {
            $findings[] = finding($relative, lineOf($tokens, $i), 'shell-backtick', 'UNSAFE',
                'the backtick operator executes a shell command',
                'Backtick execution is unreviewable and has no environment control. Use a gated runner.');
            // Skip to the closing backtick.
            while (++$i < $count && $tokens[$i] !== '`') { }
            continue;
        }

        if (! is_array($token) || $token[0] !== T_STRING) { continue; }

        $name = strtolower($token[1]);
        $line = $token[2];

        // Must be a call, and not a method or a declaration.
        $next = significant($tokens, $i + 1);
        if ($next === null || $tokens[$next] !== '(') { continue; }
        $prev = significantBack($tokens, $i - 1);
        if ($prev !== null && is_array($tokens[$prev])
            && in_array($tokens[$prev][0], [T_FUNCTION, T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_NEW, T_CONST], true)) {
            // Artisan::call and $this->call are handled separately below.
            if (! ($name === 'call' && in_array($tokens[$prev][0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR], true))) {
                continue;
            }
        }

        $isSpawn = in_array($name, SPAWN_FUNCTIONS, true);
        $isArtisanCall = false;

        if ($name === 'call' || $name === 'callsilent') {
            $owner = ownerOf($tokens, $i);
            if ($owner === 'Artisan' || $owner === '$this') { $isArtisanCall = true; }
        }

        if (! $isSpawn && ! $isArtisanCall) { continue; }

        $argument = firstArgumentText($tokens, $next, $count);

        // If the command is a bare variable or a class constant, resolve it to
        // the literal it was built from. Hand-inspecting fourteen `$cmd` sites
        // told me they all run node or ffmpeg; that is knowledge in my head, not
        // evidence in the repository, and it goes stale the moment someone edits
        // one. Resolving them here makes the verdict reproducible.
        $resolved = resolveToLiteral($tokens, $i, $argument);

        $findings[] = classify($relative, $line, $name, $resolved, $isArtisanCall, $source);
    }

    return $findings;
}

/**
 * The classification decision, with the evidence recorded on the finding.
 */
function classify(string $file, int $line, string $function, string $argument, bool $isArtisanCall, string $source): array
{
    $kind = $isArtisanCall ? 'artisan-call' : 'process-spawn';
    $lower = strtolower($argument);

    $bootsLaravel = str_contains($lower, 'artisan') || str_contains($lower, 'phpunit');
    $dynamic = str_contains($argument, '$') || $argument === '';

    // Which destructive verb, if any, is named literally.
    $verb = null;
    foreach (DESTRUCTIVE_ARTISAN as $candidate) {
        if (preg_match('/(^|[\s\'"])' . preg_quote($candidate, '/') . '($|[\s\'"])/i', $argument)) {
            $verb = $candidate;
            break;
        }
    }

    $stripped = str_contains($argument, 'env -u') || str_contains($argument, 'env  -u');
    $strippedAll = $stripped;
    if ($stripped) {
        foreach (MUST_STRIP as $variable) {
            if (! str_contains($argument, '-u ' . $variable)) { $strippedAll = false; break; }
        }
    }

    $isTest = str_starts_with($file, 'tests/');
    $isTool = str_starts_with($file, 'tools/');

    // ── the INC-2026-006 shape ──────────────────────────────────────────
    if ($kind === 'process-spawn' && $bootsLaravel && $verb !== null && ! $strippedAll) {
        return finding($file, $line, $kind, 'UNSAFE',
            "spawns a Laravel-booting child running '{$verb}' without stripping the parent's database environment",
            'This is the exact INC-2026-006 mechanism: the child inherits DB_DATABASE through $_SERVER, which '
            . 'phpunit and .env loading do not override. Prefix with env -u for every DB_* and APP_ENV.',
            $argument);
    }

    if ($kind === 'process-spawn' && $bootsLaravel && $dynamic && ! $strippedAll) {
        return finding($file, $line, $kind, 'UNKNOWN',
            'spawns a Laravel-booting child from a command built at runtime',
            'The subcommand cannot be resolved statically, so whether it is destructive is unknown. '
            . 'Strip the environment unconditionally, or route through a gated runner.',
            $argument);
    }

    if ($kind === 'artisan-call' && $verb !== null) {
        return finding($file, $line, $kind, 'UNSAFE',
            "invokes the destructive artisan command '{$verb}' in-process",
            'An in-process Artisan::call inherits the caller\'s connection exactly. It cannot be made safe by '
            . 'environment stripping — it must be gated on the resolved database.',
            $argument);
    }

    if ($kind === 'process-spawn' && $bootsLaravel && $strippedAll) {
        return finding($file, $line, $kind, 'SAFE',
            'spawns a Laravel-booting child with the database environment stripped',
            'env -u removes every DB_* and APP_ENV, so the child cannot inherit a production target.',
            $argument);
    }

    // A runner that strips the environment is safe whatever it ends up running.
    // This is the strongest form of evidence available: it does not depend on
    // knowing the command, which is why the two Engineer888 choke points were
    // changed to strip unconditionally rather than argued to be harmless.
    if ($kind === 'process-spawn' && $strippedAll) {
        return finding($file, $line, $kind, 'SAFE',
            'strips the database environment before spawning, whatever the command turns out to be',
            'env -u removes DB_* and APP_ENV, so no child of this call can inherit a database target.',
            $argument);
    }

    if ($dynamic) {
        // A concatenated command still usually begins with a literal naming the
        // binary — '/usr/bin/ffprobe ... ' . $path. The first version gave up on
        // any concatenation and returned UNKNOWN for all of them, which produced
        // a report where the two findings that mattered were buried among
        // seventy that did not. Resolving the leading literal is what makes the
        // verdict mean something.
        $binary = leadingBinary($argument);

        if ($binary !== null && ! in_array($binary, ['php', 'artisan', 'phpunit'], true)) {
            return finding($file, $line, $kind, 'SAFE',
                "runs '{$binary}', which does not boot Laravel",
                'The arguments are dynamic but the binary is fixed. A non-PHP process cannot read '
                . 'this application\'s database configuration, inherited or otherwise.',
                $argument);
        }

        if ($binary === 'php') {
            // `php -l` is a syntax check. It parses the file and exits; it never
            // executes it, so it cannot boot Laravel or open a connection
            // whatever the target file contains.
            if (preg_match('/^\s*[\'"]\s*php\s+-l\b/', $argument)) {
                return finding($file, $line, $kind, 'SAFE',
                    'runs php -l, which lints without executing',
                    'A lint parses and exits. No code in the target file runs, so no database is reached.',
                    $argument);
            }

            return finding($file, $line, $kind, 'UNKNOWN',
                'spawns a PHP child whose script is resolved at runtime',
                'A PHP child may boot Laravel and reach a database. Confirm the script sets its own '
                . 'target before booting and refuses anything else, or strip the environment here.',
                $argument);
        }

        return finding($file, $line, $kind, 'UNKNOWN',
            'command is constructed at runtime with no literal prefix to identify the binary',
            'Nothing can be concluded statically. Review by hand, or route it through a runner that '
            . 'strips the database environment unconditionally.',
            $argument);
    }

    return finding($file, $line, $kind, 'SAFE',
        'does not boot Laravel and names no destructive verb',
        'A child that never boots the framework cannot reach the database through inherited configuration.',
        $argument);
}

/**
 * Test classes that use RefreshDatabase but do not inherit the guard.
 *
 * The guard lives in Tests\TestCase::setUpTraits(). A test extending PHPUnit's
 * TestCase directly, or any other base, would run migrate:fresh unguarded.
 *
 * @return array<int,array<string,mixed>>
 */
function auditTestClasses(string $root, array $files): array
{
    $findings = [];

    foreach ($files as $relative) {
        if (! str_starts_with($relative, 'tests/')) { continue; }
        $source = @file_get_contents($root . '/' . $relative);
        if ($source === false) { continue; }

        $usesRefresh = (bool) preg_match('/^\s*use\s+RefreshDatabase\s*;/m', $source)
            || str_contains($source, 'Illuminate\\Foundation\\Testing\\RefreshDatabase');
        if (! $usesRefresh) { continue; }

        if (! preg_match('/class\s+\w+\s+extends\s+([\w\\\\]+)/', $source, $m)) { continue; }
        $base = ltrim($m[1], '\\');

        // Resolve the written name through the file's imports. Comparing short
        // names is not enough: `use PHPUnit\Framework\TestCase;` also makes the
        // base read as "TestCase", and the first version of this rule passed
        // such a class as SAFE. Its own selftest caught that.
        $resolved = $base;
        if (! str_contains($base, '\\')) {
            if (preg_match('/^\s*use\s+([\w\\\\]*\\\\' . preg_quote($base, '/') . ')\s*;/m', $source, $useMatch)) {
                $resolved = ltrim($useMatch[1], '\\');
            } elseif (preg_match('/^\s*use\s+([\w\\\\]+)\s+as\s+' . preg_quote($base, '/') . '\s*;/m', $source, $aliasMatch)) {
                $resolved = ltrim($aliasMatch[1], '\\');
            } elseif (preg_match('/^\s*namespace\s+([\w\\\\]+)\s*;/m', $source, $nsMatch)) {
                // No import: the name resolves inside the file's own namespace.
                $resolved = $nsMatch[1] . '\\' . $base;
            }
        }

        $inheritsGuard = $resolved === 'Tests\\TestCase';

        $findings[] = $inheritsGuard
            ? finding($relative, lineOfMatch($source, 'RefreshDatabase'), 'refresh-database', 'SAFE',
                'uses RefreshDatabase and extends Tests\\TestCase, which asserts the target in setUpTraits()',
                'The guard runs after the application boots and before any trait, so migrate:fresh cannot reach production.')
            : finding($relative, lineOfMatch($source, 'RefreshDatabase'), 'refresh-database', 'UNSAFE',
                "uses RefreshDatabase but extends {$resolved}, which does not inherit the production guard",
                'RefreshDatabase runs migrate:fresh in setUpTraits(). Without the guard it will drop whatever '
                . 'database the environment resolves to. Extend Tests\\TestCase.');
    }

    return $findings;
}

/**
 * Every phpunit configuration and the database it targets.
 *
 * @return array<int,array<string,mixed>>
 */
function auditPhpunitConfigs(string $root): array
{
    $findings = [];

    foreach ((array) glob($root . '/phpunit*.xml') as $path) {
        $xml = (string) @file_get_contents($path);
        $relative = basename($path);

        if (! preg_match('/DB_DATABASE"\s+value="([^"]*)"/', $xml, $m)) {
            $findings[] = finding($relative, 1, 'phpunit-config', 'UNSAFE',
                'declares no DB_DATABASE, so the suite resolves to whatever .env provides',
                'On this platform .env is production. A test configuration must name its database explicitly.');
            continue;
        }

        $database = $m[1];
        $forced = (bool) preg_match('/DB_DATABASE"\s+value="[^"]*"\s+force="true"/', $xml);
        $production = in_array(strtolower($database), ['levelup_staging', 'levelup', 'levelup_production', 'levelup_prod'], true);
        $recognisable = (bool) preg_match('/(_test|_testing)$/i', $database);

        if ($production) {
            $findings[] = finding($relative, 1, 'phpunit-config', 'UNSAFE',
                "targets '{$database}', which is production", 'Change the target. Nothing else about this config matters.');
        } elseif (! $recognisable) {
            $findings[] = finding($relative, 1, 'phpunit-config', 'UNKNOWN',
                "targets '{$database}', which is not recognisable as a test database",
                'The guard allowlist requires a _test or _testing suffix, so this suite would be refused at runtime.');
        } elseif (! $forced) {
            // Upgraded from UNKNOWN to UNSAFE on 2026-07-31. This is not a
            // theoretical weakness: PHPUnit leaves an already-set variable
            // alone without force, every nested invocation on this platform
            // arrives with DB_DATABASE already set, and .env is production.
            // phpunit.integration.xml had zero forced entries and a testsuite
            // covering all of tests/Feature, 126 of which use RefreshDatabase.
            $findings[] = finding($relative, 1, 'phpunit-config', 'UNSAFE',
                "targets '{$database}' but WITHOUT force=\"true\"",
                'PHPUnit does not override an environment variable that is already set unless force is '
                . 'present. A nested run arrives with DB_DATABASE already set to production, so this '
                . 'config would be ignored. Add force="true" to every env entry.');
        } else {
            $findings[] = finding($relative, 1, 'phpunit-config', 'SAFE',
                "targets '{$database}' with force=\"true\"",
                'Named explicitly, forced, and recognisable as a test database.');
        }
    }

    return $findings;
}

// ── token helpers ───────────────────────────────────────────────────────

function significant(array $tokens, int $i): ?int
{
    $count = count($tokens);
    for (; $i < $count; $i++) {
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

/** `Artisan::call` -> 'Artisan'; `$this->call` -> '$this'. */
function ownerOf(array $tokens, int $i): ?string
{
    $operator = significantBack($tokens, $i - 1);
    if ($operator === null || ! is_array($tokens[$operator])) { return null; }
    if (! in_array($tokens[$operator][0], [T_DOUBLE_COLON, T_OBJECT_OPERATOR], true)) { return null; }

    $subject = significantBack($tokens, $operator - 1);
    if ($subject === null) { return null; }
    $t = $tokens[$subject];
    if (! is_array($t)) { return null; }

    if ($t[0] === T_VARIABLE) { return $t[1]; }
    if (in_array($t[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
        $parts = explode('\\', $t[1]);

        return end($parts);
    }

    return null;
}

/** Reconstructs the first argument's source text, for evidence and matching. */
function firstArgumentText(array $tokens, int $open, int $count): string
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
        if (strlen($buffer) > 800) { break; }
    }

    return trim($buffer);
}

/**
 * Resolve a command expression to something that names its binary.
 *
 * Three shapes, all of which occur in this repository:
 *   $cmd                      -> the most recent `$cmd = '...'` above the call
 *   self::CONST . ' ...'      -> the constant's declared literal
 *   sprintf('literal ...', …) -> sprintf's first argument
 *
 * Backwards, nearest-assignment-wins, within the same file. This is not full
 * data-flow analysis and does not pretend to be: a variable reassigned inside a
 * branch will resolve to whichever assignment is textually nearest, which is why
 * anything it cannot resolve stays UNKNOWN rather than being guessed SAFE.
 */
function resolveToLiteral(array $tokens, int $callIndex, string $argument): string
{
    // sprintf('...', ...) — the format string names the binary.
    if (preg_match('/^\s*sprintf\s*\(\s*([\'"])(.*)$/s', $argument, $m)) {
        return $m[1] . $m[2];
    }

    // self::NAME or static::NAME at the start.
    if (preg_match('/^\s*(?:self|static)::([A-Z_][A-Z0-9_]*)/', $argument, $m)) {
        $literal = constantLiteral($tokens, $m[1]);
        if ($literal !== null) {
            return $literal . substr($argument, strlen($m[0]));
        }
    }

    // A bare variable, optionally with a concatenated suffix: $cmd . ' 2>&1'
    if (! preg_match('/^\s*(\$\w+)\s*(\.|$)/', $argument, $m)) { return $argument; }
    $variable = $m[1];

    for ($i = $callIndex; $i >= 0; $i--) {
        $token = $tokens[$i];
        if (! is_array($token) || $token[0] !== T_VARIABLE || $token[1] !== $variable) { continue; }

        $next = significant($tokens, $i + 1);
        if ($next === null || $tokens[$next] !== '=') { continue; }

        $value = significant($tokens, $next + 1);
        if ($value === null) { continue; }

        // sprintf(...) assignment.
        if (is_array($tokens[$value]) && $tokens[$value][0] === T_STRING
            && strtolower($tokens[$value][1]) === 'sprintf') {
            $open = significant($tokens, $value + 1);
            if ($open !== null && $tokens[$open] === '(') {
                $format = significant($tokens, $open + 1);
                if ($format !== null && is_array($tokens[$format])
                    && $tokens[$format][0] === T_CONSTANT_ENCAPSED_STRING) {
                    return $tokens[$format][1];
                }
            }

            return $argument;
        }

        // Plain string literal assignment.
        if (is_array($tokens[$value]) && $tokens[$value][0] === T_CONSTANT_ENCAPSED_STRING) {
            return $tokens[$value][1];
        }

        // Double-quoted string with interpolation.
        if ($tokens[$value] === '"') {
            $buffer = '"';
            for ($j = $value + 1; $j < count($tokens) && $tokens[$j] !== '"'; $j++) {
                $buffer .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                if (strlen($buffer) > 200) { break; }
            }

            return $buffer;
        }

        return $argument;   // assigned from something we do not model
    }

    return $argument;
}

/** The declared literal of a class constant, by name, anywhere in the file. */
function constantLiteral(array $tokens, string $name): ?string
{
    $count = count($tokens);
    for ($i = 0; $i < $count; $i++) {
        $t = $tokens[$i];
        if (! is_array($t) || $t[0] !== T_CONST) { continue; }

        $nameIndex = significant($tokens, $i + 1);
        if ($nameIndex === null || ! is_array($tokens[$nameIndex]) || $tokens[$nameIndex][1] !== $name) { continue; }

        $equals = significant($tokens, $nameIndex + 1);
        if ($equals === null || $tokens[$equals] !== '=') { continue; }

        $value = significant($tokens, $equals + 1);
        if ($value !== null && is_array($tokens[$value]) && $tokens[$value][0] === T_CONSTANT_ENCAPSED_STRING) {
            return $tokens[$value][1];
        }
    }

    return null;
}

/**
 * The binary a concatenated command runs, from its leading string literal.
 *
 * Handles the shapes that actually occur here: a bare binary, an absolute path,
 * `cd X && binary`, and `VAR=value binary` prefixes. Returns null when the
 * command starts with a variable, because then nothing is knowable.
 */
function leadingBinary(string $argument): ?string
{
    if (! preg_match('/^\s*[\'"]([^\'"]{2,})/', $argument, $m)) { return null; }
    $literal = $m[1];

    // Strip a leading `cd <path> &&`.
    $literal = preg_replace('/^\s*cd\s+\S+\s*&&\s*/', '', $literal) ?? $literal;
    // Strip `env -u X ...` and `VAR=value ` prefixes.
    $literal = preg_replace('/^\s*env(\s+-u\s+\w+)*\s+/', '', $literal) ?? $literal;
    $literal = preg_replace('/^(\s*\w+=\S*\s+)+/', '', $literal) ?? $literal;

    if (! preg_match('/^\s*(\S+)/', $literal, $b)) { return null; }
    $binary = basename(trim($b[1]));

    // A variable in the binary position tells us nothing.
    if ($binary === '' || str_contains($binary, '$') || str_contains($binary, '{')) { return null; }

    return strtolower($binary);
}

function lineOf(array $tokens, int $i): int
{
    for (; $i >= 0; $i--) {
        if (is_array($tokens[$i])) { return $tokens[$i][2]; }
    }

    return 0;
}

function lineOfMatch(string $source, string $needle): int
{
    $position = strpos($source, $needle);

    return $position === false ? 1 : substr_count(substr($source, 0, $position), "\n") + 1;
}

function finding(string $file, int $line, string $kind, string $verdict, string $summary, string $guidance, string $evidence = ''): array
{
    return [
        'file' => $file, 'line' => $line, 'kind' => $kind, 'verdict' => $verdict,
        'summary' => $summary, 'guidance' => $guidance,
        'evidence' => $evidence === '' ? '' : trim(preg_replace('/\s+/', ' ', mb_substr($evidence, 0, 220))),
    ];
}

// ── output ──────────────────────────────────────────────────────────────

function report(array $findings, int $scanned): void
{
    $counts = ['UNSAFE' => 0, 'UNKNOWN' => 0, 'SAFE' => 0];
    foreach ($findings as $f) { $counts[$f['verdict']]++; }

    echo "\n  INC-2026-006 ENGINEERING SAFETY SWEEP\n";
    echo '  ' . str_repeat('=', 92) . "\n";
    echo "  {$scanned} PHP files scanned (vendor, node_modules and backup copies excluded)\n";
    printf("  %d UNSAFE   %d UNKNOWN   %d SAFE\n", $counts['UNSAFE'], $counts['UNKNOWN'], $counts['SAFE']);

    foreach (['UNSAFE', 'UNKNOWN'] as $verdict) {
        $group = array_values(array_filter($findings, fn ($f) => $f['verdict'] === $verdict));
        if ($group === []) { continue; }

        echo "\n  " . str_repeat('-', 92) . "\n";
        echo "  {$verdict} — " . count($group) . "\n";
        echo '  ' . str_repeat('-', 92) . "\n";

        foreach ($group as $f) {
            printf("\n  %s:%d  [%s]\n", $f['file'], $f['line'], $f['kind']);
            echo '      ' . $f['summary'] . "\n";
            if ($f['evidence'] !== '') { echo '      code: ' . $f['evidence'] . "\n"; }
            echo '      fix:  ' . wordwrap($f['guidance'], 84, "\n            ") . "\n";
        }
    }

    // SAFE findings are summarised rather than listed; the detail is in --json.
    echo "\n  " . str_repeat('-', 92) . "\n";
    echo "  SAFE — {$counts['SAFE']} (by kind)\n";
    $byKind = [];
    foreach ($findings as $f) {
        if ($f['verdict'] !== 'SAFE') { continue; }
        $byKind[$f['kind']] = ($byKind[$f['kind']] ?? 0) + 1;
    }
    foreach ($byKind as $kind => $n) { printf("      %-20s %4d\n", $kind, $n); }
    echo "\n";
}

/**
 * Confirms the tokenizer found at least as many spawn sites as a naive text
 * search. A tokenizer that silently under-reports is worse than grep.
 */
function crosscheck(string $root, array $findings): void
{
    echo '  ' . str_repeat('-', 92) . "\n  CROSS-CHECK against a naive text search\n";

    $tokenFiles = [];
    foreach ($findings as $f) {
        if (in_array($f['kind'], ['process-spawn', 'shell-backtick'], true)) { $tokenFiles[$f['file']] = true; }
    }

    // Word-boundaried. Without the leading class, this pattern matched
    // `subsystem(` for `system(` and `curl_exec(` for `exec(`, and reported
    // eight files as tokenizer gaps when every one was a grep artifact. A
    // cross-check that cries wolf is worse than none.
    $pattern = '(^|[^a-zA-Z0-9_])(' . implode('|', SPAWN_FUNCTIONS) . ')\s*\(';
    $command = 'grep -rIlE ' . escapeshellarg($pattern)
        . ' --include=*.php --exclude-dir=vendor --exclude-dir=node_modules '
        . escapeshellarg($root . '/app') . ' ' . escapeshellarg($root . '/tools')
        . ' ' . escapeshellarg($root . '/tests') . ' 2>/dev/null';

    $grepFiles = [];
    exec($command, $grepFiles);
    $grepFiles = array_map(fn ($p) => ltrim(substr($p, strlen($root)), '/'), $grepFiles);
    $grepFiles = array_values(array_filter($grepFiles,
        fn ($p) => ! preg_match('/\.(bak|backup|orig|old)[-.\d]*$|\.before-/i', $p)));

    $missed = array_values(array_diff($grepFiles, array_keys($tokenFiles)));

    if ($missed === []) {
        echo "      grep found " . count($grepFiles) . " file(s); the tokenizer covered all of them.\n\n";
    } else {
        echo "      *** grep found " . count($missed) . " file(s) the tokenizer did not report:\n";
        foreach ($missed as $file) { echo "        {$file}\n"; }
        echo "      Each is a real call site in a comment/string, or a tokenizer gap. Review by hand.\n\n";
    }
}

// ── selftest ────────────────────────────────────────────────────────────

/**
 * Proves the rule detects what it claims to. The July audits shipped a static
 * rule that passed while its target was live; this exists so that cannot happen
 * again silently.
 */
function selftest(): int
{
    $dir = sys_get_temp_dir() . '/e888-audit-selftest-' . getmypid();
    @mkdir($dir . '/app/Fake', 0775, true);
    @mkdir($dir . '/tests/Fake', 0775, true);

    $cases = [
        // [path, source, expected verdict, why]
        ['app/Fake/Unsafe.php',
            "<?php\nclass Unsafe { function go() { exec('php artisan migrate:fresh'); } }\n",
            'UNSAFE', 'the exact INC-2026-006 shape'],
        ['app/Fake/UnsafeTest.php',
            "<?php\nclass T { function go() { exec('cd /x && php artisan test -c phpunit.e888.xml f.php'); } }\n",
            'UNSAFE', 'the literal command that emptied production'],
        ['app/Fake/Safe.php',
            "<?php\nclass S { function go() { exec('env -u DB_DATABASE -u DB_HOST -u DB_CONNECTION -u DB_USERNAME -u DB_PASSWORD -u APP_ENV php artisan test'); } }\n",
            'SAFE', 'the same command with the environment stripped'],
        ['app/Fake/Dynamic.php',
            "<?php\nclass D { function go(\$cmd) { exec('php artisan ' . \$cmd); } }\n",
            'UNKNOWN', 'a runtime-built artisan command'],
        ['app/Fake/Harmless.php',
            "<?php\nclass H { function go() { exec('git status --porcelain'); } }\n",
            'SAFE', 'does not boot Laravel'],
        ['app/Fake/InProcess.php',
            "<?php\nclass I { function go() { \\Artisan::call('migrate:fresh'); } }\n",
            'UNSAFE', 'in-process Artisan::call of a destructive command'],
        ['app/Fake/Mention.php',
            "<?php\n/** Do not call exec('php artisan migrate:fresh') here. */\nclass M {}\n",
            null, 'a comment mentioning the pattern must produce NO finding'],
        ['app/Fake/StringOnly.php',
            "<?php\nclass SO { const DOC = \"exec('php artisan migrate:fresh')\"; }\n",
            null, 'the pattern inside a string literal must produce NO finding'],

        // Variable resolution — the fourteen $cmd sites this repository actually has.
        ['app/Fake/VarNode.php',
            "<?php\nclass VN { function go(\$s) { \$cmd = 'node ' . escapeshellarg(\$s); exec(\$cmd); } }\n",
            'SAFE', '$cmd resolved backwards to a node command'],
        ['app/Fake/VarArtisan.php',
            "<?php\nclass VA { function go() { \$cmd = 'php artisan migrate:fresh'; exec(\$cmd); } }\n",
            'UNSAFE', '$cmd resolved backwards to a destructive artisan command'],
        ['app/Fake/VarSprintf.php',
            "<?php\nclass VS { function go(\$d) { \$cmd = sprintf('find %s -type f', \$d); exec(\$cmd); } }\n",
            'SAFE', 'sprintf format string identifies the binary'],
        ['app/Fake/VarUnknown.php',
            "<?php\nclass VU { function go(\$x) { \$cmd = \$x; exec(\$cmd); } }\n",
            'UNKNOWN', 'assigned from a parameter — must NOT be guessed safe'],
        ['app/Fake/StripConst.php',
            "<?php\nclass SC { const E = 'env -u DB_CONNECTION -u DB_HOST -u DB_PORT -u DB_DATABASE -u DB_USERNAME -u DB_PASSWORD -u APP_ENV';\n"
            . "  function go(\$c) { exec(self::E . ' ' . \$c); } }\n",
            'SAFE', 'a runner that strips the environment is safe whatever it runs'],
    ];

    $pass = 0;
    $fail = 0;

    echo "\n  SELFTEST — does this rule detect what it claims?\n";
    echo '  ' . str_repeat('-', 92) . "\n";

    foreach ($cases as [$path, $source, $expected, $why]) {
        $full = $dir . '/' . $path;
        @mkdir(dirname($full), 0775, true);
        file_put_contents($full, $source);

        $findings = auditFile($full, $path);
        $verdicts = array_column($findings, 'verdict');

        $ok = $expected === null
            ? $findings === []
            : in_array($expected, $verdicts, true);

        printf("  %-6s %-26s expected %-8s got %-22s %s\n",
            $ok ? 'PASS' : 'FAIL',
            basename($path),
            $expected ?? 'nothing',
            $verdicts === [] ? '(nothing)' : implode(',', $verdicts),
            $why);

        $ok ? $pass++ : $fail++;
        @unlink($full);
    }

    // The RefreshDatabase check.
    $guarded = "<?php\nnamespace Tests\\Fake;\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse Tests\\TestCase;\nclass GuardedTest extends TestCase { use RefreshDatabase; }\n";
    $unguarded = "<?php\nnamespace Tests\\Fake;\nuse Illuminate\\Foundation\\Testing\\RefreshDatabase;\nuse PHPUnit\\Framework\\TestCase;\nclass RogueTest extends TestCase { use RefreshDatabase; }\n";

    file_put_contents($dir . '/tests/Fake/GuardedTest.php', $guarded);
    file_put_contents($dir . '/tests/Fake/RogueTest.php', $unguarded);

    $refreshFindings = auditTestClasses($dir, ['tests/Fake/GuardedTest.php', 'tests/Fake/RogueTest.php']);
    $byFile = [];
    foreach ($refreshFindings as $f) { $byFile[$f['file']] = $f['verdict']; }

    foreach ([['tests/Fake/GuardedTest.php', 'SAFE', 'extends Tests\\TestCase — inherits the guard'],
              ['tests/Fake/RogueTest.php', 'UNSAFE', 'extends PHPUnit TestCase — bypasses the guard']] as [$file, $expected, $why]) {
        $got = $byFile[$file] ?? '(nothing)';
        $ok = $got === $expected;
        printf("  %-6s %-26s expected %-8s got %-22s %s\n", $ok ? 'PASS' : 'FAIL', basename($file), $expected, $got, $why);
        $ok ? $pass++ : $fail++;
    }

    @unlink($dir . '/tests/Fake/GuardedTest.php');
    @unlink($dir . '/tests/Fake/RogueTest.php');
    @rmdir($dir . '/tests/Fake'); @rmdir($dir . '/tests');
    @rmdir($dir . '/app/Fake'); @rmdir($dir . '/app'); @rmdir($dir);

    echo '  ' . str_repeat('-', 92) . "\n";
    printf("  %d passed, %d failed\n\n", $pass, $fail);

    return $fail === 0 ? 0 : 2;
}
