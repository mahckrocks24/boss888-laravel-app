<?php

namespace App\Core\Engineer888\Baseline;

use App\Core\Engineer888\Coordination\GovernedFiles;
use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Repository\FileClassifier;

/**
 * Sorts every dirty file into a category that decides whether it may enter a
 * baseline commit.
 *
 * THE DISTINCTION THAT MATTERS. Repository Intelligence answers "what is this
 * file" — complete, partial, debris. That is necessary and not sufficient. A
 * file can be complete, coherent, and still not mine to commit. On 2026-07-31 a
 * commit captured nine documents belonging to the PlatformEvents engineer
 * because only the first question was asked.
 *
 * So the categories below combine WHAT a file is with WHOSE it is, and anything
 * that cannot be attributed stays OWNERSHIP_UNKNOWN. Unknown is not a soft
 * "probably fine"; it is an exclusion.
 */
final class DirtyFileClassifier
{
    public const OWNED_COMPLETE     = 'OWNED_COMPLETE';
    public const OWNED_PARTIAL      = 'OWNED_PARTIAL';
    public const EXTERNALLY_OWNED   = 'EXTERNALLY_OWNED';
    public const OWNERSHIP_UNKNOWN  = 'OWNERSHIP_UNKNOWN';
    public const GENERATED          = 'GENERATED';
    public const BACKUP             = 'BACKUP';
    public const TEMPORARY          = 'TEMPORARY';
    public const ABANDONED          = 'ABANDONED';
    public const SENSITIVE          = 'SENSITIVE';
    public const RUNTIME_DATA       = 'RUNTIME_DATA';
    public const DOCUMENTATION      = 'DOCUMENTATION';
    public const GOVERNED_SHARED    = 'GOVERNED_SHARED';
    public const UNSAFE_TO_COMMIT   = 'UNSAFE_TO_COMMIT';

    /**
     * Categories that may be proposed for a baseline commit.
     *
     * DOCUMENTATION belongs here because the category is only reached when the
     * file is already owned — unowned documentation falls through to
     * EXTERNALLY_OWNED or OWNERSHIP_UNKNOWN. Omitting it excluded this sprint's
     * own README from its own baseline, which the first classification run
     * showed as "DOCUMENTATION 2 — excluded".
     */
    public const COMMITTABLE = [
        self::OWNED_COMPLETE, self::OWNED_PARTIAL, self::GOVERNED_SHARED, self::DOCUMENTATION,
    ];

    /**
     * Paths whose content is produced by running the platform, not by authoring.
     * These are excluded regardless of ownership: committing them records a
     * moment of runtime state as though it were source.
     */
    private const RUNTIME_PREFIXES = [
        'storage/', 'bootstrap/cache/', 'public/build/', 'public/hot',
        '.phpunit.cache', 'node_modules/', 'vendor/',
    ];

    public function __construct(
        private ?OwnershipManifest $manifest,
        private array $externalEvidence = [],
    ) {}

    /**
     * @param  array<string,mixed>  $file  a Repository Intelligence file record
     * @return array{category:string, basis:string, committable:bool}
     */
    public function classify(array $file): array
    {
        $path = $file['path'];
        $analysis = $file['analysis'] ?? [];
        $classification = $analysis['classification'] ?? '';
        $flags = $analysis['flags'] ?? [];

        // ── 1. anything that could leak credentials ─────────────────────
        if (in_array('may-contain-session-token', $flags, true) || $this->looksSensitive($path)) {
            return $this->result(self::SENSITIVE,
                'may carry authentication material; must never enter version control');
        }

        // ── 2. anything that cannot be committed at all ─────────────────
        if ($classification === FileClassifier::MERGE) {
            return $this->result(self::UNSAFE_TO_COMMIT, 'contains unresolved conflict markers');
        }
        if (in_array('debug-statements', $flags, true) && str_starts_with($path, 'app/')) {
            return $this->result(self::UNSAFE_TO_COMMIT, 'debug output in application code would run in production');
        }

        // ── 3. artifacts and runtime state ──────────────────────────────
        foreach (self::RUNTIME_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $this->result(self::RUNTIME_DATA, 'produced by running the platform, not by authoring');
            }
        }
        if ($classification === FileClassifier::TEMPORARY) {
            return $this->result(self::TEMPORARY, $analysis['reason'] ?? 'accidental artifact');
        }
        if ($classification === FileClassifier::BACKUP) {
            return $this->result(self::BACKUP, 'a copy of another file');
        }
        if ($classification === FileClassifier::GENERATED) {
            return $this->result(self::GENERATED, 'build or dependency output');
        }

        // ── 4. ownership decides the rest ───────────────────────────────
        $ownership = $this->manifest?->classify($path)
            ?? ['status' => OwnershipManifest::UNKNOWN, 'evidence' => 'no active sprint manifest'];

        // A governed shared file is only committable when explicitly declared.
        if (GovernedFiles::isGoverned($path)) {
            return $ownership['status'] === OwnershipManifest::SHARED_DECLARED
                ? $this->result(self::GOVERNED_SHARED, 'governed shared file, declared: ' . $ownership['evidence'])
                : $this->result(self::OWNERSHIP_UNKNOWN,
                    'governed shared file, NOT declared by this sprint — ' . $ownership['evidence']);
        }

        if ($classification === FileClassifier::DOCUMENTATION) {
            // Documentation still needs an owner. The c42ea4b incident was docs.
            return OwnershipManifest::isCommittable($ownership['status'])
                ? $this->result(self::DOCUMENTATION, 'documentation owned by this sprint: ' . $ownership['evidence'])
                : $this->externalOrUnknown($path, 'documentation, ' . $ownership['evidence']);
        }

        if (! OwnershipManifest::isCommittable($ownership['status'])) {
            return $this->externalOrUnknown($path, $ownership['evidence']);
        }

        if ($classification === FileClassifier::ABANDONED) {
            return $this->result(self::ABANDONED, $analysis['reason'] ?? 'named or behaving as abandoned work');
        }
        if ($classification === FileClassifier::PARTIAL || $classification === FileClassifier::DEBUGGING) {
            return $this->result(self::OWNED_PARTIAL, 'owned, but ' . ($analysis['reason'] ?? 'incomplete'));
        }

        return $this->result(self::OWNED_COMPLETE, 'owned by this sprint: ' . $ownership['evidence']);
    }

    /**
     * Not mine. Is there positive evidence it belongs to a specific other
     * engineer, or is it simply unattributed?
     *
     * EXTERNALLY_OWNED is a claim and needs support — a matching session test
     * directory or a recent commit naming the subsystem. Without that the honest
     * answer is OWNERSHIP_UNKNOWN, and both are excluded either way. The
     * distinction exists so the coordination queue can say WHO to ask.
     */
    private function externalOrUnknown(string $path, string $evidence): array
    {
        foreach ($this->externalEvidence as $marker => $owner) {
            if (stripos($path, (string) $marker) !== false) {
                return $this->result(self::EXTERNALLY_OWNED,
                    "attributable to {$owner} ({$marker}); not this sprint's to commit");
            }
        }

        return $this->result(self::OWNERSHIP_UNKNOWN, $evidence);
    }

    private function looksSensitive(string $path): bool
    {
        $base = strtolower(basename($path));

        return (bool) preg_match('/(^\.env|cookie|credential|secret|token|\.pem$|\.key$|id_rsa)/', $base);
    }

    /** @return array{category:string, basis:string, committable:bool} */
    private function result(string $category, string $basis): array
    {
        return [
            'category'    => $category,
            'basis'       => $basis,
            'committable' => in_array($category, self::COMMITTABLE, true),
        ];
    }
}
