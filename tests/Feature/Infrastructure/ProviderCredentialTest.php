<?php

namespace Tests\Feature\Infrastructure;

use App\Connectors\Infrastructure\CredentialVerificationResult;
use App\Connectors\Infrastructure\Null\NullCredentialVerifier;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\Models\InfraProviderEvent;
use App\Engines\Infrastructure\Services\ProviderCredentialService;
use App\Engines\Infrastructure\Services\ProviderRegistryService;
use App\Engines\Infrastructure\States\CredentialState;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Infrastructure\Support\FakeCredentialVerifier;
use Tests\TestCase;

/**
 * Phase 2B-3: credential storage, verification, rotation, revocation.
 *
 * 🔴 THE SECRET USED THROUGHOUT IS A DISTINCTIVE SENTINEL. Several tests assert
 * it appears NOWHERE — not in JSON, not in a log line, not in a raw DB column,
 * not in a var_dump. A generic value like "secret" would produce false passes.
 */
class ProviderCredentialTest extends TestCase
{
    use DatabaseTransactions;

    /** Deliberately unmistakable and unlikely to occur by chance. */
    private const SENTINEL = 'zqx-SENTINEL-CREDENTIAL-7f3a91c4e8b2-DO-NOT-LEAK';

    private ProviderCredentialService $service;
    private ProviderRegistryService $registry;
    private InfraProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service  = app(ProviderCredentialService::class);
        $this->registry = app(ProviderRegistryService::class);

