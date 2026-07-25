<?php

namespace App\Engines\Studio\Services;

use App\Models\CreativeJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * STUDIO888 Phase I — shadow persistence for the observational CreativeJob record.
 *
 * CONTRACT: every method is best-effort and MUST NEVER throw into the caller.
 * A CreativeJob persistence failure can never abort generation, roll back a
 * successful provider output, change billing, assets, or task completion.
 * Generation is authoritative; CreativeJob merely observes.
 *
 * Gated by config('studio.creative_jobs') — OFF ⇒ every method is a no-op and the
 * system behaves exactly as before.
 */
class CreativeJobService
{
    public function enabled(): bool
    {
        // Flag-gated AND schema-gated: if the table is not yet migrated (e.g. a
        // production host pending the deploy window) the feature is SILENTLY inert
        // — no jobs, no log noise — until the migration is applied.
        if (! (bool) config('studio.creative_jobs', true)) {
            return false;
        }

        try {
            return Schema::hasTable('creative_jobs');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Record the START of a Studio execution as a running CreativeJob.
     * Returns the job, or null (flag off / persistence failed / not applicable).
     * For task-backed executions it is idempotent per task (one job across retries);
     * taskless (direct/sync) executions always create a fresh job.
     */
    public function begin(array $ctx): ?CreativeJob
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            if (! empty($ctx['task_id'])) {
                $existing = CreativeJob::where('task_id', $ctx['task_id'])->first();
                if ($existing) {
                    $existing->update([
                        'status'     => 'running',
                        'started_at' => $existing->started_at ?? now(),
                    ]);
                    return $existing;
                }
            }

            return CreativeJob::create([
                'workspace_id'    => (int) ($ctx['workspace_id'] ?? 0),
                'user_id'         => $ctx['user_id'] ?? null,
                'task_id'         => $ctx['task_id'] ?? null,
                'parent_job_id'   => $ctx['parent_job_id'] ?? null,
                'type'            => $ctx['type'] ?? 'generation',
                'capability'      => $ctx['capability'] ?? null,
                'status'          => 'running',
                'original_prompt' => $ctx['original_prompt'] ?? null,
                'compiled_prompt' => null, // Phase J placeholder
                'metadata'        => ['source' => $ctx['source'] ?? null],
                'started_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[CreativeJob] begin failed (execution unaffected): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Finalize a job as completed (or a provided in-flight status such as 'running'
     * for async video). Links the produced asset back via assets.creative_job_id.
     */
    public function complete(?CreativeJob $job, array $data = []): void
    {
        if (! $job) {
            return;
        }

        try {
            $status  = $data['status'] ?? 'completed';
            $assetId = $data['asset_id'] ?? null;

            $update = array_filter([
                'status'            => $status,
                'asset_id'          => $assetId,
                'provider'          => $data['provider'] ?? null,
                'provider_model'    => $data['provider_model'] ?? null,
                'provider_response' => isset($data['provider_response'])
                    ? $this->sanitize((array) $data['provider_response']) : null,
            ], fn ($v) => $v !== null);

            if ($status === 'failed') {
                $update['failed_at'] = now();
            } elseif ($status === 'completed') {
                $update['completed_at'] = now();
            }

            $job->update($update);

            // Populate assets.creative_job_id for the produced asset (associate only).
            if ($assetId) {
                DB::table('assets')->where('id', $assetId)->whereNull('creative_job_id')
                    ->update(['creative_job_id' => $job->id]);
            }
        } catch (\Throwable $e) {
            Log::warning('[CreativeJob] complete failed (execution unaffected): ' . $e->getMessage());
        }
    }

    /** Finalize a job as failed. Best-effort. */
    public function fail(?CreativeJob $job, string $reason): void
    {
        if (! $job) {
            return;
        }

        try {
            $job->update([
                'status'    => 'failed',
                'metadata'  => array_merge((array) ($job->metadata ?? []), [
                    'error' => mb_substr($reason, 0, 300),
                ]),
                'failed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[CreativeJob] fail update failed (execution unaffected): ' . $e->getMessage());
        }
    }

    /** Strip obviously-sensitive keys before persisting any provider payload. */
    private function sanitize(array $data): array
    {
        $blocked = ['api_key', 'apikey', 'authorization', 'secret', 'token', 'password',
                    'x-levelup-secret', 'bearer', 'access_token', 'refresh_token'];
        array_walk_recursive($data, function (&$v, $k) use ($blocked) {
            if (is_string($k) && in_array(strtolower($k), $blocked, true)) {
                $v = '[redacted]';
            }
        });

        return $data;
    }
}
