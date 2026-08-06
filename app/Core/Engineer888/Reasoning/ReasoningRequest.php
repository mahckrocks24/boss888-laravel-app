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
