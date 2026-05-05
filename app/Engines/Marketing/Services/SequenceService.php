<?php

namespace App\Engines\Marketing\Services;

use Illuminate\Support\Facades\DB;

class SequenceService
{
    public function createSequence(int $wsId, array $data): array
    {
        $id = DB::table('sequences')->insertGetId([
            'workspace_id'        => $wsId,
            'name'                => $data['name'] ?? 'Untitled Sequence',
            'status'              => 'draft',
            'trigger_type'        => $data['trigger_type'] ?? 'manual',
            'trigger_config_json' => json_encode($data['trigger_config'] ?? []),
            'steps_json'          => json_encode($data['steps'] ?? []),
            'stats_json'          => json_encode(['enrolled' => 0, 'completed' => 0, 'converted' => 0]),
            'created_by'          => $data['user_id'] ?? null,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // If steps included on create, also materialize into sequence_steps
        if (!empty($data['steps']) && is_array($data['steps'])) {
            $this->replaceSteps($id, $data['steps']);
        }

        return ['sequence_id' => $id, 'status' => 'draft'];
    }

    public function listSequences(int $wsId): array
    {
        $rows = DB::table('sequences')
            ->where('workspace_id', $wsId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->get();

        return ['sequences' => $rows->toArray(), 'total' => $rows->count()];
    }

    public function getSequence(int $wsId, int $id): ?object
    {
        $seq = DB::table('sequences')
            ->where('workspace_id', $wsId)
            ->where('id', $id)
            ->whereNull('deleted_at')
            ->first();

        if ($seq) {
            $seq->steps = DB::table('sequence_steps')
                ->where('sequence_id', $id)
                ->orderBy('step_order')
                ->get()
                ->toArray();
        }
        return $seq;
    }

    public function updateSequence(int $id, array $data): array
    {
        $update = array_intersect_key($data, array_flip(['name', 'status', 'trigger_type']));
        if (isset($data['trigger_config'])) {
            $update['trigger_config_json'] = json_encode($data['trigger_config']);
        }
        $update['updated_at'] = now();
        DB::table('sequences')->where('id', $id)->update($update);
        return ['updated' => true];
    }

    public function deleteSequence(int $id): bool
    {
        DB::table('sequences')->where('id', $id)->update(['deleted_at' => now()]);
        return true;
    }

    public function toggleSequence(int $id, string $status): array
    {
        $allowed = ['draft', 'active', 'paused', 'archived'];
        if (!in_array($status, $allowed, true)) {
            return ['toggled' => false, 'error' => 'invalid_status'];
        }
        DB::table('sequences')->where('id', $id)->update([
            'status' => $status, 'updated_at' => now(),
        ]);
        return ['toggled' => true, 'status' => $status];
    }

    public function addStep(int $sequenceId, array $data): array
    {
        $nextOrder = (int) DB::table('sequence_steps')->where('sequence_id', $sequenceId)->max('step_order') + 1;

        $stepId = DB::table('sequence_steps')->insertGetId([
            'sequence_id'     => $sequenceId,
            'step_order'      => $data['step_order'] ?? $nextOrder,
            'type'            => $data['type']            ?? 'email',
            'delay_hours'     => (int) ($data['delay_hours'] ?? 0),
            'email_subject'   => $data['email_subject']   ?? null,
            'email_body_html' => $data['email_body_html'] ?? null,
            'template_id'     => $data['template_id']     ?? null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->syncStepsSnapshot($sequenceId);
        return ['step_id' => $stepId];
    }

    public function removeStep(int $sequenceId, int $stepId): bool
    {
        DB::table('sequence_steps')
            ->where('sequence_id', $sequenceId)
            ->where('id', $stepId)
            ->delete();
        $this->syncStepsSnapshot($sequenceId);
        return true;
    }

    // ── internals ──────────────────────────────────────────────

    private function replaceSteps(int $sequenceId, array $steps): void
    {
        DB::table('sequence_steps')->where('sequence_id', $sequenceId)->delete();
        foreach (array_values($steps) as $i => $s) {
            DB::table('sequence_steps')->insert([
                'sequence_id'     => $sequenceId,
                'step_order'      => $s['step_order'] ?? $i + 1,
                'type'            => $s['type']            ?? 'email',
                'delay_hours'     => (int) ($s['delay_hours'] ?? 0),
                'email_subject'   => $s['email_subject']   ?? null,
                'email_body_html' => $s['email_body_html'] ?? null,
                'template_id'     => $s['template_id']     ?? null,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
        $this->syncStepsSnapshot($sequenceId);
    }

    private function syncStepsSnapshot(int $sequenceId): void
    {
        $steps = DB::table('sequence_steps')
            ->where('sequence_id', $sequenceId)
            ->orderBy('step_order')
            ->get()
            ->toArray();

        DB::table('sequences')->where('id', $sequenceId)->update([
            'steps_json' => json_encode($steps),
            'updated_at' => now(),
        ]);
    }
}
