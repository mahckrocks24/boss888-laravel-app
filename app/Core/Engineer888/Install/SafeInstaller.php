<?php

namespace App\Core\Engineer888\Install;

use App\Core\Engineer888\Coordination\OwnershipManifest;
use RuntimeException;

/**
 * Installs source files, and refuses to destroy work it did not put there.
 *
 * THE INCIDENT THIS REPLACES
 * The ad-hoc installers used through Sprints 1–4 wrote transport content over
 * destinations unconditionally under `--force`. On 2026-08-01 that reverted a
 * migration index fix, a migration-count fix and a worker-detection fix, all of
 * which had been verified by hand after installation. The migration revert broke
 * `migrate:fresh`, which failed every test, and two defects shipped twice.
 *
 * THE RULE
 *   destination absent                        -> install
 *   destination == last installed, source new -> update
 *   destination == last installed, source same-> no-op
 *   destination != last installed             -> REFUSE (someone edited it)
 *   destination exists, no install record     -> REFUSE (unknown provenance)
 *
 * `--force` is deliberately NOT a bypass. Overriding a divergent destination
 * requires a separate, explicit flag AND a reason, and always backs the
 * destination up first. A single flag that means both "yes I meant to install"
 * and "yes destroy whatever is there" is how this went wrong.
 */
final class SafeInstaller
{
    public const VERSION = '1.0.0';

    private InstallManifest $manifest;

    public function __construct(
        private string $repoPath,
        ?InstallManifest $manifest = null,
    ) {
        $this->manifest = $manifest ?? InstallManifest::load($repoPath);
    }

    /**
     * Decide what would happen, without touching anything.
     *
     * Separated from the write so a caller can present the decision for
     * approval, and so the tests can assert the decision without side effects.
     */
    public function decide(string $destination, string $content, ?string $sourceLabel = null): InstallDecision
    {
        $full = $this->absolute($destination);
        $sourceHash = sha1($content);

        $entry = $this->manifest->entryFor($destination);   // throws on a corrupt record

        if (! file_exists($full)) {
            return new InstallDecision(
                InstallDecision::INSTALL, $destination, $sourceHash, null,
                $entry['source_hash'] ?? null, $entry['destination_hash'] ?? null,
                'destination does not exist; nothing can be lost'
            );
        }

        $current = @file_get_contents($full);
        if ($current === false) {
            return new InstallDecision(
                InstallDecision::REFUSED, $destination, $sourceHash, null,
                $entry['source_hash'] ?? null, $entry['destination_hash'] ?? null,
                'destination exists but cannot be read, so its content cannot be compared',
                [], null, 'check permissions, then re-run'
            );
        }

        $destinationHash = sha1($current);

        if ($entry === null) {
            return new InstallDecision(
                InstallDecision::UNKNOWN_PROVENANCE, $destination, $sourceHash, $destinationHash,
                null, null,
                'the destination exists but this installer has no record of putting it there, so it '
                . 'cannot tell whether overwriting would destroy someone else\'s work',
                $this->diff($current, $content),
                null,
                'if this content is expected, adopt it with --adopt; if it should be replaced, use '
                . '--override-divergence with a reason'
            );
        }

        if ($destinationHash !== $entry['destination_hash']) {
            return new InstallDecision(
                InstallDecision::DIVERGED, $destination, $sourceHash, $destinationHash,
                $entry['source_hash'], $entry['destination_hash'],
                'the destination has changed since it was installed — it was edited after installation, '
                . 'and overwriting would silently discard that edit',
                $this->diff($current, $content),
                null,
                'inspect the diff. If the destination edit is the newer truth, update the transport source '
                . 'instead. If it really should be replaced, use --override-divergence with a reason.'
            );
        }

        if ($destinationHash === $sourceHash) {
            return new InstallDecision(
                InstallDecision::UNCHANGED_NO_OP, $destination, $sourceHash, $destinationHash,
                $entry['source_hash'], $entry['destination_hash'],
                'destination matches the install record and the source is identical; nothing to do'
            );
        }

        return new InstallDecision(
            InstallDecision::UPDATE, $destination, $sourceHash, $destinationHash,
            $entry['source_hash'], $entry['destination_hash'],
            'destination is exactly as installed and the source has changed; safe to update',
            $this->diff($current, $content)
        );
    }

