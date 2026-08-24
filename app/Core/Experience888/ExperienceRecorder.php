<?php

namespace App\Core\Experience888;

use Illuminate\Support\Facades\DB;

/**
 * EXPERIENCE888 — event and outcome capture.
 *
 * Every row is workspace-scoped and carries provenance: which authoritative
 * record proves it happened. Nothing is recorded because the model said so.
 *
 * Capture is idempotent. The ingestor re-reads the same authoritative tables on
 * every pass, so a dedupe key derived from (workspace, type, evidence) is what
 * stops one completed task becoming five observations and inventing a pattern.
 */
final class ExperienceRecorder
{
    // Owner-driven
    public const OWNER_REQUEST       = 'OWNER_REQUEST';
    public const OWNER_APPROVAL      = 'OWNER_APPROVAL';
    public const OWNER_REJECTION     = 'OWNER_REJECTION';
    public const OWNER_CORRECTION    = 'OWNER_CORRECTION';
    public const OWNER_OVERRIDE      = 'OWNER_OVERRIDE';
    // Execution
    public const TASK_CREATED        = 'TASK_CREATED';
    public const TASK_COMPLETED      = 'TASK_COMPLETED';
    public const TASK_FAILED         = 'TASK_FAILED';
    public const TASK_RETRIED        = 'TASK_RETRIED';
    public const CONTENT_PUBLISHED   = 'CONTENT_PUBLISHED';
    public const CAMPAIGN_EXECUTED   = 'CAMPAIGN_EXECUTED';
    public const INCIDENT_OPENED     = 'INCIDENT_OPENED';
    public const INCIDENT_RECOVERED  = 'INCIDENT_RECOVERED';
    // Sarah-driven
    public const RECOMMENDATION_ISSUED   = 'RECOMMENDATION_ISSUED';
    public const RECOMMENDATION_ACCEPTED = 'RECOMMENDATION_ACCEPTED';
    public const RECOMMENDATION_REJECTED = 'RECOMMENDATION_REJECTED';
    public const PLAYBOOK_APPLIED        = 'PLAYBOOK_APPLIED';
    public const COMMITMENT_MADE         = 'COMMITMENT_MADE';

    public const EVENT_TYPES = [
        self::OWNER_REQUEST, self::OWNER_APPROVAL, self::OWNER_REJECTION,
        self::OWNER_CORRECTION, self::OWNER_OVERRIDE,
        self::TASK_CREATED, self::TASK_COMPLETED, self::TASK_FAILED, self::TASK_RETRIED,
        self::CONTENT_PUBLISHED, self::CAMPAIGN_EXECUTED,
        self::INCIDENT_OPENED, self::INCIDENT_RECOVERED,
        self::RECOMMENDATION_ISSUED, self::RECOMMENDATION_ACCEPTED,
        self::RECOMMENDATION_REJECTED, self::PLAYBOOK_APPLIED, self::COMMITMENT_MADE,
    ];

    // Attribution ladder. Strength ascends; never report above what is proven.
    public const ATTR_UNKNOWN     = 'UNKNOWN';
    public const ATTR_OBSERVED    = 'OBSERVED';
    public const ATTR_CORRELATED  = 'CORRELATED';
    public const ATTR_LIKELY      = 'LIKELY_CONTRIBUTOR';
    public const ATTR_DIRECT      = 'DIRECTLY_ATTRIBUTABLE';

    public const ATTRIBUTION_LEVELS = [
        self::ATTR_UNKNOWN, self::ATTR_OBSERVED, self::ATTR_CORRELATED,
        self::ATTR_LIKELY, self::ATTR_DIRECT,
    ];

