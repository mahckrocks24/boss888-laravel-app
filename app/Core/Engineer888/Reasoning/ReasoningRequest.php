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
    ) {}

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
        $out[] = '';
        $out[] = '# TASK';
        $out[] = 'title: ' . ($this->task['title'] ?? '');
        $out[] = 'kind: ' . ($this->task['kind'] ?? 'feature') . ' · priority: ' . ($this->task['priority'] ?? 'normal');
        $out[] = '';
        $out[] = (string) ($this->task['description'] ?? '');

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

        if ($existingTargets !== []) {
            $out[] = '';
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
            'excluded'         => $this->excluded,
            'fingerprint'      => $this->fingerprint(),
            'size_bytes'       => $this->sizeBytes(),
        ];
    }
}
