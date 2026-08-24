<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\Models\EmailDeliveryEvent;
use App\Core\Email888\Models\WebhookReceipt;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EMAIL888 EM-6 — the operator surface over webhook ingress.
 *
 * The load-bearing assertions here are the ones about what health REFUSES to
 * say. A webhook health page that reports green because nothing has happened is
 * worse than no page at all: it converts an absence of evidence into a positive
 * claim, which is the defect family this subsystem exists to eliminate.
 */
class WebhookAdminTest extends TestCase
{
    use RefreshDatabase;

    private function receipt(array $attrs = []): WebhookReceipt
    {
        return WebhookReceipt::create(array_merge([
            'received_at'           => now(),
            'provider'              => 'postmark',
            'endpoint'              => '/api/webhooks/email/postmark/{secret}',
            'request_id'            => (string) \Illuminate\Support\Str::uuid(),
            'authentication_result' => WebhookReceipt::AUTH_ACCEPTED,
            'validation_result'     => WebhookReceipt::VALID,
            'processing_stage'      => WebhookReceipt::STAGE_COMPLETE,
            'processing_result'     => 'applied',
            'http_status'           => 200,
            'processing_latency_ms' => 20,
        ], $attrs));
    }

    private function delivery(array $attrs = []): EmailDelivery
    {
        return EmailDelivery::create(array_merge([
            'correlation_id'    => (string) \Illuminate\Support\Str::uuid(),
            'purpose'           => 'notification',
            'stream_class'      => 'transactional',
            'provider'          => 'postmark',
            'recipient_address' => 'someone@example.com',
            'state'             => DeliveryState::DELIVERED->value,
            'queued_at'         => now()->subMinutes(2),
            'accepted_at'       => now()->subMinutes(2),
            'delivered_at'      => now()->subMinute(),
        ], $attrs));
    }

    private function health(): array
    {
        return app(\App\Core\Email888\Admin\WebhookAdminController::class)
            ->health()->getData(true)['health'];
    }

    // ── health refuses to fabricate ───────────────────────────────────

    /** @test */
    public function health_is_unassessable_before_any_webhook_has_ever_been_accepted(): void
    {
        $h = $this->health();

        $this->assertSame('unassessable', $h['verdict']);
        $this->assertNotEmpty($h['reasons']);
    }

    /** @test */
    public function silence_with_no_mail_sent_is_idle_and_never_healthy(): void
    {
        // An accepted receipt exists, but long ago, and nothing was sent since.
        $this->receipt(['received_at' => now()->subDays(5)]);

        $h = $this->health();

        $this->assertSame('idle', $h['verdict']);
        $this->assertStringContainsString('nothing to assess', strtolower($h['reasons'][0]));
    }

    /** @test */
    public function silence_while_mail_was_sent_is_degraded(): void
    {
        $this->receipt(['received_at' => now()->subDays(5)]);
        $this->delivery(['created_at' => now()->subHours(2)]);

        $this->assertSame('degraded', $this->health()['verdict']);
    }

    /** @test */
    public function a_delivery_the_provider_owes_us_an_event_for_degrades_health(): void
    {
        $this->receipt();
        $this->delivery([
            'state'        => DeliveryState::ACCEPTED->value,
            'accepted_at'  => now()->subHours(3),
            'delivered_at' => null,
        ]);

        $h = $this->health();

        $this->assertSame('degraded', $h['verdict']);
        $this->assertSame(1, $h['deliveries_owed_an_event']);
    }

    /** @test */
    public function a_refusal_that_indicts_our_configuration_degrades_health(): void
    {
        $this->receipt();
        $this->delivery();
        $this->receipt([
            'authentication_result' => WebhookReceipt::AUTH_BAD_BASIC,
            'validation_result'     => null,
            'processing_stage'      => WebhookReceipt::STAGE_AUTH,
            'processing_result'     => null,
            'http_status'           => 404,
        ]);

        $h = $this->health();

        $this->assertSame('degraded', $h['verdict']);
        $this->assertSame(1, $h['our_misconfiguration_24h']);
    }

