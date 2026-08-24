<?php

namespace App\Core\Engineer888\Migration;

use Illuminate\Support\Facades\DB;

/**
 * A migration cannot be approved on the strength of a rehearsal of something else.
 *
 * Sprint 10.1 certified the rehearsal; it did not make one compulsory. A
 * candidate could still reach a human with no rehearsal at all, or with one
 * that ran against different bytes, a different database or a different
 * project — and "we rehearsed it" would have been true and meaningless.
 *
 * The binding is therefore over content, not over time. An hour-old rehearsal
 * of the exact bytes is trustworthy; a five-second-old rehearsal of the file as
 * it was before the last edit is not. Elapsed time is a backstop, never the
 * primary test.
 */
final class RehearsalGate
{
    public const VERSION = 'e888-rehearsal-gate-v1';

    // Every way the gate can refuse, named separately. "Migration unsafe" tells
    // an engineer nothing about what to do next.
    public const REHEARSAL_MISSING = 'REHEARSAL_MISSING';
    public const REHEARSAL_STALE = 'REHEARSAL_STALE';
    public const REHEARSAL_FAILED = 'REHEARSAL_FAILED';
    public const MIGRATION_BYTES_CHANGED = 'MIGRATION_BYTES_CHANGED';
    public const TARGET_ASSIGNMENT_CHANGED = 'TARGET_ASSIGNMENT_CHANGED';
    public const RECOVERY_PLAN_MISSING = 'RECOVERY_PLAN_MISSING';
    public const CLASSIFICATION_UNKNOWN = 'CLASSIFICATION_UNKNOWN';
    public const EVIDENCE_INCOMPLETE = 'EVIDENCE_INCOMPLETE';

    /**
     * A rehearsal older than this is re-run even when nothing appears to have
     * changed. Not the main defence — the content binding is — but the platform
     * schema moves underneath us and a day-old baseline may describe a database
     * that no longer exists in that shape.
     */
    public const MAX_AGE_HOURS = 24;

    /** @param array<string,string> $migrationFiles path => exact proposed bytes */
    public function evaluate(
        array $migrationFiles,
        string $candidateUuid,
        string $projectKey,
        string $assignedDatabase,
    ): array {
        if ($migrationFiles === []) {
            return ['required' => false, 'permitted' => true, 'migrations' => [], 'refusals' => []];
        }

        $migrations = [];
        $refusals = [];

        foreach ($migrationFiles as $path => $bytes) {
            $hash = hash('sha256', $bytes);

            $row = DB::table('engineering_migration_rehearsals')
                ->where('migration_path', $path)
                ->where('candidate_uuid', $candidateUuid)
                ->orderByDesc('id')
                ->first();

            [$reason, $detail] = $this->assess($row, $path, $hash, $projectKey, $assignedDatabase);

            $migrations[] = [
                'path'           => $path,
                'content_hash'   => $hash,
                'rehearsal_uuid' => $row->rehearsal_uuid ?? null,
                'verdict'        => $row->status ?? null,
                'classification' => $row->classification ?? null,
                'database'       => $row->database ?? null,
                'rehearsed_at'   => $row->created_at ?? null,
                'permitted'      => $reason === null,
                'refusal'        => $reason,
                'detail'         => $detail,
                'evidence_fingerprint' => $row->evidence_fingerprint ?? null,
                'recovery_plan_fingerprint' => $row->recovery_plan_fingerprint ?? null,
            ];

            if ($reason !== null) { $refusals[] = ['path' => $path, 'reason' => $reason, 'detail' => $detail]; }
        }

        return [
            'required'   => true,
            'permitted'  => $refusals === [],
            'migrations' => $migrations,
            'refusals'   => $refusals,
        ];
    }

