<?php

namespace App\Core\Engineer888\Repository;

use App\Core\Engineer888\Signals\Shell;

/**
 * The single reader of git working-tree state.
 *
 * WHY THIS IS SEPARATE FROM GitSignal
 * GitSignal parsed `git status --porcelain` itself to produce counts. Repository
 * Intelligence needs the same output as a list of files. Rather than parse it
 * twice, the parsing lives here and GitSignal consumes it — one place where the
 * porcelain format is understood.
 *
 * IT ALSO FIXES A REAL UNDERCOUNT. `git status --porcelain` collapses an
 * untracked DIRECTORY into a single entry, so `app/Core/Engineer888/` counted as
 * one file when it is nine. On this repository the default reports 176 entries
 * and the expanded form reports 394. The brief was understating the size of the
 * uncommitted surface by a factor of 2.2. Every count here uses -uall.
 */
final class WorkingTree
{
    /**
     * @return array{
     *   available: bool,
     *   reason?: string,
     *   files: array<int,array<string,mixed>>,
     *   by_area: array<string,int>,
     *   collapsed_entries: int,
     *   expanded_files: int,
     * }
     */
    public static function read(string $repoPath): array
    {
        if (! is_dir($repoPath . '/.git')) {
            return ['available' => false, 'reason' => 'not a git checkout', 'files' => [],
                    'by_area' => [], 'collapsed_entries' => 0, 'expanded_files' => 0];
        }

        // -uall expands untracked directories. See the class comment.
        $expanded = Shell::run('git', ['status', '--porcelain', '-uall'], $repoPath, 60);
        if (! $expanded['ok'] && trim($expanded['out']) === '') {
            return ['available' => false, 'reason' => 'git status failed', 'files' => [],
                    'by_area' => [], 'collapsed_entries' => 0, 'expanded_files' => 0];
        }

        // The default form, kept only so the difference can be reported.
        $collapsed = Shell::run('git', ['status', '--porcelain'], $repoPath, 60);

        $numstat = self::numstat($repoPath);

        $files = [];
        $byArea = [];

        foreach (self::lines($expanded['out']) as $line) {
            if (strlen($line) < 4) { continue; }

            $x = $line[0];
            $y = $line[1];
            $rest = substr($line, 3);

            // Renames and copies report "old -> new".
            $oldPath = null;
            if ($x === 'R' || $x === 'C') {
                $parts = explode(' -> ', $rest, 2);
                if (count($parts) === 2) {
                    $oldPath = self::unquote($parts[0]);
                    $rest = $parts[1];
                }
            }

            $path = self::unquote($rest);
            if ($path === '') { continue; }

            $full = $repoPath . '/' . $path;
            $untracked = ($x === '?' && $y === '?');
            $deleted = ($x === 'D' || $y === 'D');

            $stat = $numstat[$path] ?? null;
            if ($stat === null && $untracked && is_file($full)) {
                // Untracked files have no diff; every line is an addition.
                $stat = ['insertions' => self::countLines($full), 'deletions' => 0];
            }

            $area = explode('/', $path)[0];
            if ($area === $path) { $area = '(root)'; }
            $byArea[$area] = ($byArea[$area] ?? 0) + 1;

            $files[] = [
                'path'       => $path,
                'old_path'   => $oldPath,
                'status'     => $x . $y,
                'staged'     => $x !== ' ' && $x !== '?',
                'untracked'  => $untracked,
                'deleted'    => $deleted,
                'exists'     => is_file($full),
                'size'       => is_file($full) ? (int) filesize($full) : 0,
                'extension'  => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
                'area'       => $area,
                'insertions' => $stat['insertions'] ?? null,
                'deletions'  => $stat['deletions'] ?? null,
                'mtime'      => is_file($full) ? (int) filemtime($full) : null,
            ];
        }

        arsort($byArea);

        // Stable order so two runs of the report diff cleanly.
        usort($files, fn ($a, $b) => $a['path'] <=> $b['path']);

        return [
            'available'         => true,
            'files'             => $files,
            'by_area'           => $byArea,
            'collapsed_entries' => count(self::lines($collapsed['out'])),
            'expanded_files'    => count($files),
        ];
    }

    /** Insertions/deletions for tracked changes, staged and unstaged combined. */
    private static function numstat(string $repoPath): array
    {
        $out = [];
        foreach ([['diff', '--numstat'], ['diff', '--cached', '--numstat']] as $args) {
            $res = Shell::run('git', $args, $repoPath, 60);
            foreach (self::lines($res['out']) as $line) {
                $parts = preg_split('/\t/', $line);
                if (! is_array($parts) || count($parts) < 3) { continue; }
                // Binary files report "-" instead of a count.
                $ins = $parts[0] === '-' ? null : (int) $parts[0];
                $del = $parts[1] === '-' ? null : (int) $parts[1];
                $path = self::unquote($parts[2]);
                $out[$path] = [
                    'insertions' => ($out[$path]['insertions'] ?? 0) + (int) $ins,
                    'deletions'  => ($out[$path]['deletions'] ?? 0) + (int) $del,
                ];
            }
        }

        return $out;
    }

    /**
     * git quotes paths containing unusual bytes. Undo that so the path matches
     * what is on disk — otherwise stat() and file reads silently fail on exactly
     * the odd filenames most worth investigating.
     */
    private static function unquote(string $path): string
    {
        $path = trim($path);
        if (strlen($path) >= 2 && $path[0] === '"' && substr($path, -1) === '"') {
            $inner = substr($path, 1, -1);
            $decoded = stripcslashes($inner);

            return $decoded === '' ? $inner : $decoded;
        }

        return $path;
    }

    private static function countLines(string $full): int
    {
        $n = 0;
        $fh = @fopen($full, 'rb');
        if ($fh === false) { return 0; }
        while (fgets($fh) !== false) { $n++; }
        fclose($fh);

        return $n;
    }

    /** @return array<int,string> */
    private static function lines(string $out): array
    {
        // No /u and no \R — a bare \R without /u splits on byte 0x85, which is a
        // UTF-8 continuation byte, and corrupts non-ASCII paths.
        return array_values(array_filter(preg_split('/\r\n|\n|\r/', $out) ?: [], fn ($l) => trim($l) !== ''));
    }
}
