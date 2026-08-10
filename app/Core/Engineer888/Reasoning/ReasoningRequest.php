<?php

namespace App\Core\Engineer888\Reasoning;

/**
 * The engineering context handed to a reasoning provider.
 *
 * A provider must never receive only a prompt. It receives the project, the
 * task, the acceptance criteria, and whichever institutional knowledge the
 * ContextBuilder judged relevant — and nothing else. What was deliberately left
 * out travels alongside in `excluded`, so a poor proposal can be traced to a
 * poor selection rather than guessed at.
 *
 * Immutable and provider-neutral. Rendering to messages is each provider's own
 * business; the shared part is that every provider is asked the same question
 * in the same words, which is what makes them comparable and replaceable.
 */
final class ReasoningRequest
{
    public function __construct(
        /** @var array{key:string,name:string,company:string,repository_path:string} */
        public readonly array $project,
        /** @var array<string,mixed> uuid, title, description, kind, priority, criteria, constraints, modules */
        public readonly array $task,
        /** @var array<string,array<int,array<string,mixed>>> selected knowledge, by source */
        public readonly array $sections,
        /** @var array<int,array{source:string,item:string,reason:string}> what was left out, and why */
        public readonly array $excluded,
        /** @var array<string,mixed> the structure the provider must return */
        public readonly array $outputContract,
        /**
         * What the repository actually is, read from disk before anything was
         * designed. Optional so every existing caller keeps working; when it is
         * absent the provider is told nothing about the tree, which is exactly
         * the condition that produced the 2026-08-10 Laravel proposal.
         */
        public readonly ?RepositoryMap $repositoryMap = null,
        /**
         * The requirements the task listed, as obligations rather than prose.
         * Optional for the same reason as the map: every existing caller keeps
         * working, and a task with no checklist adds nothing.
         */
        public readonly ?RequirementSet $requirements = null,
    ) {}

    /** The same request, with the task's own checklist attached. */
    public function withRequirements(?RequirementSet $requirements): self
    {
        return new self($this->project, $this->task, $this->sections,
                        $this->excluded, $this->outputContract, $this->repositoryMap, $requirements);
    }

    /** The same request, with discovery attached. */
    public function withRepositoryMap(?RepositoryMap $map): self
    {
        return new self($this->project, $this->task, $this->sections,
                        $this->excluded, $this->outputContract, $map, $this->requirements);
    }