    /**
     * Record an event. Returns the row id, or the existing id if already seen.
     *
     * @throws \InvalidArgumentException on an unknown type or a missing workspace,
     *         because a mis-typed event silently becomes evidence otherwise.
     */
    public function recordEvent(int $wsId, string $type, array $a): int
    {
        if ($wsId <= 0) throw new \InvalidArgumentException('Experience888: workspace_id is required');
        if (!in_array($type, self::EVENT_TYPES, true))
            throw new \InvalidArgumentException("Experience888: unknown event_type {$type}");
        if (empty($a['evidence_type']) || empty($a['evidence_id']))
            throw new \InvalidArgumentException("Experience888: {$type} needs evidence_type and evidence_id");

        $occurred = $a['occurred_at'] ?? now();
        $dedupe = $a['dedupe_key'] ?? self::dedupe($wsId, $type, $a['evidence_type'], (string) $a['evidence_id']);

        $existing = DB::table('experience_events')->where('dedupe_key', $dedupe)->value('id');
        if ($existing) return (int) $existing;

        return (int) DB::table('experience_events')->insertGetId([
            'workspace_id'      => $wsId,
            'event_type'        => $type,
            'occurred_at'       => $occurred,
            'actor_type'        => $a['actor_type'] ?? 'system',
            'actor_id'          => $a['actor_id'] ?? null,
            'capability'        => $a['capability'] ?? null,
            'action'            => $a['action'] ?? null,
            'entity_type'       => $a['entity_type'] ?? null,
            'entity_id'         => isset($a['entity_id']) ? (string) $a['entity_id'] : null,
            'conversation_id'   => $a['conversation_id'] ?? null,
            'execution_id'      => $a['execution_id'] ?? null,
            'task_id'           => $a['task_id'] ?? null,
            'proposal_id'       => $a['proposal_id'] ?? null,
            'commitment_id'     => $a['commitment_id'] ?? null,
            'source_message_id' => $a['source_message_id'] ?? null,
            'evidence_type'     => $a['evidence_type'],
            'evidence_id'       => (string) $a['evidence_id'],
            'payload_json'      => isset($a['payload']) ? json_encode($a['payload']) : null,
            'dedupe_key'        => $dedupe,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    /**
     * Record what resulted from an event.
     *
     * attribution_basis is mandatory in spirit: if the caller cannot say WHY the
     * level is what it is, the level is UNKNOWN. This is the guard against the
     * "traffic rose afterwards, therefore we caused it" failure.
     */
    public function recordOutcome(int $wsId, int $eventId, string $outcomeType, array $a = []): int
    {
        if ($wsId <= 0) throw new \InvalidArgumentException('Experience888: workspace_id is required');

        $level = strtoupper($a['attribution_level'] ?? self::ATTR_UNKNOWN);
        if (!in_array($level, self::ATTRIBUTION_LEVELS, true))
            throw new \InvalidArgumentException("Experience888: unknown attribution_level {$level}");

        // An event must belong to the same workspace as its outcome. Without
        // this, a caller could staple workspace B's result onto workspace A.
        $owner = DB::table('experience_events')->where('id', $eventId)->value('workspace_id');
        if ($owner === null) throw new \InvalidArgumentException("Experience888: event {$eventId} not found");
        if ((int) $owner !== $wsId)
            throw new \RuntimeException("Experience888: cross-workspace outcome refused (event {$eventId} belongs to {$owner}, not {$wsId})");

        if ($level !== self::ATTR_UNKNOWN && empty($a['attribution_basis']))
            throw new \InvalidArgumentException('Experience888: attribution above UNKNOWN requires attribution_basis');

        $dedupe = $a['dedupe_key'] ?? self::dedupe($wsId, "OUT:{$outcomeType}",
            $a['evidence_type'] ?? 'event', (string) ($a['evidence_id'] ?? $eventId));

        $existing = DB::table('experience_outcomes')->where('dedupe_key', $dedupe)->value('id');
        if ($existing) return (int) $existing;

        return (int) DB::table('experience_outcomes')->insertGetId([
            'workspace_id'      => $wsId,
            'event_id'          => $eventId,
            'outcome_type'      => $outcomeType,
            'observed_at'       => $a['observed_at'] ?? now(),
            'attribution_level' => $level,
            'attribution_basis' => $a['attribution_basis'] ?? null,
            'value_json'        => isset($a['value']) ? json_encode($a['value']) : null,
            'evidence_type'     => $a['evidence_type'] ?? 'experience_events',
            'evidence_id'       => (string) ($a['evidence_id'] ?? $eventId),
            'status'            => $a['status'] ?? 'resolved',
            'dedupe_key'        => $dedupe,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public static function dedupe(int $wsId, string $type, string $evidenceType, string $evidenceId): string
    {
        return substr(hash('sha256', "{$wsId}|{$type}|{$evidenceType}|{$evidenceId}"), 0, 48);
    }
}
