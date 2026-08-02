<?php

namespace App\Core\Engineer888\Deployment;

use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Repository\ArchitectureMap;
use App\Core\Engineer888\Repository\WorkingTree;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Answers one question: did the intended release reach this environment, and is
 * the application behaving correctly afterwards?
 *
 * Those are two questions, and the whole design turns on keeping them apart. A
 * healthy application proves the application is healthy. It proves nothing at
 * all about which code is running. On a platform where deployment is a file copy
 * into a working tree that carries hundreds of uncommitted files, the second
 * question usually has no answer — and the correct output is
 * HEALTHY_IDENTITY_UNPROVEN, not a success with an asterisk.
 *
 * Read-only. Nothing here writes to the application, runs a migration, restarts
 * a service or rolls anything back.
 */
final class DeploymentVerifier
{
    public const VERIFIED = 'VERIFIED';
    public const HEALTHY_IDENTITY_UNPROVEN = 'HEALTHY_IDENTITY_UNPROVEN';
    public const DEGRADED = 'DEGRADED';
    public const FAILED = 'FAILED';
    public const BLOCKED = 'BLOCKED';

    /**
     * Checks whose failure means the application is materially broken rather
     * than merely degraded.
     */
    private const CRITICAL_CHECKS = [
        'http:public site', 'http:application',
        'database:connectivity', 'routes:resolve', 'routes:controllers',
        'config:required-keys',
    ];

    public function __construct(private string $repoPath) {}

    /** @return array<string,mixed> */
    public function verify(string $environment = 'production', ?string $intentUuid = null, bool $persist = true): array
    {
        $started = microtime(true);
        $uuid = (string) Str::uuid();
        $manifest = OwnershipManifest::active($this->repoPath);

        // ── intent ───────────────────────────────────────────────────────
        $intents = new DeploymentIntent($this->repoPath);
        $intent = $intents->find($intentUuid, $environment);

        if ($intentUuid !== null && $intent === null) {
            return $this->blocked($uuid, $environment,
                "no deployment intent exists with uuid {$intentUuid}", $started);
        }

        // ── observed identity ────────────────────────────────────────────
        $identityObjects = (new ReleaseIdentity($this->repoPath))->collect();
        $identity = array_map(fn (Evidence $e) => $e->toArray(), $identityObjects);

        $comparison = (new ReleaseIdentity($this->repoPath))->compareWithIntent($identityObjects, $intent);

        // ── change-scoped check selection ────────────────────────────────
        $families = $this->relevantFamilies();

        // ── smoke checks ─────────────────────────────────────────────────
        $checks = (new SmokeChecks($this->repoPath))->run($families);

        // ── baseline ─────────────────────────────────────────────────────
        $baselineComparison = new BaselineComparison();
        $baseline = $baselineComparison->baselineFor($environment);
        $regression = $baselineComparison->compare($identity, $checks, $baseline);

        // ── verdict ──────────────────────────────────────────────────────
        [$verdict, $reason, $failures] = $this->decide($checks, $comparison, $identity);

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $record = [
            'uuid'           => $uuid,
            'intent_id'      => $intent->id ?? null,
            'environment'    => $environment,
            'verdict'        => $verdict,
            'verdict_reason' => $reason,
            'identity'       => json_encode($identity),
            'checks'         => json_encode($checks),
            'failures'       => json_encode($failures),
            'comparison'     => json_encode(['intent' => $comparison, 'baseline' => $regression]),
            'baseline_id'    => $baseline->id ?? null,
            'session'        => $manifest?->session(),
            'duration_ms'    => $durationMs,
            'created_at'     => now(),
            'updated_at'     => now(),
        ];

        $id = null;
        if ($persist) {
            try {
                $id = DB::table('engineering_deployment_verifications')->insertGetId($record);
            } catch (\Throwable $e) {
                // A logging failure must never be silent, and must never turn a
                // real verdict into an exception the caller loses.
                $reason .= ' [WARNING: this verification could not be persisted: '
                        . substr($e->getMessage(), 0, 120) . ']';
            }
        }

        return [
            'id'             => $id,
            'uuid'           => $uuid,
            'environment'    => $environment,
            'verdict'        => $verdict,
            'reason'         => $reason,
            'intent'         => $intent,
            'identity'       => $identity,
            'identity_check' => $comparison,
            'checks'         => $checks,
            'failures'       => $failures,
            'baseline'       => $regression,
            'families'       => $families,
            'duration_ms'    => $durationMs,
            'session'        => $manifest?->session(),
        ];
    }

    /**
     * Which check families the current changed set makes relevant.
     *
     * Focused checks are better than exhaustive ones nobody reads — but a
     * focused pass must never be reported as full certification, so the caller
     * is given the family list to display alongside the verdict.
     *
     * @return array<int,string>
     */
    public function relevantFamilies(): array
    {
        $tree = WorkingTree::read($this->repoPath);
        if (! ($tree['available'] ?? false)) { return []; }

        $map = new ArchitectureMap($this->repoPath);
        $families = [];

        foreach ($tree['files'] as $file) {
            $families[match ($map->layer($file['path'])) {
                'routing', 'bootstrap', 'provider', 'middleware' => 'routing',
                'migration', 'seeder', 'factory'                 => 'migration',
                'job', 'console', 'command'                      => 'queue',
                'config'                                         => 'configuration',
                'frontend', 'public-asset', 'view'               => 'http',
                'core', 'engine', 'service', 'controller', 'model' => 'http',
                default                                          => 'platform',
            }] = true;
        }

        // Always relevant: if these are broken nothing else matters.
        $families['database'] = true;
        $families['errors'] = true;

        $out = array_keys($families);
        sort($out);

        return $out;
    }

