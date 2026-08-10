<?php

namespace Tests\Feature\Engineer888;

use App\Core\Engineer888\Approval\ApprovalBinding;
use App\Core\Engineer888\Approval\ApprovalLedger;
use App\Core\Engineer888\Reasoning\CandidateImplementation;
use App\Core\Engineer888\Reasoning\PreImage;
use App\Core\Engineer888\Reasoning\ReasoningEngine;
use App\Core\Engineer888\Reasoning\SourceGrounding;
use App\Core\Engineer888\Workflow\WorkflowEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The grounded pre-image, and the approval that now binds it.
 *
 * WHAT IS ACTUALLY BEING PROVED. Not that a hash can be computed — that a human
 * approval covers both halves of a change. Before E1-A an approval said "write
 * these bytes"; it could not say "…over this file, as it stood when the model
 * read it". The gap is not theoretical: approve a rewrite, let a parallel
 * session rewrite the same file first, and the approved bytes still install
 * cleanly while discarding work nobody agreed to discard.
 *
 * THE REFUSALS MATTER MORE THAN THE HAPPY PATH. Three claims here are about
 * what the system must NOT do. It must not manufacture a pre-image for an old
 * candidate by hashing the repository today. It must not let "the file was
 * verified absent" read the same as "we never looked". And it must not quietly
 * change what a historical approval meant.
 *
 * NOTHING HERE WRITES TO THE REPOSITORY. Fixtures live in a throwaway directory
 * under the system temp path, and every assertion about the real tree is a read.
 */
class PreImageBindingTest extends TestCase
{
    use RefreshDatabase;

    private string $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame('levelup_e888_test', DB::connection()->getDatabaseName());

        $this->repo = sys_get_temp_dir() . '/e888-preimage-' . getmypid() . '-' . substr(md5($this->name()), 0, 8);

        $declared = getenv('E888_SPRINT_MANIFEST') ?: '.engineer888/sprints/fixture.json';
        $manifestPath = $this->repo . '/' . ltrim($declared, '/');
        @mkdir(dirname($manifestPath), 0775, true);
        file_put_contents($manifestPath, json_encode([
            'manifest_version' => 1, 'engineer' => 'Engineer888', 'session' => 'fixture',
            'sprint' => 'pre-image-test', 'test_database' => 'levelup_e888_test',
            'owned_paths' => ['app/Owned/**'],
        ], JSON_PRETTY_PRINT));

        file_put_contents($this->repo . '/.gitignore', ".engineer888/\n.gitignore\n");
        // E1-G: coverage is resolved against the PROJECT repository, so a test
        // this fixture cites has to exist in the fixture's own tree.
        @mkdir($this->repo . '/tests/Feature/Engineer888', 0775, true);
        file_put_contents($this->repo . '/tests/Feature/Engineer888/CommandCenterTest.php', "<?php\n");
        file_put_contents($this->repo . '/tests/Feature/Engineer888/ExecutionRecoveryTest.php', "<?php\n");

        $quoted = escapeshellarg($this->repo);
        exec("cd {$quoted} && git init -q && git config user.email e888@test && git config user.name e888 2>&1");

