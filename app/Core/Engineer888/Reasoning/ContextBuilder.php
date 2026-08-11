<?php

namespace App\Core\Engineer888\Reasoning;

use App\Core\Engineer888\Repository\ArchitectureMap;

/**
 * Chooses what the model gets to know.
 *
 * The temptation is to send everything: the whole incident register, every
 * promoted asset, the entire architecture. That is worse than sending too
 * little. Relevant knowledge buried under irrelevant knowledge is knowledge the
 * model will not use, and it costs the same as useful context.
 *
 * So each item is scored against the task's own vocabulary and dropped when it
 * scores nothing. Every drop is recorded with a reason — a bad proposal should
 * be traceable to what the model was and was not told, not left as a mystery.
 */
final class ContextBuilder
{
    /** Words too common to indicate relevance. */
    private const STOPWORDS = [
        'the', 'and', 'for', 'that', 'this', 'with', 'from', 'into', 'when', 'which',
        'have', 'has', 'was', 'were', 'are', 'not', 'but', 'its', 'it', 'a', 'an',
        'should', 'must', 'can', 'will', 'would', 'each', 'than', 'then', 'them',
        'file', 'files', 'code', 'change', 'changes', 'task', 'work', 'make', 'add',
        'php', 'laravel', 'app', 'core', 'test', 'tests',
    ];

    public function __construct(
        private readonly KnowledgeBase $knowledge,
        private readonly int $maxBytes = 60000,
        private readonly int $maxItemsPerSource = 6,
        private readonly int $minScore = 1,
    ) {}

    /**
     * @param array<int,array<string,mixed>> $extraItems structural items the
     *        caller has already decided are relevant — currently the revision
     *        instruction, which must reach the provider whatever its wording
     *        overlap with the task happens to be.
     */
    public function build(object $project, object $task, array $extraItems = []): ReasoningRequest
    {
        $taskData = [
            'uuid'                => $task->uuid,
            'title'               => (string) $task->title,
            'description'         => (string) $task->description,
            'kind'                => (string) $task->kind,
            'priority'            => (string) $task->priority,
            'acceptance_criteria' => $this->json($task->acceptance_criteria ?? null),
            'constraints'         => $this->json($task->constraints ?? null),
            'modules'             => $this->json($task->modules ?? null),
        ];

        $terms = $this->focusTerms($taskData, $project);

        // Gather everything the knowledge base can offer, then select.
        $assets = $this->knowledge->assets((int) $project->id);
        $excluded = $assets['excluded'];

        $pool = array_merge(
            $assets['items'],
            $this->knowledge->failureHistory((int) $project->id),
            $this->knowledge->taskHistory((int) $project->id),
            $this->knowledge->incidents(),
            $this->knowledge->executionConstraints($project),
            $this->architecture($project, $taskData),
        );

        // Ownership boundaries and execution constraints are structural: they
        // apply to every task regardless of vocabulary, so they bypass scoring.
        //
        // code_surface is structural for a different reason: it is selected by
        // the task naming a class, so scoring it against the task's vocabulary
        // would only re-derive the selection that already happened.
        //
        // revision_instruction is structural because a human wrote it about
        // THIS proposal. Scoring a reviewer's words against the task's
        // vocabulary could drop the very correction the revision exists to make.
        // grounded_source leads: it is the exact bytes the proposal must
        // reproduce, so a summary is dropped long before a target file is.
        // coding_standards is exempt from the PER-SOURCE CAP, which is what the
        // structural list actually controls alongside scoring.
        //
        // This exemption was added once before on the assumption that scoring
        // would drop a standard, could not be demonstrated at any byte budget,
        // and was reverted rather than kept as insurance nobody could measure.
        // The evidence arrived when the ninth standard was registered on
        // 2026-08-11: max_items_per_source is 6, so three of the nine — the
        // documented-command rule, the error-rendering rule and the write-path
        // rule — were silently absent from the prompt.
        //
        // A knowledge source is sampled because more examples add little. A
        // standard is not an example: every one of them is a rule that has to
        // hold, and the six that survive a cap are chosen by insertion order,
        // which is to say by accident.
        $always = ['grounded_source', 'verified_tests', 'ownership_boundaries', 'test_constraints',
                   'deployment_constraints', 'code_surface', 'revision_instruction', 'coding_standards'];
        $pool = array_merge(
            $pool,
            $extraItems,
            $this->knowledge->ownershipBoundaries(),
            $this->knowledge->codeSurface($taskData['title'] . ' ' . $taskData['description'] . ' '
                . implode(' ', array_map(fn ($v) => is_array($v) ? json_encode($v) : (string) $v,
                    $taskData['acceptance_criteria'])))
        );

        $sections = [];
        $budget = $this->maxBytes;

        // Structural sections first — they must survive a tight budget.
        foreach ([true, false] as $structuralPass) {
            foreach ($pool as $item) {
                $section = (string) ($item['section'] ?? 'context');
                $isStructural = in_array($section, $always, true);
                if ($isStructural !== $structuralPass) { continue; }

                $text = trim(((string) ($item['label'] ?? '')) . ' ' . ((string) ($item['body'] ?? '')));
                $score = $isStructural ? PHP_INT_MAX : $this->score($text, $terms);

                if (! $isStructural && $score < $this->minScore) {
                    $excluded[] = ['source' => $section, 'item' => (string) ($item['label'] ?? ''),
                                   'reason' => 'no vocabulary overlap with this task'];
                    continue;
                }

                // Structural sections are not capped. The per-source limit
                // exists to stop one knowledge source crowding out the others;
                // a boundary or a reviewer's correction is not competing for
                // that space, and truncating it would silently withhold the
                // thing the provider most needs.
                if (! $isStructural && count($sections[$section] ?? []) >= $this->maxItemsPerSource) {
                    $excluded[] = ['source' => $section, 'item' => (string) ($item['label'] ?? ''),
                                   'reason' => 'lower relevance than the ' . $this->maxItemsPerSource . ' already selected'];
                    continue;
                }

                $cost = strlen($text);
                if ($cost > $budget) {
                    $excluded[] = ['source' => $section, 'item' => (string) ($item['label'] ?? ''),
                                   'reason' => 'context budget exhausted'];
                    continue;
                }

                $budget -= $cost;
                $item['score'] = $isStructural ? 'structural' : $score;
                $sections[$section][] = $item;
            }
        }

        // Highest-scoring first within each section, so truncation elsewhere
        // never buries the most relevant item.
        foreach ($sections as $name => $items) {
            usort($items, fn ($a, $b) => ($b['score'] === 'structural' ? PHP_INT_MAX : $b['score'])
                                       <=> ($a['score'] === 'structural' ? PHP_INT_MAX : $a['score']));
            $sections[$name] = $items;
        }

        return new ReasoningRequest(
            project: [
                'key'             => (string) $project->key,
                'name'            => (string) $project->name,
                'company'         => (string) $project->company,
                'repository_path' => (string) $project->repository_path,
            ],
            task: $taskData,
            sections: $sections,
            excluded: $excluded,
            outputContract: CandidateImplementation::CONTRACT,
        );
    }