    /**
     * Apply a decision.
     *
     * @param  bool  $overrideDivergence  explicit, separate from any --force
     * @throws RuntimeException on refusal, or when an override lacks a reason
     */
    public function install(
        string $destination,
        string $content,
        ?string $sourceLabel = null,
        string $component = 'engineer888',
        bool $overrideDivergence = false,
        ?string $overrideReason = null,
        bool $adopt = false,
    ): InstallDecision {
        $decision = $this->decide($destination, $content, $sourceLabel);

        // Ownership: never install over a file this sprint cannot claim.
        $ownership = $this->ownership($destination);
        if ($ownership !== null && ! OwnershipManifest::isCommittable($ownership['status'])) {
            return new InstallDecision(
                InstallDecision::REFUSED, $destination, $decision->sourceHash, $decision->destinationHash,
                $decision->recordedSourceHash, $decision->recordedDestinationHash,
                'this sprint does not own the destination (' . $ownership['status'] . ': '
                . $ownership['evidence'] . ')',
                $decision->diff, null,
                'declare it in the sprint manifest, or leave it to its owner'
            );
        }

        if ($decision->state === InstallDecision::UNCHANGED_NO_OP) {
            return $decision;
        }

        if ($decision->isRefusal()) {
            $canOverride = in_array($decision->state, [InstallDecision::DIVERGED, InstallDecision::UNKNOWN_PROVENANCE], true);

            if ($adopt && $decision->state === InstallDecision::UNKNOWN_PROVENANCE) {
                // Adoption records the CURRENT destination as the installed
                // state without writing anything. It establishes provenance for
                // a file that predates the installer; it never overwrites.
                $current = (string) file_get_contents($this->absolute($destination));
                $this->write_record($destination, $component, $sourceLabel, sha1($current), sha1($current));
                $this->manifest->save();

                return new InstallDecision(
                    InstallDecision::UNCHANGED_NO_OP, $destination, $decision->sourceHash,
                    sha1($current), sha1($current), sha1($current),
                    'adopted the existing destination as the installed state; nothing was written'
                );
            }

            if (! ($canOverride && $overrideDivergence)) {
                return $decision;
            }

            if ($overrideReason === null || trim($overrideReason) === '') {
                return new InstallDecision(
                    InstallDecision::REFUSED, $destination, $decision->sourceHash, $decision->destinationHash,
                    $decision->recordedSourceHash, $decision->recordedDestinationHash,
                    'an override must state why. Destroying newer content silently is the defect this '
                    . 'installer exists to prevent, and an unexplained override is the same thing with a flag.',
                    $decision->diff, null, 'pass --override-reason="..."'
                );
            }
        }

        // ── backup before any write over existing content ────────────────
        $backup = null;
        if (file_exists($this->absolute($destination))) {
            $backup = $this->backup($destination);
        }

        $this->atomicWrite($destination, $content);

        $finalHash = sha1((string) file_get_contents($this->absolute($destination)));
        if ($finalHash !== $decision->sourceHash) {
            throw new RuntimeException("post-write verification failed for {$destination}: expected "
                . "{$decision->sourceHash}, found {$finalHash}");
        }

        $this->write_record($destination, $component, $sourceLabel, $decision->sourceHash, $finalHash);

        if ($decision->isRefusal()) {
            $this->manifest->addOverride($destination, [
                'at'                        => gmdate('c'),
                'reason'                    => $overrideReason,
                'previous_state'            => $decision->state,
                'previous_destination_hash' => $decision->destinationHash,
                'backup'                    => $backup,
            ]);
        }

        $this->manifest->save();

        return new InstallDecision(
            $decision->isRefusal() ? InstallDecision::OVERRIDDEN : $decision->state,
            $destination, $decision->sourceHash, $finalHash,
            $decision->recordedSourceHash, $decision->recordedDestinationHash,
            $decision->isRefusal()
                ? 'operator override applied: ' . $overrideReason
                : $decision->reason,
            $decision->diff, $backup
        );
    }

