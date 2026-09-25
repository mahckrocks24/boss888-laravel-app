<?php

/*
|--------------------------------------------------------------------------
| Publisher transport (SOCIAL-LIVE-1, 2026-09-25)
|--------------------------------------------------------------------------
| The Social publisher (App\Core\Publisher\*) talks to Meta through a Transport.
| Until this file existed there was no config/publisher.php, so
| config('publisher.live_transport') was always false and every publish ran
| through MockTransport: validated, marked dry_run_ok, nothing sent (RISK-0205).
|
| The Owner authorised live sending on 2026-09-25 ("turn it on ... the page is
| there for testing"). The switch is the environment key below so that the
| test suite (phpunit.xml pins it to false) and any future host can never reach
| Facebook or Instagram by accident. Flip PUBLISHER_LIVE_TRANSPORT in .env and
| restart the queue workers (they read config at boot); never config:cache.
*/

return [
    'live_transport' => filter_var(env('PUBLISHER_LIVE_TRANSPORT', false), FILTER_VALIDATE_BOOLEAN),
];
