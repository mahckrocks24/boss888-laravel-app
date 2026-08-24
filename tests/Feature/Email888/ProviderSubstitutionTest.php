<?php

namespace Tests\Feature\Email888;

use App\Core\Email888\Contracts\SendEmailCommand;
use App\Core\Email888\EmailDispatcher;
use App\Core\Email888\FailureClassifier;
use App\Core\Email888\Models\EmailDelivery;
use App\Core\Email888\Models\WebhookReceipt;
use App\Core\Email888\Providers\PostmarkNormalizer;
use App\Core\Email888\Providers\WebhookNormalizerRegistry;
use App\Core\Email888\States\DeliveryState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Fakes\Email888\CertificationMailNormalizer;
use Tests\TestCase;

/**
 * EMAIL888 EM-8 — provider substitution, proven against an ADVERSARIAL adapter.
 *
 * "Postmark works" is not neutrality. The only convincing proof is a second
 * provider whose payload shares nothing with the first, driven through the same
 * core, producing the same neutral outcomes — with no upstream code changed.
 *
 * CertificationMailNormalizer deliberately differs in every dimension:
 *
 *   field names   kind / ref / at / to      not RecordType / MessageID
 *   event names   msg.delivered             not Delivery
 *   timestamps    unix epoch integers       not ISO-8601 strings
 *   ids           AM-XXXXXX                 not UUIDs
 *   batching      {"events":[...]}          not a bare array
 *   error codes   AMX-nnn                   not "error code: nnn"
 *
 * It lives in tests/ and is registered only here, so it can never be one
 * environment variable away from production — the rule E2 established when a
 * configuration-reachable fake provider was rejected.
 */
