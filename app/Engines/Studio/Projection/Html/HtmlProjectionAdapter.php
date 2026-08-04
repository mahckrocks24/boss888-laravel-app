<?php

namespace App\Engines\Studio\Projection\Html;

use App\Engines\Studio\Projection\Contracts\StudioProjectionAdapterInterface;
use App\Engines\Studio\Projection\Html\Contracts\HtmlProjectionDocument;
use App\Engines\Studio\Projection\ProjectionBatch;
use App\Engines\Studio\Projection\ProjectionBatchResult;
use App\Engines\Studio\Projection\ProjectionCapability;
use App\Engines\Studio\Projection\ProjectionRequest;
use App\Engines\Studio\Projection\ProjectionResult;
use App\Engines\Studio\Projection\ProjectionStatus;
use App\Engines\Studio\Projection\ReplayState;
use App\Engines\Studio\Projection\StudioDocumentVersion;
use App\Engines\Studio\Transform\ColorNormalizer;
use App\Engines\Studio\Transform\OperationRegistry;
use App\Engines\Studio\Transform\OperationValidator;

/**
 * STUDIO888 · Projection/Html — server-side HTML compatibility adapter (dormant).
 *
 * Implements the Phase-3B StudioProjectionAdapterInterface against a supplied,
 * in-memory Studio document (raw HTML or structured fields). It NEVER loads a
 * live design, persists, touches the iframe/studio.js, calls routes/providers/
 * Runtime/billing, or changes live behaviour. Success is proven by reading back
 * the ACTUAL mutated state — never by acceptance.
 */
final class HtmlProjectionAdapter implements StudioProjectionAdapterInterface
{
    /** @var array<string, array{status:string,result?:ProjectionResult,batchResult?:ProjectionBatchResult}> */
    private array $ledger = [];

    public function __construct(
        private readonly HtmlProjectionDocument $document,
        private readonly ProjectionCapability $capability,
        private readonly OperationValidator $validator,
    ) {
    }

    public static function forRawHtml(string $html): self
    {
        return new self(new RawHtmlDocument($html), HtmlProjectionCapabilityFactory::forRaw(), self::defaultValidator());
    }

    /** @param array $payload {template_slug, fields, ...} */
    public static function forStructured(array $payload): self
    {
        return new self(new StructuredFieldsDocument($payload), HtmlProjectionCapabilityFactory::forStructured(), self::defaultValidator());
    }

    private static function defaultValidator(): OperationValidator
    {
        $registry = new OperationRegistry(true);

        return new OperationValidator($registry, new ColorNormalizer());
    }

    public function capability(): ProjectionCapability
    {
        return $this->capability;
    }

    public function currentVersion(): StudioDocumentVersion
    {
        return StudioDocumentVersion::of($this->document->versionToken());
    }

    /** The current, output-sanitised document payload (string for raw, array for structured). */
    public function payload(): mixed
    {
        return $this->document->payload();
    }

    public function document(): HtmlProjectionDocument
    {
        return $this->document;
    }

    // --- idempotency test simulators ---

    public function markInProgress(string $key): void
    {
        $this->ledger[$key] = ['status' => ReplayState::IN_PROGRESS];
    }

    public function expire(string $key): void
    {
        $this->ledger[$key] = ['status' => ReplayState::EXPIRED];
    }

    // --- projection ---

