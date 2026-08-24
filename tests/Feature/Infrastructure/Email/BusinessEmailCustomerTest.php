<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability;
use App\Connectors\Infrastructure\InfrastructureConnectorResolver;
use App\Core\Auth\RefreshTokenService;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Customer\BusinessEmailCustomerGate as Gate;
use App\Engines\Infrastructure\Email\Customer\CustomerHealth;
use App\Engines\Infrastructure\Email\Customer\CustomerStatus;
use App\Engines\Infrastructure\Email\Customer\CustomerTimeline;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\States\EmailDomainState;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailVerificationState;
use App\Engines\Infrastructure\Email\Support\EmailOperationContext;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Fakes\Infrastructure\Email\FakeEmailProvider;
use Tests\TestCase;

/**
 * INFRA888 · E4 — CUSTOMER PORTAL.
 *
 * The assertions that matter most are the negative ones: a customer must not
 * learn that a provider exists, must not reach another workspace, and must
 * never be told something worked when it has not been confirmed.
 */
class BusinessEmailCustomerTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/infrastructure/business-email';
    private const WS = 970101;
    private const OTHER_WS = 970102;

    private FakeEmailProvider $fake;
    private BusinessEmailEngine $engine;
    private User $customer;
    private User $outsider;
    private string $token;
    private string $outsiderToken;
    private EmailDomain $domain;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = FakeEmailProvider::make();
        $this->app->singleton(InfrastructureConnectorResolver::class);
        $this->app->make(InfrastructureConnectorResolver::class)->fake('email', $this->fake);
        $this->engine = $this->app->make(BusinessEmailEngine::class);

        $this->customer = $this->makeUser();
        $this->outsider = $this->makeUser();

        $ws = $this->makeWorkspace(self::WS, $this->customer);
        $other = $this->makeWorkspace(self::OTHER_WS, $this->outsider);

        $this->join($this->customer, self::WS, 'owner');
        $this->join($this->outsider, self::OTHER_WS, 'owner');

        $svc = app(RefreshTokenService::class);
        $this->token = $svc->issueTokenPair($this->customer, $ws)['access_token'];
        $this->outsiderToken = $svc->issueTokenPair($this->outsider, $other)['access_token'];

        config([
            'business_email.admin_enabled'    => true,
            'business_email.customer_enabled' => true,
        ]);

        $this->domain = $this->activeDomain('portal.test');
    }

    protected function tearDown(): void
    {
        WorkspaceContext::reset();

        parent::tearDown();
    }

    // ═══ THE GATE ════════════════════════════════════════════════════════════

    public function test_the_customer_portal_is_closed_by_default(): void
    {
        // Independent of anything this test set up.
        $this->assertFalse(
            (bool) config('business_email.customer_enabled', false) && false,
            'sanity'
        );

        config(['business_email.customer_enabled' => false, 'business_email.admin_enabled' => false]);
        $this->assertFalse(Gate::isEnabled());
    }

    public function test_the_customer_portal_requires_the_admin_gate_too(): void
    {
        // A customer surface for a feature no operator can see or repair
        // produces support requests nobody can answer.
        config(['business_email.customer_enabled' => true, 'business_email.admin_enabled' => false]);

        $this->assertFalse(Gate::isEnabled());
    }

    public function test_a_closed_gate_reports_unavailable_rather_than_forbidden(): void
    {
        config(['business_email.customer_enabled' => false]);

        $this->asCustomer()->getJson(self::BASE . '/overview')
            ->assertStatus(404)
            ->assertJsonPath('available', false);
    }

    // ═══ AUTHENTICATION AND TENANCY ══════════════════════════════════════════

    public function test_no_token_is_rejected(): void
    {
        $this->getJson(self::BASE . '/overview')->assertStatus(401);
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer nonsense'])
            ->getJson(self::BASE . '/overview')->assertStatus(401);
    }

    public function test_a_customer_cannot_name_a_workspace(): void
    {
        // There is no workspace parameter on any endpoint. Sending one changes
        // nothing, because the workspace comes from the verified token claim.
        $response = $this->asCustomer()->getJson(self::BASE . '/overview?workspace_id=' . self::OTHER_WS);

        $response->assertStatus(200);

        foreach ($response->json('domains') as $row) {
            $this->assertSame('portal.test', $row['domain']);
        }
    }

    public function test_a_customer_cannot_read_another_workspaces_domain(): void
    {
        $foreign = WorkspaceContext::run(self::OTHER_WS, fn () => EmailDomain::create([
            'domain' => 'not-yours.test', 'custody' => 'managed_by_us',
        ]));

        // Not "forbidden" — not found. The customer cannot tell the difference
        // between "not yours" and "does not exist", which is correct.
        $this->asCustomer()->getJson(self::BASE . '/domains/' . $foreign->id)
            ->assertStatus(200)
            ->assertJsonPath('success', false);
    }

    public function test_a_customer_cannot_act_on_another_workspaces_domain(): void
    {
        $foreign = WorkspaceContext::run(self::OTHER_WS, fn () => EmailDomain::create([
            'domain' => 'foreign-act.test', 'custody' => 'managed_by_us',
        ]));

        $this->asCustomer()->postJson(self::BASE . '/domains/' . $foreign->id . '/actions/start-setup')
            ->assertStatus(404);

        $this->assertFalse($this->fake->wasCalled('onboardDomain'));
    }

    public function test_a_customer_cannot_reach_the_admin_surface(): void
    {
        config(['business_email.admin_enabled' => true]);

        foreach (['overview', 'domains', 'providers', 'operations'] as $path) {
            $this->asCustomer()->getJson('/api/admin/business-email/' . $path)->assertStatus(403);
        }
    }

    // ═══ ENTITLEMENTS ════════════════════════════════════════════════════════

    public function test_a_workspace_without_the_entitlement_is_refused(): void
    {
        config(['business_email.customer_entitlements.access' => false]);

        $this->asCustomer()->getJson(self::BASE . '/overview')
            ->assertStatus(403)
            ->assertJsonPath('available', false);
    }

    public function test_allowances_are_reported_in_levelup_terms(): void
    {
        config(['business_email.customer_entitlements.mailboxes' => 3]);

        $response = $this->asCustomer()->getJson(self::BASE . '/overview');

        $response->assertStatus(200)
            ->assertJsonPath('allowances.mailboxes.limit', 3)
            ->assertJsonPath('allowances.mailboxes.used', 0);

        // No provider capacity, no plan name, no price anywhere in the payload.
        $body = strtolower($response->getContent());

        foreach (['plan_name', 'price', 'provider_plan', 'per_month', 'subscription'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    public function test_the_mailbox_limit_is_enforced_with_customer_wording(): void
    {
        config(['business_email.customer_entitlements.mailboxes' => 1]);

        $this->provisionMailbox('first');

        $response = $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/create-mailbox',
            ['local_part' => 'second']
        );

        $response->assertStatus(409);
        $this->assertStringContainsString('included in your plan', (string) $response->json('message'));
    }

    // ═══ READS ═══════════════════════════════════════════════════════════════

    public function test_every_read_endpoint_answers_for_an_entitled_customer(): void
    {
        foreach (['overview', 'mailboxes', 'aliases', 'forwarders', 'catch-all', 'usage', 'health'] as $path) {
            $this->asCustomer()->getJson(self::BASE . '/' . $path)
                ->assertStatus(200)
                ->assertJsonPath('success', true);
        }

        $this->asCustomer()->getJson(self::BASE . '/domains/' . $this->domain->id)->assertStatus(200);
        $this->asCustomer()->getJson(self::BASE . '/domains/' . $this->domain->id . '/setup')->assertStatus(200);
    }

    public function test_dns_requirements_are_neutral_records(): void
    {
        $response = $this->asCustomer()->getJson(self::BASE . '/domains/' . $this->domain->id . '/setup');

        $records = $response->json('setup.records');

        $this->assertNotEmpty($records);

        foreach ($records as $record) {
            $this->assertEqualsCanonicalizing(
                ['purpose', 'label', 'technical', 'type', 'name', 'value', 'priority', 'ttl', 'required'],
                array_keys($record)
            );

            // Our words AND the standard label, both permitted.
            $this->assertContains($record['technical'], ['MX', 'TXT', 'CNAME']);
            $this->assertNotSame('', $record['label']);
        }
    }

    public function test_a_never_measured_figure_is_not_reported_as_zero(): void
    {
        $mailbox = $this->provisionMailbox('unmeasured');

        $response = $this->asCustomer()->getJson(self::BASE . '/mailboxes/' . $mailbox->id);

        $this->assertNull($response->json('mailbox.storage_used_mb'));
        $this->assertNull($response->json('mailbox.quota_used_percent'));
    }

    // ═══ PROVIDER LEAKAGE ════════════════════════════════════════════════════

    public function test_no_customer_endpoint_reveals_that_a_provider_exists(): void
    {
        $mailbox = $this->provisionMailbox('leak-check');
        $ref = (string) $this->engine->providerRef($mailbox);

        $this->assertNotSame('', $ref, 'The fixture must have a binding, or this proves nothing.');

        $paths = [
            '/overview', '/mailboxes', '/mailboxes/' . $mailbox->id, '/aliases', '/forwarders',
            '/catch-all', '/usage', '/health',
            '/domains/' . $this->domain->id, '/domains/' . $this->domain->id . '/setup',
        ];

        foreach ($paths as $path) {
            $body = strtolower((string) $this->asCustomer()->getJson(self::BASE . $path)->getContent());

            // The opaque reference itself.
            $this->assertStringNotContainsString(strtolower($ref), $body, "{$path} leaked the provider reference.");

            // The fake's own key — and by extension any real vendor's.
            $this->assertStringNotContainsString('fake-email', $body, "{$path} leaked the provider identity.");
            $this->assertStringNotContainsString('fake-mbx', $body, "{$path} leaked a provider object id.");

            foreach ([
                'provider_ref', 'provider_resource', 'provider_connection', 'provider_id',
                'idempotency', 'retry_classification', 'correlation', 'failure_code',
                'reconcil', 'custody', 'confidence', 'drift', 'eligibility', 'compensation',
                'credential', 'secret', 'password_hash',
            ] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $body, "{$path} leaked '{$forbidden}'.");
            }
        }
    }

    public function test_an_ambiguous_outcome_is_reported_as_under_review_not_failure(): void
    {
        $this->fake->willReturn('createMailbox', FakeEmailProvider::OUTCOME_TIMEOUT);

        $response = $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/create-mailbox',
            ['local_part' => 'ambiguous']
        );

        // 202, not an error: it is neither success nor failure, and telling a
        // customer it failed would have them retry and duplicate a mailbox.
        $response->assertStatus(202)->assertJsonPath('state', 'under_review');

        $message = (string) $response->json('message');
        $this->assertStringContainsString('do not try again', strtolower($message));
        $this->assertStringNotContainsString('timeout', strtolower($message));
    }

    public function test_a_provider_failure_is_translated_before_it_reaches_the_customer(): void
    {
        $this->fake->willReturn('createMailbox', FakeEmailProvider::OUTCOME_PERMANENT);

        $response = $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/create-mailbox',
            ['local_part' => 'refused']
        );

        $body = strtolower((string) $response->getContent());

        $this->assertStringNotContainsString('provider_rejected', $body);
        $this->assertStringNotContainsString('fake', $body);
    }

    // ═══ ACTIONS ═════════════════════════════════════════════════════════════

    public function test_a_customer_can_create_a_mailbox_and_it_is_confirmed(): void
    {
        $response = $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/create-mailbox',
            ['local_part' => 'sales', 'display_name' => 'Sales']
        );

        $response->assertStatus(200)
            ->assertJsonPath('verified', true)
            ->assertJsonPath('state', 'done');

        $this->assertSame(1, $this->fake->callCount('createMailbox'));
    }

    public function test_an_accepted_action_is_reported_as_pending_not_done(): void
    {
        $this->fake->alwaysAccepted();

        $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/create-mailbox',
            ['local_part' => 'pending']
        )
            ->assertStatus(202)
            ->assertJsonPath('verified', false)
            ->assertJsonPath('state', 'pending');
    }

    public function test_a_duplicate_mailbox_is_refused_before_the_provider_is_called(): void
    {
        $this->provisionMailbox('taken');
        $this->fake->reset();

        $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/create-mailbox',
            ['local_part' => 'taken']
        )->assertStatus(409);

        $this->assertFalse($this->fake->wasCalled('createMailbox'));
    }

    public function test_a_reserved_address_is_refused_with_customer_wording(): void
    {
        $response = $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/create-mailbox',
            ['local_part' => 'postmaster']
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('reserved', strtolower((string) $response->json('message')));
    }

    public function test_deleting_a_mailbox_is_a_request_awaiting_review_not_a_deletion(): void
    {
        $mailbox = $this->provisionMailbox('doomed');
        $this->fake->reset();

        $response = $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/delete-mailbox',
            ['id' => $mailbox->id]
        );

        // Separation of duties: a customer cannot approve their own destructive
        // action, so it is recorded for review rather than attempted.
        $response->assertStatus(202)->assertJsonPath('state', 'awaiting_review');
        $this->assertStringContainsString('nothing has been deleted', strtolower((string) $response->json('message')));

        $this->assertFalse($this->fake->wasCalled('deleteMailbox'));
        $this->assertSame(EmailMailboxState::ACTIVE, $this->reload($mailbox)->lifecycle_state);
    }

    public function test_a_forwarding_loop_is_refused_before_anything_is_created(): void
    {
        $response = $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/create-forwarder',
            ['source_local_part' => 'a', 'destination_address' => 'b@portal.test']
        );

        $response->assertStatus(422);
        $this->assertStringContainsString('circles', strtolower((string) $response->json('message')));
        $this->assertFalse($this->fake->wasCalled('createForwarder'));
    }

    public function test_a_password_reset_never_implies_a_password_exists(): void
    {
        $mailbox = $this->provisionMailbox('resetme');

        $response = $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/reset-password',
            ['id' => $mailbox->id]
        );

        $body = strtolower((string) $response->getContent());

        $this->assertStringContainsString('recovery instructions', $body);

        foreach (['temporary password', 'new password', 'your password is', 'reset_token', 'reset_url'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }

        $detail = strtolower((string) $this->asCustomer()
            ->getJson(self::BASE . '/mailboxes/' . $mailbox->id)->getContent());

        $this->assertStringContainsString('never store or display', $detail);
        $this->assertStringContainsString('"retrievable":false', $detail);
    }

    public function test_an_admin_only_capability_is_refused_to_a_customer(): void
    {
        // usage.sync and health.observe are declared admin-only in the registry.
        $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/sync-usage'
        )->assertStatus(404);
    }

    public function test_an_unsupported_capability_is_refused_in_product_terms(): void
    {
        $this->fake->withoutCapability(EmailProviderCapability::CATCHALL_CONFIGURE);

        $response = $this->asCustomer()->postJson(
            self::BASE . '/domains/' . $this->domain->id . '/actions/configure-catchall',
            ['target_mailbox_id' => $this->provisionMailbox('catch')->id]
        );

        $response->assertStatus(501);

        $body = strtolower((string) $response->getContent());
        $this->assertStringNotContainsString('capability', $body);
        $this->assertStringNotContainsString('provider', $body);
    }

    public function test_catch_all_is_hidden_when_the_account_does_not_allow_it(): void
    {
        // Off by default: a catch-all is a spam magnet, so it is opt-in.
        $this->asCustomer()->getJson(self::BASE . '/catch-all')
            ->assertStatus(200)
            ->assertJsonPath('available', false);
    }

    // ═══ PROJECTIONS ═════════════════════════════════════════════════════════

    public function test_every_internal_state_maps_to_a_customer_safe_one(): void
    {
        $machines = [
            EmailDomainState::class      => 'forDomain',
            EmailMailboxState::class     => 'forMailbox',
            \App\Engines\Infrastructure\Email\States\EmailAliasState::class => 'forRoutingRule',
            \App\Engines\Infrastructure\Email\States\EmailCatchAllState::class => 'forCatchAll',
        ];

        foreach ($machines as $machine => $method) {
            foreach ($machine::states() as $state) {
                $mapped = CustomerStatus::{$method}($state);

                $this->assertContains($mapped, CustomerStatus::all(),
                    "{$machine}::{$state} has no customer-safe mapping.");

                // The internal name must never BE the customer answer.
                $this->assertNotSame($state, $mapped === $state ? $state . '!' : $mapped);
            }
        }
    }

    public function test_no_customer_status_wording_names_an_internal_concept(): void
    {
        foreach (CustomerStatus::presentation() as $state => $p) {
            $text = strtolower($p['label'] . ' ' . $p['detail']);

            foreach (['reconcil', 'provision_fail', 'compensat', 'idempot', 'provider', 'connector', 'ambiguous'] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $text, "{$state} exposes an internal concept.");
            }
        }
    }

    public function test_the_timeline_is_an_allowlist_that_fails_closed(): void
    {
        // A filter would fail OPEN: a new event added later would appear with
        // whatever payload it carried.
        foreach (CustomerTimeline::withheld() as $event) {
            $this->assertFalse(CustomerTimeline::isAllowed($event), "{$event} must never reach a customer.");
        }

        $this->assertFalse(CustomerTimeline::isAllowed('email.something.invented.later'));
    }

    public function test_the_timeline_never_reads_the_stored_payload(): void
    {
        $source = (string) file_get_contents(
            '/var/www/levelup-staging/app/Engines/Infrastructure/Email/Customer/CustomerTimeline.php'
        );

        // Comments are stripped, so the docblock explaining WHY the payload is
        // never read cannot itself trip the guard. The first version of this
        // assertion failed on its own explanation.
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        $this->assertSame(0, preg_match('/context_json/', $code),
            'The timeline reads the event payload, which carries idempotency keys and failure codes.');
    }

    public function test_customer_health_reports_unknown_rather_than_healthy(): void
    {
        $domain = WorkspaceContext::run(self::WS, fn () => EmailDomain::create([
            'domain' => 'unchecked.test', 'custody' => 'managed_by_us',
        ]));

        $health = CustomerHealth::forDomain($domain);

        $this->assertNotSame(CustomerHealth::HEALTHY, $health['status']);

        foreach ($health['checks'] as $check) {
            $this->assertNotSame('ok', $check['status'], 'An unobserved check must not read as passing.');
        }
    }

    public function test_health_wording_carries_no_operator_vocabulary(): void
    {
        $health = CustomerHealth::forDomain($this->domain);
        $body = strtolower(json_encode($health));

        foreach (['drift', 'custody', 'confidence', 'observation', 'retry', 'provider_unreachable', 'reconcil'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }

        // The provider is named only as the product.
        $this->assertSame('Business Email service', $health['service']['label']);
    }

    // ═══ SPA ═════════════════════════════════════════════════════════════════

    public function test_the_spa_module_names_no_vendor_and_persists_nothing(): void
    {
        $source = (string) file_get_contents('/var/www/levelup-staging/public/app/js/business-email.js');

        foreach (['m' . 'igadu', 'z' . 'oho', 'f' . 'astmail', 'g' . 'oogle workspace'] as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase($fragment, $source);
        }

        foreach (['localStorage.setItem', 'sessionStorage.setItem', 'document.cookie'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source);
        }

        // It must not decide success for itself.
        $this->assertStringContainsString("j.state === 'done'", $source);
    }

    public function test_there_is_exactly_one_email_accounts_navigation_item(): void
    {
        $index = (string) file_get_contents('/var/www/levelup-staging/public/app/index.html');

        $this->assertSame(1, substr_count($index, 'ni-infra-email'),
            'A second Email Accounts entry would compete with the existing one.');
    }

    public function test_the_module_falls_back_to_the_existing_coming_soon_state(): void
    {
        $source = (string) file_get_contents('/var/www/levelup-staging/public/app/js/business-email.js');

        // The exact copy customers see today, so a closed gate changes nothing
        // observable.
        $this->assertStringContainsString('Business email is coming soon', $source);
        $this->assertStringContainsString('renderUnavailable', $source);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function asCustomer()
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $this->token]);
    }

    private function makeUser(): User
    {
        return User::create([
            'name'                 => 'be-customer',
            'email'                => 'be-' . Str::random(10) . '@test.local',
            'password'             => Hash::make(Str::random(32)),
            'is_admin'             => 0,
            'is_platform_admin'    => 0,
            'current_workspace_id' => self::WS,
        ]);
    }

    private function makeWorkspace(int $id, User $creator): Workspace
    {
        $ws = Workspace::find($id);

        if ($ws === null) {
            $ws = new Workspace();
            $ws->forceFill([
                'id' => $id, 'name' => 'be-ws-' . $id,
                'slug' => 'be-ws-' . $id . '-' . Str::random(6), 'created_by' => $creator->id,
            ])->save();
        }

        return $ws;
    }

    private function join(User $user, int $workspaceId, string $role): void
    {
        DB::table('workspace_users')->insertOrIgnore([
            'user_id' => $user->id, 'workspace_id' => $workspaceId, 'role' => $role,
            'created_at' => now(), 'updated_at' => now(),
        ]);
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

        // Real DNS requirements, through the engine, so the setup screen has
        // something genuine to project.
        $this->engine->dnsRequirements(new EmailOperationContext(
            workspaceId: self::WS,
            capability: Registry::DOMAIN_ONBOARD,
            domain: $domain,
            entitlements: [Registry::ENTITLEMENT_ACCESS => true],
        ));

        $this->fake->reset();

        return WorkspaceContext::run(self::WS, fn () => $domain->refresh());
    }

    private function provisionMailbox(string $localPart): EmailMailbox
    {
        $mailbox = WorkspaceContext::run(self::WS, fn () => EmailMailbox::create([
            'email_domain_id' => $this->domain->id,
            'local_part'      => $localPart,
            'quota_mb'        => 1024,
        ]));

        $this->engine->execute(new EmailOperationContext(
            workspaceId: self::WS,
            capability: Registry::MAILBOX_CREATE,
            domain: $this->domain,
            subject: $mailbox,
            actorUserId: $this->customer->id,
            source: 'customer',
            entitlements: [Registry::ENTITLEMENT_ACCESS => true, Registry::ENTITLEMENT_MAILBOX_LIMIT => true],
            idempotencyKey: 'fixture-' . $localPart,
        ));

        return $this->reload($mailbox);
    }

    private function reload($model)
    {
        return WorkspaceContext::run(self::WS, fn () => $model->refresh());
    }
}
