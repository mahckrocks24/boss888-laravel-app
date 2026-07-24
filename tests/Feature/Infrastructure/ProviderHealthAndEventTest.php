<?php

namespace Tests\Feature\Infrastructure;

use App\Connectors\Infrastructure\ProviderResult;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderEvent;
use App\Engines\Infrastructure\Models\InfraProviderHealth;
use App\Engines\Infrastructure\Models\InfraSubscription;
use App\Engines\Infrastructure\Services\ProviderEventRecorder;
use App\Engines\Infrastructure\Services\ProviderHealthService;
use App\Engines\Infrastructure\Services\ProviderRegistryService;
use App\Engines\Infrastructure\States\ProviderHealthState;
use App\Engines\Infrastructure\States\ProviderLifecycleState;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Phase 2B-4 (health) and 2B-9 (events).
 *
 * The two are tested together because nearly every health transition is only
 * meaningful if it also produces an immutable, sanitized, attributed event —
 * testing them apart would let a silent state change pass.
 */
class ProviderHealthAndEventTest extends TestCase
{
    use DatabaseTransactions;

    private ProviderHealthService $health;
    private ProviderRegistryService $registry;
    private ProviderEventRecorder $events;
    private InfraProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->health   = app(ProviderHealthService::class);
        $this->registry = app(ProviderRegistryService::class);
        $this->events   = app(ProviderEventRecorder::class);

        $this->provider = $this->registry->register([
            'provider_key'      => 'health-' . substr(md5(uniqid('', true)), 0, 8),
            'display_name'      => 'Health Test Provider',
            'provider_type'     => 'composite',
            'environments_json' => [InfraProvider::ENV_SANDBOX],
            'sandbox_ready'     => true,
        ], 1);

