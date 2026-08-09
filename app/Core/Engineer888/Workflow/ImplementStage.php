<?php

namespace App\Core\Engineer888\Workflow;

use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Approval\ApprovedPreImageGuard;
use App\Core\Engineer888\Coordination\GovernedFiles;
use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Install\SafeInstaller;
use App\Core\Engineer888\Recovery\RecoveryManifest;
use App\Core\Engineer888\Reasoning\ReasoningEngine;

/**
 * 9. Do the work.
 *
 * The change set applied here comes from one of two places — a human, or a
 * candidate a human bound themselves to — and after that point the two are
 * indistinguishable. There is exactly one write path: SafeInstaller, with
 * ownership proved, provenance recorded and a verified backup taken before any
 * overwrite. Model-authored content earns no shortcut and no second path.
 *
 * THE GATE IS THE APPROVAL LEDGER, NOT THE TASK ROW. Sprint 7 read
 * `approved_by` off the task, which can only say that somebody approved
 * something. The ledger says which candidate, which provider, which paths and
 * the sha256 of every byte — and IMPLEMENT recomputes all of it before writing.
 * If any part differs, nothing is written and the difference is named.
 */
final class ImplementStage extends BaseStage
{
    public function name(): string { return 'IMPLEMENT'; }
    public function purpose(): string { return 'Apply the exact approved change set through SafeInstaller, with provenance, ownership and backup.'; }
    public function inputs(): array { return ['approval ledger', 'files.owned', 'task.change_set or the approved candidate']; }
    public function outputs(): array { return ['implement.decisions', 'implement.origin', 'implement.enforcement', 'implement.drift', 'implement.recovery_manifest']; }
    public function failureModes(): array {
        return [
            'no approval exists, or it is rejected, revoked, superseded or expired',
            'the candidate no longer matches the bytes that were approved',
            'an approved target changed in the repository after it was approved',
            'the candidate proposes a file that was never checked for ownership',
            'the destination diverged after the plan was made — someone edited it',
            'the source file is missing',
            'a write fails verification after the fact',
            'the pre-write state of a destination cannot be proved, so a clean undo cannot be promised',
        ];
    }
    public function verification(): string { return 'the approval binding is recomputed from the candidate and compared field by field before any write; SafeInstaller then re-hashes each destination after writing and refuses on mismatch'; }
    public function recovery(): string { return 'every overwrite leaves a verified backup; re-approve the current candidate, or reason again and approve the result'; }