    /**
     * @return array{0:string, 1:string, 2:array<int,array<string,mixed>>}
     */
    private function decide(array $checks, array $comparison, array $identity): array
    {
        $failures = [];
        $unknowns = [];

        foreach ($checks as $check) {
            if ($check['status'] === SmokeChecks::FAIL) { $failures[] = $check; }
            if ($check['status'] === SmokeChecks::UNKNOWN) { $unknowns[] = $check; }
        }

        $criticalFailures = array_values(array_filter($failures,
            fn ($check) => in_array($check['name'], self::CRITICAL_CHECKS, true)));

        // ── materially broken ────────────────────────────────────────────
        if ($criticalFailures !== []) {
            return [self::FAILED,
                'a critical check failed: ' . implode(', ', array_column($criticalFailures, 'name')),
                $failures];
        }

        // ── cannot see enough to judge ───────────────────────────────────
        $criticalUnknowns = array_values(array_filter($unknowns,
            fn ($check) => in_array($check['name'], self::CRITICAL_CHECKS, true)));

        if ($criticalUnknowns !== []) {
            return [self::BLOCKED,
                'required evidence is unavailable: ' . implode(', ', array_column($criticalUnknowns, 'name'))
                . '. A verdict would be a guess.',
                $failures];
        }

        // ── operational, but something material failed ───────────────────
        if ($failures !== []) {
            return [self::DEGRADED,
                count($failures) . ' non-critical check(s) failed: ' . implode(', ', array_column($failures, 'name')),
                $failures];
        }

        // ── healthy. now: can identity be proven? ────────────────────────
        if (! ($comparison['identity_proven'] ?? false)) {
            return [self::HEALTHY_IDENTITY_UNPROVEN,
                'every check passed, but the deployed release cannot be identified — '
                . ($comparison['reason'] ?? 'no intent recorded')
                . ' A healthy application does not prove which code is running.',
                $failures];
        }

        return [self::VERIFIED,
            'the observed release matches the recorded intent and every check passed.',
            $failures];
    }

    private function blocked(string $uuid, string $environment, string $reason, float $started): array
    {
        return [
            'id' => null, 'uuid' => $uuid, 'environment' => $environment,
            'verdict' => self::BLOCKED, 'reason' => $reason,
            'intent' => null, 'identity' => [], 'identity_check' => [],
            'checks' => [], 'failures' => [], 'baseline' => [], 'families' => [],
            'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            'session' => null,
        ];
    }

    /**
     * What to do when verification does not pass.
     *
     * Guidance only. This sprint does not execute rollback, and where no
     * rollback procedure exists the honest output says so rather than inventing
     * a plausible-looking one.
     *
     * @return array<string,mixed>
     */
    public function guidance(array $result): array
    {
        if (in_array($result['verdict'], [self::VERIFIED, self::HEALTHY_IDENTITY_UNPROVEN], true)) {
            return ['required' => false];
        }

        $baseline = (new BaselineComparison())->baselineFor($result['environment']);
        $regressions = $result['baseline']['regressions'] ?? [];

        // A check failing in the baseline too is not this release's doing.
        $preExisting = [];
        $introduced = [];
        foreach ($result['failures'] as $failure) {
            $wasRegression = false;
            foreach ($regressions as $regression) {
                if (($regression['check'] ?? null) === $failure['name']) { $wasRegression = true; break; }
            }
            $wasRegression ? $introduced[] = $failure['name'] : $preExisting[] = $failure['name'];
        }

        return [
            'required'          => true,
            'failing_checks'    => array_column($result['failures'], 'name'),
            'evidence_sources'  => array_values(array_unique(array_column($result['failures'], 'family'))),
            'introduced_now'    => $introduced,
            'pre_existing'      => $preExisting,
            'attribution_note'  => $introduced === []
                ? 'no failing check regressed against the baseline, so these failures are not evidence that '
                . 'this release caused them'
                : 'these checks passed in the baseline and fail now',
            'last_known_good'   => $baseline !== null
                ? ['id' => $baseline->id, 'at' => $baseline->created_at, 'verdict' => $baseline->verdict]
                : null,
            'rollback'          => $this->rollbackPosition(),
        ];
    }

    /** @return array<string,mixed> */
    private function rollbackPosition(): array
    {
        // Stated from what is true of this platform, not from a template.
        return [
            'available' => false,
            'reason'    => 'no rollback procedure is proven for this environment. Deployment happens by direct '
                         . 'file change into a working tree with hundreds of uncommitted files, so there is no '
                         . 'released artifact to revert to and `git checkout` would discard uncommitted '
                         . 'production code.',
            'what_exists' => [
                'nightly database dump at 01:00 UTC in /root/backups (verified usable on 2026-07-30)',
                'DigitalOcean droplet snapshot at 02:00 UTC',
                'per-change backups under storage/app/ads-backups for files Engineer888 edited',
            ],
            'not_proven' => 'no code-level rollback has been performed or tested on this platform',
        ];
    }
}
