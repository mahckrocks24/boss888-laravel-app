<?php

namespace App\Engines\Infrastructure\Console;

use App\Engines\Infrastructure\Services\ProviderCredentialService;
use Illuminate\Console\Command;

/**
 * Credential expiry sweep (Phase 2B-CERT, WS14).
 *
 * Closes the gap disclosed in Phase 2B-G: `sweepExpiry()` was built and tested
 * but never scheduled, so nothing would ever have moved a credential to
 * `expiring` or `expired` in real time.
 *
 * WHY EXPIRY IS A SWEEP RATHER THAN A COMPUTED PROPERTY
 * A computed "is it expired?" flag would be correct on read and silent forever —
 * nobody is ever told. A state TRANSITION emits an event, which is what makes
 * "the credential expired and no one noticed" impossible.
 *
 * SAFETY PROPERTIES
 *   - contacts NO provider. It reads stored expiry timestamps only.
 *   - idempotent: a credential already `expiring` is not re-transitioned, so
 *     repeated runs emit no duplicate events.
 *   - mutates NO commercial state. An expired credential is an operational fact;
 *     it must never cancel a subscription.
 *   - hourly, not sub-hourly: expiry is measured in days, so a finer cadence
 *     would add load and log noise for no earlier detection.
 */
class SweepCredentialExpiry extends Command
{
    protected $signature = 'infra:sweep-credential-expiry {--dry-run : Report without transitioning}';

    protected $description = 'Transition provider credentials to expiring/expired and update health.';

    public function handle(ProviderCredentialService $credentials): int
    {
        if ($this->option('dry-run')) {
            $this->info('DRY RUN — no state will change.');

            $due = \App\Engines\Infrastructure\Models\InfraProviderCredential::query()
                ->whereNotNull('expires_at')
                ->whereIn('state', [
                    \App\Engines\Infrastructure\States\CredentialState::ACTIVE,
                    \App\Engines\Infrastructure\States\CredentialState::EXPIRING,
                ])->get();

            foreach ($due as $c) {
                $this->line(sprintf('  %-28s state=%-10s days=%s',
                    $c->credential_key, $c->state, var_export($c->daysUntilExpiry(), true)));
            }

            $this->info("Candidates: {$due->count()}");

            return self::SUCCESS;
        }

        $counts = $credentials->sweepExpiry();

        $this->info(sprintf(
            'Credential expiry sweep: %d -> expiring, %d -> expired.',
            $counts['expiring'], $counts['expired']
        ));

        return self::SUCCESS;
    }
}
