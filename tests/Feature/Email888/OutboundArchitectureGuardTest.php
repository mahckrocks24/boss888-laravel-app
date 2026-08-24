<?php

namespace Tests\Feature\Email888;

use Tests\TestCase;

/**
 * EMAIL888 EM-7 — permanent guards against a second outbound architecture.
 *
 * Runtime tests prove the code sends correctly TODAY. They cannot prove that
 * nobody adds `Http::post('https://api.postmarkapp.com/email')` to a service
 * next month — that code would pass every behavioural test in this suite while
 * quietly reintroducing exactly the defects EM-7 removed: no ledger row, no
 * stream policy, an unmonitored sender.
 *
 * COMMENTS ARE STRIPPED WITH token_get_all(), NOT GREP.
 * This file, and the retired EmailConnector, both DISCUSS the forbidden
 * patterns at length. A grep-based guard would either flag those docblocks (and
 * get "fixed" by deleting the explanation) or be loosened until it caught
 * nothing. Only executable tokens are scanned.
 *
 * EVERY GUARD HAS A POSITIVE CONTROL.
 * A guard that has never been observed failing is not evidence — a typo in a
 * needle produces a permanently green test that proves nothing. Each rule below
 * is run against a known-bad fixture and asserted to FIRE.
 */
class OutboundArchitectureGuardTest extends TestCase
{
    /** Directories that MAY contain provider-specific outbound code. */
    private const PROVIDER_BOUNDARY = [
        'app/Core/Email888/',
        'app/Connectors/Infrastructure/',
    ];

    private function base(): string
    {
        return rtrim(base_path(), '/') . '/';
    }

    /** Executable source only — comments and docblocks removed. */
    private function codeOf(string $file): string
    {
        $out = '';
        foreach (token_get_all((string) file_get_contents($file)) as $t) {
            if (is_array($t)) {
                if ($t[0] === T_COMMENT || $t[0] === T_DOC_COMMENT) {
                    continue;
                }
                $out .= $t[1];
            } else {
                $out .= $t;
            }
        }

        return $out;
    }

    /** @return list<string> php files under app/, repo-relative */
    private function appFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base() . 'app', \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($it as $f) {
            if ($f->isFile() && $f->getExtension() === 'php') {
                $files[] = str_replace($this->base(), '', $f->getPathname());
            }
        }
        sort($files);

