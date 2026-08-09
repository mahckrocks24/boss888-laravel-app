<?php

namespace App\Core\Engineer888\Approval;

use App\Core\Engineer888\Reasoning\CandidateImplementation;

/**
 * Is the repository still the one the approver agreed to change?
 *
 * THE HOLE THIS CLOSES, MEASURED. In E1-B a candidate was approved, the target
 * file was rewritten underneath it by a second session, and
 * `ApprovalLedger::enforce()` was asked again: it returned permitted, with no
 * refusals. That is correct behaviour for what enforce() actually does — it
 * compares a candidate to its approval, two records that had not moved. Nothing
 * anywhere compared either of them to the disk. So an approval to replace file X
 * would still install cleanly over a file that was no longer X, discarding work
 * nobody agreed to discard.
 *
 * WHAT IT COMPARES, AND WHAT IT REFUSES TO COMPARE. Only the pre-image E1-A
 * persisted with the candidate: the exact sha256, byte length and existence that
 * SourceGrounding observed at the instant the prompt was built. That is the only
 * record of what the model — and therefore the human reading its diff — believed
 * was there. It never re-grounds, never falls back to the RecoveryManifest's
 * pre-hash (captured later, after approval), never consults InstallManifest
 * provenance (which describes what Engineer888 last wrote, not what was
 * approved), and never treats today's bytes as evidence of yesterday's state.
 *
 * IT ANSWERS A NARROW QUESTION. "Did an approved target move?" — not "is the
 * repository clean". This tree carries hundreds of unrelated dirty files from
 * other engineers at any moment, and refusing on global dirt would make every
 * approval unexecutable while proving nothing about the change under review.
 *
 * IT IS READ-ONLY, AND THAT IS LOAD-BEARING. It runs at the last point in
 * IMPLEMENT where nothing has touched the filesystem — before
 * RecoveryManifest::capture() takes its backups. A guard that wrote anything
 * would destroy the property it exists to provide.
 */
final class ApprovedPreImageGuard
{
    public function __construct(private readonly string $repoPath) {}

    /**
     * Compare the approved pre-image with what is on disk right now.
     *
     * Every target is inspected before a verdict is returned. Stopping at the
     * first mismatch would make a two-file drift look like a one-file drift and
     * send the engineer back for a second refusal.
     */
    public function check(CandidateImplementation $candidate): DriftVerdict
    {
        // A candidate whose evidence does not completely cover what it changes
        // cannot be compared against anything. `hasBoundPreImage()` is strict on
        // purpose: it requires an entry per changed path, every entry grounded,
        // a real hash for every update, and a positive observation of absence
        // for every create. Legacy candidates fail here, and so does the create
        // whose parent directory did not exist at grounding time — SourceGrounding
        // could not prove that absence, and this is not the place to invent it.
        if (! $candidate->hasBoundPreImage()) {
            return DriftVerdict::refuse(DriftVerdict::UNBOUND, [[
                'path'   => '(candidate)',
                'code'   => DriftVerdict::PRE_IMAGE_UNBOUND,
                'detail' => 'this candidate carries no complete record of the repository state it was '
                          . 'reasoned against, so there is nothing to compare the repository with. It '
                          . 'predates pre-image binding, or its evidence does not cover every file it '
                          . 'changes. Reason again and approve the result.',
            ]]);
        }

        $findings = [];

        foreach ($candidate->preImage as $entry) {
            $finding = $this->inspect($entry);
            if ($finding !== null) { $findings[] = $finding; }
        }

        $checked = count($candidate->preImage);

        return $findings === []
            ? DriftVerdict::permit($checked)
            : DriftVerdict::refuse(DriftVerdict::DRIFT, $findings, $checked);
    }

