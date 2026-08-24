<?php

namespace Tests\Feature\Infrastructure;

use Tests\TestCase;

/**
 * INFRA888 S8.2 — ARCHITECTURAL GUARDS.
 *
 * These exist to fail the moment a future change turns observation into
 * something that acts. Every previous milestone asserted "observation only" in a
 * report; a report cannot fail a build.
 *
 * Comments are stripped before matching, so the prose in these files describing
 * what they must NOT do cannot satisfy or trip the guard.
 */
class ObservationArchitectureTest extends TestCase
{
    private const ROOT = '/var/www/levelup-staging';

    /** @return array<string,string> path => executable code */
    private function observationCode(): array
    {
        $dirs = [
            self::ROOT . '/app/Engines/Infrastructure/Observation',
            self::ROOT . '/app/Engines/Infrastructure/Observation/Observers',
        ];

        $out = [];

        foreach ($dirs as $d) {
            if (! is_dir($d)) {
                continue;
            }

            foreach ((array) glob($d . '/*.php') as $f) {
                $out[basename($f)] = $this->stripComments((string) file_get_contents($f));
            }
        }

        return $out;
    }

    private function stripComments(string $src): string
    {
        $out = '';

        foreach (token_get_all($src) as $t) {
            if (is_array($t) && in_array($t[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $out .= is_array($t) ? $t[1] : $t;
        }

        return $out;
    }

    private function assertNoneMatch(string $pattern, string $why): void
    {
        $code = $this->observationCode();
        $this->assertNotEmpty($code, 'No observation source found — the guard would pass vacuously.');

        foreach ($code as $file => $src) {
            $this->assertSame(0, preg_match($pattern, $src),
                "{$file} violates an architectural guard: {$why}");
        }
    }

    public function test_observation_never_sends_notifications(): void
    {
        $this->assertNoneMatch('/\bMail::|\bNotification::|->notify\(|Notifiable|SendMail|Mailable/',
            'observation must never deliver anything; delivery is S9 and belongs to another workstream');
    }

    public function test_observation_never_dispatches_jobs_or_events(): void
    {
        $this->assertNoneMatch('/\bdispatch\(|->dispatch\(|\bevent\(|Bus::|Queue::/',
            'observation must not trigger side effects through the queue or event bus');
    }

    public function test_observation_never_mutates_domains_at_a_provider(): void
    {
        $this->assertNoneMatch('/renewDomain\(|registerDomain\(|transferDomain\(|updateNameservers\(|setAutoRenew\(|deleteCustomHostname\(|createCustomHostname\(/',
            'observation is read-only against every provider');
    }

    public function test_observation_never_mutates_customer_state(): void
    {
        $this->assertNoneMatch(
            "/table\(\s*'(customer_domains|websites|custom_domains|domain_orders|domain_order_items|users|workspaces)'\s*\)\s*->\s*(update|insert|insertGetId|delete|truncate|upsert)\s*\(/",
            'observation must never write to customer or estate tables — it records observations only'
        );
    }

    public function test_observation_never_uses_eloquent_write_paths_on_customer_models(): void
    {
        $this->assertNoneMatch('/CustomerDomain::(create|updateOrCreate|firstOrCreate)\(|->(save|forceDelete)\(\)/',
            'observation must not persist through customer models');
    }

    public function test_observation_never_shells_out_to_mutating_tools(): void
    {
        // `whois` is read-only and expected; certbot, nginx reloads and dns tools are not.
        $this->assertNoneMatch('/certbot|systemctl|nginx\s+-s|nsupdate|\bdig\s+\+update/',
            'observation must not reconfigure the host');
    }

    public function test_observation_never_registers_a_schedule(): void
    {
        $this->assertNoneMatch('/Schedule::|->everyMinute\(|->hourly\(|->dailyAt\(|withSchedule/',
            'scheduler activation requires explicit authorisation and is not observation');
    }

    public function test_whois_shell_call_is_argument_escaped(): void
    {
        $code = $this->observationCode();
        $dns = $code['DnsObserver.php'] ?? '';

        if (str_contains($dns, 'shell_exec')) {
            $this->assertStringContainsString('escapeshellarg', $dns,
                'A hostname reaches the shell here; it must be escaped or a crafted domain becomes command injection.');
        }

        $this->assertTrue(true);
    }
}
