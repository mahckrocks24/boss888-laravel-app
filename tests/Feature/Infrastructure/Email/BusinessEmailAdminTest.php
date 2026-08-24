<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\InfrastructureConnectorResolver;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\Admin\BusinessEmailAdminAccess as Access;
use App\Engines\Infrastructure\Email\Admin\BusinessEmailAdminGate as Gate;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\States\EmailDomainState;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailVerificationState;
use App\Engines\Infrastructure\Email\Support\EmailOperationContext;
use App\Engines\Infrastructure\Models\InfraEvent;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\States\OperationState;
use App\Http\Controllers\Api\Admin\BusinessEmailAdminActionController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\Fakes\Infrastructure\Email\FakeEmailProvider;
use Tests\TestCase;

/**
 * INFRA888 · E3 — ADMIN SURFACE.
 *
 * The properties asserted hardest are the ones whose failure is expensive:
 * the gate is closed by default on every installation, the narrowest
 * capabilities are granted to nobody until an owner names someone, a controller
 * cannot reach a provider without the engine, and an ambiguous operation cannot
 * be retried from the console.
 */
class BusinessEmailAdminTest extends TestCase
{
    use RefreshDatabase;

    private const WS = 9501;

    private FakeEmailProvider $fake;
    private BusinessEmailEngine $engine;

    private User $admin;
    private User $elevated;
    private string $tokenAdmin;
    private string $tokenElevated;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = FakeEmailProvider::make();
        $this->app->singleton(InfrastructureConnectorResolver::class);
        $this->app->make(InfrastructureConnectorResolver::class)->fake('email', $this->fake);
        $this->engine = $this->app->make(BusinessEmailEngine::class);

        // E3.1 — REAL TOKENS, REAL MIDDLEWARE.
        //
        // E3 used actingAs(), which sets the session guard and never reaches
        // auth.jwt. Every HTTP assertion returned 401, and would have proven
        // nothing about the guard even had it passed. This mints tokens through
        // RefreshTokenService exactly as ProviderControlPlaneHttpTest does —
        // INFRA888's own canonical admin HTTP test. No second helper was invented.
        $this->admin = $this->makeUser(true);
        $this->elevated = $this->makeUser(true);

        // The id must be FORCED.
        //
        // `Workspace::firstOrCreate(['id' => N], ...)` silently assigns an
        // auto-increment id, because `id` is not mass-assignable. The token's
        // `ws` claim then names that id while the membership row named N, and
        // JwtAuthMiddleware correctly answered 403 workspace_access_revoked —
        // a real mismatch, not a harness quirk. forceFill sets the id we then
        // use consistently for the claim, the membership and the email records.
        $workspace = \App\Models\Workspace::find(self::WS);

        if ($workspace === null) {
            $workspace = new \App\Models\Workspace();
            $workspace->forceFill([
                'id'         => self::WS,
                'name'       => 'be-admin-ws',
                'slug'       => 'be-admin-ws-' . \Illuminate\Support\Str::random(6),
                'created_by' => $this->admin->id,
            ])->save();
        }

        $this->assertSame(self::WS, (int) $workspace->id, 'The workspace id must be the one under test.');

        foreach ([$this->admin, $this->elevated] as $user) {
            \Illuminate\Support\Facades\DB::table('workspace_users')->insertOrIgnore([
                'user_id'      => $user->id,
                'workspace_id' => self::WS,
                'role'         => 'owner',
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        $svc = app(\App\Core\Auth\RefreshTokenService::class);
        $this->tokenAdmin = $svc->issueTokenPair($this->admin, $workspace)['access_token'];
        $this->tokenElevated = $svc->issueTokenPair($this->elevated, $workspace)['access_token'];
    }

    /** Ordinary platform admin: standard capabilities only. */
    private function adminHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->tokenAdmin];
    }

    /** An operator explicitly named in the elevated allowlist. */
    private function elevatedHeaders(): array
    {
        config(['business_email.elevated_admin_user_ids' => [$this->elevated->id]]);

        return ['Authorization' => 'Bearer ' . $this->tokenElevated];
    }

    protected function tearDown(): void
    {
        WorkspaceContext::reset();

        parent::tearDown();
    }

    // ═══ THE GATE ════════════════════════════════════════════════════════════