    public function run(WorkflowContext $context): StageResult
    {
        $changeSet = $context->taskJson('change_set');
        $origin = 'human';
        $enforcement = null;

        if ($changeSet === []) {
            $engine = ReasoningEngine::make();
            $ledger = new ApprovalLedger();

            $approval = $ledger->activeFor((int) $context->task->id);
            if ($approval === null) {
                return StageResult::blocked('not approved',
                    'no approved candidate exists for this task. IMPLEMENT never writes on the strength of '
                    . 'a name on the task row — approval binds to exact bytes or it is not approval.');
            }

            $candidateUuid = (string) $approval->candidate_uuid;
            $candidate = $engine->approvedCandidate((int) $context->task->id);

            if ($candidate === null) {
                return StageResult::failed('approved candidate no longer validates',
                    'candidate ' . $candidateUuid . ' is approved but no longer passes the output contract. '
                    . 'A row that once validated does not stay installable.');
            }

            // The whole point of the sprint, in one call.
            $enforcement = $ledger->enforce($candidate, $candidateUuid, $context->task, $context->project);
            $context->set('implement.enforcement', $enforcement->toArray());

            if (! $enforcement->permitted) {
                return StageResult::blocked('refused: ' . $enforcement->summary,
                    $enforcement->detail(), ['enforcement' => $enforcement->toArray()]);
            }

            // Ownership was proved against the plan's file list. A candidate
            // holding a path that list never saw has not passed that gate.
            $owned = $context->get('files.owned', []);
            $unchecked = array_diff($candidate->paths(), $owned);
            if ($unchecked !== []) {
                return StageResult::failed('unchecked files in candidate',
                    'these paths never passed the ownership gate: ' . implode(', ', $unchecked));
            }

            // WHAT THE MODEL READ MUST STILL BE THERE.
            //
            // enforce() above proves the candidate is the one that was approved.
            // It cannot prove the repository is the one that was approved — in
            // E1-B a target was rewritten underneath a live approval and
            // enforce() still returned permitted, because both records it
            // compares had stayed put. This is the other half: the approved
            // pre-image against the disk.
            //
            // It sits here, and not one line later, because the next thing that
            // happens is RecoveryManifest::capture(), which writes backup files.
            // This is the last moment at which a refusal costs nothing.
            $drift = (new ApprovedPreImageGuard($context->repoPath))->check($candidate);
            $context->set('implement.drift', $drift->toArray());

            if (! $drift->permitted) {
                // BLOCKED, not failed: nothing is broken, the change is simply
                // no longer the change anybody read. The repair is a human one —
                // reason again and approve the result — and nothing here
                // regenerates, rebases or re-approves on their behalf.
                return StageResult::blocked('refused: ' . $drift->summary, $drift->detail(),
                    ['drift' => $drift->toArray()]);
            }

            $changeSet = $candidate->asChangeSet();
            $origin = 'reasoning:' . $candidate->provider . '/' . $candidate->model
                    . ' [candidate ' . substr($candidateUuid, 0, 8) . ']';
        } elseif (! $context->get('approval.granted', false)) {
            return StageResult::blocked('not approved', 'IMPLEMENT never runs without approval');
        }

        // NOTHING IS WRITTEN UNTIL THE UNDO IS PROVED.
        //
        // Sprint 8 ended with an approved file installed, VERIFY failed, and
        // the file still sitting in the running application. The manifest is
        // captured here — before the first byte — because after the write the
        // pre-state is gone and any reconstruction is a guess. It takes and
        // hash-verifies its own backup of every existing destination; refusing
        // now is the difference between a rollback and a hope.
        $manifest = null;
        $resolvedChangeSet = [];
        foreach ($changeSet as $destination => $spec) {
            $content = is_array($spec) ? ($spec['content'] ?? null) : null;
            $sourcePath = is_array($spec) ? ($spec['source'] ?? null) : $spec;

            if ($content === null && $sourcePath !== null) {
                $full = str_starts_with((string) $sourcePath, '/') ? $sourcePath : $context->repoPath . '/' . $sourcePath;
                $content = is_file($full) ? file_get_contents($full) : null;
            }

            if ($content === null) {
                return StageResult::failed('implementation halted',
                    "no content available for {$destination}");
            }

            $resolvedChangeSet[$destination] = $content;
        }

        $ownershipManifest = OwnershipManifest::active($context->repoPath);

        try {
            $manifest = RecoveryManifest::capture(
                $context->repoPath,
                (string) $context->task->uuid,
                (string) $context->task->uuid,
                (string) ($context->get('plan.candidate_uuid') ?? 'human-change-set'),
                (string) ($enforcement?->approval?->fingerprint ?? 'not-candidate-bound'),
                $resolvedChangeSet,
                fn (string $p) => $ownershipManifest?->classify($p)['status'] ?? 'UNKNOWN',
                fn (string $p) => GovernedFiles::isGoverned($p),
                '.engineer888/recovery/' . substr((string) $context->task->uuid, 0, 8),
            );
        } catch (\Throwable $e) {
            return StageResult::failed('recovery manifest incomplete',
                'nothing was written. ' . $e->getMessage());
        }

        $deficiencies = $manifest->deficiencies();
        if ($deficiencies !== []) {
            return StageResult::failed('recovery manifest incomplete',
                'nothing was written because a clean undo could not be promised: '
                . implode('; ', $deficiencies));
        }

        $context->set('implement.recovery_manifest', $manifest);

        $installer = new SafeInstaller($context->repoPath);
        $decisions = [];
        $failure = null;

        // Content was resolved above, when the manifest was captured. Reading it
        // twice would allow the bytes hashed into the manifest and the bytes
        // written to differ.
        foreach ($resolvedChangeSet as $destination => $content) {
            $decision = $installer->install($destination, $content,
                'workflow task ' . $context->task->uuid . ' (' . $origin . ')',
                'engineer888/workflow');

            $decisions[] = $decision->toArray();

            if ($decision->isRefusal()) {
                $failure = "{$destination}: {$decision->reason}";
                break;
            }
        }

        $context->set('implement.decisions', $decisions);
        $context->set('implement.origin', $origin);

        if ($failure !== null) {
            return StageResult::failed('implementation halted', $failure, ['decisions' => $decisions]);
        }

        $written = array_values(array_filter($decisions, fn ($d) => in_array($d['state'], ['INSTALL', 'UPDATE', 'OVERRIDDEN'], true)));

        return StageResult::ok(
            count($written) . ' file(s) written, ' . (count($decisions) - count($written)) . ' unchanged'
                . ($origin === 'human' ? '' : ' [' . $origin . ']'),
            ['decisions' => $decisions, 'origin' => $origin,
             'enforcement' => $enforcement?->toArray(),
             'recovery_manifest' => $manifest->toArray()],
            ['backups' => array_values(array_filter(array_column($decisions, 'backup')))]
        );
    }
}