        $this->registry->transition($this->provider, ProviderLifecycleState::TESTING, 1);
        $this->registry->setEnabled($this->provider, true, 1);
    }

    private function enable(string $capability): void
    {
        $this->registry->declareCapability($this->provider, $capability, InfraProvider::ENV_SANDBOX, [], 1);
        $this->registry->enableCapability($this->provider->fresh(), $capability, InfraProvider::ENV_SANDBOX, 1);
    }

    // ── health at two levels ──────────────────────────────────────────────

    /**
     * The measured Cloudflare situation, encoded as a test: DNS works while
     * certificate operations are unauthorized. A single up/down flag could not
     * represent this.
     */
    public function test_capability_health_is_independent_within_one_provider(): void
    {
        $this->enable('dns');
        $this->enable('certificate');

        $this->health->recordSuccess($this->provider, 'dns', InfraProvider::ENV_SANDBOX, 40);
        $this->health->recordFailure($this->provider, 'certificate', InfraProvider::ENV_SANDBOX,
            'unauthorized', 'Authentication error.');

        $dns  = $this->health->current($this->provider, 'dns', InfraProvider::ENV_SANDBOX);
        $cert = $this->health->current($this->provider, 'certificate', InfraProvider::ENV_SANDBOX);

        $this->assertSame(ProviderHealthState::HEALTHY, $dns->health_state);
        $this->assertSame(ProviderHealthState::UNAUTHORIZED, $cert->health_state);
        $this->assertTrue($dns->isSelectable());
        $this->assertFalse($cert->isSelectable());
    }

    /** The rollup must be pessimistic — a working capability must not mask a broken one. */
    public function test_provider_rollup_is_pessimistic(): void
    {
        $this->enable('dns');
        $this->enable('certificate');

        $this->health->recordSuccess($this->provider, 'dns', InfraProvider::ENV_SANDBOX, 10);
        $this->health->recordFailure($this->provider, 'certificate', InfraProvider::ENV_SANDBOX,
            'unauthorized', 'Denied.');

        $rollup = $this->health->recomputeProviderRollup($this->provider, InfraProvider::ENV_SANDBOX);

        $this->assertSame(ProviderHealthState::UNAUTHORIZED, $rollup->health_state);
        $this->assertTrue($rollup->isProviderLevel());
    }

    // ── classification ────────────────────────────────────────────────────

    public function test_error_classes_map_to_distinct_states(): void
    {
        $cases = [
            'unauthorized'   => ProviderHealthState::UNAUTHORIZED,
            'rate_limited'   => ProviderHealthState::RATE_LIMITED,
            'quota_exceeded' => ProviderHealthState::QUOTA_LIMITED,
            'maintenance'    => ProviderHealthState::MAINTENANCE,
        ];

        foreach ($cases as $code => $expected) {
            $cap = 'dns';
            InfraProviderHealth::where('provider_id', $this->provider->id)->delete();
            $row = $this->health->recordFailure($this->provider, $cap, InfraProvider::ENV_SANDBOX, $code, "err {$code}");

            $this->assertSame($expected, $row->health_state, "Error '{$code}' misclassified.");
        }
    }

    public function test_permanent_failures_are_not_retryable(): void
    {
        $row = $this->health->recordFailure($this->provider, 'dns', InfraProvider::ENV_SANDBOX,
            'unauthorized', 'Denied.');

        $this->assertFalse($row->isRetryable(), 'Retrying an auth failure is a retry storm.');
    }

    public function test_rate_limit_sets_a_retry_window(): void
    {
        $row = $this->health->recordFailure($this->provider, 'dns', InfraProvider::ENV_SANDBOX,
            'rate_limited', 'Slow down.', InfraProviderHealth::PROBE_PASSIVE,
            ['retry_after_seconds' => 120]);

        $this->assertTrue($row->isRetryable());
        $this->assertNotNull($row->rate_limited_until);
        $this->assertNotNull($row->retryNotBefore());
        $this->assertFalse($row->isSelectable(), 'A rate-limited provider must not be selected.');
    }

    public function test_transient_failures_escalate_only_after_repetition(): void
    {
        for ($i = 1; $i <= 2; $i++) {
            $row = $this->health->recordFailure($this->provider, 'dns', InfraProvider::ENV_SANDBOX,
                'timeout', 'Timed out.');
        }
        $this->assertSame(ProviderHealthState::DEGRADED, $row->health_state);

        for ($i = 3; $i <= 6; $i++) {
            $row = $this->health->recordFailure($this->provider, 'dns', InfraProvider::ENV_SANDBOX,
                'timeout', 'Timed out.');
        }
        $this->assertSame(ProviderHealthState::UNAVAILABLE, $row->health_state);
        $this->assertSame(6, $row->consecutive_failures);
    }

    public function test_recovery_clears_failures_and_emits_an_event(): void
    {
        $this->health->recordFailure($this->provider, 'dns', InfraProvider::ENV_SANDBOX, 'timeout', 'x');
        $this->health->recordFailure($this->provider, 'dns', InfraProvider::ENV_SANDBOX, 'timeout', 'x');
        $this->health->recordFailure($this->provider, 'dns', InfraProvider::ENV_SANDBOX, 'timeout', 'x');

        $row = $this->health->recordSuccess($this->provider, 'dns', InfraProvider::ENV_SANDBOX, 25);

        $this->assertSame(ProviderHealthState::HEALTHY, $row->health_state);
        $this->assertSame(0, $row->consecutive_failures);
        $this->assertNull($row->last_error_code);

        $this->assertDatabaseHas('infra_provider_events', [
            'provider_id' => $this->provider->id,
            'event_type'  => InfraProviderEvent::PROVIDER_RECOVERED,
        ]);
    }

    public function test_passive_outcome_recording_from_a_provider_result(): void
    {
        // ProviderResult has a private constructor by design; use the factory.
        $failure = ProviderResult::failed(
            errorCode: 'unauthorized',
            errorSummary: 'Token rejected.'
        );

        $row = $this->health->recordOutcome($this->provider, 'dns', InfraProvider::ENV_SANDBOX, $failure);

        $this->assertSame(ProviderHealthState::UNAUTHORIZED, $row->health_state);
        $this->assertSame(InfraProviderHealth::PROBE_PASSIVE, $row->probe_type);
    }

    public function test_active_probe_is_recorded_distinctly_from_passive(): void
    {
        $row = $this->health->recordSuccess($this->provider, 'dns', InfraProvider::ENV_SANDBOX, 15,
            InfraProviderHealth::PROBE_ACTIVE, 1);

        $this->assertSame(InfraProviderHealth::PROBE_ACTIVE, $row->probe_type);
        $this->assertSame(1, $row->checked_by_user_id);
    }

    public function test_maintenance_is_not_an_incident(): void
    {
        $this->enable('dns');
        $this->health->recordSuccess($this->provider, 'dns', InfraProvider::ENV_SANDBOX, 10);
        $this->health->startMaintenance($this->provider, InfraProvider::ENV_SANDBOX, now()->addHour(), 'planned', 1);

        $row = $this->health->current($this->provider, 'dns', InfraProvider::ENV_SANDBOX);
        $this->assertSame(ProviderHealthState::MAINTENANCE, $row->health_state);
        $this->assertFalse($row->isSelectable());

        $this->assertDatabaseHas('infra_provider_events', [
            'provider_id' => $this->provider->id,
            'event_type'  => InfraProviderEvent::MAINTENANCE_STARTED,
        ]);
    }

    // ── 🔴 commercial isolation ───────────────────────────────────────────

    /**
     * The rule the directive states most emphatically: provider unavailable is
     * NOT customer suspended.
     */
    public function test_provider_failure_does_not_mutate_commercial_state(): void
    {
        $before = InfraSubscription::withoutGlobalScopes()->get()
            ->map(fn ($s) => [$s->id, $s->state])->toArray();

        for ($i = 0; $i < 8; $i++) {
            $this->health->recordFailure($this->provider, 'dns', InfraProvider::ENV_SANDBOX,
                'unauthorized', 'Everything is broken.');
        }
        $this->health->recomputeProviderRollup($this->provider, InfraProvider::ENV_SANDBOX);

        $after = InfraSubscription::withoutGlobalScopes()->get()
            ->map(fn ($s) => [$s->id, $s->state])->toArray();

        $this->assertSame($before, $after,
            'Provider health must never change subscription state.');
    }

    public function test_health_never_writes_provider_lifecycle_state(): void
    {
        $before = $this->provider->fresh()->lifecycle_state;

        for ($i = 0; $i < 8; $i++) {
            $this->health->recordFailure($this->provider, 'dns', InfraProvider::ENV_SANDBOX, 'timeout', 'x');
        }

        $this->assertSame($before, $this->provider->fresh()->lifecycle_state,
            'An observation must not silently become a configuration decision.');
    }

    // ── 2B-9 events ───────────────────────────────────────────────────────

    public function test_events_are_append_only(): void
    {
        $e = $this->events->record(
            eventType: InfraProviderEvent::PROVIDER_REGISTERED,
            provider: $this->provider,
            summary: 'immutability probe'
        );

        $this->assertNotNull($e);

        try {
            $e->update(['summary' => 'rewritten']);
            $this->fail('Expected update to be refused.');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('append-only', $ex->getMessage());
        }

        try {
            $e->delete();
            $this->fail('Expected delete to be refused.');
        } catch (\RuntimeException $ex) {
            $this->assertStringContainsString('append-only', $ex->getMessage());
        }
    }

    public function test_every_event_has_a_unique_immutable_identifier(): void
    {
        $a = $this->events->record(InfraProviderEvent::PROVIDER_ENABLED, $this->provider, summary: 'a');
        $b = $this->events->record(InfraProviderEvent::PROVIDER_ENABLED, $this->provider, summary: 'b');

        $this->assertNotSame($a->event_uid, $b->event_uid);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $a->event_uid);
    }

    public function test_correlation_id_links_a_chain_of_events(): void
    {
        $cid = 'corr-test-' . substr(md5(uniqid('', true)), 0, 8);

        $this->events->record(InfraProviderEvent::CREDENTIAL_ROTATION_STARTED, $this->provider,
            summary: 'start', correlationId: $cid);
        $this->events->record(InfraProviderEvent::CREDENTIAL_ROTATED, $this->provider,
            summary: 'done', correlationId: $cid);

        $this->assertSame(2, InfraProviderEvent::where('correlation_id', $cid)->count());
    }

    public function test_state_transitions_are_preserved_in_history(): void
    {
        $this->registry->transition($this->provider->fresh(), ProviderLifecycleState::ACTIVE, 1, 'promoted');

        $e = InfraProviderEvent::where('provider_id', $this->provider->id)
            ->where('event_type', InfraProviderEvent::PROVIDER_STATE_CHANGED)
            ->orderByDesc('id')->first();

        $this->assertNotNull($e);
        $this->assertSame(ProviderLifecycleState::TESTING, $e->from_state);
        $this->assertSame(ProviderLifecycleState::ACTIVE, $e->to_state);
        $this->assertSame(1, $e->actor_user_id);
    }

    public function test_provider_key_is_denormalised_so_history_survives(): void
    {
        $e = $this->events->record(InfraProviderEvent::PROVIDER_REGISTERED, $this->provider, summary: 'x');

        $this->assertSame($this->provider->provider_key, $e->provider_key);
    }

    public function test_denials_are_recorded(): void
    {
        $e = $this->events->recordDenial('activate_credential', $this->provider,
            'requester may not approve their own request', 1);

        $this->assertSame(InfraProviderEvent::PERMISSION_DENIED, $e->event_type);
        $this->assertSame(InfraProviderEvent::SEVERITY_WARNING, $e->severity);
        $this->assertSame(1, $e->actor_user_id);
    }

    // ── sanitization ──────────────────────────────────────────────────────

    public function test_sanitizer_redacts_by_key_name(): void
    {
        $clean = $this->events->sanitize([
            'api_token'     => 'abc123',
            'authorization' => 'Bearer xyz',
            'nested'        => ['client_secret' => 'shh', 'safe' => 'visible'],
        ]);

        $this->assertSame('[redacted]', $clean['api_token']);
        $this->assertSame('[redacted]', $clean['authorization']);
        $this->assertSame('[redacted]', $clean['nested']['client_secret']);
        $this->assertSame('visible', $clean['nested']['safe']);
    }

    /**
     * The pass a key-name filter cannot do: a secret hiding under a blameless
     * key such as 'value' or 'result'.
     */
    public function test_sanitizer_redacts_by_value_shape(): void
    {
        $clean = $this->events->sanitize([
            'value'  => 'aB3dEf9hIjKlMnOpQrStUvWxYz012345678',            // high entropy
            'header' => 'Bearer abcdefghijklmnop',                        // auth header
            'jwt'    => 'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.abcdef',
            'pem'    => "-----BEGIN PRIVATE KEY-----\nMIIE...",
            'prose'  => 'this is an ordinary human sentence',
        ]);

        $this->assertSame('[redacted:high-entropy]', $clean['value']);
        $this->assertSame('[redacted:auth-header]', $clean['header']);
        $this->assertSame('[redacted:jwt]', $clean['jwt']);
        $this->assertSame('[redacted:pem]', $clean['pem']);
        $this->assertSame('this is an ordinary human sentence', $clean['prose'],
            'Over-redaction of prose would make events useless.');
    }

    public function test_sanitizer_strips_credentials_from_urls_but_keeps_the_host(): void
    {
        $clean = $this->events->sanitize([
            'endpoint' => 'https://api.example.com/v1/zones?api_key=supersecret&page=2',
        ]);

        $this->assertStringContainsString('api.example.com/v1/zones', $clean['endpoint']);
        $this->assertStringNotContainsString('supersecret', $clean['endpoint']);
        $this->assertStringContainsString('page=2', $clean['endpoint']);
    }

    public function test_sanitizer_never_serializes_objects_blindly(): void
    {
        $clean = $this->events->sanitize(['model' => $this->provider]);

        $this->assertStringStartsWith('[object:', $clean['model']);
    }

    public function test_sanitizer_allows_known_safe_keys(): void
    {
        $clean = $this->events->sanitize(['credential_key' => 'dns-primary', 'credential_id' => 7]);

        $this->assertSame('dns-primary', $clean['credential_key']);
        $this->assertSame(7, $clean['credential_id']);
    }
}