    public function test_the_admin_area_is_closed_by_default_on_every_installation(): void
    {
        // Not an environment check. APP_ENV here is `staging`, and this same
        // Laravel serves live customer domains — an environment test would
        // leave the area wide open on the install that matters most.
        $this->assertFalse(
            (bool) config('business_email.admin_enabled'),
            'The Business Email admin area must ship switched OFF.'
        );
        $this->assertFalse(Gate::isEnabled());
        $this->assertNotNull(Gate::unavailableReason());
    }

    public function test_scenario_control_requires_both_flags_and_is_off_by_default(): void
    {
        $this->assertFalse(Gate::allowsScenarioControl());

        // Its own flag alone is not enough: it also needs the admin gate, so it
        // cannot be reached on an install where Business Email admin is off.
        config(['business_email.allow_scenario_control' => true, 'business_email.admin_enabled' => false]);
        $this->assertFalse(Gate::allowsScenarioControl());

        config(['business_email.admin_enabled' => true]);
        $this->assertTrue(Gate::allowsScenarioControl());
    }

    public function test_every_capability_is_refused_while_the_gate_is_closed(): void
    {
        config(['business_email.admin_enabled' => false]);

        $request = $this->requestAs($this->platformAdmin());

        foreach (Access::all() as $capability) {
            $this->assertFalse(
                (new Access())->allows($request, $capability),
                "{$capability} was granted on a closed surface."
            );
        }
    }

    public function test_a_closed_gate_reports_absence_rather_than_denial(): void
    {
        config(['business_email.admin_enabled' => false]);

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/business-email/overview');

        // 404, not 403: the reply must not confirm the module exists to someone
        // who may not use it.
        $response->assertStatus(404)->assertJsonPath('error', 'not_available');
    }

    // ═══ AUTHORIZATION ═══════════════════════════════════════════════════════

    public function test_a_platform_admin_holds_the_standard_capabilities(): void
    {
        config(['business_email.admin_enabled' => true]);

        $request = $this->requestAs($this->platformAdmin());

        foreach (Access::standard() as $capability) {
            $this->assertTrue((new Access())->allows($request, $capability), "{$capability} was refused.");
        }
    }

    public function test_the_narrowest_capabilities_are_granted_to_nobody_by_default(): void
    {
        config(['business_email.admin_enabled' => true, 'business_email.elevated_admin_user_ids' => []]);

        $admin = $this->platformAdmin();
        $request = $this->requestAs($admin);

        foreach (Access::elevated() as $capability) {
            $this->assertFalse(
                (new Access())->allows($request, $capability),
                "{$capability} was granted with an empty allowlist. Deleting a mailbox and rotating a "
                . 'credential must be refused until an owner names someone.'
            );
        }

        // Named explicitly, the same admin holds them.
        config(['business_email.elevated_admin_user_ids' => [$admin->id]]);
        $this->assertTrue((new Access())->allows($request, Access::MAILBOX_DESTROY));
    }

    public function test_credential_management_is_narrower_than_ordinary_operation(): void
    {
        $this->assertContains(Access::PROVIDER_CREDENTIALS, Access::elevated());
        $this->assertContains(Access::OPERATE, Access::standard());
        $this->assertNotContains(Access::OPERATE, Access::elevated());
    }

