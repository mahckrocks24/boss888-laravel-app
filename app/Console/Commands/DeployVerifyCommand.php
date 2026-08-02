<?php

namespace App\Console\Commands;

use App\Core\Engineer888\Deployment\DeploymentIntent;
use App\Core\Engineer888\Deployment\DeploymentVerifier;
use App\Core\Engineer888\Deployment\Evidence;
use App\Core\Engineer888\Deployment\SmokeChecks;
use Illuminate\Console\Command;

/**
 * Engineer888 — deployment verification.
 *
 * Read-only by default and by design. Nothing here deploys, migrates, restarts
 * or rolls back.
 *
 *   php artisan engineering:deploy-verify
 *   php artisan engineering:deploy-verify --intent=<uuid>
 *   php artisan engineering:deploy-verify --section=identity
 *   php artisan engineering:deploy-verify --json
 *   php artisan engineering:deploy-verify --record-intent --expected-commit=<sha> --method="how it was deployed"
 *   php artisan engineering:deploy-verify --history
 *
 * Exit codes: 0 VERIFIED · 1 HEALTHY_IDENTITY_UNPROVEN · 2 DEGRADED · 3 FAILED · 4 BLOCKED.
 * HEALTHY_IDENTITY_UNPROVEN is deliberately NOT 0. It is not a success.
 */
class DeployVerifyCommand extends Command
{
    protected $signature = 'engineering:deploy-verify
        {--environment=production : environment being verified}
        {--intent= : verify against a specific deployment intent uuid}
        {--section=* : limit output (identity,health,baseline,guidance)}
        {--json : machine-readable output}
        {--no-store : do not persist this verification}
        {--history : list recent verifications and exit}
        {--record-intent : record a deployment intent instead of verifying}
        {--expected-commit= : the commit that was deployed (never inferred)}
        {--expected-branch= : the branch that was deployed}
        {--method= : how the deployment was performed}
        {--notes= : free-text notes}';

    protected $description = 'Engineer888 deployment verification — prove release identity where possible, assess health always, never confuse the two';

    public function handle(): int
    {
        if ($this->option('record-intent')) { return $this->recordIntent(); }
        if ($this->option('history')) { return $this->history(); }

        $result = (new DeploymentVerifier(base_path()))->verify(
            (string) $this->option('environment'),
            $this->option('intent') ?: null,
            ! $this->option('no-store'),
        );

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $this->exitCode($result['verdict']);
        }

        $sections = $this->option('section') ?: ['identity', 'health', 'baseline', 'guidance'];

        $this->header($result);
        if (in_array('identity', $sections, true)) { $this->renderIdentity($result); }
        if (in_array('health', $sections, true))   { $this->renderHealth($result); }
        if (in_array('baseline', $sections, true)) { $this->renderBaseline($result); }
        if (in_array('guidance', $sections, true)) { $this->renderGuidance($result); }
        $this->verdict($result);

