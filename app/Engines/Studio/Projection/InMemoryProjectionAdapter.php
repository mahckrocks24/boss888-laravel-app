<?php

namespace App\Engines\Studio\Projection;

use App\Engines\Studio\Projection\Contracts\StudioProjectionAdapterInterface;

/**
 * STUDIO888 · Projection — fake in-memory projection adapter (tests + dormant core).
 *
 * Maintains renderer state as a flat field map per element ('style.color', 'text',
 * 'visible', ...). It advertises capabilities, enforces optimistic concurrency
 * (version + before-snapshot hash), negotiates capability, applies supported
 * fields, reads back the ACTUAL state, and supports idempotent replay. It NEVER
 * touches HTML/DOM/renderer/persistence — it is a stand-in for a real renderer.
 */
final class InMemoryProjectionAdapter implements StudioProjectionAdapterInterface
{
    /** @var array<string, array<string,mixed>> elementId => (fieldPath => value) */
    private array $state;

    private ProjectionCapability $capability;

    private int $versionCounter;

    /** @var array<string, array{status:string,result?:ProjectionResult,batchResult?:ProjectionBatchResult}> */
    private array $ledger = [];

    public function __construct(array $state = [], ?ProjectionCapability $capability = null, int $startVersion = 1)
    {
        $this->state = $state;
        $this->capability = $capability ?? ProjectionCapability::referenceDefault();
        $this->versionCounter = $startVersion;
    }

    public function capability(): ProjectionCapability
    {
        return $this->capability;
    }

    public function currentVersion(): StudioDocumentVersion
    {
        return StudioDocumentVersion::of('v' . $this->versionCounter);
    }

    // --- test-simulation helpers (not part of the interface) ---

    public function markInProgress(string $idempotencyKey): void
    {
        $this->ledger[$idempotencyKey] = ['status' => ReplayState::IN_PROGRESS];
    }

    public function expire(string $idempotencyKey): void
    {
        $this->ledger[$idempotencyKey] = ['status' => ReplayState::EXPIRED];
    }

    public function fieldValue(string $targetId, string $path): mixed
    {
        return $this->state[$targetId][$path] ?? null;
    }

    // --- projection ---

