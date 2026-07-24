<?php

namespace App\Engines\Infrastructure\Services;

use App\Engines\Infrastructure\Models\InfraAsset;
use App\Engines\Infrastructure\Models\InfraIncident;
use App\Engines\Infrastructure\Models\InfraIncidentTransition;
use App\Engines\Infrastructure\Models\InfraMonitorCheck;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * First-class incident lifecycle (Phase 3B).
 *
 * The one incident authority. Monitoring, and every future provider integration,
 * opens and drives incidents through THIS service — never a per-source variant.
 *
 * Every state change is validated against the lifecycle graph, timestamped,
 * actor-attributed and recorded as an append-only transition. Resolving computes
 * time-to-resolve, which feeds MTTR. All workspace-scoped.
 */
class IncidentService
{
    public function __construct(
        private readonly InfraEventRecorder $events,
    ) {
    }

    /** Open a new incident on an asset (lands in `detected`). */
    public function open(
        InfraAsset $asset,
        string $title,
        string $severity = InfraIncident::SEV_MAJOR,
        string $source = 'monitoring',
        ?string $sourceRef = null,
        ?string $cause = null,
        ?int $actorUserId = null,
        string $actorType = 'monitoring'
    ): InfraIncident {
        $incident = DB::transaction(function () use ($asset, $title, $severity, $source, $sourceRef, $cause, $actorUserId, $actorType) {
            $incident = InfraIncident::create([
                'incident_uid'    => (string) Str::uuid(),
                'workspace_id'    => $asset->workspace_id,
                'asset_id'        => $asset->id,
                'title'           => $title,
                'severity'        => $severity,
                'lifecycle_state' => InfraIncident::STATE_DETECTED,
                'source'          => $source,
                'source_ref'      => $sourceRef,
                'cause'           => $cause,
                'detected_at'     => now(),
            ]);

            $this->recordTransition($incident, null, InfraIncident::STATE_DETECTED, $actorUserId, $actorType, $cause);

            // The asset reflects an active incident in its health.
            $asset->update(['health_state' => InfraAsset::HEALTH_DOWN, 'health_checked_at' => now()]);

            return $incident;
        });

        $this->events->record(
            workspaceId: $asset->workspace_id,
            event: 'incident_detected',
            ownerType: 'infra_asset',
            ownerId: $asset->id,
            severity: 'error',
            toState: InfraIncident::STATE_DETECTED,
            summary: "Incident detected on '{$asset->name}': {$title}",
            context: ['incident_uid' => $incident->incident_uid, 'severity' => $severity, 'source' => $source],
            actorUserId: $actorUserId,
            source: $actorType,
        );

        return $incident;
    }

    /**
     * Move an incident to a new lifecycle state. Illegal transitions throw.
     * Sets the matching timestamp and computes time-to-resolve on resolve.
     */
    public function transition(
        InfraIncident $incident,
        string $toState,
        ?int $actorUserId = null,
        string $actorType = 'user',
        ?string $note = null
    ): InfraIncident {
        $from = $incident->lifecycle_state;
        $legal = InfraIncident::transitions()[$from] ?? [];

        if (!in_array($toState, $legal, true)) {
            throw new InvalidArgumentException(
                "Illegal incident transition '{$from}' -> '{$toState}'. Legal: " . implode(', ', $legal ?: ['(terminal)'])
            );
        }

        DB::transaction(function () use ($incident, $from, $toState, $actorUserId, $actorType, $note) {
            $patch = ['lifecycle_state' => $toState];

            match ($toState) {
                InfraIncident::STATE_ACKNOWLEDGED => $patch += ['acknowledged_at' => now(), 'acknowledged_by' => $actorUserId],
                InfraIncident::STATE_MITIGATED    => $patch += ['mitigated_at' => now()],
                InfraIncident::STATE_RESOLVED     => $patch += [
                    'resolved_at' => now(),
                    'resolved_by' => $actorUserId,
                    'time_to_resolve_seconds' => max(0, (int) $incident->detected_at->diffInSeconds(now())),
                ],
                InfraIncident::STATE_CLOSED       => $patch += ['closed_at' => now()],
                default                           => null,
            };

            $incident->update($patch);
            $this->recordTransition($incident, $from, $toState, $actorUserId, $actorType, $note);

            // On resolve, if the asset has no other open incidents, restore health.
            if ($toState === InfraIncident::STATE_RESOLVED) {
                $stillOpen = InfraIncident::where('asset_id', $incident->asset_id)
                    ->whereIn('lifecycle_state', InfraIncident::openStates())
                    ->where('id', '!=', $incident->id)->exists();

                if (!$stillOpen && $incident->asset) {
                    $incident->asset->update(['health_state' => InfraAsset::HEALTH_HEALTHY, 'health_checked_at' => now()]);
                }
            }
        });

        $this->events->record(
            workspaceId: $incident->workspace_id,
            event: 'incident_' . $toState,
            ownerType: 'infra_asset',
            ownerId: $incident->asset_id,
            severity: $toState === InfraIncident::STATE_RESOLVED ? 'success' : 'info',
            fromState: $from,
            toState: $toState,
            summary: "Incident {$incident->incident_uid}: {$from} -> {$toState}",
            context: array_filter(['note' => $note]),
            actorUserId: $actorUserId,
            source: $actorType,
        );

        return $incident->fresh();
    }

