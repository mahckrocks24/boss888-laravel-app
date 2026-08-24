<?php

namespace App\Console\Commands;

use App\Core\PlatformEvents\ChainVerifier;
use Illuminate\Console\Command;

/**
 * Pre-deployment verification gate for the platform event scheduled chain.
 *
 *   php artisan platform-events:verify
 *
 * Exit 0 = safe to deploy. Non-zero = at least one check failed.
 *
 * READ-ONLY. Produces no event, fans out nothing, executes no subscriber, modifies
 * no row, queues no job, and contacts no registrar, payment provider or notification
 * channel. Its only side effect is a one-second probe lock on a Redis key the chain
 * never uses, to prove the lock backend is reachable.
 *
 * Run this AFTER any change to app/Core/PlatformEvents or to the scheduled command,
 * and BEFORE leaving that change in place — `php -l` cannot see an undefined static
 * method, an undefined class constant or an undefined property, and the scheduled
 * command runs every five minutes.
 */
class PlatformEventsVerifyCommand extends Command
{
    protected $signature = 'platform-events:verify {--json : emit machine-readable output}';

    protected $description = 'Verify the platform event chain resolves at runtime (read-only, safe to run in production)';

    public function handle(): int
    {
        $result = app(ChainVerifier::class)->verify();

        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $result['passed'] ? self::SUCCESS : self::FAILURE;
        }

        $this->line('PLATFORM EVENT CHAIN VERIFICATION');
        $this->newLine();

        foreach ($result['checks'] as $c) {
            $this->line(sprintf('  [%s] %-38s %s',
                $c['ok'] ? ' OK ' : 'FAIL',
                $c['name'],
                $c['detail']
            ));
        }

        $this->newLine();

        if ($result['passed']) {
            $this->info(sprintf('PASS — %d checks, 0 failures', $result['summary']['total']));

            return self::SUCCESS;
        }

        $this->error(sprintf('FAIL — %d of %d checks failed: %s',
            $result['summary']['failed'],
            $result['summary']['total'],
            implode(', ', $result['summary']['failed_names'])
        ));

        return self::FAILURE;
    }
}