    public function project(ProjectionRequest $req): ProjectionResult
    {
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

        if ($req->expectedDocumentVersion !== null && $req->expectedDocumentVersion !== $this->document->versionToken()) {
            return $this->reject($req, ProjectionStatus::STALE_VERSION, ProjectionResult::R_STALE_VERSION, "Expected version {$req->expectedDocumentVersion}, document at {$this->document->versionToken()}.");
        }

        $count = $this->document->count($req->targetId);
        if ($count === 0) {
            return $this->reject($req, ProjectionStatus::TARGET_MISSING, ProjectionResult::R_TARGET_MISSING, "Target '{$req->targetId}' not found.");
        }
        if ($count > 1) {
            return $this->reject($req, ProjectionStatus::REJECTED, HtmlProjectionReason::AMBIGUOUS_TARGET, "Target '{$req->targetId}' is ambiguous ({$count} matches).");
        }

        if ($req->beforeSnapshotHash !== null) {
            $current = [];
            foreach ($req->changedFields as $p) {
                $current[$p] = $this->document->get($req->targetId, $p);
            }
            if (ProjectionRequest::hashState($current) !== $req->beforeSnapshotHash) {
                return $this->reject($req, ProjectionStatus::STALE_VERSION, ProjectionResult::R_SNAPSHOT_MISMATCH, 'Target snapshot changed since it was read.');
            }
        }

        // Plan: decide supported/normalized value per field.
        $plan = [];
        $rejectedFields = [];
        $firstReason = null;
        $hadValidationFailure = false;

        foreach ($req->changedFields as $p) {
            $desired = $req->desiredAfterState[$p] ?? null;

            if (! $this->capability->supportsField($p)) {
                $rejectedFields[] = $p;
                $firstReason ??= $this->unsupportedReason($p);
                continue;
            }
            if (! $this->document->supports($req->targetId, $p)) {
                $rejectedFields[] = $p;
                $firstReason ??= ($p === 'text' ? HtmlProjectionReason::NON_LEAF_TARGET : ProjectionResult::R_UNSUPPORTED_PROPERTY);
                continue;
            }

            if (str_starts_with($p, 'style.')) {
                [$ok, $normalized, $reason] = $this->validateStyleValue(substr($p, 6), $desired);
                if (! $ok) {
                    $rejectedFields[] = $p;
                    $firstReason ??= $reason;
                    $hadValidationFailure = true;
                    continue;
                }
                $plan[$p] = $normalized;
            } elseif ($p === 'visible') {
                $plan[$p] = (bool) $desired;
            } else {
                $plan[$p] = (string) $desired;
            }
        }

        if ($plan === []) {
            $status = $hadValidationFailure ? ProjectionStatus::REJECTED : ProjectionStatus::UNSUPPORTED;
            return $this->reject($req, $status, $firstReason ?? ProjectionResult::R_UNSUPPORTED_PROPERTY, 'No requested field could be applied.');
        }

        // Apply, then read back the ACTUAL state.
        $applied = [];
        foreach ($plan as $p => $v) {
            if ($this->document->set($req->targetId, $p, $v)) {
                $applied[] = $p;
            }
        }
        $actual = [];
        foreach ($req->changedFields as $p) {
            $actual[$p] = $this->document->get($req->targetId, $p);
        }

        // Verify applied fields against the normalized desired values.
        $matched = 0;
        foreach ($applied as $p) {
            if ($this->valuesEqual($actual[$p], $plan[$p])) {
                $matched++;
            }
        }
        $verified = $applied !== [] && $matched === count($applied);

        if ($applied === [] && $rejectedFields === []) {
            $result = $this->build($req, ProjectionStatus::FAILED, [], [], $actual, false, ProjectionResult::R_NO_CHANGE, 'No change (no-op).');
        } elseif ($applied === []) {
            $status = $hadValidationFailure ? ProjectionStatus::REJECTED : ProjectionStatus::UNSUPPORTED;
            $result = $this->build($req, $status, [], $rejectedFields, $actual, false, $firstReason, 'No field applied.');
        } elseif (! $verified) {
            $result = $this->build($req, ProjectionStatus::FAILED, $applied, $rejectedFields, $actual, false, ProjectionResult::R_VERIFICATION_FAILED, 'Actual state did not match desired.');
        } elseif ($rejectedFields !== []) {
            $result = $this->build($req, ProjectionStatus::PARTIAL, $applied, $rejectedFields, $actual, true, $firstReason, 'Applied supported fields; rejected the rest.');
        } else {
            $result = $this->build($req, ProjectionStatus::APPLIED, $applied, [], $actual, true, null, 'Applied and verified against actual state.');
        }

        if ($req->idempotencyKey !== null && $result->isSuccess()) {
            $this->ledger[$req->idempotencyKey] = ['status' => ReplayState::COMPLETED, 'result' => $result];
        }

        return $result;
    }

    public function projectBatch(ProjectionBatch $batch): ProjectionBatchResult
    {
        if ($batch->idempotencyKey !== null && isset($this->ledger['batch:' . $batch->idempotencyKey])
            && $this->ledger['batch:' . $batch->idempotencyKey]['status'] === ReplayState::COMPLETED) {
            return $this->ledger['batch:' . $batch->idempotencyKey]['batchResult']->withReplay(ReplayState::COMPLETED);
        }

        $result = $batch->boundary->atomic ? $this->projectAtomic($batch) : $this->projectBestEffort($batch);

        if ($batch->idempotencyKey !== null && ProjectionStatus::isSuccess($result->status)) {
            $this->ledger['batch:' . $batch->idempotencyKey] = ['status' => ReplayState::COMPLETED, 'batchResult' => $result];
        }

        return $result;
    }

    private function projectAtomic(ProjectionBatch $batch): ProjectionBatchResult
    {
        $stateBackup = $this->document->snapshotState();
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
            $this->document->restoreState($stateBackup);
            $this->ledger = $ledgerBackup;
            $rolled = array_map(fn (ProjectionResult $r) => $r->isSuccess()
                ? new ProjectionResult(ProjectionStatus::REJECTED, $r->operationId, $r->targetId, [], $r->rejectedFields, $this->document->versionToken(), [], [], ProjectionResult::R_ROLLED_BACK, 'Rolled back (atomic batch failure).', 0.0, ReplayState::FIRST, $r->correlationId)
                : $r, $results);

            return new ProjectionBatchResult($batch->batchId, ProjectionStatus::FAILED, $rolled, $this->document->versionToken(), ReplayState::FIRST, $batch->correlationId);
        }