        config(['engineer888_reasoning.enabled' => true, 'engineer888_reasoning.provider' => 'null']);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->repo)) { $this->rmrf($this->repo); }
        parent::tearDown();
    }

    // ── source persistence ──────────────────────────────────────────────

    public function test_an_update_persists_the_exact_source_grounding_sha256(): void
    {
        $body = $this->writeTarget('app/Owned/Existing.php', "<?php\n// the file as the model was shown it\n");

        $evidence = $this->reasonOverUpdate();

        $this->assertSame(hash('sha256', $body), $evidence[0]['pre_image_sha256'],
            'the stored hash must be the hash of the bytes the model actually received');
    }

    public function test_an_update_persists_the_source_byte_count(): void
    {
        $body = $this->writeTarget('app/Owned/Existing.php', "<?php\n// a file of a particular length\n");

        $evidence = $this->reasonOverUpdate();

        $this->assertSame(strlen($body), $evidence[0]['pre_image_bytes']);
        $this->assertTrue($evidence[0]['existed']);
    }

    public function test_a_create_persists_that_the_target_did_not_exist(): void
    {
        $evidence = $this->reasonOverCreate();

        $this->assertFalse($evidence[0]['existed'],
            'a create must record a positive observation of absence, not silence');
        $this->assertTrue($evidence[0]['grounded']);
        $this->assertSame(0, $evidence[0]['pre_image_bytes']);
    }

    public function test_a_create_stores_a_null_pre_image_hash(): void
    {
        $evidence = $this->reasonOverCreate();

        $this->assertTrue($evidence[0]['grounded'], 'otherwise the null below proves nothing');
        $this->assertNull($evidence[0]['pre_image_sha256'],
            'there are no prior bytes to hash, and inventing one would be a claim about a file that was not there');
    }

    /**
     * A LIMIT, RECORDED RATHER THAN PAPERED OVER.
     *
     * SourceGrounding refuses a `create` whose parent directory does not exist
     * yet — it cannot resolve a containment check against a directory that is
     * not there. That refusal is correct, and it means such a proposal reaches
     * the model with no observation of its target at all.
     *
     * E1-A's job is to carry evidence truthfully, not to invent more of it, so
     * the candidate records "never observed" and reports itself unbound. What
     * must never happen is the other outcome: this case quietly borrowing the
     * "verified absent" shape and passing a later drift check it never earned.
     */
    public function test_a_create_into_a_directory_that_does_not_exist_yet_is_unbound(): void
    {
        $evidence = $this->reasonOverCreate(withDirectory: false);

        $this->assertFalse($evidence[0]['grounded']);
        $this->assertNull($evidence[0]['existed'], 'not observed is not the same as observed absent');
        $this->assertNull($evidence[0]['pre_image_bytes']);
        $this->assertFalse(PreImage::isBoundEntry($evidence[0]));
    }

    /**
     * DELETE is proved at the grounding seam rather than through a candidate.
     *
     * The provider contract has no delete action today, and inventing one here
     * to make a test pass would be a test proving a capability that does not
     * exist. What can be proved is the thing E1-A actually promises: that when a
     * delete IS grounded, its pre-image is carried exactly.
     */
    public function test_a_delete_persists_the_exact_pre_image_of_what_it_removes(): void
    {
        $body = $this->writeTarget('app/Owned/Doomed.php', "<?php\n// about to be removed\n");

        $ground = (new SourceGrounding($this->repo))->forPaths(['app/Owned/Doomed.php' => 'delete']);

        $evidence = PreImage::fromGrounding($ground['grounded'], [
            ['path' => 'app/Owned/Doomed.php', 'action' => 'delete', 'content' => ''],
        ]);

        $this->assertSame(hash('sha256', $body), $evidence[0]['pre_image_sha256']);
        $this->assertSame(strlen($body), $evidence[0]['pre_image_bytes']);
        $this->assertTrue($evidence[0]['existed']);
        $this->assertTrue(PreImage::isBoundEntry($evidence[0]));
    }

    public function test_the_stored_evidence_equals_what_source_grounding_reported(): void
    {
        $this->writeTarget('app/Owned/Existing.php', "<?php\n// grounded once, recorded once\n");

        $stored = $this->reasonOverUpdate();

        $direct = (new SourceGrounding($this->repo))->forPaths(['app/Owned/Existing.php' => 'update']);

        $this->assertSame($direct['grounded'][0]['sha256'], $stored[0]['pre_image_sha256']);
        $this->assertSame($direct['grounded'][0]['bytes'], $stored[0]['pre_image_bytes']);
        $this->assertSame($direct['grounded'][0]['exists'], $stored[0]['existed']);
    }

    public function test_reloading_a_candidate_preserves_its_pre_image_evidence(): void
    {
        $this->writeTarget('app/Owned/Existing.php', "<?php\n// survives a round trip\n");

        $uuid = $this->reasonOverUpdateReturningUuid();

        $reloaded = ReasoningEngine::make()->store()->candidateByUuid($uuid);

        $this->assertNotNull($reloaded);
        $this->assertTrue($reloaded->hasBoundPreImage());
        $this->assertSame(
            json_decode((string) DB::table('engineering_candidates')->where('uuid', $uuid)->value('pre_image'), true),
            $reloaded->preImage
        );
    }

    // ── approval binding ────────────────────────────────────────────────

    public function test_the_fingerprint_changes_once_a_pre_image_is_bound(): void
    {
        $evidence = $this->evidenceFor('app/Owned/A.php', 'update', 'x');

        $without = $this->binding($this->candidateFor('app/Owned/A.php', 'update', "<?php\n// out\n"), []);
        $with = $this->binding($this->candidateFor('app/Owned/A.php', 'update', "<?php\n// out\n"), $evidence);

        $this->assertNotSame($without->fingerprint(), $with->fingerprint(),
            'an approval that binds the pre-image must not hash the same as one that does not');
        $this->assertSame([], $without->extraParts);
        $this->assertNotSame([], $with->extraParts);
    }

    public function test_the_same_candidate_and_the_same_pre_image_are_deterministic(): void
    {
        $evidence = $this->evidenceFor('app/Owned/A.php', 'update', 'x');

        $first = $this->binding($this->candidateFor('app/Owned/A.php', 'update', "<?php\n// same\n"), $evidence);
        $second = $this->binding($this->candidateFor('app/Owned/A.php', 'update', "<?php\n// same\n"), $evidence);

        $this->assertSame($first->fingerprint(), $second->fingerprint());
    }

    public function test_the_same_post_image_over_a_different_pre_image_is_a_different_approval(): void
    {
        $content = "<?php\n// identical proposal\n";

        $a = $this->binding($this->candidateFor('app/Owned/A.php', 'update', $content),
            $this->evidenceFor('app/Owned/A.php', 'update', 'before'));
        $b = $this->binding($this->candidateFor('app/Owned/A.php', 'update', $content),
            $this->evidenceFor('app/Owned/A.php', 'update', 'a different before'));

        $this->assertNotSame($a->fingerprint(), $b->fingerprint(),
            'approving a rewrite of one file must not authorise the same rewrite of a different one');

        $differences = $b->differencesFrom($a);
        $this->assertNotSame([], $differences);
        $this->assertStringContainsString('app/Owned/A.php', implode(' | ', $differences),
            'the refusal must name the file whose source state moved');
    }

    public function test_the_same_pre_image_with_a_different_post_image_is_a_different_approval(): void
    {
        $evidence = $this->evidenceFor('app/Owned/A.php', 'update', 'before');

        $a = $this->binding($this->candidateFor('app/Owned/A.php', 'update', "<?php\n// one\n"), $evidence);
        $b = $this->binding($this->candidateFor('app/Owned/A.php', 'update', "<?php\n// two\n"), $evidence);

        $this->assertNotSame($a->fingerprint(), $b->fingerprint());
    }

    public function test_file_ordering_cannot_change_the_fingerprint(): void
    {
        $this->writeTarget('app/Owned/A.php', "<?php\n// a\n");
        $this->writeTarget('app/Owned/B.php', "<?php\n// b\n");

        $ground = (new SourceGrounding($this->repo))->forPaths([
            'app/Owned/A.php' => 'update', 'app/Owned/B.php' => 'update',
        ]);

        $forward = [
            ['path' => 'app/Owned/A.php', 'action' => 'update', 'content' => "<?php\n// 1\n"],
            ['path' => 'app/Owned/B.php', 'action' => 'update', 'content' => "<?php\n// 2\n"],
        ];
        $reversed = array_reverse($forward);

        $this->assertSame(
            PreImage::canonicalParts(PreImage::fromGrounding($ground['grounded'], $forward)),
            PreImage::canonicalParts(PreImage::fromGrounding($ground['grounded'], $reversed)),
            'the order a provider happened to list its files in is not a fact about the change'
        );
    }

    public function test_no_source_body_reaches_the_binding(): void
    {
        $secretish = 'THIS_EXACT_STRING_IS_THE_FILE_BODY';
        $this->writeTarget('app/Owned/Existing.php', "<?php\n// {$secretish}\n");

        $ground = (new SourceGrounding($this->repo))->forPaths(['app/Owned/Existing.php' => 'update']);
        $evidence = PreImage::fromGrounding($ground['grounded'], [
            ['path' => 'app/Owned/Existing.php', 'action' => 'update', 'content' => "<?php\n// replacement\n"],
        ]);

        $binding = $this->binding($this->candidateFor('app/Owned/Existing.php', 'update', "<?php\n// replacement\n"), $evidence);

        $serialised = json_encode($binding->toArray()) . implode("\n", $binding->extraParts);

        $this->assertStringNotContainsString($secretish, $serialised,
            'the binding must carry the identity of the source, never the source');
    }

    public function test_a_refused_secret_path_contributes_no_bytes_to_the_fingerprint(): void
    {
        file_put_contents($this->repo . '/.env', "APP_KEY=base64:THE_ACTUAL_SECRET_VALUE\n");

        $ground = (new SourceGrounding($this->repo))->forPaths(['.env' => 'update']);

        $this->assertSame([], $ground['grounded'], 'a secret-bearing path is never grounded');
        $this->assertNotSame([], $ground['refused']);

        $evidence = PreImage::fromGrounding($ground['grounded'], [
            ['path' => '.env', 'action' => 'update', 'content' => "APP_KEY=whatever\n"],
        ]);

        $material = implode("\n", PreImage::canonicalParts($evidence));

        $this->assertStringNotContainsString('THE_ACTUAL_SECRET_VALUE', $material);
        $this->assertStringContainsString('grounded=no', $material);
        $this->assertFalse(PreImage::isBoundEntry($evidence[0]),
            'a path whose contents were withheld cannot be a bound pre-image');
    }

    // ── legacy ──────────────────────────────────────────────────────────

    public function test_a_legacy_candidate_reports_itself_unbound(): void
    {
        $legacy = $this->candidateFor('app/Owned/A.php', 'update', "<?php\n// old\n");

        $this->assertSame([], $legacy->preImage);
        $this->assertFalse($legacy->hasBoundPreImage());
    }

    public function test_a_legacy_binding_still_hashes_exactly_as_it_always_did(): void
    {
        $legacy = $this->candidateFor('app/Owned/A.php', 'update', "<?php\n// old\n");
        $binding = ApprovalBinding::forCandidate($legacy, 'candidate-uuid', 'task-uuid', 'project-key');

        $this->assertSame([], $binding->extraParts, 'nothing may be added to a binding that had nothing');

        // The pre-E1-A definition, written out longhand. If the fingerprint of a
        // historical approval ever stops matching this, every approval made
        // before E1-A has silently changed meaning.
        $expected = hash('sha256', implode("\n", [
            ApprovalBinding::VERSION,
            'candidate:candidate-uuid',
            'task:task-uuid',
            'project:project-key',
            'provider:scripted',
            'model:fixture',
            'file:app/Owned/A.php:' . hash('sha256', "<?php\n// old\n"),
        ]));

        $this->assertSame($expected, $binding->fingerprint());
    }

    public function test_recording_a_new_candidate_never_rewrites_an_existing_ledger_row(): void
    {
        $this->writeTarget('app/Owned/Existing.php', "<?php\n// first\n");

        $project = $this->project();
        $task = $this->task($project, 'Bind a pre-image');

        $this->useUpdateProvider("<?php\n// first proposal\n");
        ReasoningEngine::make()->propose($project, $task);

        $first = DB::table('engineering_candidate_approvals')->orderBy('id')->first();
        $before = ['binding' => $first->binding, 'fingerprint' => $first->fingerprint,
                   'approved_paths' => $first->approved_paths, 'approved_hashes' => $first->approved_hashes];

        $this->useUpdateProvider("<?php\n// second proposal\n");
        ReasoningEngine::make()->propose($project, $task);

        $after = DB::table('engineering_candidate_approvals')->where('id', $first->id)->first();

        $this->assertSame($before['binding'], $after->binding);
        $this->assertSame($before['fingerprint'], $after->fingerprint);
        $this->assertSame($before['approved_paths'], $after->approved_paths);
        $this->assertSame($before['approved_hashes'], $after->approved_hashes);
        $this->assertSame('SUPERSEDED', $after->state, 'the state may move; the evidence may not');
    }

    public function test_an_update_the_model_was_never_shown_is_recorded_as_ungrounded_not_as_covered(): void
    {
        // files_affected names one file; file_changes rewrites another. Only the
        // first was ever read, so only the first has a pre-image.
        $this->writeTarget('app/Owned/Seen.php', "<?php\n// seen\n");
        $this->writeTarget('app/Owned/Unseen.php', "<?php\n// never sent to the model\n");

        $ground = (new SourceGrounding($this->repo))->forFilesAffected([
            ['path' => 'app/Owned/Seen.php', 'action' => 'update', 'why' => 'the declared target'],
        ]);

        $evidence = PreImage::fromGrounding($ground['grounded'], [
            ['path' => 'app/Owned/Unseen.php', 'action' => 'update', 'content' => "<?php\n// rewritten blind\n"],
        ]);

        $this->assertFalse($evidence[0]['grounded']);
        $this->assertNull($evidence[0]['existed']);
        $this->assertNull($evidence[0]['pre_image_sha256']);
        $this->assertFalse(PreImage::covers($evidence, ['app/Owned/Unseen.php']),
            'an update with no pre-image must never pass as bound');
        $this->assertStringContainsString('grounded=no', implode("\n", PreImage::canonicalParts($evidence)),
            'the omission has to be in the fingerprint, or it is a silent omission');
    }

    public function test_a_verified_absence_is_never_confused_with_an_unobserved_file(): void
    {
        $absent = PreImage::fromGrounding(
            [['path' => 'app/Owned/New.php', 'sha256' => null, 'bytes' => 0, 'exists' => false]],
            [['path' => 'app/Owned/New.php', 'action' => 'create', 'content' => "<?php\n"]]
        );

        $unobserved = PreImage::fromGrounding(
            [],
            [['path' => 'app/Owned/New.php', 'action' => 'create', 'content' => "<?php\n"]]
        );

        $this->assertNotSame($absent, $unobserved);
        $this->assertNotSame(
            PreImage::canonicalParts($absent),
            PreImage::canonicalParts($unobserved),
            '"verified absent" and "never looked" must not hash to the same approval'
        );

        $this->assertTrue(PreImage::isBoundEntry($absent[0]));
        $this->assertFalse(PreImage::isBoundEntry($unobserved[0]));
    }

    // ── integration ─────────────────────────────────────────────────────

    public function test_a_real_grounded_candidate_reloads_with_a_bound_pre_image(): void
    {
        $body = $this->writeTarget('app/Owned/Existing.php', "<?php\n// the real thing\n");

        $uuid = $this->reasonOverUpdateReturningUuid();

        $candidate = ReasoningEngine::make()->store()->candidateByUuid($uuid);

        $this->assertNotNull($candidate);
        $this->assertTrue($candidate->hasBoundPreImage());
        $this->assertSame(hash('sha256', $body), $candidate->preImage[0]['pre_image_sha256']);
    }

    public function test_the_statement_and_the_approval_use_the_bound_fingerprint(): void
    {
        $this->writeTarget('app/Owned/Existing.php', "<?php\n// approvable\n");

        $uuid = $this->reasonOverUpdateReturningUuid();

        $ledger = new ApprovalLedger();
        $row = $ledger->forCandidateUuid($uuid);

        $candidate = ReasoningEngine::make()->store()->candidateByUuid($uuid);
        $task = DB::table('engineering_tasks')->find($row->task_id);
        $project = DB::table('engineering_projects')->find($row->project_id);

        $rebuilt = ApprovalBinding::forCandidate($candidate, $uuid, (string) $task->uuid, (string) $project->key);

        $this->assertSame($row->fingerprint, $rebuilt->fingerprint(),
            'the recorded fingerprint must be reproducible from the stored candidate alone');
        $this->assertNotSame([], $rebuilt->extraParts);

        $statement = $ledger->statement($uuid, (string) $row->fingerprint);
        $this->assertStringContainsString((string) $row->fingerprint, $statement);

        $approved = $ledger->approve($uuid, (string) $row->fingerprint, 1, 'Mark (CEO)');
        $this->assertSame('APPROVED', $approved->state);
        $this->assertSame($statement, $approved->statement);
    }

    public function test_an_approval_quoting_the_pre_e1a_fingerprint_is_refused(): void
    {
        $this->writeTarget('app/Owned/Existing.php', "<?php\n// approvable\n");

        $uuid = $this->reasonOverUpdateReturningUuid();
        $ledger = new ApprovalLedger();
        $row = $ledger->forCandidateUuid($uuid);

        $candidate = ReasoningEngine::make()->store()->candidateByUuid($uuid);
        $task = DB::table('engineering_tasks')->find($row->task_id);
        $project = DB::table('engineering_projects')->find($row->project_id);

        // What the fingerprint would have been without the pre-image bound.
        $postImageOnly = $this->binding(
            new CandidateImplementation($candidate->payload, $candidate->provider, $candidate->model, 'req'),
            []
        );
        $stripped = ApprovalBinding::fromArray(array_merge($postImageOnly->toArray(), [
            'candidate_uuid' => $uuid, 'task_uuid' => (string) $task->uuid, 'project_key' => (string) $project->key,
        ]))->fingerprint();

        $this->assertNotSame($row->fingerprint, $stripped);

        $this->expectExceptionMessageMatches('/does not match this candidate/');
        $ledger->approve($uuid, $stripped, 1, 'Mark (CEO)');
    }

    public function test_rejection_is_unchanged_by_the_pre_image(): void
    {
        $this->writeTarget('app/Owned/Existing.php', "<?php\n// to be refused\n");

        $uuid = $this->reasonOverUpdateReturningUuid();

        $rejected = (new ApprovalLedger())->reject($uuid, 1, 'Mark (CEO)', 'not this way');

        $this->assertSame('REJECTED', $rejected->state);
        $this->assertSame('not this way', $rejected->comment);
    }

    // ── fixtures ────────────────────────────────────────────────────────

    private function writeTarget(string $relative, string $body): string
    {
        $full = $this->repo . '/' . $relative;
        @mkdir(dirname($full), 0775, true);
        file_put_contents($full, $body);

        return $body;
    }

    /** @return array<int,array<string,mixed>> the persisted evidence */
    private function reasonOverUpdate(): array
    {
        $uuid = $this->reasonOverUpdateReturningUuid();

        return json_decode((string) DB::table('engineering_candidates')->where('uuid', $uuid)->value('pre_image'), true);
    }

    private function reasonOverUpdateReturningUuid(): string
    {
        $project = $this->project();
        $task = $this->task($project, 'Rewrite the existing file');

        $this->useUpdateProvider("<?php\n// the proposed replacement\n");

        $outcome = ReasoningEngine::make()->propose($project, $task);

        $this->assertSame('VALIDATED', $outcome->status, 'the fixture proposal must validate: '
            . json_encode($outcome->violations ?? []));

        return (string) DB::table('engineering_candidates')
            ->where('task_id', $task->id)->orderByDesc('id')->value('uuid');
    }

    /**
     * @param bool $withDirectory whether the destination directory already exists,
     *                            which is what decides whether grounding can
     *                            observe the target's absence at all
     * @return array<int,array<string,mixed>>
     */
    private function reasonOverCreate(bool $withDirectory = true): array
    {
        if ($withDirectory) { @mkdir($this->repo . '/app/Owned', 0775, true); }

        $project = $this->project();
        $task = $this->task($project, 'Add a new file');

        $this->useScriptedProvider($this->payload(
            [['path' => 'app/Owned/Reasoned.php', 'action' => 'create', 'why' => 'the deliverable']],
            [['path' => 'app/Owned/Reasoned.php', 'action' => 'create', 'content' => "<?php\n// brand new\n"]]
        ));

        $outcome = ReasoningEngine::make()->propose($project, $task);
        $this->assertSame('VALIDATED', $outcome->status);

        $uuid = DB::table('engineering_candidates')->where('task_id', $task->id)->orderByDesc('id')->value('uuid');

        return json_decode((string) DB::table('engineering_candidates')->where('uuid', $uuid)->value('pre_image'), true);
    }

    private function useUpdateProvider(string $content): void
    {
        $this->useScriptedProvider($this->payload(
            [['path' => 'app/Owned/Existing.php', 'action' => 'update', 'why' => 'the declared target']],
            [['path' => 'app/Owned/Existing.php', 'action' => 'update', 'content' => $content]]
        ));
    }

    /** Evidence for one path, grounded against a real file with the given body. */
    private function evidenceFor(string $path, string $operation, string $body): array
    {
        $this->writeTarget($path, "<?php\n// {$body}\n");

        $ground = (new SourceGrounding($this->repo))->forPaths([$path => $operation]);

        return PreImage::fromGrounding($ground['grounded'], [
            ['path' => $path, 'action' => $operation, 'content' => 'irrelevant here'],
        ]);
    }

    private function candidateFor(string $path, string $action, string $content, array $preImage = []): CandidateImplementation
    {
        return new CandidateImplementation(
            $this->payload([['path' => $path, 'action' => $action, 'why' => 'fixture']],
                           [['path' => $path, 'action' => $action, 'content' => $content]]),
            'scripted', 'fixture', 'req', $preImage
        );
    }

    private function binding(CandidateImplementation $candidate, array $preImage): ApprovalBinding
    {
        $bound = new CandidateImplementation(
            $candidate->payload, $candidate->provider, $candidate->model, $candidate->requestFingerprint, $preImage
        );

        return ApprovalBinding::forCandidate($bound, 'candidate-uuid', 'task-uuid', 'project-key');
    }

    private function project(): object
    {
        return (new WorkflowEngine())->registerProject([
            'company'         => 'Fixture Co',
            'key'             => 'pre-image-project',
            'name'            => 'Pre-image Project',
            'repository_path' => $this->repo,
            'phpunit_config'  => 'phpunit.e888.xml',
            'test_database'   => 'levelup_e888_test',
        ]);
    }

    private function task(object $project, string $title): object
    {
        return (new WorkflowEngine())->createTask($project->key, [
            'title'               => $title,
            'description'         => $title . ' — fixture task for the pre-image binding.',
            'acceptance_criteria' => ['the approval binds both sides of the change'],
            'change_set'          => [],
        ]);
    }

    private function useScriptedProvider(array $payload): void
    {
        config([
            'engineer888_reasoning.provider'          => 'scripted',
            'engineer888_reasoning.scripted.response' => $payload,
            'engineer888_reasoning.scripted.label'    => 'pre-image-fixture',
        ]);
    }

    private function payload(array $filesAffected, array $fileChanges): array
    {
        return [
            'problem_understanding'   => 'A file under an owned path must carry different content.',
            'assumptions'             => ['the destination directory may not exist yet'],
            'unknowns'                => [],
            'implementation_strategy' => 'Write the declared content to the declared path.',
            'files_affected'          => $filesAffected,
            'migrations'              => ['required' => false, 'detail' => 'no schema change'],
            'risks'                   => [['risk' => 'none material', 'breaks' => 'nothing', 'mitigation' => 'n/a']],
            'testing_strategy'        => 'php -l, then the project suite.',
            'rollback'                => 'SafeInstaller backs up before any overwrite.',
            'file_changes'            => $fileChanges,
            'test_coverage' => [
                'behaviour_changed'    => 'installs a fixture class used to exercise the approval gate',
                'test_files_proposed'  => [],
                'existing_tests'       => ['tests/Feature/Engineer888/CommandCenterTest.php'],
                'expected_assertions'  => ['the approved bytes are the bytes installed'],
                'regression_prevented' => 'an approval that does not bind to exact content',
                'test_database'        => 'levelup_e888_test',
                'full_suite_required'  => false,
                'gaps'                 => [],
                'classification'       => 'TESTED',
                'confidence'           => 'high',
            ],
            'confidence'              => 'high',
            'confidence_basis'        => 'the change is a single file',
        ];
    }

    private function rmrf(string $path): void
    {
        if (! is_dir($path)) { @unlink($path); return; }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            $this->rmrf($path . '/' . $entry);
        }
        @rmdir($path);
    }
}