        return $files;
    }

    /**
     * Files whose executable code contains $needle, excluding the provider
     * boundary and any explicitly listed file.
     *
     * @param  list<string> $alsoAllow
     * @return list<string>
     */
    private function offenders(string $needle, array $alsoAllow = []): array
    {
        $allow = array_merge(self::PROVIDER_BOUNDARY, $alsoAllow);
        $hits  = [];

        foreach ($this->appFiles() as $rel) {
            foreach ($allow as $prefix) {
                if (str_starts_with($rel, $prefix)) {
                    continue 2;
                }
            }
            if (str_contains($this->codeOf($this->base() . $rel), $needle)) {
                $hits[] = $rel;
            }
        }

        return $hits;
    }

    /** Runs a needle against a synthetic file, to prove the rule can fire. */
    private function detectsInFixture(string $needle, string $badCode): bool
    {
        $tmp = sys_get_temp_dir() . '/em7guard_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($tmp, "<?php\n" . $badCode . "\n");

        try {
            return str_contains($this->codeOf($tmp), $needle);
        } finally {
            @unlink($tmp);
        }
    }

    // ── the comment-stripper itself must work ─────────────────────────

    /** @test */
    public function the_scanner_ignores_comments_and_sees_code(): void
    {
        $this->assertFalse(
            $this->detectsInFixture('api.postmarkapp.com', '// we no longer post to api.postmarkapp.com'),
            'A guard that flags its own documentation gets fixed by deleting the documentation.',
        );
        $this->assertFalse(
            $this->detectsInFixture('api.postmarkapp.com', '/** posts to api.postmarkapp.com */'),
        );
        $this->assertTrue(
            $this->detectsInFixture('api.postmarkapp.com', '$x = "https://api.postmarkapp.com/email";'),
            'The scanner must still see real code, or every guard below is vacuous.',
        );
    }

    // ── 1. no direct provider HTTP outside the boundary ───────────────

    /** @test */
    public function no_application_code_posts_to_the_provider_directly(): void
    {
        $this->assertSame([], $this->offenders('api.postmarkapp.com'),
            'Provider HTTP belongs behind the Email888 boundary, where identity, stream, '
            . 'idempotency and the ledger are applied.');

        $this->assertTrue(
            $this->detectsInFixture('api.postmarkapp.com',
                'Http::post("https://api.postmarkapp.com/email", $payload);'),
            'POSITIVE CONTROL: the rule must fire on a real violation.',
        );
    }

    /** @test */
    public function no_application_code_carries_a_provider_auth_header(): void
    {
        $this->assertSame([], $this->offenders('X-Postmark-Server-Token'));

        $this->assertTrue(
            $this->detectsInFixture('X-Postmark-Server-Token',
                'Http::withHeaders(["X-Postmark-Server-Token" => $t]);'),
            'POSITIVE CONTROL',
        );
    }

    // ── 2. provider credentials ───────────────────────────────────────

    /**
     * @test
     *
     * The list is EMPTY, and it stays empty.
     *
     * It was briefly going to be an inventory lock with one justified entry:
     * MarketingService held POSTMARK_TOKEN for an email-settings screen. That
     * turned out not to be a style violation at all — the route was `auth.jwt`
     * only, so any authenticated user could overwrite the platform's provider
     * credential in .env and route every password-reset email through their own
     * Postmark account. Both the methods and the routes were removed, so no
     * exemption is needed and none should ever be added.
     */
    public function no_application_code_names_the_provider_token_at_all(): void
    {
        $this->assertSame([], $this->offenders('POSTMARK_TOKEN'),
            'A file started naming the provider token again. Provider credentials belong to the '
            . 'Email888 boundary; a producer must never hold, read or persist one.');

        $this->assertTrue(
            $this->detectsInFixture('POSTMARK_TOKEN', '$t = env("POSTMARK_TOKEN");'),
            'POSITIVE CONTROL',
        );
    }

    /**
     * @test
     *
     * The specific hole that was closed: no route may accept a provider
     * credential from a request and persist it.
     */
    public function no_route_writes_provider_configuration_into_the_environment(): void
    {
        $routes = $this->codeOf($this->base() . 'routes/api.php');

        foreach (['getEmailSettings', 'updateEmailSettings'] as $gone) {
            $this->assertStringNotContainsString($gone, $routes,
                "The ungoverned email-settings endpoint is back on a route.");
        }

        $mkt = $this->codeOf($this->base() . 'app/Engines/Marketing/Services/MarketingService.php');

        foreach (['MAIL_MAILER', 'MAIL_FROM_ADDRESS', 'EMAIL_CONNECTOR_DRIVER'] as $envKey) {
            $this->assertStringNotContainsString($envKey, $mkt,
                "A workspace-scoped service is writing platform mail configuration again.");
        }
    }

    // ── 3. sender identity ────────────────────────────────────────────

    /** @test */
    public function no_application_code_hardcodes_the_unmonitored_sender(): void
    {
        $this->assertSame([], $this->offenders('noreply@levelupgrowth.io'),
            'noreply@ is absent from the sender registry deliberately: nothing monitors it, '
            . 'so a bounce sent there is read by nobody. It must never return as a fallback.');

        $this->assertTrue(
            $this->detectsInFixture('noreply@levelupgrowth.io',
                '$from = config("x.from", "noreply@levelupgrowth.io");'),
            'POSITIVE CONTROL',
        );
    }

    /** @test */
    public function the_sender_registry_still_refuses_to_contain_noreply(): void
    {
        foreach (config('email888.senders') as $key => $sender) {
            $this->assertStringNotContainsString('noreply', (string) $sender['address'],
                "Sender registry key '{$key}' resolves to an unmonitored address.");
        }
    }

    // ── 4. stream selection is policy, not producer choice ────────────

    /** @test */
    public function no_producer_chooses_a_provider_message_stream(): void
    {
        $this->assertSame([], $this->offenders('MessageStream'),
            'Stream selection belongs to the stream registry. A producer choosing its own is how '
            . 'bulk mail ends up on the reputation that delivers password resets.');

        $this->assertTrue(
            $this->detectsInFixture('MessageStream', '$payload["MessageStream"] = "outbound";'),
            'POSITIVE CONTROL',
        );
    }

    // ── 5. the retired connector cannot regrow ────────────────────────

    /** @test */
    public function the_retired_connector_supports_no_send_action(): void
    {
        $connector = app(\App\Connectors\EmailConnector::class);

        $this->assertSame([], $connector->supportedActions(),
            'EmailConnector was a complete second outbound architecture. An empty action list is '
            . 'what makes regrowth loud instead of silent.');

        foreach (['send_email', 'send_campaign'] as $action) {
            $result = $connector->execute($action, ['to' => 'a@b.com', 'subject' => 's', 'body' => 'b']);
            $this->assertFalse($result['success'] ?? true, "EmailConnector still executes '{$action}'.");
        }
    }

    /** @test */
    public function the_retired_connector_holds_no_credential_and_calls_no_provider(): void
    {
        $code = $this->codeOf($this->base() . 'app/Connectors/EmailConnector.php');

        foreach (['api.postmarkapp.com', 'X-Postmark-Server-Token', 'POSTMARK_TOKEN', 'noreply@'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $code,
                "EmailConnector still contains {$forbidden} in executable code.");
        }

        // Health must not be a synthetic provider ping any more.
        $this->assertStringNotContainsString('Http::', $code);
    }

    /** @test */
    public function no_service_still_injects_the_retired_connector(): void
    {
        $offenders = [];

        foreach ($this->appFiles() as $rel) {
            if ($rel === 'app/Connectors/EmailConnector.php'
                || $rel === 'app/Connectors/ConnectorResolver.php'
                || $rel === 'app/Providers/AppServiceProvider.php') {
                continue;   // the resolver map and the container binding keep the health matrix alive
            }
            if (str_contains($this->codeOf($this->base() . $rel), 'EmailConnector')) {
                $offenders[] = $rel;
            }
        }

        $this->assertSame([], $offenders,
            'A service is depending on the retired connector again.');
    }

    // ── 6. campaign producers use the canonical contract ──────────────

    /** @test */
    public function every_campaign_fan_out_uses_the_canonical_contract(): void
    {
        // The services that actually loop over an audience. MarketingService is
        // deliberately NOT here any more — see the next test.
        foreach ([
            'app/Engines/Marketing/Services/EmailBuilderService.php',
            'app/Jobs/SendEmailCampaignJob.php',
        ] as $rel) {
            $code = $this->codeOf($this->base() . $rel);

            $this->assertStringContainsString('SendEmailCommand', $code,
                "{$rel} sends campaign mail without the canonical command.");
            $this->assertStringContainsString('CampaignDispatchOutcome', $code,
                "{$rel} computes its own campaign aggregate instead of using the shared contract.");
        }
    }

    /**
     * @test
     *
     * PHASE 12. The HTTP path enqueues and returns; it must never fan out
     * inline again. Holding the shared aggregate would mean it is computing a
     * campaign result, which is the worker's job — so its ABSENCE here is the
     * assertion, not an oversight.
     */
    public function the_request_path_enqueues_and_does_not_aggregate(): void
    {
        $code = $this->codeOf($this->base() . 'app/Engines/Marketing/Services/MarketingService.php');

        $this->assertStringContainsString('SendEmailCampaignJob', $code,
            'The campaign request path no longer enqueues the fan-out.');
        $this->assertStringNotContainsString('CampaignDispatchOutcome', $code,
            'The request path is computing a campaign aggregate again, which means it is '
            . 'fanning out inline — the exact coupling PHASE 12 removed.');
    }

    /**
     * @test
     *
     * The specific shape of the original defect: a status literal written
     * unconditionally at the end of a loop. Campaign status may only come from
     * the shared outcome object.
     */
    public function no_campaign_producer_writes_a_sent_status_literal(): void
    {
        foreach ([
            'app/Engines/Marketing/Services/MarketingService.php',
            'app/Engines/Marketing/Services/EmailBuilderService.php',
            'app/Jobs/SendEmailCampaignJob.php',
        ] as $rel) {
            $code = $this->codeOf($this->base() . $rel);

            $this->assertStringNotContainsString("'status'     => 'sent'", $code, $rel);
            $this->assertStringNotContainsString("'status'  => 'sent'", $code, $rel);
            $this->assertStringNotContainsString("'status' => 'sent'", $code, $rel);
        }
    }

    /** @test */
    public function the_shared_outcome_cannot_be_told_a_campaign_succeeded(): void
    {
        $rc = new \ReflectionClass(\App\Engines\Marketing\Support\CampaignDispatchOutcome::class);

        // There must be no way to SET the status — only to derive it.
        foreach ($rc->getMethods(\ReflectionMethod::IS_PUBLIC) as $m) {
            $this->assertNotSame('setStatus', $m->name);
            $this->assertStringStartsNotWith('markSent', $m->name);
        }

        $this->assertTrue($rc->getMethod('status')->isPublic());
        $this->assertTrue($rc->isFinal(), 'A subclass could override status() and reintroduce the defect.');
    }
}