    /** @return array{0:?string,1:string} */
    private function assess(?object $row, string $path, string $hash, string $projectKey, string $database): array
    {
        if ($row === null) {
            return [self::REHEARSAL_MISSING,
                "{$path} has never been rehearsed for this candidate. A migration is approved on "
                . 'evidence that these exact bytes survive up, down and up again in isolation.'];
        }

        // Content first. This is the test that matters; everything else is
        // environment.
        if (! hash_equals((string) $row->migration_hash, $hash)) {
            return [self::MIGRATION_BYTES_CHANGED,
                "{$path} has changed since it was rehearsed (rehearsed "
                . substr((string) $row->migration_hash, 0, 12) . ', holding ' . substr($hash, 0, 12)
                . '). The evidence describes a different file.'];
        }

        if ((string) $row->status !== MigrationRehearsal::PASSED) {
            return [self::REHEARSAL_FAILED,
                "{$path} rehearsed as {$row->status}" . ($row->status === MigrationRehearsal::BLOCKED_EVIDENCE
                    ? ' — the schema evidence could not be collected, so nothing was proved'
                    : '')];
        }

        if ((string) $row->project_key !== $projectKey) {
            return [self::TARGET_ASSIGNMENT_CHANGED,
                "{$path} was rehearsed for project {$row->project_key}, not {$projectKey}"];
        }

        if ((string) $row->database !== $database) {
            return [self::TARGET_ASSIGNMENT_CHANGED,
                "{$path} was rehearsed against {$row->database}; this session is assigned {$database}"];
        }

        if (in_array((string) $row->classification, [MigrationClassifier::UNKNOWN, ''], true)) {
            return [self::CLASSIFICATION_UNKNOWN,
                "{$path} could not be classified, so whether it can be undone is unknown. A human must "
                . 'decide that explicitly rather than inherit it from a gate.'];
        }

        if (trim((string) $row->recovery_plan) === '' || $row->recovery_plan_fingerprint === null) {
            return [self::RECOVERY_PLAN_MISSING,
                "{$path} has no recorded recovery plan"];
        }

        $evidence = json_decode((string) $row->evidence, true) ?: [];
        $schema = $evidence['schema'] ?? [];

        foreach (['baseline', 'after_up', 'after_down', 'after_second_up'] as $key) {
            if (empty($schema[$key]) || $schema[$key] === hash('sha256', '')) {
                return [self::EVIDENCE_INCOMPLETE,
                    "{$path}: the {$key} schema fingerprint is missing or empty. An empty fingerprint is "
                    . 'the absence of evidence, not evidence of no change.'];
            }
        }

        if (($evidence['target_proof']['proven'] ?? false) !== true) {
            return [self::EVIDENCE_INCOMPLETE,
                "{$path}: the rehearsal did not prove which database it was talking to"];
        }

        if ($schema['baseline'] === $schema['after_up']) {
            return [self::EVIDENCE_INCOMPLETE,
                "{$path}: the schema did not change, so up() proved nothing"];
        }

        if ($schema['baseline'] !== $schema['after_down']) {
            return [self::REHEARSAL_FAILED, "{$path}: down() did not restore the baseline"];
        }

        if ($schema['after_up'] !== $schema['after_second_up']) {
            return [self::REHEARSAL_FAILED, "{$path}: re-applying produced a different schema"];
        }

        $age = time() - strtotime((string) $row->created_at);
        if ($age > self::MAX_AGE_HOURS * 3600) {
            return [self::REHEARSAL_STALE,
                "{$path} was rehearsed " . round($age / 3600) . 'h ago; the platform schema may have moved '
                . 'underneath the baseline since'];
        }

        return [null, 'rehearsed PASSED for these exact bytes'];
    }

    /**
     * What the approval binding must cover for a migration.
     *
     * Returned separately so ApprovalBinding stays a value object and this class
     * stays the only place that knows what a rehearsal means.
     *
     * @return array<int,string>
     */
    public static function bindingParts(array $evaluation): array
    {
        $parts = [];

        foreach ($evaluation['migrations'] ?? [] as $migration) {
            $parts[] = implode(':', [
                'migration',
                $migration['path'],
                $migration['content_hash'],
                (string) $migration['rehearsal_uuid'],
                (string) $migration['verdict'],
                (string) $migration['classification'],
                (string) $migration['evidence_fingerprint'],
                (string) $migration['recovery_plan_fingerprint'],
            ]);
        }

        sort($parts);

        return $parts;
    }
}