    /** @test */
    public function a_stranger_probing_the_endpoint_does_not_degrade_health(): void
    {
        // Someone else's mistake is not our outage. It is recorded, counted,
        // and deliberately does NOT turn the badge amber.
        $this->receipt();
        $this->delivery();
        $this->receipt([
            'authentication_result' => WebhookReceipt::AUTH_MALFORMED_PATH,
            'validation_result'     => null,
            'processing_stage'      => WebhookReceipt::STAGE_AUTH,
            'processing_result'     => null,
            'http_status'           => 404,
        ]);

        $h = $this->health();

        $this->assertSame('healthy', $h['verdict']);
        $this->assertSame(0, $h['our_misconfiguration_24h']);
        $this->assertSame(1, $h['refusals_24h'][WebhookReceipt::AUTH_MALFORMED_PATH]);
    }

    /** @test */
    public function latency_with_no_samples_is_null_and_never_zero(): void
    {
        $h = $this->health();

        $this->assertSame(0, $h['processing_latency_ms']['samples']);
        $this->assertNull($h['processing_latency_ms']['p50']);
        $this->assertNull($h['processing_latency_ms']['max']);
    }

    /** @test */
    public function unmatched_events_are_surfaced(): void
    {
        EmailDeliveryEvent::create([
            'email_delivery_id'   => null,
            'provider'            => 'postmark',
            'provider_message_id' => 'pm-orphan-1',
            'event_type'          => DeliveryState::DELIVERED->value,
            'provider_event_type' => 'Delivery',
            'occurred_at'         => now(),
            'payload_digest'      => hash('sha256', 'x'),
        ]);

        $this->assertSame(1, $this->health()['unmatched_events']);
    }

    // ── replay is classified, not invented ────────────────────────────

    /** @test */
    public function replay_is_declared_unsupported_with_its_reason_and_its_alternative(): void
    {
        $r = app(\App\Core\Email888\Admin\WebhookAdminController::class)
            ->health()->getData(true)['replay'];

        $this->assertFalse($r['supported']);
        $this->assertStringContainsString('digest', $r['reason']);
        $this->assertStringContainsString('email888:reconcile', $r['instead']);
    }

    // ── the timeline shows only what happened ─────────────────────────

    /** @test */
    public function a_refused_attempt_has_a_short_truthful_timeline(): void
    {
        $r = $this->receipt([
            'authentication_result' => WebhookReceipt::AUTH_BAD_SECRET,
            'validation_result'     => null,
            'processing_stage'      => WebhookReceipt::STAGE_AUTH,
            'processing_result'     => null,
            'http_status'           => 404,
        ]);

        $stages = array_column($r->stages(), 'stage');

        $this->assertSame(['received', 'authentication'], $stages);
        $this->assertNotContains('validation', $stages, 'A request that never authenticated was never validated.');
        $this->assertNotContains('ledger', $stages);
    }

    /** @test */
    public function a_fully_processed_attempt_shows_every_stage_it_reached(): void
    {
        $stages = array_column($this->receipt()->stages(), 'stage');

        $this->assertSame(['received', 'authentication', 'validation', 'processing', 'ledger'], $stages);
    }

    /** @test */
    public function http_200_alone_never_produces_a_ledger_stage(): void
    {
        // The whole family this platform keeps rediscovering: a 200 that
        // describes an event which was never applied to anything.
        $r = $this->receipt(['processing_result' => 'error', 'http_status' => 500]);

        $this->assertFalse($r->wasFullyProcessed());
        $this->assertNotContains('ledger', array_column($r->stages(), 'stage'));
    }

    // ── summary counts the whole table ────────────────────────────────

