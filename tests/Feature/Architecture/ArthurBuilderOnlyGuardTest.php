<?php

namespace Tests\Feature\Architecture;

use Tests\TestCase;

/**
 * WAVE 0-1 · ARTHUR-is-Builder-only ownership guard.
 *
 * Fails if the "Arthur" AI persona (a system-prompt ROLE, "You are Arthur …")
 * appears in any production file that is NOT (a) a Builder-owned path, or (b) a
 * KNOWN legacy violation registered below with owner + remediation wave.
 * Purpose: prevent FURTHER Arthur-persona spread while the known drift is
 * characterized (not fixed) in this wave. Ordinary user-facing copy that merely
 * mentions the name "Arthur" is NOT matched — only the persona role string.
 */
class ArthurBuilderOnlyGuardTest extends TestCase
{
    /** Builder is the constitutional home of Arthur. */
    private const ALLOWED_PREFIXES = ['app/Engines/Builder/'];

    /**
     * KNOWN legacy violations — characterized here, corrected in a later
     * Restoration wave. file (repo-relative) => [owner, remediation].
     */
    private const KNOWN_LEGACY = [
        'app/Core/ImageIntelligence/ImageReasoningService.php' => 'Studio image reasoning; move to Creative888 (Restoration Wave 7)',
        'app/Engines/Studio/Services/StudioAiService.php'      => 'Studio design/AI-edit reasoning; move concept/copy to Creative888 (Restoration Wave 7)',
    ];

    /** Every production file that currently uses the "You are Arthur" persona role. */
    private function personaFiles(): array
    {
        $hits = [];
        $rii = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(base_path('app'), \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($rii as $file) {
            if ($file->getExtension() !== 'php') continue;
            $rel = str_replace(base_path() . '/', '', str_replace('\\', '/', $file->getPathname()));
            if (str_contains($rel, '.bak')) continue;
            $src = file_get_contents($file->getPathname());
            if (preg_match('/You are Arthur\b/i', $src)) $hits[] = $rel;
        }
        sort($hits);
        return $hits;
    }

    public function test_no_arthur_persona_outside_builder_or_known_legacy(): void
    {
        $offenders = [];
        foreach ($this->personaFiles() as $rel) {
            $allowed = false;
            foreach (self::ALLOWED_PREFIXES as $p) {
                if (str_starts_with($rel, $p)) { $allowed = true; break; }
            }
            if ($allowed) continue;
            if (array_key_exists($rel, self::KNOWN_LEGACY)) continue; // characterized legacy
            $offenders[] = $rel;
        }
        $this->assertSame([], $offenders,
            "New non-Builder 'Arthur' AI persona introduced — constitutional violation (Arthur is Builder-only). "
            . "Offending file(s): " . implode(', ', $offenders));
    }

    public function test_known_legacy_arthur_violations_are_still_registered(): void
    {
        // Keeps the register honest: if a legacy violation's persona is removed
        // (by a real remediation wave), update KNOWN_LEGACY. Guards against
        // silent/rogue removal and confirms the characterized baseline.
        foreach (self::KNOWN_LEGACY as $rel => $note) {
            $path = base_path($rel);
            $this->assertFileExists($path, "$rel registered as legacy but file missing");
            $this->assertMatchesRegularExpression('/You are Arthur\b/i', file_get_contents($path),
                "$rel is registered as an Arthur-persona legacy violation ($note); if the persona is gone, update the register.");
        }
    }
}
