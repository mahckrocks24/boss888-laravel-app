<?php

namespace App\Core\Engineer888\Deployment;

use App\Core\Engineer888\Coordination\OwnershipManifest;
use App\Core\Engineer888\Signals\Shell;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * What someone said they were deploying.
 *
 * Recording an intent is a deliberate act. Engineer888 does not manufacture one
 * by reading git and assuming HEAD was deployed — on this platform HEAD is
 * routinely not what is running, because the working tree carries hundreds of
 * uncommitted files that production executes directly.
 *
 * With no intent, identity cannot be verified. That is a reportable state, not
 * an error, and it is the state this platform is in most of the time.
 */
final class DeploymentIntent
{
    public function __construct(private string $repoPath) {}

    /**
     * Record an intent. Every expected value is supplied by the caller; none is
     * inferred. Omitted values stay null.
     *
     * @param  array<string,mixed>  $attributes
     */
    public function record(array $attributes): array
    {
        $manifest = OwnershipManifest::active($this->repoPath);

        $uuid = (string) Str::uuid();

        $row = [
            'uuid'                 => $uuid,
            'environment'          => $attributes['environment'] ?? 'production',
            'repository'           => $this->repoPath,
            'expected_branch'      => $attributes['expected_branch'] ?? null,
            'expected_commit'      => $attributes['expected_commit'] ?? null,
            'expected_artifacts'   => isset($attributes['expected_artifacts'])
                ? json_encode($attributes['expected_artifacts']) : null,
            'initiating_session'   => $manifest?->session(),
            'manifest_path'        => $manifest?->path(),
            'related_execution_id' => $attributes['related_execution_id'] ?? null,
            'requested_at'         => $attributes['requested_at'] ?? now(),
            'verification_policy'  => $attributes['policy'] ?? 'standard',
            'deployment_method'    => $attributes['deployment_method'] ?? null,
            'notes'                => $attributes['notes'] ?? null,
            'created_at'           => now(),
            'updated_at'           => now(),
        ];

        $id = DB::table('engineering_deployment_intents')->insertGetId($row);

        return ['id' => $id, 'uuid' => $uuid] + $row;
    }

    /** The intent with this uuid, or the most recent for the environment, or null. */
    public function find(?string $uuid, string $environment = 'production'): ?object
    {
        $query = DB::table('engineering_deployment_intents');

        return $uuid !== null
            ? $query->where('uuid', $uuid)->first()
            : $query->where('environment', $environment)->orderByDesc('id')->first();
    }

    /**
     * Capture the CURRENT git state as a candidate intent, for an engineer who
     * is about to deploy. Explicitly not called during verification: reading
     * HEAD and calling it the expectation is exactly the circular reasoning that
     * turns "no provenance" into a false VERIFIED.
     *
     * @return array<string,mixed>
     */
    public function candidateFromGit(): array
    {
        $branch = Shell::run('git', ['rev-parse', '--abbrev-ref', 'HEAD'], $this->repoPath);
        $commit = Shell::run('git', ['rev-parse', 'HEAD'], $this->repoPath);
        $dirty = Shell::run('git', ['status', '--porcelain', '-uall'], $this->repoPath);

        $dirtyCount = count(array_filter(explode("\n", trim($dirty['out']))));

        return [
            'expected_branch' => trim($branch['out']) ?: null,
            'expected_commit' => trim($commit['out']) ?: null,
            'warning' => $dirtyCount > 0
                ? "the working tree has {$dirtyCount} uncommitted files, so this commit does NOT describe "
                . 'what production runs. Recording it as the expectation would make a later verification lie.'
                : null,
        ];
    }
}