        return $this->exitCode($result['verdict']);
    }

    // ── modes ───────────────────────────────────────────────────────────

    private function recordIntent(): int
    {
        $intents = new DeploymentIntent(base_path());

        if (! $this->option('expected-commit')) {
            $candidate = $intents->candidateFromGit();
            $this->newLine();
            $this->line('  <options=bold>NO EXPECTED COMMIT SUPPLIED</>');
            $this->line('  <fg=gray>Engineer888 will not infer one. Reading HEAD and calling it the expectation</>');
            $this->line('  <fg=gray>is circular: it would guarantee a later match regardless of what was deployed.</>');
            $this->newLine();
            $this->line('  Current git state, for you to confirm or correct:');
            $this->line('    branch: ' . ($candidate['expected_branch'] ?? 'unknown'));
            $this->line('    commit: ' . ($candidate['expected_commit'] ?? 'unknown'));
            if ($candidate['warning'] !== null) {
                $this->newLine();
                $this->line('  <fg=yellow>WARNING</> ' . wordwrap($candidate['warning'], 84, "\n          "));
            }
            $this->newLine();
            $this->line('  Re-run with --expected-commit=<sha> once you know what was actually deployed.');
            $this->newLine();

            return 4;
        }

        $intent = $intents->record([
            'environment'       => (string) $this->option('environment'),
            'expected_commit'   => (string) $this->option('expected-commit'),
            'expected_branch'   => $this->option('expected-branch') ?: null,
            'deployment_method' => $this->option('method') ?: null,
            'notes'             => $this->option('notes') ?: null,
        ]);

        $this->newLine();
        $this->line('  <fg=green;options=bold>INTENT RECORDED</>  ' . $intent['uuid']);
        $this->line('  environment: ' . $intent['environment']);
        $this->line('  commit:      ' . ($intent['expected_commit'] ?? 'unknown'));
        $this->line('  method:      ' . ($intent['deployment_method'] ?? 'not stated'));
        $this->newLine();
        $this->line('  Verify with: php artisan engineering:deploy-verify --intent=' . $intent['uuid']);
        $this->newLine();

        return 0;
    }

    private function history(): int
    {
        $rows = \Illuminate\Support\Facades\DB::table('engineering_deployment_verifications')
            ->where('environment', (string) $this->option('environment'))
            ->orderByDesc('id')->limit(15)->get();

        $this->newLine();
        $this->line('  <options=bold>VERIFICATION HISTORY</> — ' . $this->option('environment'));
        $this->line('  ' . str_repeat('─', 84));

        if ($rows->isEmpty()) { $this->line('    none recorded'); $this->newLine(); return 0; }

        foreach ($rows as $row) {
            $this->line(sprintf('    %-4s %-20s %-28s %6dms  %s',
                '#' . $row->id, $row->created_at, $this->colour($row->verdict), $row->duration_ms,
                mb_substr((string) $row->verdict_reason, 0, 60)));
        }
        $this->newLine();

        return 0;
    }

    // ── rendering ───────────────────────────────────────────────────────

    private function header(array $result): void
    {
        $this->newLine();
        $this->line('  <options=bold>ENGINEER888 — DEPLOYMENT VERIFICATION</>');
        $this->line('  ' . now()->toDayDateTimeString() . '  ·  environment: ' . $result['environment']
            . '  ·  session: ' . ($result['session'] ?? 'none'));
        $this->line('  ' . str_repeat('═', 84));

        if ($result['intent'] === null) {
            $this->newLine();
            $this->line('  <fg=yellow>NO DEPLOYMENT INTENT RECORDED</> — health can be assessed, identity cannot.');
        }
    }

    private function renderIdentity(array $result): void
    {
        $this->section('RELEASE IDENTITY');

        foreach ($result['identity'] as $field => $evidence) {
            $colour = match ($evidence['confidence']) {
                Evidence::PROVEN   => 'green',
                Evidence::OBSERVED => 'cyan',
                Evidence::INFERRED => 'yellow',
                default            => 'red',
            };

            $value = $evidence['value'];
            if (is_array($value)) {
                $value = count($value) <= 3 ? json_encode($value) : '(' . count($value) . ' entries)';
            } elseif (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $this->line(sprintf('    <fg=%s>%-9s</> %-26s %s',
                $colour, $evidence['confidence'], $field, mb_substr((string) ($value ?? '—'), 0, 44)));

            if (! empty($evidence['caveat'])) {
                $this->line('              <fg=gray>' . wordwrap($evidence['caveat'], 76, "\n              ") . '</>');
            }
        }

        $check = $result['identity_check'];
        $this->newLine();
        $this->line('    <options=bold>identity verdict</>  '
            . (($check['identity_proven'] ?? false)
                ? '<fg=green>PROVEN</>'
                : '<fg=red>NOT PROVEN</>'));
        $this->line('    <fg=gray>' . wordwrap((string) ($check['reason'] ?? ''), 80, "\n    ") . '</>');

        foreach (($check['mismatches'] ?? []) as $mismatch) {
            $this->line('    <fg=red>mismatch</> ' . $mismatch);
        }
    }

    private function renderHealth(array $result): void
    {
        $this->section('HEALTH CHECKS  <fg=gray>(all read-only)</>');

        $relevant = $result['families'];
        $this->line('    <fg=gray>change-scoped families for the current working set: '
            . implode(', ', $relevant) . '</>');
        $this->newLine();

        foreach ($result['checks'] as $check) {
            $colour = match ($check['status']) {
                SmokeChecks::PASS => 'green',
                SmokeChecks::WARN => 'yellow',
                SmokeChecks::FAIL => 'red',
                default           => 'magenta',
            };
            $marker = ($check['relevant_to_release'] ?? false) ? '›' : ' ';

            $this->line(sprintf('    %s <fg=%s>%-7s</> %-28s %s',
                $marker, $colour, $check['status'], $check['name'], mb_substr($check['detail'], 0, 46)));
        }

        $this->newLine();
        $this->line('    <fg=gray>› = relevant to the current change set. A focused pass is not full certification.</>');
    }

    private function renderBaseline(array $result): void
    {
        $this->section('BASELINE COMPARISON');
        $baseline = $result['baseline'];

        if (! ($baseline['comparable'] ?? false)) {
            $this->line('    <fg=gray>' . ($baseline['reason'] ?? 'not comparable') . '</>');

            return;
        }

        $this->line('    ' . $baseline['reason'] . ' (' . $baseline['baseline_at'] . ')');

        if ($baseline['regressions'] === []) {
            $this->line('    <fg=green>no regressions</>');
        } else {
            foreach ($baseline['regressions'] as $regression) {
                $this->line('    <fg=red>REGRESSION</> ' . $regression['check']
                    . '  ' . $regression['from'] . ' -> ' . $regression['to']);
            }
        }

        foreach (array_slice($baseline['changes'], 0, 6) as $change) {
            $label = $change['change'] ?? 'changed';
            $colour = $label === 'NOT_COMPARABLE' ? 'yellow' : 'gray';
            $this->line("    <fg={$colour}>" . $label . '</> ' . ($change['field'] ?? $change['check'] ?? '')
                . (isset($change['detail']) ? '  ' . mb_substr($change['detail'], 0, 60) : ''));
        }
    }

    private function renderGuidance(array $result): void
    {
        $guidance = (new DeploymentVerifier(base_path()))->guidance($result);
        if (! $guidance['required']) { return; }

        $this->section('FAILURE GUIDANCE  <fg=gray>(no action taken — this sprint does not roll back)</>');

        $this->line('    failing:      ' . implode(', ', $guidance['failing_checks']));
        $this->line('    sources:      ' . implode(', ', $guidance['evidence_sources']));
        $this->line('    attribution:  ' . wordwrap($guidance['attribution_note'], 70, "\n                  "));

        if ($guidance['last_known_good'] !== null) {
            $this->line('    last good:    #' . $guidance['last_known_good']['id']
                . ' at ' . $guidance['last_known_good']['at']);
        } else {
            $this->line('    last good:    <fg=yellow>none recorded</>');
        }

        $this->newLine();
        $this->line('    <options=bold>rollback</>  <fg=red>' . ($guidance['rollback']['available'] ? 'available' : 'NOT AVAILABLE') . '</>');
        $this->line('    <fg=gray>' . wordwrap($guidance['rollback']['reason'], 78, "\n    ") . '</>');
        foreach ($guidance['rollback']['what_exists'] as $item) {
            $this->line('      · ' . $item);
        }
    }

    private function verdict(array $result): void
    {
        $this->newLine();
        $this->line('  ' . str_repeat('═', 84));
        $this->line('  <options=bold>VERDICT</>  ' . $this->colour($result['verdict']));
        $this->line('  ' . wordwrap($result['reason'], 82, "\n  "));
        $this->newLine();
        $this->line('  <fg=gray>' . count($result['checks']) . ' checks in ' . $result['duration_ms'] . 'ms'
            . ($result['id'] !== null ? ' · recorded as verification #' . $result['id'] : ' · not persisted') . '</>');
        $this->newLine();
    }

    private function colour(string $verdict): string
    {
        return match ($verdict) {
            DeploymentVerifier::VERIFIED => '<fg=green;options=bold>VERIFIED</>',
            DeploymentVerifier::HEALTHY_IDENTITY_UNPROVEN => '<fg=yellow;options=bold>HEALTHY_IDENTITY_UNPROVEN</>',
            DeploymentVerifier::DEGRADED => '<fg=yellow;options=bold>DEGRADED</>',
            DeploymentVerifier::FAILED   => '<fg=red;options=bold>FAILED</>',
            default                      => '<fg=magenta;options=bold>BLOCKED</>',
        };
    }

    private function exitCode(string $verdict): int
    {
        return match ($verdict) {
            DeploymentVerifier::VERIFIED => 0,
            DeploymentVerifier::HEALTHY_IDENTITY_UNPROVEN => 1,
            DeploymentVerifier::DEGRADED => 2,
            DeploymentVerifier::FAILED   => 3,
            default                      => 4,
        };
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('  <options=bold>' . $title . '</>');
        $this->line('  ' . str_repeat('─', 84));
    }
}