        $this->provider = $this->registry->register([
            'provider_key'      => 'cred-test-' . substr(md5(uniqid('', true)), 0, 8),
            'display_name'      => 'Credential Test Provider',
            'provider_type'     => 'dns',
            'environments_json' => [InfraProvider::ENV_SANDBOX],
            'sandbox_ready'     => true,
        ], 1);
    }

    private function create(string $secret = self::SENTINEL, array $attrs = []): InfraProviderCredential
    {
        return $this->service->create(
            $this->provider,
            $attrs['credential_key'] ?? 'primary',
            $secret,
            array_merge(['capability_scope_json' => ['dns']], $attrs),
            actorUserId: 1
        );
    }

    // ── storage ───────────────────────────────────────────────────────────

    public function test_credential_starts_inert(): void
    {
        $c = $this->create();

        $this->assertSame(CredentialState::PENDING_VERIFICATION, $c->state);
        $this->assertFalse($c->isUsable());
    }

    public function test_secret_is_encrypted_at_rest(): void
    {
        $c = $this->create();

        $raw = DB::table('infra_provider_credentials')
            ->where('id', $c->id)
            ->value('secret_encrypted');

        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(
            self::SENTINEL, $raw,
            'The raw database column must not contain plaintext.'
        );
        // And it must genuinely round-trip, not merely be mangled.
        $this->assertSame(self::SENTINEL, $c->fresh()->secretForVerification());
    }

    public function test_secret_never_appears_in_json_serialization(): void
    {
        $c = $this->create();

        foreach ([json_encode($c), json_encode($c->toArray()), json_encode($c->toSafeArray())] as $encoded) {
            $this->assertStringNotContainsString(self::SENTINEL, $encoded);
        }
    }

    /**
     * The guard most often missing: Log::info($model) and queue payloads use
     * attributesToArray(), NOT toArray(). $hidden alone does not cover them.
     */
    public function test_secret_never_appears_in_attributes_array_or_var_dump(): void
    {
        $c = $this->create();

        $this->assertStringNotContainsString(self::SENTINEL, json_encode($c->attributesToArray()));

        ob_start();
        var_dump($c);
        $dump = ob_get_clean();
        $this->assertStringNotContainsString(self::SENTINEL, $dump);
    }

    public function test_fingerprint_is_not_reversible_and_hint_is_minimal(): void
    {
        $c = $this->create();

        $this->assertSame(64, strlen($c->secret_fingerprint));
        $this->assertStringNotContainsString(self::SENTINEL, $c->secret_fingerprint);
        $this->assertSame('...LEAK', substr($c->secret_hint, 0, 3) . substr($c->secret_hint, -4));
    }

    public function test_unusable_credential_refuses_to_yield_its_secret(): void
    {
        $c = $this->create();

        $this->expectExceptionMessageMatches('/Only active\|expiring/i');
        $c->secret();
    }

    public function test_unscoped_credential_is_refused(): void
    {
        $this->expectExceptionMessageMatches('/at least one capability/i');
        $this->service->create($this->provider, 'unscoped', self::SENTINEL,
            ['capability_scope_json' => []], actorUserId: 1);
    }

    public function test_capability_scope_fails_closed(): void
    {
        $c = $this->create(attrs: ['capability_scope_json' => ['dns']]);

        $this->assertTrue($c->coversCapability('dns'));
        $this->assertFalse($c->coversCapability('certificate'));
    }

    public function test_multiple_capability_scoped_credentials_per_provider(): void
    {
        $dns  = $this->create(self::SENTINEL . '-dns',  ['credential_key' => 'dns-token',  'capability_scope_json' => ['dns']]);
        $cert = $this->create(self::SENTINEL . '-cert', ['credential_key' => 'cert-token', 'capability_scope_json' => ['certificate']]);

        $this->assertNotSame($dns->id, $cert->id);
        $this->assertTrue($dns->coversCapability('dns'));
        $this->assertFalse($dns->coversCapability('certificate'));
        $this->assertTrue($cert->coversCapability('certificate'));
    }

    // ── governance ────────────────────────────────────────────────────────

    public function test_machine_identity_cannot_create_a_credential(): void
    {
        $this->expectExceptionMessageMatches('/individual authenticated user is required/i');
        $this->service->create($this->provider, 'machine', self::SENTINEL,
            ['capability_scope_json' => ['dns']], actorUserId: null);
    }

    public function test_machine_identity_cannot_activate_a_credential(): void
    {
        $c = $this->create();
        $result = CredentialVerificationResult::success(granted: ['dns']);

        $this->expectExceptionMessageMatches('/individual authenticated user is required/i');
        $this->service->activate($c, $result, actorUserId: null);
    }

    // ── verification ──────────────────────────────────────────────────────

    /**
     * The single most important guarantee in 2B-3: the default verifier can
     * never certify anything.
     */
    public function test_null_verifier_never_permits_activation(): void
    {
        $c = $this->create();
        $result = $this->service->verify($c, new NullCredentialVerifier(), 1);

        $this->assertFalse($result->attempted);
        $this->assertFalse($result->authenticated);
        $this->assertFalse($result->permitsActivation());

        $this->expectExceptionMessageMatches('/verification was never attempted/i');
        $this->service->activate($c->fresh(), $result, 1);
    }

    public function test_not_attempted_does_not_mark_credential_invalid(): void
    {
        $c = $this->create();
        $this->service->verify($c, new NullCredentialVerifier(), 1);

        $this->assertSame(
            CredentialState::PENDING_VERIFICATION, $c->fresh()->state,
            '"We did not check" must never be recorded as "it failed".'
        );
        $this->assertNull($c->fresh()->last_verification_ok);
    }

    public function test_rejected_credential_becomes_invalid(): void
    {
        $c = $this->create();
        $this->service->verify($c, FakeCredentialVerifier::rejecting(), 1);

        $this->assertSame(CredentialState::INVALID, $c->fresh()->state);
        $this->assertFalse($c->fresh()->last_verification_ok);
    }

    public function test_verification_records_provider_account_identity(): void
    {
        $c = $this->create();
        $this->service->verify($c, FakeCredentialVerifier::granting(['dns'], 'acct-owned-by-someone-else'), 1);

        $this->assertSame('acct-owned-by-someone-else', $c->fresh()->account_identifier);
    }

    /**
     * This is the D2 finding encoded as a permanent guard: a credential claiming
     * a capability the provider did not actually grant cannot be activated.
     */
    /**
     * Phase 2B-G: a scope spanning two credential purposes is refused at
     * CREATION — earlier and stricter than the old activation-time check.
     * This is the structural answer to "a DNS adapter must never be able to
     * request the certificate credential".
     */
    public function test_scope_that_spans_multiple_purposes_is_refused_at_creation(): void
    {
        $this->expectExceptionMessageMatches('/spans multiple credential purposes/i');
        $this->create(attrs: [
            'credential_key'        => 'cross-purpose',
            'capability_scope_json' => ['dns', 'certificate'],
        ]);
    }

    /**
     * The original guarantee, still enforced: a credential scoped INSIDE one
     * purpose but under-granted by the provider cannot activate.
     * `certificate_operations` legitimately covers certificate + custom_hostname,
     * so this scope survives creation and reaches the activation check.
     */
    public function test_activation_refused_when_provider_did_not_grant_scoped_capability(): void
    {
        $c = $this->create(attrs: [
            'credential_key'        => 'under-granted',
            'capability_scope_json' => ['certificate', 'custom_hostname'],
        ]);
        $result = $this->service->verify($c, FakeCredentialVerifier::granting(['certificate']), 1);

        $this->expectExceptionMessageMatches('/did not grant/i');
        $this->service->activate($c->fresh(), $result, 1);
    }

    /** A credential granted MORE than its purpose allows is also refused. */
    public function test_activation_refused_on_excess_grant(): void
    {
        $c = $this->create(attrs: [
            'credential_key'        => 'excess',
            'capability_scope_json' => ['dns'],
        ]);
        $result = $this->service->verify(
            $c, FakeCredentialVerifier::granting(['dns', 'registrar', 'hosting']), 1
        );

        $this->expectExceptionMessageMatches('/beyond its purpose/i');
        $this->service->activate($c->fresh(), $result, 1);
    }

    public function test_purpose_is_derived_from_capability_scope(): void
    {
        $dns  = $this->create(attrs: ['credential_key' => 'derive-dns',  'capability_scope_json' => ['dns']]);
        $cert = $this->create(self::SENTINEL . '-c', ['credential_key' => 'derive-cert', 'capability_scope_json' => ['certificate']]);

        $this->assertSame('dns_operations', $dns->purpose);
        $this->assertSame('certificate_operations', $cert->purpose);
    }

    public function test_successful_activation(): void
    {
        $c = $this->create();
        $result = $this->service->verify($c, FakeCredentialVerifier::granting(['dns']), 1);
        $active = $this->service->activate($c->fresh(), $result, 1);

        $this->assertSame(CredentialState::ACTIVE, $active->state);
        $this->assertNotNull($active->activated_at);
        $this->assertSame(1, $active->activated_by_user_id);
        $this->assertSame(self::SENTINEL, $active->secret(), 'An active credential may be read.');
    }

    // ── rotation ──────────────────────────────────────────────────────────

    private function activeCredential(): InfraProviderCredential
    {
        $c = $this->create();
        $r = $this->service->verify($c, FakeCredentialVerifier::granting(['dns']), 1);

        return $this->service->activate($c->fresh(), $r, 1);
    }

    public function test_rotation_creates_a_new_row_and_never_overwrites(): void
    {
        $old    = $this->activeCredential();
        $oldFp  = $old->secret_fingerprint;
        $newSecret = self::SENTINEL . '-ROTATED';

        $new = $this->service->beginRotation($old, $newSecret, [], 1);

        $this->assertNotSame($old->id, $new->id, 'Rotation must create a NEW row.');
        $this->assertSame($old->id, $new->supersedes_id);
        $this->assertSame($new->id, $old->fresh()->superseded_by_id);

        // The old secret is untouched.
        $this->assertSame($oldFp, $old->fresh()->secret_fingerprint);
        $this->assertSame(self::SENTINEL, $old->fresh()->secretForVerification());

        // And the old credential KEEPS WORKING until the new one is proven.
        $this->assertSame(CredentialState::ACTIVE, $old->fresh()->state);
        $this->assertSame(CredentialState::PENDING_VERIFICATION, $new->state);
    }

    /** The real-world rotation failure: pasting the same secret back in. */
    public function test_rotation_refuses_an_identical_secret(): void
    {
        $old = $this->activeCredential();

        $this->expectExceptionMessageMatches('/identical to the current one/i');
        $this->service->beginRotation($old, self::SENTINEL, [], 1);
    }

    public function test_rotation_cannot_complete_until_replacement_is_active(): void
    {
        $old = $this->activeCredential();
        $new = $this->service->beginRotation($old, self::SENTINEL . '-R2', [], 1);

        $this->expectExceptionMessageMatches('/not active/i');
        $this->service->completeRotation($new, 1);
    }

    public function test_completed_rotation_supersedes_without_deleting(): void
    {
        $old = $this->activeCredential();
        $new = $this->service->beginRotation($old, self::SENTINEL . '-R3', [], 1);

        $r = $this->service->verify($new, FakeCredentialVerifier::granting(['dns']), 1);
        $this->service->activate($new->fresh(), $r, 1);
        $this->service->completeRotation($new->fresh(), 1);

        $this->assertSame(CredentialState::SUPERSEDED, $old->fresh()->state);
        // The row and its history survive.
        $this->assertNotNull(InfraProviderCredential::find($old->id));
        $this->assertSame($new->id, $old->fresh()->superseded_by_id);
    }

    public function test_superseded_credential_can_never_be_read_again(): void
    {
        $old = $this->activeCredential();
        $new = $this->service->beginRotation($old, self::SENTINEL . '-R4', [], 1);
        $r   = $this->service->verify($new, FakeCredentialVerifier::granting(['dns']), 1);
        $this->service->activate($new->fresh(), $r, 1);
        $this->service->completeRotation($new->fresh(), 1);

        $this->expectExceptionMessageMatches('/terminal/i');
        $old->fresh()->secretForVerification();
    }

    // ── revocation & expiry ───────────────────────────────────────────────

    public function test_revocation_is_immediate_and_attributed(): void
    {
        $c = $this->activeCredential();
        $revoked = $this->service->revoke($c, 'suspected leak', 1);

        $this->assertSame(CredentialState::REVOKED, $revoked->state);
        $this->assertSame('suspected leak', $revoked->revoked_reason);
        $this->assertSame(1, $revoked->revoked_by_user_id);
        $this->assertNotNull($revoked->revoked_at);
    }

    public function test_revoked_credential_cannot_be_used_or_revived(): void
    {
        $c = $this->service->revoke($this->activeCredential(), 'leak', 1);

        $this->assertFalse($c->isUsable());
        $this->assertFalse(CredentialState::canTransition(CredentialState::REVOKED, CredentialState::ACTIVE));

        $this->expectExceptionMessageMatches('/terminal/i');
        $c->secretForVerification();
    }

    public function test_expiry_sweep_moves_credentials_through_states(): void
    {
        $expiring = $this->activeCredential();
        $expiring->update(['expires_at' => now()->addDays(3)]);

        $counts = $this->service->sweepExpiry();
        $this->assertGreaterThanOrEqual(1, $counts['expiring']);
        $this->assertSame(CredentialState::EXPIRING, $expiring->fresh()->state);

        // An `expiring` credential is still usable — that is the point of warning.
        $this->assertTrue($expiring->fresh()->isUsable());

        $expiring->update(['expires_at' => now()->subDay()]);
        $counts2 = $this->service->sweepExpiry();
        $this->assertGreaterThanOrEqual(1, $counts2['expired']);
        $this->assertSame(CredentialState::EXPIRED, $expiring->fresh()->state);
        $this->assertFalse($expiring->fresh()->isUsable());
    }

    // ── audit ─────────────────────────────────────────────────────────────

    public function test_no_credential_event_contains_the_secret(): void
    {
        $c = $this->activeCredential();
        $this->service->beginRotation($c, self::SENTINEL . '-R5', [], 1);
        $this->service->revoke($c->fresh(), 'test sweep', 1);

        $events = InfraProviderEvent::where('provider_id', $this->provider->id)->get();
        $this->assertGreaterThanOrEqual(4, $events->count());

        foreach ($events as $e) {
            $blob = json_encode($e->toArray());
            $this->assertStringNotContainsString(
                self::SENTINEL, $blob,
                "Event '{$e->event_type}' leaked secret material."
            );
        }
    }

    public function test_credential_events_preserve_actor_attribution(): void
    {
        $this->activeCredential();

        $created = InfraProviderEvent::where('provider_id', $this->provider->id)
            ->where('event_type', InfraProviderEvent::CREDENTIAL_CREATED)
            ->first();

        $this->assertNotNull($created);
        $this->assertSame(1, $created->actor_user_id);
        $this->assertSame(InfraProviderEvent::ACTOR_USER, $created->actor_type);
    }
}
