<?php

namespace App\Core\Engineer888\Install;

use RuntimeException;

/**
 * The record of what was installed, from where, and what it looked like
 * afterwards.
 *
 * WHY THIS EXISTS
 * On 2026-08-01 an installer rewrote three files from stale transport sources
 * and silently discarded the fixes that had been applied to them after
 * installation. One of those fixes was a migration index name; losing it broke
 * `migrate:fresh`, which broke every test, which cost a full certification
 * cycle and let two defects ship twice.
 *
 * The installer had no way to know it was destroying newer content, because
 * nothing recorded what it had put there in the first place. That is the gap
 * this file closes: with a recorded destination hash, "the file changed since I
 * installed it" becomes a question that can be ANSWERED rather than assumed.
 *
 * FAIL CLOSED. A missing manifest, an unreadable manifest, or a corrupt entry
 * all mean provenance is unknown, and unknown provenance refuses. The previous
 * behaviour — overwrite and hope — is exactly what caused the incident.
 */
final class InstallManifest
{
    public const PATH = '.engineer888/installed.json';

    public const VERSION = 1;

    /** @var array<string,mixed> */
    private array $data;

    private function __construct(private string $repoPath, array $data)
    {
        $this->data = $data;
    }

    /**
     * Load the manifest, or an empty one if it has never existed.
     *
     * A file that exists but does not parse is NOT treated as empty — that
     * would silently downgrade every entry to unknown provenance and re-open
     * the exact hole this class closes.
     *
     * @throws RuntimeException when the manifest exists but is unusable
     */
    public static function load(string $repoPath): self
    {
        $full = rtrim($repoPath, '/') . '/' . self::PATH;

        if (! is_file($full)) {
            return new self($repoPath, ['manifest_version' => self::VERSION, 'entries' => []]);
        }

        $raw = file_get_contents($full);
        if ($raw === false) {
            throw new RuntimeException("REFUSING: {$full} exists but cannot be read. Provenance is "
                . 'unknown, and unknown provenance fails closed.');
        }

        $data = json_decode($raw, true);
        if (! is_array($data) || ! isset($data['entries']) || ! is_array($data['entries'])) {
            throw new RuntimeException("REFUSING: {$full} is not a usable install manifest. Repair or "
                . 'remove it deliberately — it will not be silently rebuilt, because rebuilding it would '
                . 'mark every installed file as safe to overwrite.');
        }

        if (($data['manifest_version'] ?? null) !== self::VERSION) {
            throw new RuntimeException('REFUSING: install manifest version '
                . var_export($data['manifest_version'] ?? null, true) . ' is not supported by this installer '
                . '(expected ' . self::VERSION . ').');
        }

        return new self($repoPath, $data);
    }

    /** @return array<string,mixed>|null */
    public function entryFor(string $destination): ?array
    {
        $entry = $this->data['entries'][$this->normalise($destination)] ?? null;

        if ($entry === null) { return null; }

        // A half-written entry is worse than none: it would claim provenance it
        // cannot support.
        foreach (['source_hash', 'destination_hash', 'installed_at'] as $required) {
            if (! isset($entry[$required]) || ! is_string($entry[$required]) || $entry[$required] === '') {
                throw new RuntimeException("REFUSING: the install record for {$destination} is incomplete "
                    . "(missing {$required}). Treat it as unknown provenance and re-establish it deliberately.");
            }
        }

        return $entry;
    }

    public function record(string $destination, array $entry): void
    {
        $this->data['entries'][$this->normalise($destination)] = $entry;
    }

    public function addOverride(string $destination, array $override): void
    {
        $key = $this->normalise($destination);
        $this->data['entries'][$key]['overrides'][] = $override;
    }

    public function save(): string
    {
        $full = rtrim($this->repoPath, '/') . '/' . self::PATH;
        $directory = dirname($full);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true)) {
            throw new RuntimeException("cannot create {$directory}");
        }

        ksort($this->data['entries']);
        $this->data['manifest_version'] = self::VERSION;
        $this->data['updated_at'] = gmdate('c');

        // Atomic: a manifest half-written by an interrupted run would fail
        // closed on the next load, blocking every install.
        $temp = $full . '.tmp';
        if (file_put_contents($temp, json_encode($this->data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n") === false) {
            throw new RuntimeException("cannot write {$temp}");
        }
        if (! rename($temp, $full)) {
            @unlink($temp);
            throw new RuntimeException("cannot move {$temp} into place");
        }

        return $full;
    }

    /** @return array<string,array<string,mixed>> */
    public function entries(): array
    {
        return $this->data['entries'];
    }

    public function count(): int
    {
        return count($this->data['entries']);
    }

    private function normalise(string $path): string
    {
        return ltrim(str_replace('\\', '/', $path), '/');
    }
}