    public function acknowledge(InfraIncident $i, int $actorUserId, ?string $note = null): InfraIncident
    {
        return $this->transition($i, InfraIncident::STATE_ACKNOWLEDGED, $actorUserId, 'user', $note);
    }

    public function resolve(InfraIncident $i, ?int $actorUserId = null, string $actorType = 'system', ?string $note = null): InfraIncident
    {
        return $this->transition($i, InfraIncident::STATE_RESOLVED, $actorUserId, $actorType, $note);
    }

    // ── monitoring integration ──────────────────────────────────────────────

    /**
     * Open (or reuse) a monitoring incident for a down check. Idempotent: if an
     * open monitoring incident already exists for this check, it is returned
     * rather than duplicated.
     */
    public function openForMonitorCheck(InfraMonitorCheck $check, ?string $cause = null): ?InfraIncident
    {
        if (!$check->asset_id) {
            return null; // check not attached to the graph yet
        }

        $existing = InfraIncident::where('asset_id', $check->asset_id)
            ->where('source', 'monitoring')
            ->where('source_ref', (string) $check->id)
            ->whereIn('lifecycle_state', InfraIncident::openStates())
            ->first();

        if ($existing) {
            return $existing;
        }

        $asset = InfraAsset::find($check->asset_id);
        if (!$asset) {
            return null;
        }

        return $this->open(
            $asset,
            "Target unreachable: {$check->name}",
            InfraIncident::SEV_MAJOR,
            'monitoring',
            (string) $check->id,
            $cause,
            null,
            'monitoring'
        );
    }

    /** Resolve the open monitoring incident for a recovered check, if any. */
    public function resolveForMonitorCheck(InfraMonitorCheck $check): ?InfraIncident
    {
        if (!$check->asset_id) {
            return null;
        }

        $open = InfraIncident::where('asset_id', $check->asset_id)
            ->where('source', 'monitoring')
            ->where('source_ref', (string) $check->id)
            ->whereIn('lifecycle_state', InfraIncident::openStates())
            ->orderByDesc('id')->first();

        return $open ? $this->resolve($open, null, 'monitoring', 'Target recovered.') : null;
    }

    private function recordTransition(
        InfraIncident $incident,
        ?string $from,
        string $to,
        ?int $actorUserId,
        string $actorType,
        ?string $note
    ): void {
        InfraIncidentTransition::create([
            'workspace_id'  => $incident->workspace_id,
            'incident_id'   => $incident->id,
            'from_state'    => $from,
            'to_state'      => $to,
            'actor_user_id' => $actorUserId,
            'actor_type'    => $actorType,
            'note'          => $note,
            'created_at'    => now(),
        ]);
    }
}