    public function manifest(): InstallManifest
    {
        return $this->manifest;
    }

    // ── internals ───────────────────────────────────────────────────────

    private function write_record(string $destination, string $component, ?string $sourceLabel, string $sourceHash, string $destinationHash): void
    {
        $existing = null;
        try { $existing = $this->manifest->entryFor($destination); } catch (RuntimeException) { $existing = null; }

        $ownershipManifest = OwnershipManifest::active($this->repoPath);

        $this->manifest->record($destination, [
            'component'          => $component,
            'source_path'        => $sourceLabel,
            'source_hash'        => $sourceHash,
            'destination_hash'   => $destinationHash,
            'installer_version'  => self::VERSION,
            'installed_at'       => gmdate('c'),
            'installed_by'       => $ownershipManifest?->session(),
            'ownership_manifest' => $ownershipManifest !== null
                ? ltrim(str_replace($this->repoPath, '', $ownershipManifest->path()), '/')
                : null,
            'overrides'          => $existing['overrides'] ?? [],
        ]);
    }

    private function backup(string $destination): string
    {
        $directory = $this->repoPath . '/storage/app/ads-backups/e888-install';
        if (! is_dir($directory) && ! mkdir($directory, 0775, true)) {
            throw new RuntimeException("cannot create backup directory {$directory}");
        }

        $name = str_replace('/', '__', ltrim($destination, '/')) . '.' . gmdate('Ymd-His') . '.bak';
        $path = $directory . '/' . $name;

        $content = (string) file_get_contents($this->absolute($destination));
        if (file_put_contents($path, $content) === false) {
            throw new RuntimeException("cannot write backup {$path}");
        }

        // A backup that was not verified is a hope, not a backup.
        if (sha1((string) file_get_contents($path)) !== sha1($content)) {
            throw new RuntimeException("backup verification failed for {$path}");
        }

        return ltrim(str_replace($this->repoPath, '', $path), '/');
    }

    private function atomicWrite(string $destination, string $content): void
    {
        $full = $this->absolute($destination);
        $directory = dirname($full);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true)) {
            throw new RuntimeException("cannot create {$directory}");
        }

        $temp = $full . '.installing';
        if (file_put_contents($temp, $content) === false) {
            throw new RuntimeException("cannot write {$temp}");
        }
        if (! rename($temp, $full)) {
            @unlink($temp);
            throw new RuntimeException("cannot move {$temp} into place");
        }
        @chmod($full, 0644);
    }

    /** @return array{status:string, evidence:string}|null */
    private function ownership(string $destination): ?array
    {
        $manifest = OwnershipManifest::active($this->repoPath);

        return $manifest?->classify($destination);
    }

    /**
     * A concise, line-based summary. Enough to judge whether the destination
     * edit matters; not a full patch, which nobody reads in a refusal message.
     *
     * @return array<string,mixed>
     */
    private function diff(string $current, string $incoming): array
    {
        $a = preg_split('/\r\n|\n|\r/', $current) ?: [];
        $b = preg_split('/\r\n|\n|\r/', $incoming) ?: [];

        $firstDifference = null;
        $limit = max(count($a), count($b));
        for ($i = 0; $i < $limit; $i++) {
            if (($a[$i] ?? null) !== ($b[$i] ?? null)) {
                $firstDifference = [
                    'line'        => $i + 1,
                    'destination' => mb_substr(trim((string) ($a[$i] ?? '(absent)')), 0, 110),
                    'source'      => mb_substr(trim((string) ($b[$i] ?? '(absent)')), 0, 110),
                ];
                break;
            }
        }

        $inDestinationOnly = count(array_diff($a, $b));
        $inSourceOnly = count(array_diff($b, $a));

        return [
            'destination_lines'    => count($a),
            'source_lines'         => count($b),
            'only_in_destination'  => $inDestinationOnly,
            'only_in_source'       => $inSourceOnly,
            'first_difference'     => $firstDifference,
        ];
    }

    private function absolute(string $destination): string
    {
        return rtrim($this->repoPath, '/') . '/' . ltrim($destination, '/');
    }
}