class ProviderSubstitutionTest extends TestCase
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

        // One shared registry for the whole test, with the certification
        // adapter added on top of the real ones.
        $registry = new WebhookNormalizerRegistry();
        $registry->register(new CertificationMailNormalizer());
        $this->app->instance(WebhookNormalizerRegistry::class, $registry);
    }

    private function hook(string $provider, array $payload, bool $auth = true)
    {
        if ($auth) {
            $this->withHeaders(['Authorization' => 'Basic ' . base64_encode(self::USER . ':' . self::PASS)]);
        }

        return $this->postJson("/api/webhooks/email/{$provider}/" . self::SECRET, $payload);
    }

    private function delivery(string $provider, string $messageId): EmailDelivery
    {
        return EmailDelivery::create([
            'correlation_id'      => (string) \Illuminate\Support\Str::uuid(),
            'purpose'             => 'notification',
            'stream_class'        => 'transactional',
            'provider'            => $provider,
            'provider_message_id' => $messageId,
            'recipient_address'   => 'someone@example.com',
            'state'               => DeliveryState::ACCEPTED->value,
            'queued_at'           => now()->subMinute(),
            'accepted_at'         => now()->subMinute(),
        ]);
    }

    // ── the fake must not secretly be Postmark ────────────────────────

    /** @test */
    public function the_certification_payload_shares_no_field_name_with_the_incumbent(): void
    {
        $payload = ['events' => [[
            'kind' => 'msg.delivered', 'ref' => 'AM-7F3K2', 'at' => 1786608669,
            'to' => 'someone@example.com', 'note' => '250 ok',
        ]]];

        $json = json_encode($payload);

        foreach (['RecordType', 'MessageID', 'DeliveredAt', 'BouncedAt', 'MessageStream', 'ServerID'] as $postmarkField) {
            $this->assertStringNotContainsString($postmarkField, $json,
                "The certification payload reuses Postmark's '{$postmarkField}'. A fake that mirrors "
                . 'the incumbent proves only that we can read the incumbent.');
        }
    }

    // ── webhook normalisation under substitution ──────────────────────

    /** @test */
    public function a_certification_delivery_event_reaches_terminal_state(): void
    {
        $d = $this->delivery(CertificationMailNormalizer::PROVIDER, 'AM-7F3K2');

        $this->hook(CertificationMailNormalizer::PROVIDER, ['events' => [[
            'kind' => 'msg.delivered', 'ref' => 'AM-7F3K2', 'at' => 1786608669,
            'to' => 'someone@example.com', 'note' => '250 ok',
        ]]])->assertStatus(200)->assertJsonPath('results.0', 'applied');

        $this->assertSame(DeliveryState::DELIVERED->value, $d->fresh()->state);
        $this->assertNotNull($d->fresh()->delivered_at);
    }

    /** @test */
    public function a_certification_hard_rejection_becomes_bounced(): void
    {
        $d = $this->delivery(CertificationMailNormalizer::PROVIDER, 'AM-BOUNCE1');

        $this->hook(CertificationMailNormalizer::PROVIDER, [
            'kind' => 'msg.rejected.hard', 'ref' => 'AM-BOUNCE1', 'at' => 1786608669,
            'to' => 'nobody@example.com', 'note' => 'no such mailbox', 'code' => 'AMX-550',
        ])->assertStatus(200);

        $this->assertSame(DeliveryState::BOUNCED->value, $d->fresh()->state);
    }

    /** @test */
    public function a_certification_soft_rejection_is_deferred_not_terminal(): void
    {
        $d = $this->delivery(CertificationMailNormalizer::PROVIDER, 'AM-SOFT1');

        $this->hook(CertificationMailNormalizer::PROVIDER, [
            'kind' => 'msg.rejected.soft', 'ref' => 'AM-SOFT1', 'at' => 1786608669,
        ])->assertStatus(200);

        $this->assertSame(DeliveryState::DEFERRED->value, $d->fresh()->state);
    }

    /** @test */
    public function an_understood_but_inert_certification_event_is_ignored_not_rejected(): void
    {
        $this->delivery(CertificationMailNormalizer::PROVIDER, 'AM-OPEN1');

        // Understood, changes nothing. Distinct from "not understood", which is
        // a 400 — an operator has to be able to tell those apart.
        $this->hook(CertificationMailNormalizer::PROVIDER, [
            'kind' => 'msg.opened', 'ref' => 'AM-OPEN1', 'at' => 1786608669,
        ])->assertStatus(200)->assertJsonPath('results.0', 'ignored');
    }

    /** @test */
    public function an_unparseable_certification_body_is_refused_as_malformed(): void
    {
        $this->hook(CertificationMailNormalizer::PROVIDER, ['nothing' => 'useful'])
            ->assertStatus(400);

        $r = WebhookReceipt::latest('id')->first();

        $this->assertSame(WebhookReceipt::INVALID_SHAPE, $r->validation_result);
        $this->assertSame(WebhookReceipt::STAGE_VALIDATE, $r->processing_stage);
    }

    /** @test */
    public function a_redelivered_certification_event_is_recorded_once(): void
    {
        $d = $this->delivery(CertificationMailNormalizer::PROVIDER, 'AM-DUP1');

        $body = ['kind' => 'msg.delivered', 'ref' => 'AM-DUP1', 'at' => 1786608669];

        $this->hook(CertificationMailNormalizer::PROVIDER, $body)->assertJsonPath('results.0', 'applied');
        $this->hook(CertificationMailNormalizer::PROVIDER, $body)->assertJsonPath('results.0', 'duplicate');

        $this->assertSame(1, $d->events()->count());
    }

    /** @test */
    public function a_certification_event_for_an_unknown_message_is_kept_as_evidence(): void
    {
        $this->hook(CertificationMailNormalizer::PROVIDER, [
            'kind' => 'msg.delivered', 'ref' => 'AM-NEVER-SEEN', 'at' => 1786608669,
        ])->assertStatus(200)->assertJsonPath('results.0', 'unmatched');
    }

    /** @test */
    public function a_batched_certification_envelope_is_fully_processed(): void
    {
        $a = $this->delivery(CertificationMailNormalizer::PROVIDER, 'AM-B1');
        $b = $this->delivery(CertificationMailNormalizer::PROVIDER, 'AM-B2');

        $this->hook(CertificationMailNormalizer::PROVIDER, ['events' => [
            ['kind' => 'msg.delivered', 'ref' => 'AM-B1', 'at' => 1786608669],
            ['kind' => 'msg.rejected.hard', 'ref' => 'AM-B2', 'at' => 1786608669],
        ]])->assertStatus(200);

        $this->assertSame(DeliveryState::DELIVERED->value, $a->fresh()->state);
        $this->assertSame(DeliveryState::BOUNCED->value, $b->fresh()->state);
    }

    // ── the incumbent still works, unchanged ──────────────────────────

    /** @test */
    public function the_incumbent_provider_is_unaffected_by_the_refactor(): void
    {
        $d = $this->delivery('postmark', 'pm-still-works');

        $this->hook('postmark', [
            'RecordType' => 'Delivery',
            'MessageID'  => 'pm-still-works',
            'DeliveredAt' => now()->toIso8601String(),
            'Recipient'  => 'someone@example.com',
        ])->assertStatus(200)->assertJsonPath('results.0', 'applied');

        $this->assertSame(DeliveryState::DELIVERED->value, $d->fresh()->state);
    }

    // ── unknown providers are refused, and recorded ───────────────────

    /** @test */
    public function an_unregistered_provider_is_refused_and_leaves_a_receipt(): void
    {
        $this->hook('nobody-mail', ['kind' => 'msg.delivered', 'ref' => 'X'])
            ->assertStatus(404);

        $r = WebhookReceipt::latest('id')->first();

        $this->assertSame(WebhookReceipt::AUTH_UNKNOWN_PROVIDER, $r->authentication_result);
        $this->assertSame('nobody-mail', $r->provider);
    }

    /** @test */
    public function an_unknown_provider_is_indistinguishable_from_a_wrong_secret(): void
    {
        $unknown = $this->hook('nobody-mail', ['kind' => 'msg.delivered']);

        $wrong = $this->withHeaders(['Authorization' => 'Basic ' . base64_encode(self::USER . ':' . self::PASS)])
            ->postJson('/api/webhooks/email/postmark/' . str_repeat('Z', 40), ['RecordType' => 'Delivery']);

        $this->assertSame($wrong->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($wrong->getContent(), $unknown->getContent());
    }

    // ── failure classification under substitution ─────────────────────

    /** @test */
    public function each_provider_classifies_its_own_error_codes(): void
    {
        // Same neutral outcome, entirely different vendor vocabulary.
        $pm = FailureClassifier::classify(new \RuntimeException('Postmark error code: 406 inactive recipient'), 'postmark');
        $cm = FailureClassifier::classify(new \RuntimeException('AMX-552 destination blocked'), CertificationMailNormalizer::PROVIDER);

        $this->assertSame('recipient_suppressed', $pm['category']);
        $this->assertSame('recipient_suppressed', $cm['category']);
        $this->assertFalse($pm['retryable']);
        $this->assertFalse($cm['retryable']);
    }

    /** @test */
    public function one_providers_codes_are_not_applied_to_another(): void
    {
        // 'AMX-550' means invalid recipient to the certification provider and
        // nothing at all to Postmark. If the shared classifier still owned these
        // codes, this would misclassify real customer mail.
        $wrongProvider = FailureClassifier::classify(new \RuntimeException('AMX-550 unknown user'), 'postmark');

        $this->assertSame('transport_error', $wrongProvider['category']);
        $this->assertTrue($wrongProvider['retryable'],
            'An unrecognised failure must degrade to retryable - the safe direction.');
    }

    /** @test */
    public function a_rate_limited_certification_failure_stays_retryable(): void
    {
        $v = FailureClassifier::classify(new \RuntimeException('AMX-429 slow down'), CertificationMailNormalizer::PROVIDER);

        $this->assertSame('rate_limited', $v['category']);
        $this->assertTrue($v['retryable']);
        $this->assertSame(DeliveryState::DEFERRED, $v['state']);
    }

    /** @test */
    public function neutral_heuristics_still_apply_when_no_adapter_recognises_the_error(): void
    {
        $v = FailureClassifier::classify(new \RuntimeException('recipient has been suppressed'), 'nobody-mail');

        $this->assertSame('recipient_suppressed', $v['category']);
    }

    // ── the fabricated-success law survives substitution ──────────────

    /** @test */
    public function acceptance_is_non_terminal_for_every_provider(): void
    {
        foreach (['postmark', CertificationMailNormalizer::PROVIDER] as $provider) {
            config(['email888.provider' => $provider]);

            $result = app(EmailDispatcher::class)->send(new SendEmailCommand(
                purpose:    'notification',
                recipients: ["accept-{$provider}@example.com"],
                subject:    'EM-8 acceptance',
                html:       '<p>hi</p>',
            ));

            $row = EmailDelivery::latest('id')->first();

            $this->assertTrue($result->accepted, 'the provider took the message');
            $this->assertSame($provider, $row->provider, 'the ledger records which provider handled it');

            // The whole point: accepted is NOT delivered, whoever the provider is.
            $this->assertNotSame(DeliveryState::DELIVERED->value, $row->state);
            $this->assertNull($row->delivered_at,
                "Provider '{$provider}' acceptance was treated as delivery.");
        }
    }

    /** @test */
    public function the_registry_contains_no_fake_in_production_configuration(): void
    {
        // A registry built the way production builds it must know only real
        // adapters. E2's rule: a fake reachable from configuration is one env
        // var away from being reachable in production.
        $production = new WebhookNormalizerRegistry();

        $this->assertSame(['postmark'], $production->providers());
        $this->assertFalse($production->has(CertificationMailNormalizer::PROVIDER));
        $this->assertInstanceOf(PostmarkNormalizer::class, $production->for('postmark'));
    }
}
