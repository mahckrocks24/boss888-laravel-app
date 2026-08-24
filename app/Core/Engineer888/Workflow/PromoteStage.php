<?php

namespace App\Core\Engineer888\Workflow;

use Illuminate\Support\Facades\DB;

/**
 * 14. Does anything here outlive this project?
 *
 * Assets are now written with the project that proved them. That scoping is what
 * makes the reasoning engine's knowledge selection honest across projects: a
 * lesson learned in LevelUp Growth is offered to LevelUp Growth, not presented
 * to a future project as a company standard it never earned.
 *
 * A NULL project_id means company-wide, and nothing here writes one. Raising an
 * asset from project-proven to company-wide is a deliberate human act, because
 * it is a claim about generality that one implementation cannot support.
 */
final class PromoteStage extends BaseStage
{
    public function name(): string { return 'PROMOTE'; }
    public function purpose(): string { return 'Turn proven work into a reusable company asset, so future projects do not start from zero.'; }
    public function inputs(): array { return ['learn.findings', 'task.uuid']; }
    public function outputs(): array { return ['promote.assets']; }
    public function failureModes(): array {
        return [
            'an asset is promoted without implementation evidence',
            'an asset proved in one project is presented as company-wide',
        ];
    }
    public function verification(): string { return 'every promoted asset references the task that proved it and the project it was proved in'; }
    public function recovery(): string { return 'not applicable — promoting nothing is the common and correct outcome'; }

    public function run(WorkflowContext $context): StageResult
    {
        $findings = $context->get('learn.findings', []);
        if ($findings === []) {
            return StageResult::ok('nothing promoted',
                ['assets' => []],
                ['note' => 'no finding carried enough evidence. Most tasks promote nothing, and a register '
                         . 'padded with unproven entries is worse than an empty one.']);
        }

        $promoted = [];
        foreach ($findings as $finding) {
            $name = substr(preg_replace('/[^a-z0-9]+/i', '-', strtolower($finding['finding'])), 0, 80);

            $existing = DB::table('engineering_assets')
                ->where('kind', $finding['candidate'])->where('name', $name)->first();
            if ($existing !== null) { continue; }

            DB::table('engineering_assets')->insert([
                'kind'             => $finding['candidate'],
                'project_id'       => $context->project->id,
                'name'             => $name,
                'summary'          => $finding['finding'],
                'evidence_task_id' => $context->task->id,
                'evidence'         => $finding['evidence'],
                'promoted_by'      => $context->task->session,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            $promoted[] = ['kind' => $finding['candidate'], 'name' => $name, 'scope' => $context->project->key];
        }

        return StageResult::ok(count($promoted) . ' asset(s) promoted', ['assets' => $promoted],
            ['note' => 'scoped to this project; company-wide promotion is a separate human decision']);
    }
}