        return new ProjectionBatchResult($batch->batchId, ProjectionStatus::APPLIED, $results, $this->document->versionToken(), ReplayState::FIRST, $batch->correlationId);
    }

    private function projectBestEffort(ProjectionBatch $batch): ProjectionBatchResult
    {
        $results = [];
        $stopped = false;
        foreach ($batch->requests as $req) {
            if ($stopped) {
                $results[] = new ProjectionResult(ProjectionStatus::REJECTED, $req->operationId, $req->targetId, [], $req->changedFields, $this->document->versionToken(), [], [], ProjectionResult::R_SKIPPED, 'Skipped after an earlier failure.', 0.0, ReplayState::FIRST, $req->correlationId);
                continue;
            }
            $r = $this->project($req);
            $results[] = $r;
            if (! $r->isSuccess() && $batch->boundary->stopOnFirstFailure) {
                $stopped = true;
            }
        }

        $success = count(array_filter($results, fn (ProjectionResult $r) => $r->isSuccess()));
        $status = $success === count($results) ? ProjectionStatus::APPLIED : ($success > 0 ? ProjectionStatus::PARTIAL : ProjectionStatus::FAILED);

        return new ProjectionBatchResult($batch->batchId, $status, $results, $this->document->versionToken(), ReplayState::FIRST, $batch->correlationId);
    }

    // --- helpers ---

    /** @return array{0:bool,1:mixed,2:?string} [ok, normalized, reason] */
    private function validateStyleValue(string $property, mixed $value): array
    {
        $batch = ['version' => 1, 'operations' => [[
            'op_id' => 'p', 'type' => 'set_style', 'target' => ['id' => 'x'], 'property' => $property, 'value' => $value,
        ]]];
        $vr = $this->validator->validate($batch);
        if (! $vr->ok) {
            return [false, null, $vr->rejected()[0]['failure_reason'] ?? 'invalid_value'];
        }

        return [true, $vr->accepted()[0]['normalized_value'], null];
    }

    private function unsupportedReason(string $path): string
    {
        if ($this->document->form() === HtmlProjectionDocument::FORM_STRUCTURED && $path !== 'text') {
            return HtmlProjectionReason::STRUCTURED_STYLE_UNSUPPORTED;
        }
        if (str_starts_with($path, 'style.')) {
            return ProjectionResult::R_UNSUPPORTED_PROPERTY;
        }

        return ProjectionResult::R_UNSUPPORTED_TARGET;
    }

    private function valuesEqual(mixed $a, mixed $b): bool
    {
        if (is_bool($a) || is_bool($b)) {
            return (bool) $a === (bool) $b;
        }
        if (is_float($a) || is_float($b)) {
            return is_numeric($a) && is_numeric($b) && abs((float) $a - (float) $b) < 1e-9;
        }

        return (string) $a === (string) $b;
    }

    private function reject(ProjectionRequest $req, string $status, string $reason, string $explanation, string $replay = ReplayState::FIRST): ProjectionResult
    {
        $actual = [];
        if ($this->document->count($req->targetId) === 1) {
            foreach ($req->changedFields as $p) {
                $actual[$p] = $this->document->get($req->targetId, $p);
            }
        }

        return new ProjectionResult(
            status: $status, operationId: $req->operationId, targetId: $req->targetId,
            appliedFields: [], rejectedFields: $req->changedFields, rendererVersion: $this->document->versionToken(),
            actualAfterState: $actual, verification: ['verified' => false, 'matched' => 0, 'total' => count($req->changedFields)],
            errorReason: $reason, explanation: $explanation, durationMs: 0.0, replay: $replay, correlationId: $req->correlationId,
        );
    }

    private function build(ProjectionRequest $req, string $status, array $applied, array $rejected, array $actual, bool $verified, ?string $reason, string $explanation): ProjectionResult
    {
        return new ProjectionResult(
            status: $status, operationId: $req->operationId, targetId: $req->targetId,
            appliedFields: $applied, rejectedFields: $rejected, rendererVersion: $this->document->versionToken(),
            actualAfterState: $actual, verification: ['verified' => $verified, 'matched' => count($applied), 'total' => count($req->changedFields)],
            errorReason: $reason, explanation: $explanation, durationMs: 0.0, replay: ReplayState::FIRST, correlationId: $req->correlationId,
        );
    }
}