    public function test_a_non_admin_cannot_reach_any_business_email_admin_route(): void
    {
        config(['business_email.admin_enabled' => true]);

        $customer = $this->makeUser(false);

        \Illuminate\Support\Facades\DB::table('workspace_users')->insertOrIgnore([
            'user_id' => $customer->id, 'workspace_id' => self::WS, 'role' => 'member',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $token = app(\App\Core\Auth\RefreshTokenService::class)
            ->issueTokenPair($customer, \App\Models\Workspace::find(self::WS))['access_token'];

        foreach (['overview', 'domains', 'mailboxes', 'operations', 'providers', 'health'] as $path) {
            $this->withHeaders(['Authorization' => 'Bearer ' . $token])
                ->getJson("/api/admin/business-email/{$path}")
                // AdminMiddleware, not the gate: a non-platform-admin is stopped
                // before Business Email is consulted at all.
                ->assertStatus(403);
        }
    }

    public function test_an_unknown_capability_is_refused_rather_than_assumed_safe(): void
    {
        config(['business_email.admin_enabled' => true]);

        $this->assertFalse(
            (new Access())->allows($this->requestAs($this->platformAdmin()), 'business_email.invent_something')
        );
    }

    // ═══ GOVERNED ACTIONS ════════════════════════════════════════════════════

    public function test_an_unknown_action_is_refused_with_the_allowed_list(): void
    {
        config(['business_email.admin_enabled' => true]);

        $domain = $this->activeDomain('admin-action.test');

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/business-email/domains/{$domain->id}/actions/delete-everything")
            ->assertStatus(404)
            ->assertJsonPath('error', 'unknown_action');
    }

    public function test_a_capability_cannot_be_named_by_the_caller(): void
    {
        // The action map is the whole allowlist. If a caller could name a
        // capability, the console would become an arbitrary-command endpoint.
        $source = (string) file_get_contents(
            '/var/www/levelup-staging/app/Http/Controllers/Api/Admin/BusinessEmailAdminActionController.php'
        );

        $this->assertSame(
            0,
            preg_match('/input\(\s*[\'"]capability[\'"]/', $source),
            'The controller reads a capability from request input.'
        );

        foreach (array_values(BusinessEmailAdminActionController::actionMap()) as $capability) {
            $this->assertTrue(Registry::has($capability), "Action map names unregistered capability {$capability}.");
        }
    }

    public function test_a_governed_action_runs_through_the_engine_and_reaches_the_provider(): void
    {
        config(['business_email.admin_enabled' => true]);

        $domain = $this->activeDomain('governed.test');
        $mailbox = $this->requestedMailbox($domain, 'sales');

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/business-email/domains/{$domain->id}/actions/create-mailbox", [
                'subject_type' => 'mailbox',
                'subject_id'   => $mailbox->id,
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('verified', true)
            ->assertJsonPath('capability', Registry::MAILBOX_CREATE);

        $this->assertSame(1, $this->fake->callCount('createMailbox'));
        $this->assertSame(EmailMailboxState::ACTIVE, $this->reload($mailbox)->lifecycle_state);
    }

    public function test_an_accepted_action_is_reported_as_pending_rather_than_done(): void
    {
        config(['business_email.admin_enabled' => true]);

        $domain = $this->activeDomain('accepted-admin.test');
        $mailbox = $this->requestedMailbox($domain, 'pending');

        $this->fake->alwaysAccepted();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/business-email/domains/{$domain->id}/actions/create-mailbox", [
                'subject_type' => 'mailbox', 'subject_id' => $mailbox->id,
            ])
            // 202, not 200. The provider said yes; nothing is confirmed.
            ->assertStatus(202)
            ->assertJsonPath('verified', false);

        $this->assertSame(EmailMailboxState::PROVISIONING, $this->reload($mailbox)->lifecycle_state);
    }

    public function test_a_destructive_action_requires_explicit_confirmation(): void
    {
        config(['business_email.admin_enabled' => true]);

        $domain = $this->activeDomain('destroy.test');
        $mailbox = $this->provisionedMailbox($domain, 'doomed');

        $this->withHeaders($this->elevatedHeaders())
            ->postJson("/api/admin/business-email/domains/{$domain->id}/actions/delete-mailbox", [
                'subject_type' => 'mailbox', 'subject_id' => $mailbox->id,
            ])
            ->assertStatus(428)
            ->assertJsonPath('error', 'confirmation_required');

        $this->assertFalse($this->fake->wasCalled('deleteMailbox'));
    }

