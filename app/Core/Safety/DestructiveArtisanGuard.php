<?php

namespace App\Core\Safety;

use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Event;

/**
 * MISSION-018 WS-0 (2026-08-24). Refuses the artisan commands that have
 * already caused production incidents on this deployment:
 *
 *   - db:wipe / migrate:fresh / migrate:reset — the 2026-07-30 production
 *     wipe. Staging IS production; there is no second environment to absorb
 *     a destructive default, and APP_ENV=staging means Laravel's own
 *     production confirmation prompt never fires here.
 *   - config:cache — the 2026-07-21 outage: cached config stops .env from
 *     loading, and nineteen bare env() calls (RUNTIME_SECRET among them)
 *     silently return null. See RISK-0054.
 *
 * Override, per single deliberate invocation:
 *   ALLOW_DESTRUCTIVE_ARTISAN=yes-i-mean-it php artisan <command>
 *
 * Exact-name match only: queue:work, plain migrate, and every other command
 * pass through untouched. This guard deliberately fails CLOSED and has no
 * config kill switch — removing it is a tracked code change, not an
 * environment flip. (The governance registrar above fails open by design;
 * this one must not: a guard that swallows its own failure is how six
 * earlier controls ended up off the path they governed — see EV-0068.)
 */
class DestructiveArtisanGuard
{
    public const GUARDED = ['db:wipe', 'migrate:fresh', 'migrate:reset', 'config:cache'];

    public static function register(): void
    {
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (!in_array((string) $event->command, self::GUARDED, true)) {
                return;
            }

            if (getenv('ALLOW_DESTRUCTIVE_ARTISAN') === 'yes-i-mean-it') {
                $event->output->writeln(
                    '<comment>DestructiveArtisanGuard: explicit override accepted for "'
                    . $event->command . '".</comment>'
                );
                return;
            }

            $event->output->writeln(
                '<error>REFUSED by DestructiveArtisanGuard: "' . $event->command
                . '" is guarded on this deployment (staging IS production).</error>'
            );
            $event->output->writeln(
                '<error>History: db:wipe destroyed production data on 2026-07-30; '
                . 'config:cache caused the 2026-07-21 outage (RISK-0054).</error>'
            );
            $event->output->writeln(
                '<error>If you truly intend this: ALLOW_DESTRUCTIVE_ARTISAN=yes-i-mean-it '
                . 'php artisan ' . $event->command . '</error>'
            );

            exit(1);
        });
    }
}
