<?php

namespace App\Core\Safety;

/**
 * CR-22B — the set of paths that may only be written through governed tooling.
 *
 * Enforcement is deliberately NOT filesystem-level. A chmod or immutable bit
 * would break deploys and would be worked around within a day. What this gives
 * instead is:
 *
 *   - every governed write is refused unless ownership is held and hashes match
 *   - every protected path has a recorded expected hash
 *   - drift between the expected hash and the file on disk is DETECTED and
 *     reportable, which turns an invisible clobber into a named violation
 *
 * That is the honest boundary: the tooling cannot stop a session that never
 * calls it, but it can make sure such a write is never silent.
 */
final class ProtectedPaths
{
    public const ROOT = '/var/www/levelup-staging';

    /** Sentinel state: expected hash per protected path after each governed write. */
    public const SENTINEL = self::ROOT . '/storage/app/source-locks/protected-paths.json';

    /**
     * Glob patterns, relative to the project root.
     *
     * E0.6 (2026-07-27) — TD-19 closure. The set previously covered only the
     * modular API tree, so `routes/web.php` and `routes/exec-api.php` could be
     * written with no ownership check and no drift detection. A concurrent
     * session did exactly that on 2026-07-27 (second occurrence of the
     * INC-2026-004 pattern); it was caught only because a CR-22B test asserts
     * that no `.bak` sits beside the route modules.
     *
     * The set below was derived from the ACTUAL loading path, not assumed:
     *   routes/web.php      → withRouting(web:)
     *   routes/api.php      → withRouting(api:)
     *   routes/api/**       → require'd from routes/api.php (CR-22B modules)
     *   routes/exec-api.php → the withRouting(then:) closure
     *   app/Engines/CRM/Http/Routes.php
     *                       → loadRoutesFrom() in App\Engines\CRM\EngineServiceProvider,
     *                         which IS a registered, booted provider. It currently
     *                         contributes 0 live routes because crm-01.php defines
     *                         the same URIs and wins the collision — but the file is
     *                         executed on every boot, so editing it CAN change
     *                         routing. It is therefore protected. See TD-20.
     */
    public const PATTERNS = [
        'routes/api.php',
        'routes/api/*',
        'routes/api/authenticated/*.php',
        'routes/web.php',
        'routes/exec-api.php',
        'app/Engines/CRM/Http/Routes.php',
        'bootstrap/app.php',
        'storage/app/source-locks/route-ownership.json',
    ];

    /**
     * Filename patterns that must never exist inside an active route directory.
     *
     * A backup beside live source is unsafe unless it has an authoritative
     * manifest and a governed restore path — which a raw `.bak` never does.
     */
    public const UNMANAGED_BACKUP_PATTERNS = [
        '*.bak', '*.bak-*', '*.backup', '*.backup-*', '*.old', '*.copy',
        '*.orig', '*.save', '*.swp', '*~', '*.pre-*', '*.candidate',
    ];

    /** Directories scanned for unmanaged backups. */
    public const ACTIVE_ROUTE_DIRS = [
        'routes',
        'routes/api',
        'routes/api/authenticated',
        'app/Engines/CRM/Http',
    ];

    /**
     * Any backup-shaped file sitting beside active route source.
     *
     * @return string[] project-relative paths
     */
    public static function unmanagedBackups(): array
    {
        $found = [];

        foreach (self::ACTIVE_ROUTE_DIRS as $dir) {
            $abs = self::ROOT . '/' . $dir;
            if (!is_dir($abs)) {
                continue;
            }
            foreach (scandir($abs) ?: [] as $entry) {
                if ($entry === '.' || $entry === '..' || !is_file($abs . '/' . $entry)) {
                    continue;
                }
                foreach (self::UNMANAGED_BACKUP_PATTERNS as $pattern) {
                    if (fnmatch($pattern, $entry)) {
                        $found[] = $dir . '/' . $entry;
                        break;
                    }
                }
            }
        }

        sort($found);

        return array_values(array_unique($found));
    }

    /** Logical lock scope that owns each pattern. One scope for the whole route tree. */
    public const SCOPE = 'routes';

    public static function isProtected(string $relPath): bool
    {
        $rel = ltrim(str_replace(self::ROOT, '', $relPath), '/');

        foreach (self::PATTERNS as $pattern) {
            if (fnmatch($pattern, $rel)) {
                return true;
            }
        }

        return false;
    }

    /** Every protected file that currently exists, relative to the root. */
    public static function all(): array
    {
        $out = [];

        foreach (self::PATTERNS as $pattern) {
            foreach (glob(self::ROOT . '/' . $pattern) as $abs) {
                if (is_file($abs)) {
                    $out[] = ltrim(str_replace(self::ROOT, '', $abs), '/');
                }
            }
        }

        $out = array_values(array_unique($out));
        sort($out);

        return $out;
    }

    public static function absolute(string $relPath): string
    {
        return str_starts_with($relPath, '/') ? $relPath : self::ROOT . '/' . $relPath;
    }

    /**
     * Record the expected hash of every protected path. Called after any
     * governed write, and once at deployment to establish the baseline.
     */
    public static function seal(string $owner, string $reason): array
    {
        $state = [
            'sealed_at' => gmdate('c'),
            'owner'     => $owner,
            'reason'    => $reason,
            'files'     => [],
        ];

        foreach (self::all() as $rel) {
            $state['files'][$rel] = hash_file('sha256', self::absolute($rel));
        }

        file_put_contents(self::SENTINEL, json_encode($state, JSON_PRETTY_PRINT), LOCK_EX);

        return $state;
    }

    /**
     * Compare disk against the sealed state.
     *
     * Anything reported here was written outside governed tooling, which the
     * CR-22B brief requires be documented as an operational-policy violation.
     *
     * @return array{sealed_at:?string, drifted:array, added:array, removed:array}
     */
    public static function drift(): array
    {
        if (!is_file(self::SENTINEL)) {
            return ['sealed_at' => null, 'drifted' => [], 'added' => self::all(), 'removed' => []];
        }

        $state = json_decode((string) file_get_contents(self::SENTINEL), true);
        $expected = $state['files'] ?? [];
        $current = [];

        foreach (self::all() as $rel) {
            $current[$rel] = hash_file('sha256', self::absolute($rel));
        }

        $drifted = [];
        foreach ($expected as $rel => $hash) {
            if (isset($current[$rel]) && $current[$rel] !== $hash) {
                $drifted[$rel] = ['expected' => $hash, 'actual' => $current[$rel]];
            }
        }

        return [
            'sealed_at' => $state['sealed_at'] ?? null,
            'drifted'   => $drifted,
            'added'     => array_values(array_diff(array_keys($current), array_keys($expected))),
            'removed'   => array_values(array_diff(array_keys($expected), array_keys($current))),
        ];
    }
}