    /**
     * One approved target against one file. Null means it has not moved.
     *
     * @param  array<string,mixed> $entry one PreImage evidence record
     * @return array{path:string,code:string,detail:string}|null
     */
    private function inspect(array $entry): ?array
    {
        $path = (string) ($entry['path'] ?? '');
        $operation = (string) ($entry['operation'] ?? '');
        $approvedHash = $entry['pre_image_sha256'] ?? null;
        $existedAtApproval = $entry['existed'] ?? null;

        $located = $this->locate($path);

        if ($located['escaped'] !== null) {
            return $this->finding($path, DriftVerdict::PATH_ESCAPES, $located['escaped']);
        }

        // ── the target was verified ABSENT when the model read it ────────
        if ($existedAtApproval === false) {
            if (! $located['exists']) { return null; }

            return $this->finding($path, DriftVerdict::TARGET_EXISTS,
                'this was approved as a new file and the repository was verified not to contain it. '
                . 'It exists now, so installing would overwrite something created after the approval.');
        }

        // ── the target existed, and its exact bytes were approved ────────
        if (! $located['exists']) {
            return $this->finding($path, DriftVerdict::TARGET_MISSING,
                'the approved change rewrites this file, and it is no longer in the repository. '
                . 'Installing would recreate a file somebody deleted.');
        }

        if (! is_file((string) $located['real'])) {
            return $this->finding($path, DriftVerdict::TARGET_NOT_A_FILE,
                'this path no longer resolves to a regular file.');
        }

        if (! is_readable((string) $located['real'])) {
            // Unreadable is not "unchanged". A hash that cannot be taken is not
            // evidence, and guessing in either direction would be a decision
            // about somebody's file made without looking at it.
            return $this->finding($path, DriftVerdict::TARGET_UNREADABLE,
                'this file cannot be read, so it cannot be proved to be the file that was approved.');
        }

        $currentHash = @hash_file('sha256', (string) $located['real']);

        if ($currentHash === false || ! is_string($currentHash)) {
            return $this->finding($path, DriftVerdict::TARGET_UNREADABLE,
                'this file could not be hashed, so it cannot be proved to be the file that was approved.');
        }

        if (! is_string($approvedHash) || ! hash_equals($approvedHash, $currentHash)) {
            return $this->finding($path, DriftVerdict::TARGET_CHANGED,
                'this file has changed since the model read it and the change was approved '
                . '(approved ' . substr((string) $approvedHash, 0, 12) . '…, found '
                . substr($currentHash, 0, 12) . '…). The diff that was reviewed no longer describes '
                . 'what would be replaced.');
        }

        return null;
    }

    /**
     * Where this repository-relative path really is, or why it may not be read.
     *
     * The rules mirror SourceGrounding::verify() deliberately — a path that was
     * refused when the context was built must not become readable when the file
     * is being checked. Absence is not an escape: a create legitimately resolves
     * to nothing, and the caller decides what that means.
     *
     * @return array{exists:bool,real:?string,escaped:?string}
     */
    private function locate(string $path): array
    {
        $escape = fn (string $why) => ['exists' => false, 'real' => null, 'escaped' => $why];

        if ($path === '' || $path !== ltrim($path, '/')) {
            return $escape('paths are relative to the repository root; this one is not');
        }

        if (str_contains($path, "\0")) {
            return $escape('path contains a null byte');
        }

        if (preg_match('#(^|/)\.\.(/|$)#', $path)) {
            return $escape('path traversal is not accepted');
        }

        $root = realpath($this->repoPath);
        if ($root === false) {
            return $escape('the project repository path does not resolve');
        }

        $real = realpath($root . '/' . $path);

        if ($real === false) {
            // Genuinely not there. For a create that is the expected answer.
            return ['exists' => false, 'real' => null, 'escaped' => null];
        }

        // Resolved, so a symlink has already been followed: an escape shows here.
        if (! str_starts_with($real . '/', $root . '/')) {
            return $escape('this path resolves outside the project repository');
        }

        return ['exists' => true, 'real' => $real, 'escaped' => null];
    }

    /** @return array{path:string,code:string,detail:string} */
    private function finding(string $path, string $code, string $detail): array
    {
        return ['path' => $path, 'code' => $code, 'detail' => $detail];
    }
}
