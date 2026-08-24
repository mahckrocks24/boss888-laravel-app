<?php

namespace App\Core\Safety;

use RuntimeException;

/**
 * RSK-M3-1 — keep the CR-22B reconstruction artefact truthful automatically.
 *
 * CR-22B replaced one monolithic routes/api.php with a parent file plus 13
 * required modules. The proof that the split is lossless is a reconstruction:
 * expand every `require __DIR__ . '/api/authenticated/<module>.php';` back into
 * its module body and the result must hash to the recorded value. That value
 * lives in extraction-mapping.json under `source_sha256`.
 *
 * Every approved route change necessarily moves that hash. Until now the
 * artefact was refreshed by hand as a step in each deploy script, which is a
 * step that can be — and at E1 M3 was — forgotten. The reconstruction test then
 * fails against the PREVIOUS milestone's hash, reporting a fault in the module
 * set when the only thing actually stale is the artefact.
 *
 * This class moves that refresh into the governed write path so it cannot be
 * skipped. GovernedWriter calls refresh() after any successful write to a route
 * source.
 *
 * Deliberately NOT wired into the test. RouteModuleScopeInheritanceTest still
 * performs its own independent reconstruction and compares it to the stored
 * value. If this class ever computed the hash wrongly it would write a wrong
 * value and that test would fail — the check stays genuinely independent rather
 * than becoming a tautology.
 */
final class ReconstructionManifest
{
    /** The governed CR-22B artefact directory. */
    public const ARTIFACT = '/root/cr22-baseline-20260727/extraction-mapping.json';

    /** Marks the start of the verbatim body inside every extracted module. */
    private const MARK = "// ==== CR-22B MODULE BODY BEGINS - verbatim from routes/api.php, do not edit ====\n";

    private const PARENT = 'routes/api.php';

    public function __construct(private string $artifact = self::ARTIFACT) {}

    /**
     * Does writing this path change the reconstruction?
     *
     * Only the parent file and the extracted modules do. routes/web.php,
     * routes/exec-api.php, bootstrap/app.php and the CRM route file are
     * protected paths but play no part in the CR-22B reconstruction, so a write
     * to any of them must not touch the artefact.
     */
    public static function isRouteSource(string $relPath): bool
    {
        return $relPath === self::PARENT
            || (bool) preg_match('#^routes/api/authenticated/[\w-]+\.php$#', $relPath);
    }

    /**
     * Rebuild the notional pre-extraction file from the live sources and hash it.
     *
     * Mirrors the reconstruction the conformance test performs. Any divergence
     * between the two is caught by that test, not hidden by it.
     *
     * @throws RuntimeException if a module is missing or has lost its marker
     */
    public function reconstruct(): string
    {
        $parent = ProtectedPaths::absolute(self::PARENT);
        if (!is_file($parent)) {
            throw new RuntimeException('cannot reconstruct: ' . self::PARENT . ' is missing');
        }

        $out = [];

        foreach (explode("\n", (string) file_get_contents($parent)) as $line) {
            if (preg_match("#^\s*require __DIR__ \. '/api/authenticated/([\w-]+\.php)';\s*$#", $line, $m)) {
                $abs = ProtectedPaths::absolute('routes/api/authenticated/' . $m[1]);

                if (!is_file($abs)) {
                    throw new RuntimeException("cannot reconstruct: required module {$m[1]} is missing");
                }

                $parts = explode(self::MARK, (string) file_get_contents($abs), 2);
                if (!isset($parts[1])) {
                    throw new RuntimeException("cannot reconstruct: {$m[1]} has lost its module-body marker");
                }

                $body = $parts[1];
                $body = substr($body, -1) === "\n" ? substr($body, 0, -1) : $body;

                foreach (explode("\n", $body) as $l) {
                    $out[] = $l;
                }

                continue;
            }

            if (preg_match('#^\s*// CR-22B: [\w-]+ extracted to routes/api/authenticated/#', $line)) {
                continue;   // the extraction breadcrumb did not exist pre-split
            }

            $out[] = $line;
        }

        return hash('sha256', implode("\n", $out));
    }

    /** The hash currently recorded in the artefact, or null if unreadable. */
    public function stored(): ?string
    {
        $data = $this->read();

        return is_array($data) ? ($data['source_sha256'] ?? null) : null;
    }

    /**
     * Bring the artefact in line with the live sources.
     *
     * Never throws. A governed write has ALREADY succeeded by the time this
     * runs, so failing here must not unwind a completed, correct deployment.
     * Instead the outcome is reported and audited: if the refresh could not
     * happen, behaviour degrades to exactly what it was before this class
     * existed — the reconstruction test fails loudly on the next run. It never
     * degrades to silence.
     *
     * @return array{refreshed:bool, from:?string, to:?string, reason:string}
     */
    public function refresh(string $reason = ''): array
    {
        try {
            $computed = $this->reconstruct();
        } catch (\Throwable $e) {
            return $this->outcome(false, null, null, 'reconstruction failed: ' . $e->getMessage());
        }

        $data = $this->read();
        if (!is_array($data)) {
            return $this->outcome(false, null, $computed, 'artefact missing or unreadable: ' . $this->artifact);
        }

        $stored = $data['source_sha256'] ?? null;
        if ($stored === $computed) {
            return $this->outcome(false, $stored, $computed, 'already current');
        }

        // keep the audit trail of every value this artefact has held
        if ($stored !== null) {
            $data['source_sha256_history'] = array_values(array_merge(
                $data['source_sha256_history'] ?? [],
                [$stored]
            ));
        }
        $data['source_sha256'] = $computed;

        // a module's own hash is part of the same truth claim
        if (isset($data['modules']) && is_array($data['modules'])) {
            foreach ($data['modules'] as $i => $m) {
                if (!isset($m['file'])) {
                    continue;
                }
                $abs = ProtectedPaths::absolute($m['file']);
                if (is_file($abs)) {
                    $data['modules'][$i]['file_sha256'] = hash_file('sha256', $abs);
                }
            }
        }

        if (!$this->write($data)) {
            return $this->outcome(false, $stored, $computed, 'artefact not writable: ' . $this->artifact);
        }

        return $this->outcome(true, $stored, $computed, $reason !== '' ? $reason : 'route source changed');
    }

    /** @return array<string,mixed>|null */
    private function read(): ?array
    {
        if (!is_file($this->artifact) || !is_readable($this->artifact)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($this->artifact), true);

        return is_array($data) ? $data : null;
    }

    /**
     * Replace the artefact atomically, preserving ownership and mode.
     *
     * Same discipline as GovernedWriter: a correct file that the next reader
     * cannot open is not an improvement.
     */
    private function write(array $data): bool
    {
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return false;
        }

        $st = @stat($this->artifact);
        $tmp = $this->artifact . '.refresh-tmp';

        if (@file_put_contents($tmp, $json, LOCK_EX) === false) {
            @unlink($tmp);
            return false;
        }

        if (!@rename($tmp, $this->artifact)) {
            @unlink($tmp);
            return false;
        }

        if ($st !== false) {
            @chown($this->artifact, $st['uid']);
            @chgrp($this->artifact, $st['gid']);
            @chmod($this->artifact, $st['mode'] & 0777);
        }

        clearstatcache(true, $this->artifact);

        return true;
    }

    /** @return array{refreshed:bool, from:?string, to:?string, reason:string} */
    private function outcome(bool $refreshed, ?string $from, ?string $to, string $reason): array
    {
        return ['refreshed' => $refreshed, 'from' => $from, 'to' => $to, 'reason' => $reason];
    }
}