    public function project(ProjectionRequest $req): ProjectionResult
    {
        // Idempotency: only successful mutations are cached, so rejected requests may retry.
        if ($req->idempotencyKey !== null && isset($this->ledger[$req->idempotencyKey])) {
            $entry = $this->ledger[$req->idempotencyKey];
            if ($entry['status'] === ReplayState::COMPLETED) {
                return $entry['result']->withReplay(ReplayState::COMPLETED);
            }
            if ($entry['status'] === ReplayState::IN_PROGRESS) {
                return $this->reject($req, ProjectionStatus::REJECTED, ProjectionResult::R_IN_PROGRESS, 'A prior submission with this key is still in progress.', ReplayState::IN_PROGRESS);
            }
            if ($entry['status'] === ReplayState::EXPIRED) {
                return $this->reject($req, ProjectionStatus::REJECTED, ProjectionResult::R_EXPIRED, 'Idempotency record expired; a fresh key is required.', ReplayState::EXPIRED);
            }
        }

        if (! isset($this->state[$req->targetId])) {
            return $this->reject($req, ProjectionStatus::TARGET_MISSING, ProjectionResult::R_TARGET_MISSING, "Target '{$req->targetId}' is not present in the renderer.");
        }

        $curToken = $this->currentVersion()->token;
        if ($req->expectedDocumentVersion !== null && $req->expectedDocumentVersion !== $curToken) {
            return $this->reject($req, ProjectionStatus::STALE_VERSION, ProjectionResult::R_STALE_VERSION, "Expected version {$req->expectedDocumentVersion}, renderer at {$curToken}.");
        }

        if ($req->beforeSnapshotHash !== null) {
            $actualHash = ProjectionRequest::hashState($this->currentValues($req->targetId, $req->changedFields));
            if ($actualHash !== $req->beforeSnapshotHash) {
                return $this->reject($req, ProjectionStatus::STALE_VERSION, ProjectionResult::R_SNAPSHOT_MISMATCH, 'Target snapshot changed since it was read.');
            }
        }

        // Capability negotiation per field.
        $supported = [];
        $unsupported = [];
        foreach ($req->changedFields as $path) {
            $this->capability->supportsField($path) ? $supported[] = $path : $unsupported[] = $path;
        }
        if ($supported === []) {
            return $this->reject($req, ProjectionStatus::UNSUPPORTED, ProjectionResult::R_UNSUPPORTED_PROPERTY, 'No requested field is supported by this renderer.');
        }

        // Apply supported fields; read back actual state.
        $applied = [];
        foreach ($supported as $path) {
            $desired = $req->desiredAfterState[$path] ?? null;
            $before = $this->state[$req->targetId][$path] ?? null;
            $this->state[$req->targetId][$path] = $desired;
            if ($before !== $desired) {
                $applied[] = $path;
            }
        }
        if ($applied !== []) {
            $this->versionCounter++;
        }

        $actualAfter = $this->currentValues($req->targetId, $req->changedFields);

        $matched = 0;
        foreach ($supported as $path) {
            if (($actualAfter[$path] ?? null) === ($req->desiredAfterState[$path] ?? null)) {
                $matched++;
            }
        }
        $verified = $matched === count($supported);

        if ($applied === [] && $unsupported === []) {
            $result = $this->build($req, ProjectionStatus::FAILED, [], $req->changedFields, $actualAfter, $verified, $matched, count($supported), ProjectionResult::R_NO_CHANGE, 'No change (no-op); renderer already in desired state.');
        } elseif (! $verified) {
            $result = $this->build($req, ProjectionStatus::FAILED, $applied, $unsupported, $actualAfter, $verified, $matched, count($supported), ProjectionResult::R_VERIFICATION_FAILED, 'Renderer state did not match desired after apply.');
        } elseif ($unsupported !== []) {
            $result = $this->build($req, ProjectionStatus::PARTIAL, $applied, $unsupported, $actualAfter, $verified, $matched, count($supported), null, 'Applied supported fields; rejected unsupported fields.');
        } else {
            $result = $this->build($req, ProjectionStatus::APPLIED, $applied, [], $actualAfter, $verified, $matched, count($supported), null, 'Applied and verified against renderer state.');
        }

        if ($req->idempotencyKey !== null && $result->isSuccess()) {
            $this->ledger[$req->idempotencyKey] = ['status' => ReplayState::COMPLETED, 'result' => $result];
        }

        return $result;
    }

    public function projectBatch(ProjectionBatch $batch): ProjectionBatchResult
    {
        if ($batch->idempotencyKey !== null && isset($this->ledger['batch:' . $batch->idempotencyKey])) {
            $entry = $this->ledger['batch:' . $batch->idempotencyKey];
            if ($entry['status'] === ReplayState::COMPLETED) {
                return $entry['batchResult']->withReplay(ReplayState::COMPLETED);
            }
        }

        $result = $batch->boundary->atomic
            ? $this->projectAtomic($batch)
            : $this->projectBestEffort($batch);

        if ($batch->idempotencyKey !== null && ProjectionStatus::isSuccess($result->status)) {
            $this->ledger['batch:' . $batch->idempotencyKey] = ['status' => ReplayState::COMPLETED, 'batchResult' => $result];
        }

        return $result;
    }

