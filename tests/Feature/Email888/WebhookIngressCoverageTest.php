<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\Models\WebhookReceipt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * EMAIL888 EM-6 — ingress coverage: NO refusal is silent, and the audit trail
 * cannot be used as a weapon.
 *
 * WHY THIS FILE EXISTS SEPARATELY FROM WebhookReceiptTest
 * That file proves the controller records what it sees. This one proves the
 * controller is not the only thing that can refuse a request. Measured against
 * live staging BEFORE this code existed:
 *
 *     malformed secret (too short)      404   NO durable trace
 *     malformed secret (illegal chars)  404   NO durable trace
 *     well-formed wrong secret          404   receipt written
 *
 * The two invisible cases are the shape a scanner uses, which made "is someone
 * probing this endpoint?" — an EM-6 question — permanently unanswerable.
 */
class WebhookIngressCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'wh_9f3c1ea45b7d2086c41fa93be5d7';
    private const USER   = 'pm_hook_test';
    private const PASS   = 'pm_hook_password_value';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'email888.webhook.secret'         => self::SECRET,
            'email888.webhook.basic_user'     => self::USER,
            'email888.webhook.basic_password' => self::PASS,
        ]);
    }

    private function url(string $secret): string
    {
        return '/api/webhooks/email/postmark/' . $secret;
    }

    private function event(): array
    {
        return ['RecordType' => 'Delivery', 'MessageID' => 'pm-ingress-1', 'DeliveredAt' => now()->toIso8601String()];
    }

    private function authed(): self
    {
        $this->withHeaders(['Authorization' => 'Basic ' . base64_encode(self::USER . ':' . self::PASS)]);

        return $this;
    }

    // ── the gap that blocked EM-6 closure ─────────────────────────────

    /** @test */
    public function a_secret_too_short_for_the_route_still_leaves_a_receipt(): void
    {
        $this->postJson($this->url('abc'), $this->event())->assertStatus(404);

        $r = WebhookReceipt::latest('id')->first();

        $this->assertNotNull($r, 'A router-level refusal left no durable trace — the exact EM-6 gap.');
        $this->assertSame(WebhookReceipt::AUTH_MALFORMED_PATH, $r->authentication_result);
        $this->assertSame(404, (int) $r->http_status);
        $this->assertSame(WebhookReceipt::STAGE_AUTH, $r->processing_stage);
    }

    /** @test */
    public function a_secret_with_illegal_characters_still_leaves_a_receipt(): void
    {
        $this->postJson($this->url('aaaaaaaaaaaaaaaaaaaaaaaa!!!!'), $this->event())->assertStatus(404);

        $this->assertSame(
            WebhookReceipt::AUTH_MALFORMED_PATH,
            WebhookReceipt::latest('id')->first()?->authentication_result,
        );
    }

    /** @test */
    public function a_request_carrying_no_secret_at_all_still_leaves_a_receipt(): void
    {
        $this->postJson('/api/webhooks/email/postmark', $this->event())->assertStatus(404);

        $this->assertSame(
            WebhookReceipt::AUTH_MALFORMED_PATH,
            WebhookReceipt::latest('id')->first()?->authentication_result,
        );
    }

    /** @test */
    public function a_browser_style_get_is_recorded_and_named_honestly(): void
    {
        $this->getJson($this->url(self::SECRET))->assertStatus(404);

        $r = WebhookReceipt::latest('id')->first();

        $this->assertNotNull($r);
        $this->assertSame(WebhookReceipt::AUTH_MALFORMED_PATH, $r->authentication_result);
        // The secret was fine; the METHOD was wrong. Saying "malformed secret"
        // here would send an operator hunting a credential that is correct.
        $this->assertStringContainsString('POST only', (string) $r->operator_visible_reason);
        $this->assertStringNotContainsString('expected shape', (string) $r->operator_visible_reason);
    }

    // ── it must not become an oracle ──────────────────────────────────

    /** @test */
    public function a_malformed_path_is_indistinguishable_from_a_wrong_secret(): void
    {
        $malformed = $this->postJson($this->url('abc'), $this->event());
        $wrong     = $this->postJson($this->url(str_repeat('Z', 40)), $this->event());

        $this->assertSame($wrong->getStatusCode(), $malformed->getStatusCode());
        $this->assertSame($wrong->getContent(), $malformed->getContent());

        // …while remaining fully distinguishable to US.
        $reasons = WebhookReceipt::orderBy('id')->pluck('authentication_result')->all();
        $this->assertSame(
            [WebhookReceipt::AUTH_MALFORMED_PATH, WebhookReceipt::AUTH_BAD_SECRET],
            $reasons,
        );
    }

    // ── the receipt must not become the attack ────────────────────────

    /** @test */
    public function a_flood_of_refusals_cannot_grow_the_audit_trail_without_bound(): void
    {
        // The bound is "per reason PER MINUTE", so the clock is part of the
        // specification. Frozen here deliberately: without it this test spans
        // whatever minute boundary it happens to land on and asserts a range
        // instead of the actual guarantee.
        $this->freezeTime();

        for ($i = 0; $i < 25; $i++) {
            $this->postJson($this->url('bad' . $i), $this->event())->assertStatus(404);
        }

        $rows = WebhookReceipt::where('authentication_result', WebhookReceipt::AUTH_MALFORMED_PATH)->count();

        // Exactly five, plus the one row that says the rest were coalesced.
        $this->assertSame(6, $rows, 'Refusal receipts are unauthenticated writes and must be coalesced.');

        // The flood itself is on the record, in words, not merely implied by a gap.
        $this->assertTrue(
            WebhookReceipt::where('operator_visible_reason', 'like', '%coalesced%')->exists(),
            'An operator must be told the trail was truncated, not left to infer it.',
        );
    }

    /** @test */
    public function a_flood_of_one_reason_cannot_hide_a_different_one(): void
    {
        // This is why the coalescing buckets are per reason. A scanner hammering
        // garbage paths must never be able to suppress the single row that says
        // Postmark itself is being turned away by a bad credential.
        $this->freezeTime();

        for ($i = 0; $i < 25; $i++) {
            $this->postJson($this->url('bad' . $i), $this->event());
        }

        $this->postJson($this->url(self::SECRET), $this->event())->assertStatus(404);

        $this->assertTrue(
            WebhookReceipt::where('authentication_result', WebhookReceipt::AUTH_BAD_BASIC)->exists(),
            'A real misconfiguration was lost inside an unrelated flood.',
        );
    }

    /** @test */
    public function genuine_provider_traffic_is_never_coalesced(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->authed()->postJson($this->url(self::SECRET), [
                'RecordType' => 'Delivery',
                'MessageID'  => 'pm-real-' . $i,
            ])->assertStatus(200);
        }

        $this->assertSame(
            12,
            WebhookReceipt::where('authentication_result', WebhookReceipt::AUTH_ACCEPTED)->count(),
            'Dropping an accepted receipt drops evidence of real mail.',
        );
    }

    // ── secrets ───────────────────────────────────────────────────────

    /** @test */
    public function a_router_level_receipt_never_records_a_credential(): void
    {
        $this->withHeaders(['Authorization' => 'Basic ' . base64_encode(self::USER . ':' . self::PASS)])
            ->postJson($this->url('abc'), $this->event());

        $blob = json_encode(WebhookReceipt::latest('id')->first()?->toAdminArray());

        foreach ([self::SECRET, self::USER, self::PASS, 'Basic ', 'Authorization'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $blob, "Receipt leaked: {$needle}");
        }

        // The route PATTERN, never the resolved URI — the URI is the credential.
        $this->assertStringContainsString('{', (string) WebhookReceipt::latest('id')->first()?->endpoint);
    }

    /** @test */
    public function every_refusal_reason_is_a_known_one(): void
    {
        $this->postJson($this->url('abc'), $this->event());
        $this->postJson($this->url(str_repeat('Z', 40)), $this->event());
        $this->postJson($this->url(self::SECRET), $this->event());

        foreach (WebhookReceipt::all() as $r) {
            $this->assertContains(
                $r->authentication_result,
                array_merge([WebhookReceipt::AUTH_ACCEPTED], WebhookReceipt::refusalReasons()),
            );
            $this->assertTrue($r->isRefusal());
        }
    }

    /** @test */
    public function a_refusal_that_indicts_our_own_configuration_is_flagged_as_such(): void
    {
        // Wrong path secret: someone else's mistake.
        $this->postJson($this->url(str_repeat('Z', 40)), $this->event());
        $this->assertFalse(WebhookReceipt::latest('id')->first()->indictsOurConfiguration());

        // Right path secret, wrong basic auth: the caller knows the URL, so this
        // is almost certainly Postmark being refused by OUR credential change.
        $this->postJson($this->url(self::SECRET), $this->event());
        $this->assertTrue(WebhookReceipt::latest('id')->first()->indictsOurConfiguration());
    }
}