    /**
     * The task's own vocabulary — what "relevant" means for this task.
     *
     * @return array<int,string>
     */
    public function focusTerms(array $task, object $project): array
    {
        $text = implode(' ', [
            $task['title'], $task['description'],
            implode(' ', array_map(fn ($v) => is_array($v) ? json_encode($v) : (string) $v, $task['acceptance_criteria'])),
            implode(' ', array_map(fn ($v) => is_array($v) ? json_encode($v) : (string) $v, $task['modules'])),
            (string) $project->key,
        ]);

        $words = preg_split('/[^a-zA-Z0-9]+/', strtolower($text)) ?: [];
        $terms = [];
        foreach ($words as $word) {
            if (strlen($word) < 4) { continue; }
            if (in_array($word, self::STOPWORDS, true)) { continue; }
            $terms[$word] = true;
        }

        return array_keys($terms);
    }

    /** How many distinct task terms this item mentions. */
    private function score(string $text, array $terms): int
    {
        $haystack = strtolower($text);
        $score = 0;
        foreach ($terms as $term) {
            if (str_contains($haystack, $term)) { $score++; }
        }

        return $score;
    }

    /**
     * The subsystems this task touches, from the repository's own vocabulary.
     *
     * Reuses ArchitectureMap rather than describing the architecture here; the
     * map is discovered from the tree and cannot go stale the way a hand-written
     * description would.
     *
     * @return array<int,array<string,mixed>>
     */
    private function architecture(object $project, array $task): array
    {
        if (! is_dir($project->repository_path)) { return []; }

        try {
            $map = new ArchitectureMap($project->repository_path);
            $subsystems = $map->vocabulary();
        } catch (\Throwable $e) {
            return [];
        }

        $items = [];
        foreach ($subsystems as $subsystem) {
            $items[] = [
                'section' => 'architecture',
                'label'   => (string) $subsystem,
                'body'    => 'subsystem present in this repository',
            ];
        }

        return $items;
    }

    private function json(mixed $raw): array
    {
        if (is_array($raw)) { return $raw; }
        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