    public function test_a_subject_from_another_domain_cannot_be_acted_on(): void
    {
        config(['business_email.admin_enabled' => true]);

        $domainA = $this->activeDomain('tenant-a.test');
        $domainB = $this->activeDomain('tenant-b.test');
        $mailboxB = $this->requestedMailbox($domainB, 'theirs');

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/business-email/domains/{$domainA->id}/actions/create-mailbox", [
                'subject_type' => 'mailbox', 'subject_id' => $mailboxB->id,
            ])
            ->assertStatus(422)
            ->assertJsonPath('failure_code', 'email_invalid_context');

        $this->assertFalse($this->fake->wasCalled('createMailbox'));
    }

    public function test_repeating_an_action_provisions_exactly_once(): void
    {
        config(['business_email.admin_enabled' => true]);

        $domain = $this->activeDomain('admin-idem.test');
        $mailbox = $this->requestedMailbox($domain, 'once');

        for ($i = 0; $i < 3; $i++) {
            $this->withHeaders($this->adminHeaders())
                ->postJson("/api/admin/business-email/domains/{$domain->id}/actions/create-mailbox", [
                    'subject_type' => 'mailbox', 'subject_id' => $mailbox->id,
                ]);
        }

        $this->assertSame(1, $this->fake->callCount('createMailbox'));
    }

    // ═══ MANUAL REVIEW ═══════════════════════════════════════════════════════

    public function test_an_ambiguous_operation_cannot_be_retried_from_the_console(): void
    {
        config(['business_email.admin_enabled' => true]);

        $operation = $this->ambiguousOperation();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/business-email/operations/{$operation->id}/resolve", [
                'decision' => 'retry', 'reason' => 'looks stuck',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error', 'retry_forbidden')
            ->assertJsonPath('use_instead', 'confirm');
    }

    public function test_a_manual_resolution_requires_a_stated_reason(): void
    {
        config(['business_email.admin_enabled' => true]);

        $operation = $this->ambiguousOperation();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/business-email/operations/{$operation->id}/resolve", [
                'decision' => 'acknowledge',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'reason_required');
    }

    public function test_acknowledging_records_the_decision_without_changing_the_state(): void
    {
        config(['business_email.admin_enabled' => true]);

        $operation = $this->ambiguousOperation();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/business-email/operations/{$operation->id}/resolve", [
                'decision' => 'acknowledge', 'reason' => 'investigating with the provider',
            ])
            ->assertStatus(200)
            ->assertJsonPath('decision', 'acknowledged');

        // Hiding an unresolved condition behind an acknowledgement is how
        // outages get missed.
        $this->assertSame(OperationState::COMPENSATION_PENDING, $operation->refresh()->state);

        $event = InfraEvent::withoutWorkspaceScope()
            ->where('event', 'email.manual_review_resolved')->first();

        $this->assertNotNull($event);
        $this->assertSame('investigating with the provider', $event->context_json['reason']);
        $this->assertNotNull($event->actor_user_id);
    }

    public function test_an_unknown_decision_is_refused(): void
    {
        config(['business_email.admin_enabled' => true]);

        $operation = $this->ambiguousOperation();

        $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/business-email/operations/{$operation->id}/resolve", [
                'decision' => 'force_success', 'reason' => 'because',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'unknown_decision');
    }

    public function test_the_operation_timeline_states_what_an_operator_may_not_do(): void
    {
        config(['business_email.admin_enabled' => true]);

        $operation = $this->ambiguousOperation();

        $response = $this->withHeaders($this->adminHeaders())
            ->getJson("/api/admin/business-email/operations/{$operation->id}");

        $response->assertStatus(200);

        $forbidden = array_column($response->json('resolution.forbidden'), 'action');

        // Stating the forbidden list matters as much as the allowed one: a
        // console that merely omits the retry button leaves the operator
        // wondering whether it is missing or broken.
        foreach (['retry', 'force_success', 'edit_provider_reference', 'delete_audit'] as $action) {
            $this->assertContains($action, $forbidden);
        }
    }

    // ═══ SECRECY ═════════════════════════════════════════════════════════════

    public function test_a_password_reset_returns_stores_and_logs_no_credential(): void
    {
        config(['business_email.admin_enabled' => true]);

        $domain = $this->activeDomain('pwd-admin.test');
        $mailbox = $this->provisionedMailbox($domain, 'resetme');

        $response = $this->withHeaders($this->adminHeaders())
            ->postJson("/api/admin/business-email/domains/{$domain->id}/actions/reset-password", [
                'subject_type' => 'mailbox', 'subject_id' => $mailbox->id,
            ]);

        $response->assertJsonPath('data.redacted', true);
        $response->assertHeader('Cache-Control');

        $body = $response->getContent();
        $operation = InfraOperation::withoutWorkspaceScope()
            ->where('operation', Registry::PASSWORD_RESET)->first();

        $haystack = strtolower($body . json_encode($operation?->result_json) . json_encode($operation?->request_json));

        foreach (['"password"', 'temporary_password', 'reset_token', 'reset_url', 'secret'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $haystack);
        }

        // The contract deliberately cannot return a password. There is nothing
        // to show once, because nothing is ever produced — which is a stronger
        // guarantee than showing one carefully.
        $this->assertStringContainsString('no password is returned', strtolower($body));
    }

    public function test_no_admin_read_returns_a_credential_column(): void
    {
        config(['business_email.admin_enabled' => true]);

        $this->activeDomain('cred.test');

        // A connection must EXIST for the credential projection to be exercised
        // at all. Asserting against an empty list would have proven only that
        // an empty array contains no secret.
        //
        // `credential_ref` holds the NAME of a config key, never a value — the
        // column cannot hold a secret by design. A sentinel is used so a leak
        // would be unmistakable in the assertion below.
        \Illuminate\Support\Facades\DB::table('infra_provider_connections')->insert([
            'workspace_id'   => self::WS,
            'provider'       => 'fake-email',
            'capability'     => 'email',
            'label'          => 'E3 test connection',
            'state'          => 'active',
            'credential_ref' => 'BE_SENTINEL_REF_DO_NOT_LEAK',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $body = strtolower((string) $this->withHeaders($this->adminHeaders())
            ->getJson('/api/admin/business-email/providers')->getContent());

        // Credential-SHAPED keys, not the bare word. `mailbox.password_reset`
        // is a capability name and must legitimately appear; asserting on the
        // substring "password" flagged it, which would have pushed me toward
        // renaming a correct capability to satisfy a wrong test.
        foreach ([
            'secret_encrypted', '"credential_ref"', 'api_key', '"password"',
            'password_hash', 'secret_value', 'private_key', 'access_token',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }

        // Even the reference NAME must not travel: the projection reports only
        // that one is configured.
        $this->assertStringNotContainsString('be_sentinel_ref_do_not_leak', $body);

        // State is reported; value is not.
        $this->assertStringContainsString('credential_reference_present', $body);
        $this->assertStringContainsString('"credential_reference_present":true', $body);
        $this->assertStringContainsString('"secret_returned_after_creation":false', $body);
    }

    // ═══ ARCHITECTURE ════════════════════════════════════════════════════════

    public function test_no_admin_controller_talks_to_a_provider_directly(): void
    {
        $root = '/var/www/levelup-staging/app/Http/Controllers/Api/Admin/';

        foreach (['BusinessEmailAdminController.php', 'BusinessEmailAdminActionController.php'] as $file) {
            $source = (string) file_get_contents($root . $file);

            foreach ([
                'createMailbox', 'deleteMailbox', 'suspendMailbox', 'restoreMailbox',
                'createAlias', 'deleteAlias', 'createForwarder', 'deleteForwarder',
                'configureCatchAll', 'clearCatchAll', 'onboardDomain', 'verifyDomain',
                'requestPasswordReset', 'getInventory',
            ] as $connectorMethod) {
                $this->assertSame(
                    0,
                    preg_match('/->' . $connectorMethod . '\s*\(/', $source),
                    "{$file} calls the connector method {$connectorMethod}() directly, bypassing every "
                    . 'entitlement, lifecycle, idempotency and audit control the engine owns.'
                );
            }

            $this->assertSame(0, preg_match('/ProviderResult::verified\s*\(/', $source),
                "{$file} constructs a verified result. Only the engine may, and only after a read-back.");
        }
    }

    public function test_the_fake_provider_is_not_reachable_from_configuration(): void
    {
        $config = (string) file_get_contents('/var/www/levelup-staging/config/infrastructure.php');

        $this->assertSame(0, preg_match('/Fake/i', $config));
        $this->assertNull(config('infrastructure.connectors.email'));

        // And nothing in production code names it.
        $businessEmailConfig = (string) file_get_contents('/var/www/levelup-staging/config/business_email.php');
        $this->assertSame(0, preg_match('/FakeEmailProvider/', $businessEmailConfig));
    }

    public function test_no_business_email_migration_was_added_by_this_milestone(): void
    {
        // Scoped to Business Email. The original assertion globbed every
        // migration dated today and failed the moment ANOTHER session added
        // one — which says nothing about E3 and would have had me investigating
        // someone else's work.
        $all = glob('/var/www/levelup-staging/database/migrations/*.php') ?: [];

        $businessEmail = array_values(array_filter(
            array_map('basename', $all),
            fn (string $name) => (bool) preg_match('/email_(domains|mailboxes|aliases|forwarders|catchall|usage)/', $name)
        ));

        sort($businessEmail);

        // Exactly the six E1 created, and nothing more.
        $this->assertCount(6, $businessEmail, 'E3 adds no migration; it is an admin surface over the E1 schema.');

        foreach ($businessEmail as $migration) {
            $this->assertStringStartsWith('2026_08_04_1405', $migration, "{$migration} is not an E1 migration.");
        }
    }

    public function test_mutating_routes_deny_api_key_authentication(): void
    {
        $routes = (string) file_get_contents('/var/www/levelup-staging/routes/api/admin/business-email.php');

        $this->assertStringContainsString('DenyApiKeyAuth', $routes,
            'An API key must never be able to provision or destroy a mailbox.');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * This project has no model factories — `Database\Factories\UserFactory`
     * does not exist, and `User::factory()` fatals. Users are created directly,
     * which is what the rest of the suite would have to do anyway.
     */
    /**
     * This project has no model factories — `Database\Factories\UserFactory`
     * does not exist and `User::factory()` fatals. Users are created directly,
     * matching ProviderControlPlaneHttpTest.
     */
    private function makeUser(bool $platformAdmin): User
    {
        return User::create([
            'name'                 => $platformAdmin ? 'E3 Operator' : 'E3 Customer',
            'email'                => 'e3-' . \Illuminate\Support\Str::random(10) . '@test.local',
            'password'             => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
            'is_admin'             => $platformAdmin ? 1 : 0,
            'is_platform_admin'    => $platformAdmin ? 1 : 0,
            'current_workspace_id' => self::WS,
        ]);
    }

    private function platformAdmin(): User
    {
        return $this->admin;
    }

    private function requestAs(User $user)
    {
        $request = \Illuminate\Http\Request::create('/api/admin/business-email/overview');
        $request->setUserResolver(fn () => $user);

        return $request;
    }

    private function activeDomain(string $name): EmailDomain
    {
        $domain = WorkspaceContext::run(self::WS, function () use ($name) {
            $d = EmailDomain::create(['domain' => $name, 'custody' => 'managed_by_us']);
            $d->verification_state = EmailVerificationState::VERIFIED;
            $d->dns_verified_at = now();
            $d->lifecycle_state = EmailDomainState::ACTIVE;
            $d->save();

            return $d;
        });

        $this->fake->reset();

        return $domain;
    }

    private function requestedMailbox(EmailDomain $domain, string $localPart): EmailMailbox
    {
        return WorkspaceContext::run(self::WS, fn () => EmailMailbox::create([
            'email_domain_id' => $domain->id,
            'local_part'      => $localPart,
            'quota_mb'        => 1024,
        ]));
    }

    private function provisionedMailbox(EmailDomain $domain, string $localPart): EmailMailbox
    {
        $mailbox = $this->requestedMailbox($domain, $localPart);

        $this->engine->execute(new EmailOperationContext(
            workspaceId: self::WS,
            capability: Registry::MAILBOX_CREATE,
            domain: $domain,
            subject: $mailbox,
            actorUserId: 1,
            source: 'admin',
            entitlements: [Registry::ENTITLEMENT_ACCESS => true, Registry::ENTITLEMENT_MAILBOX_LIMIT => true],
            idempotencyKey: 'seed-' . $localPart,
        ));

        return $this->reload($mailbox);
    }

    private function ambiguousOperation(): InfraOperation
    {
        $domain = $this->activeDomain('ambiguous-admin.test');
        $mailbox = $this->requestedMailbox($domain, 'unknown');

        $this->fake->willReturn('createMailbox', FakeEmailProvider::OUTCOME_TIMEOUT);

        $this->engine->execute(new EmailOperationContext(
            workspaceId: self::WS,
            capability: Registry::MAILBOX_CREATE,
            domain: $domain,
            subject: $mailbox,
            actorUserId: 1,
            source: 'admin',
            entitlements: [Registry::ENTITLEMENT_ACCESS => true, Registry::ENTITLEMENT_MAILBOX_LIMIT => true],
            idempotencyKey: 'amb-admin-1',
        ));

        $operation = InfraOperation::withoutWorkspaceScope()
            ->where('idempotency_key', 'amb-admin-1')->first();

        $this->assertNotNull($operation);
        $this->assertSame(OperationState::COMPENSATION_PENDING, $operation->state);

        return $operation;
    }

    private function reload($model)
    {
        return WorkspaceContext::run(self::WS, fn () => $model->refresh());
    }
}