    /**
     * The question, rendered deterministically.
     *
     * Deterministic because it is fingerprinted: the same context must produce
     * the same fingerprint, or the drift check between approval and
     * implementation would fire at random.
     */
    public function renderText(): string
    {
        $out = [];

        $out[] = '# ROLE';
        $out[] = 'You are contributing engineering reasoning to a governed engineering workflow.';
        $out[] = 'You do not deploy, approve, or verify. Your proposal will be validated, reviewed';
        $out[] = 'by a human, and executed by a separate system that can refuse it.';
        $out[] = '';
        $out[] = '# PROJECT';
        $out[] = 'company: ' . $this->project['company'];
        $out[] = 'project: ' . $this->project['name'] . ' (' . $this->project['key'] . ')';
        $out[] = 'repository: ' . $this->project['repository_path'];

        // DISCOVERY BEFORE DESIGN. Placed immediately after the project header
        // and before the task, because what the repository IS has to be settled
        // before the model reads what it is being asked to do.
        if ($this->repositoryMap !== null) {
            $out[] = '';
            $out[] = $this->repositoryMap->render();
        }

        $out[] = '';
        $out[] = '# TASK';
        $out[] = 'title: ' . ($this->task['title'] ?? '');
        $out[] = 'kind: ' . ($this->task['kind'] ?? 'feature') . ' · priority: ' . ($this->task['priority'] ?? 'normal');
        $out[] = '';
        $out[] = (string) ($this->task['description'] ?? '');

        // THE CHECKLIST, RIGHT AFTER THE BRIEF THAT CONTAINS IT.
        //
        // Candidate 7e717943 skipped the README and every line of validation
        // while satisfying every structural rule, because the requirements were
        // prose inside the description and nothing made them obligations.
        if ($this->requirements !== null && ! $this->requirements->isEmpty()) {
            $out[] = '';
            $out[] = $this->requirements->render();
        }

        foreach (['acceptance_criteria' => 'ACCEPTANCE CRITERIA',
                  'constraints'         => 'KNOWN CONSTRAINTS',
                  'modules'             => 'AFFECTED MODULES'] as $key => $heading) {
            $values = $this->task[$key] ?? [];
            if ($values === []) { continue; }
            $out[] = '';
            $out[] = '## ' . $heading;
            foreach ($values as $value) { $out[] = '- ' . (is_array($value) ? json_encode($value) : $value); }
        }

        foreach ($this->sections as $source => $items) {
            if ($items === []) { continue; }
            $out[] = '';
            $out[] = '# CONTEXT: ' . strtoupper(str_replace('_', ' ', $source));
            foreach ($items as $item) {
                $out[] = '- ' . $this->renderItem($item);
            }
        }

        // ── THE WHOLE-FILE MANDATE ───────────────────────────────────────
        //
        // Placed here, immediately before the response schema, because that is
        // the last thing the model reads. On 2026-08-07 the complete 13,520
        // bytes of CandidateValidator.php were supplied and gpt-4o still
        // answered with 107 bytes of prose describing the change — with 6,700
        // completion tokens still unused. It was not truncated and it was not
        // short of context. It simply had not been told, in the place it would
        // act on, that the file itself was the required answer.
        //
        // Only files that already exist are named. A create target has no
        // current bytes to reproduce, and its behaviour is unchanged.
        $existingTargets = [];

        foreach (($this->sections['grounded_source'] ?? []) as $item) {
            $label = (string) ($item['label'] ?? '');
            if (str_contains($label, 'does not exist yet')) { continue; }
            if (preg_match('/^FILE (\S+) /', $label, $m)) { $existingTargets[] = $m[1]; }
        }

        // THE MANDATE THAT WAS ONLY EVER SENT FOR UPDATES.
        //
        // Everything below used to sit inside `if ($existingTargets !== [])`,
        // so it was emitted only when the model was changing a file that already
        // existed. On a greenfield build every change is a create, the list is
        // empty, and the entire instruction — content must be complete source,
        // no placeholders, no descriptions — was never sent at all.
        //
        // Measured across three acceptance runs on 2026-08-11 while this was
        // conditional: 4546 bytes of real code, then 2157 bytes of stubs, then
        // 313 bytes of stubs. The model was never told what the content field
        // was for, and drifted towards outlining it.
        $out[] = '';
        $out[] = '# THE CONTENT FIELD IS THE DELIVERABLE';
        $out[] = 'Every file_changes[].content is written to disk verbatim. It must be the';
        $out[] = 'COMPLETE, FINAL, RUNNABLE source of that file.';
        $out[] = '';
        $out[] = 'DO NOT return an outline, a skeleton, or a class with empty methods.';
        $out[] = 'DO NOT return a description of what the file will contain.';
        $out[] = 'DO NOT leave a method body as a comment saying what it should do:';
        $out[] = '  public function validate() { // Implement validation logic }';
        $out[] = 'is refused by the validator, and one such body rejects the whole candidate.';
        $out[] = 'DO NOT return a diff, a patch, or only the part that changed.';
        $out[] = 'DO NOT use placeholders such as "...existing code..." or "rest unchanged".';
        $out[] = '';
        $out[] = 'FEWER FILES, FULLY IMPLEMENTED, BEATS MORE FILES LEFT EMPTY. If the budget';
        $out[] = 'is tight, reduce the number of files — never the completeness of one.';
        $out[] = 'Spend your output on content; keep the prose sections short.';

        if ($existingTargets !== []) {
            $out[] = '';
            // The heading is load-bearing. SourceGroundingTest asserts both that
            // it appears before the schema and that a create-only candidate never
            // sees it — a new file has no current bytes to reproduce. The new
            // unconditional block above carries its own heading precisely so this
            // one can keep meaning exactly what it always meant.
            $out[] = '# COMPLETE FILE OUTPUT IS REQUIRED';
            $out[] = 'YOU HAVE BEEN GIVEN THE COMPLETE CURRENT CONTENTS OF THESE FILES:';
            foreach ($existingTargets as $path) { $out[] = '- ' . $path; }
            $out[] = '';
            $out[] = 'YOUR OUTPUT FOR EACH OF THEM MUST BE THE COMPLETE FINAL FILE CONTENT.';
            $out[] = '';
            $out[] = 'DO NOT describe the change.';
            $out[] = 'DO NOT summarize the change.';
            $out[] = 'DO NOT return instructions.';
            $out[] = 'DO NOT return a diff or a patch.';
            $out[] = 'DO NOT omit unchanged lines.';
            $out[] = 'DO NOT use placeholders such as "...existing code..." or "rest unchanged".';
            $out[] = 'DO NOT return only the changed function.';
            $out[] = 'DO NOT return prose in the content field.';
            $out[] = '';
            $out[] = 'YOU MUST reproduce every unchanged portion of the file verbatim.';
            $out[] = 'YOU MUST incorporate the requested modification.';
            $out[] = 'YOU MUST return syntactically complete final source.';
            $out[] = 'The content you return replaces the file byte for byte, so a partial';
            $out[] = 'answer does not produce a smaller change — it produces a broken file.';
            $out[] = '';
            $out[] = 'If you cannot produce the complete file, set that file_changes entry\'s';
            $out[] = 'content to exactly COMPLETE_FILE_OUTPUT_UNAVAILABLE and explain why in';
            $out[] = 'its rationale. An honest refusal is usable; an improvised fragment is not.';
        }
        if (($this->sections['verified_tests'] ?? []) !== []) {
            $out[] = '';
            $out[] = '# CITING EXISTING TESTS';
            $out[] = 'The VERIFIED EXISTING TESTS section above is the complete list of test';
            $out[] = 'files that exist here and relate to these targets.';
            $out[] = '';
            $out[] = 'test_coverage.existing_tests may contain ONLY paths from that list.';
            $out[] = 'DO NOT invent an existing test path.';
            $out[] = 'DO NOT claim a file that does not exist already covers this behaviour.';
            $out[] = 'If none of them covers it, say so and propose a NEW test instead:';
            $out[] = 'put the new test in file_changes with action create, and list it in';
            $out[] = 'test_coverage.test_files_proposed — never in existing_tests.';
            $out[] = 'Where the contract permits uncertainty, UNKNOWN is a valid answer and';
            $out[] = 'is never a filename. Saying there is no coverage yet is more useful';
            $out[] = 'than naming a file that is not there.';
        }

        $out[] = '';
        $out[] = '# REQUIRED OUTPUT';
        $out[] = 'Return a single JSON object with exactly these keys. Any value you cannot';
        $out[] = 'justify from the context above must be the string "UNKNOWN" — a plausible';
        $out[] = 'guess presented as fact is the one failure this workflow cannot catch.';
        $out[] = '';
        $out[] = json_encode($this->outputContract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return implode("\n", $out);
    }

    private function renderItem(array $item): string
    {
        $label = $item['label'] ?? ($item['name'] ?? 'item');
        $body  = $item['body'] ?? '';

        return trim($label . ($body !== '' ? ': ' . $body : ''));
    }

    /** Stable identity of this context, used to detect drift between stages. */
    public function fingerprint(): string
    {
        return sha1($this->renderText());
    }

    public function sizeBytes(): int
    {
        return strlen($this->renderText());
    }

    /**
     * What was sent, by source — counts and bytes, not content.
     *
     * Stored with every candidate. It answers "did the model know about the
     * incident register when it proposed this?" without archiving the whole
     * knowledge base on every task.
     *
     * @return array<string,array{items:int,bytes:int}>
     */
    public function contextManifest(): array
    {
        $manifest = [];
        foreach ($this->sections as $source => $items) {
            $bytes = 0;
            foreach ($items as $item) { $bytes += strlen($this->renderItem($item)); }
            $manifest[$source] = ['items' => count($items), 'bytes' => $bytes];
        }

        return $manifest;
    }

    public function toArray(): array
    {
        return [
            'project'          => $this->project,
            'task'             => $this->task,
            'context_manifest' => $this->contextManifest(),
            'repository_map'   => $this->repositoryMap?->toArray(),
            'requirements'     => $this->requirements?->toArray(),
            'excluded'         => $this->excluded,
            'fingerprint'      => $this->fingerprint(),
            'size_bytes'       => $this->sizeBytes(),
        ];
    }
}