    private function projectAtomic(ProjectionBatch $batch): ProjectionBatchResult
    {
        $stateBackup = $this->state;
        $verBackup = $this->versionCounter;
        $ledgerBackup = $this->ledger;

        $results = [];
        $failed = false;
        foreach ($batch->requests as $req) {
            $r = $this->project($req);
            $results[] = $r;
            if (! $r->isSuccess()) {
                $failed = true;
                break;
            }
        }

        if ($failed) {
            // Roll back the whole batch: state, version, and idempotency ledger.
            $this->state = $stateBackup;
            $this->versionCounter = $verBackup;
            $this->ledger = $ledgerBackup;

            $rolled = array_map(function (ProjectionResult $r) {
                return $r->isSuccess()
                    ? new ProjectionResult(ProjectionStatus::REJECTED, $r->operationId, $r->targetId, [], $r->rejectedFields, $this->currentVersion()->token, [], [], ProjectionResult::R_ROLLED_BACK, 'Rolled back (atomic batch failure).', 0.0, ReplayState::FIRST, $r->correlationId)
                    : $r;
            }, $results);

            return new ProjectionBatchResult($batch->batchId, ProjectionStatus::FAILED, $rolled, $this->currentVersion()->token, ReplayState::FIRST, $batch->correlationId);
        }

        return new ProjectionBatchResult($batch->batchId, ProjectionStatus::APPLIED, $results, $this->currentVersion()->token, ReplayState::FIRST, $batch->correlationId);
    }

    private function projectBestEffort(ProjectionBatch $batch): ProjectionBatchResult
    {
        $results = [];
        $stopped = false;
        foreach ($batch->requests as $req) {
            if ($stopped) {
                $results[] = new ProjectionResult(ProjectionStatus::REJECTED, $req->operationId, $req->targetId, [], $req->changedFields, $this->currentVersion()->token, [], [], ProjectionResult::R_SKIPPED, 'Skipped after an earlier failure (stop-on-first-failure).', 0.0, ReplayState::FIRST, $req->correlationId);
                continue;
            }
            $r = $this->project($req);
            $results[] = $r;
            if (! $r->isSuccess() && $batch->boundary->stopOnFirstFailure) {
                $stopped = true;
            }
        }

        $success = count(array_filter($results, fn (ProjectionResult $r) => $r->isSuccess()));
        $status = $success === count($results)
            ? ProjectionStatus::APPLIED
            : ($success > 0 ? ProjectionStatus::PARTIAL : ProjectionStatus::FAILED);

        return new ProjectionBatchResult($batch->batchId, $status, $results, $this->currentVersion()->token, ReplayState::FIRST, $batch->correlationId);
    }

    // --- helpers ---

    /** @return array<string,mixed> */
    private function currentValues(string $targetId, array $paths): array
    {
        $out = [];
        foreach ($paths as $path) {
            $out[$path] = $this->state[$targetId][$path] ?? null;
        }

        return $out;
    }

    private function reject(ProjectionRequest $req, string $status, string $reason, string $explanation, string $replay = ReplayState::FIRST): ProjectionResult
    {
        $actual = isset($this->state[$req->targetId]) ? $this->currentValues($req->targetId, $req->changedFields) : [];

        return new ProjectionResult(
            status: $status, operationId: $req->operationId, targetId: $req->targetId,
            appliedFields: [], rejectedFields: $req->changedFields, rendererVersion: $this->currentVersion()->token,
            actualAfterState: $actual, verification: ['verified' => false, 'matched' => 0, 'total' => count($req->changedFields)],
            errorReason: $reason, explanation: $explanation, durationMs: 0.0, replay: $replay, correlationId: $req->correlationId,
        );
    }

    private function build(ProjectionRequest $req, string $status, array $applied, array $rejected, array $actualAfter, bool $verified, int $matched, int $total, ?string $reason, string $explanation): ProjectionResult
    {
        return new ProjectionResult(
            status: $status, operationId: $req->operationId, targetId: $req->targetId,
            appliedFields: $applied, rejectedFields: $rejected, rendererVersion: $this->currentVersion()->token,
            actualAfterState: $actualAfter, verification: ['verified' => $verified, 'matched' => $matched, 'total' => $total],
            errorReason: $reason, explanation: $explanation, durationMs: 0.0, replay: ReplayState::FIRST, correlationId: $req->correlationId,
        );
    }
}