    /** @test */
    public function summary_counts_the_whole_table_not_the_current_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->receipt();
        }
        $this->receipt([
            'authentication_result' => WebhookReceipt::AUTH_BAD_SECRET,
            'validation_result'     => null,
            'processing_stage'      => WebhookReceipt::STAGE_AUTH,
            'processing_result'     => null,
        ]);

        $body = app(\App\Core\Email888\Admin\WebhookAdminController::class)
            ->index(new \Illuminate\Http\Request(['per_page' => 2]))
            ->getData(true);

        $this->assertCount(2, $body['data'], 'The page is paginated…');
        $this->assertSame(6, $body['summary']['total'], '…but the summary is not.');
        $this->assertSame(5, $body['summary']['accepted']);
        $this->assertSame(1, $body['summary']['refused']);
    }

    // ── retention (EM-6 phase 7) ──────────────────────────────────────

    /** @test */
    public function pruning_bounds_refusals_far_sooner_than_evidence_of_real_mail(): void
    {
        // Two of each kind so the newest-of-its-kind exemption does not mask
        // the window being tested.
        foreach ([200, 100] as $age) {
            $this->receipt(['received_at' => now()->subDays($age)]);
            $this->receipt([
                'received_at'           => now()->subDays($age),
                'authentication_result' => WebhookReceipt::AUTH_MALFORMED_PATH,
                'validation_result'     => null,
                'processing_stage'      => WebhookReceipt::STAGE_AUTH,
                'processing_result'     => null,
            ]);
        }

        $this->artisan('email888:prune-receipts')->assertSuccessful();

        $this->assertSame(2, WebhookReceipt::where('authentication_result', WebhookReceipt::AUTH_ACCEPTED)->count(),
            'Accepted receipts are evidence about real mail and are kept for a year.');
        $this->assertSame(1, WebhookReceipt::where('authentication_result', WebhookReceipt::AUTH_MALFORMED_PATH)->count(),
            'Refusals older than the short window go, except the newest of their kind.');
    }

    /** @test */
    public function pruning_never_erases_the_last_evidence_that_a_category_existed(): void
    {
        // One very old row, and nothing else of its kind. Bounding volume must
        // not turn "we saw this in March" into "we have never seen this".
        $this->receipt([
            'received_at'           => now()->subYears(3),
            'authentication_result' => WebhookReceipt::AUTH_UNCONFIGURED,
            'validation_result'     => null,
            'processing_stage'      => WebhookReceipt::STAGE_AUTH,
            'processing_result'     => null,
        ]);

        $this->artisan('email888:prune-receipts')->assertSuccessful();

        $this->assertSame(1, WebhookReceipt::where('authentication_result', WebhookReceipt::AUTH_UNCONFIGURED)->count());
    }

    // ── security ──────────────────────────────────────────────────────

    /** @test */
    public function the_admin_endpoints_refuse_unauthenticated_callers(): void
    {
        $this->getJson('/api/admin/email888/webhooks')->assertStatus(401);
        $this->getJson('/api/admin/email888/webhooks/health')->assertStatus(401);
        $this->getJson('/api/admin/email888/webhooks/1')->assertStatus(401);
    }

    /** @test */
    public function no_admin_payload_can_carry_a_credential(): void
    {
        config([
            'email888.webhook.secret'         => 'wh_9f3c1ea45b7d2086c41fa93be5d7',
            'email888.webhook.basic_user'     => 'pm_hook_test',
            'email888.webhook.basic_password' => 'pm_hook_password_value',
        ]);

        $this->receipt();

        $c    = app(\App\Core\Email888\Admin\WebhookAdminController::class);
        $blob = json_encode($c->index(new \Illuminate\Http\Request())->getData(true))
              . json_encode($c->health()->getData(true));

        foreach ([
            config('email888.webhook.secret'),
            config('email888.webhook.basic_user'),
            config('email888.webhook.basic_password'),
            config('services.postmark.token') ?: 'POSTMARK_TOKEN_UNSET_SENTINEL',
        ] as $needle) {
            $this->assertStringNotContainsString((string) $needle, $blob);
        }

        // The route PATTERN legitimately contains the word "secret" — that is
        // the whole point of storing `/postmark/{secret}` instead of the URI
        // that carries the real one. Scrub the placeholder, then assert the
        // word does not survive anywhere else.
        $placeholder = '/api/webhooks/email/postmark/{secret}';
        // json_encode escapes forward slashes by default, so the pattern appears
        // as \/api\/… in the blob. Both spellings are scrubbed.
        $escaped = str_replace('/', '\\/', $placeholder);

        $this->assertTrue(
            str_contains($blob, $placeholder) || str_contains($blob, $escaped),
            'The endpoint must be recorded as the pattern.',
        );

        // Two more legitimate uses of the word: the refusal reason
        // `refused_bad_secret`, which is vocabulary an operator filters on, and
        // its appearance in the facet list. Scrubbed by name rather than by
        // weakening the check, so a genuine `"secret":"…"` still trips it.
        $scrubbed = strtolower(str_replace(
            [$placeholder, $escaped, WebhookReceipt::AUTH_BAD_SECRET],
            '',
            $blob,
        ));

        foreach (['authorization', 'x-postmark', 'password', 'api_key', 'secret', 'basic '] as $needle) {
            $this->assertStringNotContainsString($needle, $scrubbed, "Admin payload mentions {$needle}");
        }
    }
}
