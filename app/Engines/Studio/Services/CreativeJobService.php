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

            $job = CreativeJob::create([
                'workspace_id'    => (int) ($ctx['workspace_id'] ?? 0),
                'user_id'         => $ctx['user_id'] ?? null,
                'task_id'         => $ctx['task_id'] ?? null,
                'parent_job_id'   => $ctx['parent_job_id'] ?? null,
                'type'            => $ctx['type'] ?? 'generation',
                'capability'      => $ctx['capability'] ?? null,
                'status'          => 'running',
                'original_prompt' => $ctx['original_prompt'] ?? null,
                'compiled_prompt' => null, // populated below by the shadow compiler (Phase J)
                'metadata'        => ['source' => $ctx['source'] ?? null],
                'started_at'      => now(),
            ]);

            // Phase J — SHADOW prompt compilation (observational; never affects execution).
            $this->applyPromptCompiler($job, $ctx);

            return $job;
        } catch (\Throwable $e) {
            Log::warning('[CreativeJob] begin failed (execution unaffected): ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Phase J — run the deterministic Prompt Compiler in shadow mode and persist
     * its output onto the CreativeJob. Fully isolated: a compiler fault leaves
     * compiled_prompt NULL and never touches execution, billing, or assets.
     */
    private function applyPromptCompiler(CreativeJob $job, array $ctx): void
    {
        try {
            $compiler = app(\App\Engines\Studio\Compiler\PromptCompilerService::class);
            if (! $compiler->enabled()) {
                return;
            }

            $result = $compiler->compile([
                'prompt'           => (string) ($ctx['original_prompt'] ?? ''),
                'capability'       => $ctx['capability'] ?? null,
                'provider'         => $ctx['provider'] ?? null,
                'model'            => $ctx['model'] ?? null,
                'reference_images' => $ctx['reference_images'] ?? [],
                'workspace_id'     => $ctx['workspace_id'] ?? null,
            ]);

            $metaUpdate = [
                'compiler' => [
                    'version'    => $result->compilerVersion,
                    'confidence' => $result->confidence,
                    'warnings'   => $result->warnings,
                    'comparison' => $result->comparison,
                    'meta'       => $result->metadata,
                ],
            ];

            // Phase K — SHADOW guardrails over the compiler output (isolated; a
            // guardrail fault omits the report but never affects compiled_prompt
            // persistence or execution).
            try {
                $guardrails = app(\App\Engines\Studio\Guardrail\PromptGuardrailService::class);
                if ($guardrails->enabled()) {
                    $metaUpdate['guardrails'] = $guardrails->evaluate($result)->toArray();
                }
            } catch (\Throwable $ge) {
                Log::warning('[PromptGuardrail] shadow eval failed (execution unaffected): ' . $ge->getMessage());
            }

            $job->update([
                'compiled_prompt' => $result->compiledPrompt,
                'generation_spec' => $result->spec->toArray(),
                'metadata'        => array_merge((array) ($job->metadata ?? []), $metaUpdate),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[PromptCompiler] shadow compile failed (execution unaffected): ' . $e->getMessage());
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

            // Phase L — SHADOW execution observation + compiler-readiness comparison.
            $this->applyReadiness($job, $assetId, $data);
        } catch (\Throwable $e) {
            Log::warning('[CreativeJob] complete failed (execution unaffected): ' . $e->getMessage());
        }
    }

    /**
     * Phase L — capture the actual production provider prompt (non-invasively,
     * from the persisted asset) and run the deterministic shadow comparison /
     * readiness scoring. Fully isolated + flag-gated: any fault here leaves the
     * job, asset, billing, and provider result untouched.
     */
    private function applyReadiness(CreativeJob $job, ?int $assetId, array $data): void
    {
        try {
            if (! (bool) config('studio.execution_prompt_observation', true)) {
                return;
            }

            // Observe the actual provider prompt from assets.prompt — enhanced for
            // the EES/CreativeService path, raw for the Sarah/connector path. This
            // reads existing persisted data; the live provider request is untouched.
            $observedRaw = null;
            $truncated = false;
            if ($assetId) {
                $row = DB::table('assets')->where('id', $assetId)->first(['prompt']);
                if ($row && $row->prompt !== null) {
                    $observedRaw = (string) $row->prompt;
                    $truncated = mb_strlen($observedRaw) >= 250; // varchar(255) / mb_substr(250) storage cap
                }
            }

            $meta = is_array($job->metadata) ? $job->metadata : [];
            $metaUpdate = [];

            if ($observedRaw !== null) {
                $san = \App\Engines\Studio\Readiness\PromptSanitizer::sanitize($observedRaw);
                $metaUpdate['execution_observation'] = [
                    'actual_provider_prompt'      => $san['text'],
                    'actual_provider_prompt_hash' => hash('sha256', $observedRaw),
                    'prompt_length'               => mb_strlen($observedRaw),
                    'capture_version'             => '1.0.0-shadow',
                    'sanitized'                   => $san['redacted'],
                    'truncated'                   => $truncated,
                    'source'                      => 'assets.prompt',
                    'provider'                    => $data['provider'] ?? null,
                    'model'                       => $data['provider_model'] ?? null,
                ];
            }

            if ((bool) config('studio.compiler_readiness_shadow', true)) {
                try {
                    $cmp = app(\App\Engines\Studio\Readiness\PromptComparisonService::class)->compare([
                        'original_prompt'        => $job->original_prompt,
                        'compiled_prompt'        => $job->compiled_prompt,
                        'actual_provider_prompt' => $metaUpdate['execution_observation']['actual_provider_prompt'] ?? null,
                        'generation_spec'        => is_array($job->generation_spec) ? $job->generation_spec : [],
                        'compiler'               => $meta['compiler'] ?? [],
                        'guardrail'              => $meta['guardrails'] ?? [],
                        'capability'             => $job->capability,
                        'observation_truncated'  => $truncated,
                    ]);
                    $metaUpdate['compiler_readiness'] = $cmp->toArray();
                } catch (\Throwable $ce) {
                    $metaUpdate['compiler_readiness'] = ['comparison_failed' => true, 'error' => mb_substr($ce->getMessage(), 0, 200)];
                    Log::warning('[CompilerReadiness] comparison failed (execution unaffected): ' . $ce->getMessage());
                }
            }

            if (! empty($metaUpdate)) {
                $job->update(['metadata' => array_merge($meta, $metaUpdate)]);
            }
        } catch (\Throwable $e) {
            Log::warning('[CompilerReadiness] observation failed (execution unaffected): ' . $e->getMessage());
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
