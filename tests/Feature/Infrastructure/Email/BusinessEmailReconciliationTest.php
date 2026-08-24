<?php

namespace Tests\Feature\Infrastructure\Email;

use App\Connectors\Infrastructure\BusinessEmail\EmailProviderCapability;
use App\Connectors\Infrastructure\InfrastructureConnectorResolver;
use App\Core\Tenancy\WorkspaceContext;
use App\Engines\Infrastructure\Email\BusinessEmailEngine;
use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\Models\EmailMailbox;
use App\Engines\Infrastructure\Email\Reconciliation\EmailReconciliationService;
use App\Engines\Infrastructure\Email\Reconciliation\ReconciliationFinding;
use App\Engines\Infrastructure\Email\Registry\BusinessEmailCapabilityRegistry as Registry;
use App\Engines\Infrastructure\Email\States\EmailDomainState;
use App\Engines\Infrastructure\Email\States\EmailMailboxState;
use App\Engines\Infrastructure\Email\States\EmailVerificationState;
use App\Engines\Infrastructure\Email\Support\EmailOperationContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Fakes\Infrastructure\Email\FakeEmailProvider;
use Tests\TestCase;

/**
 * INFRA888 · E2-H — RECONCILIATION.
 *
 * The two properties that make this trustworthy are asserted first and hardest:
 * it never mutates desired state, and it refuses to conclude anything from an
 * incomplete read.
 */
class BusinessEmailReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const WS = 9301;

    private FakeEmailProvider $fake;
    private EmailReconciliationService $service;
    private BusinessEmailEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = FakeEmailProvider::make();
        $this->app->singleton(InfrastructureConnectorResolver::class);
        $this->app->make(InfrastructureConnectorResolver::class)->fake('email', $this->fake);

        $this->engine = $this->app->make(BusinessEmailEngine::class);
        $this->service = new EmailReconciliationService($this->engine);
    }

    protected function tearDown(): void
    {
        WorkspaceContext::reset();

        parent::tearDown();
    }

    // ── the rules that make it safe ──────────────────────────────────────────

    public function test_reconciliation_never_mutates_desired_state(): void
    {
        $domain = $this->domainWithMailbox('immutable.test', 'sales');
        $mailbox = WorkspaceContext::run(self::WS, fn () => EmailMailbox::query()->first());

        $before = [
            'lifecycle_state' => $mailbox->lifecycle_state,
            'quota_mb'        => $mailbox->quota_mb,
            'domain_state'    => $domain->lifecycle_state,
        ];

        // Create every kind of drift at once.
        $this->fake->dropMailbox($this->refFor($mailbox));
        $this->fake->seedMailbox('intruder', 2048);

        $report = $this->service->reconcile($domain);

        $this->assertFalse($report->isClean());

        $mailbox->refresh();
        $domain->refresh();

        $this->assertSame($before['lifecycle_state'], $mailbox->lifecycle_state, 'Observation must not repair.');
        $this->assertSame($before['quota_mb'], $mailbox->quota_mb);
        $this->assertSame($before['domain_state'], $domain->lifecycle_state);
    }

    public function test_an_incomplete_enumeration_draws_no_absence_conclusions(): void
    {
        $domain = $this->domainWithMailbox('partial.test', 'sales');

        $this->fake->withPartialInventory('rate limited on page 2');
        $this->fake->dropMailbox($this->refFor(WorkspaceContext::run(self::WS, fn () => EmailMailbox::query()->first())));

        $report = $this->service->reconcile($domain);

        $this->assertFalse($report->conclusive);
        $this->assertSame('rate limited on page 2', $report->inconclusiveReason);

        // The mailbox IS absent from the list, but an incomplete list cannot
        // prove absence — acting on it would recreate a mailbox that exists.
        $this->assertFalse(
            $report->hasKind(ReconciliationFinding::MISSING_MAILBOX),
            'A truncated enumeration must never produce a "missing" finding.'
        );
        $this->assertFalse($report->hasKind(ReconciliationFinding::UNEXPECTED_MAILBOX));
    }

    public function test_a_provider_that_cannot_enumerate_is_reported_as_uncheckable(): void
    {
        $this->fake->withoutCapability(EmailProviderCapability::INVENTORY_LIST);

        $domain = $this->domainWithMailbox('blind.test', 'sales');
        $report = $this->service->reconcile($domain);

        $this->assertFalse($report->conclusive);
        $this->assertTrue($report->isClean(), 'Nothing can be compared, so nothing may be claimed.');
    }

    public function test_an_unreachable_provider_produces_one_honest_finding(): void
    {
        $domain = $this->domainWithMailbox('unreachable.test', 'sales');

        $this->fake->willReturn('getInventory', FakeEmailProvider::OUTCOME_PERMANENT);

        $report = $this->service->reconcile($domain);

        $this->assertFalse($report->conclusive);
        $this->assertTrue($report->hasKind(ReconciliationFinding::PROVIDER_UNREACHABLE));
    }

    // ── the thirteen findings ────────────────────────────────────────────────

    public function test_a_mailbox_missing_at_the_provider_is_critical(): void
    {
        $domain = $this->domainWithMailbox('missing.test', 'sales');
        $mailbox = WorkspaceContext::run(self::WS, fn () => EmailMailbox::query()->first());

        $this->fake->dropMailbox($this->refFor($mailbox));

        $report = $this->service->reconcile($domain);

        $this->assertTrue($report->hasKind(ReconciliationFinding::MISSING_MAILBOX));
        $this->assertSame(ReconciliationFinding::SEVERITY_CRITICAL, $report->ofKind(ReconciliationFinding::MISSING_MAILBOX)[0]->severity);
        // Its binding is dangling too.
        $this->assertTrue($report->hasKind(ReconciliationFinding::PROVIDER_OBJECT_MISSING));
    }

    public function test_a_mailbox_created_in_the_provider_console_is_reported_as_unexpected(): void
    {
        $domain = $this->domainWithMailbox('unexpected.test', 'sales');

        $this->fake->seedMailbox('shadow', 1024);

        $report = $this->service->reconcile($domain);

        $this->assertTrue($report->hasKind(ReconciliationFinding::UNEXPECTED_MAILBOX));
        $this->assertSame('shadow@unexpected.test', $report->ofKind(ReconciliationFinding::UNEXPECTED_MAILBOX)[0]->subject);
    }

    public function test_a_quota_changed_at_the_provider_is_reported(): void
    {
        $domain = $this->domainWithMailbox('quota.test', 'sales');
        $mailbox = WorkspaceContext::run(self::WS, fn () => EmailMailbox::query()->first());

        $this->fake->mutateMailbox($this->refFor($mailbox), quotaMb: 99999);

        $report = $this->service->reconcile($domain);

        $this->assertTrue($report->hasKind(ReconciliationFinding::QUOTA_MISMATCH));

        $finding = $report->ofKind(ReconciliationFinding::QUOTA_MISMATCH)[0];
        $this->assertSame(1024, $finding->detail['desired_mb']);
        $this->assertSame(99999, $finding->detail['observed_mb']);
    }

    public function test_a_suspension_changed_at_the_provider_is_reported(): void
    {
        $domain = $this->domainWithMailbox('suspmismatch.test', 'sales');
        $mailbox = WorkspaceContext::run(self::WS, fn () => EmailMailbox::query()->first());

        $this->fake->mutateMailbox($this->refFor($mailbox), suspended: true);

        $report = $this->service->reconcile($domain);

        $this->assertTrue($report->hasKind(ReconciliationFinding::SUSPENSION_MISMATCH));
    }

    public function test_an_alias_and_forwarder_at_the_provider_we_never_created_are_reported(): void
    {
        $domain = $this->domainWithMailbox('routes.test', 'sales');

        $this->fake->seedAlias('ghost', 'somewhere@elsewhere.test');
        $this->fake->seedForwarder('leak', 'attacker@elsewhere.test');

        $report = $this->service->reconcile($domain);

        $this->assertTrue($report->hasKind(ReconciliationFinding::UNEXPECTED_ALIAS));
        $this->assertTrue($report->hasKind(ReconciliationFinding::UNEXPECTED_FORWARDER));

        // Mail going somewhere the customer did not authorise is high severity.
        $this->assertSame(
            ReconciliationFinding::SEVERITY_HIGH,
            $report->ofKind(ReconciliationFinding::UNEXPECTED_FORWARDER)[0]->severity
        );
    }

    public function test_a_duplicated_provider_identity_is_reported(): void
    {
        $domain = $this->domainWithMailbox('dupe.test', 'sales');

        $this->fake->seedDuplicateRef('shared-ref', 'one', 'two');

        $report = $this->service->reconcile($domain);

        $this->assertTrue($report->hasKind(ReconciliationFinding::DUPLICATE_PROVIDER_OBJECT));
    }

    public function test_usage_that_has_never_been_sampled_is_reported_as_stale(): void
    {
        $domain = $this->domainWithMailbox('stale-usage.test', 'sales');

        $report = $this->service->reconcile($domain);

        $this->assertTrue($report->hasKind(ReconciliationFinding::USAGE_STALE));
        $this->assertSame(ReconciliationFinding::SEVERITY_LOW, $report->ofKind(ReconciliationFinding::USAGE_STALE)[0]->severity);
    }

    public function test_a_clean_domain_produces_only_the_stale_usage_finding(): void
    {
        $domain = $this->domainWithMailbox('clean.test', 'sales');

        // Sample usage so even that finding clears.
        $this->engine->execute(new EmailOperationContext(
            workspaceId: self::WS,
            capability: Registry::USAGE_SYNC,
            domain: $domain,
            actorUserId: 1,
            source: 'admin',
            entitlements: [Registry::ENTITLEMENT_ACCESS => true],
            idempotencyKey: 'usage-clean',
        ));

        $report = $this->service->reconcile($domain->refresh());

        $this->assertTrue($report->conclusive);
        $this->assertTrue($report->isClean(), 'A domain in agreement with its provider must produce no findings.');
        $this->assertNull($report->worstSeverity());
    }

    public function test_all_thirteen_finding_kinds_are_declared_with_severity_and_customer_wording(): void
    {
        $this->assertCount(13, ReconciliationFinding::kinds());

        foreach (ReconciliationFinding::kinds() as $kind) {
            $this->assertArrayHasKey($kind, ReconciliationFinding::defaultSeverities(), "{$kind} has no severity.");
            $this->assertArrayHasKey($kind, ReconciliationFinding::customerMessages(), "{$kind} has no customer wording.");
        }
    }

    // ── observation facts ────────────────────────────────────────────────────

    public function test_a_pass_is_recorded_in_the_estate_observation_registry(): void
    {
        $domain = $this->domainWithMailbox('observed.test', 'sales');
        $this->fake->seedMailbox('extra');

        $this->service->reconcile($domain);

        $fact = DB::table('infra_observation_facts')->where('subject', 'observed.test')->first();

        $this->assertNotNull($fact, 'Email drift must land in the same registry as DNS and certificate drift.');
        $this->assertSame('email_provider', $fact->dimension);
        $this->assertSame('managed_by_us', $fact->custody);
        $this->assertSame('business_email', $fact->provider, 'The fact records WHAT observed, never a vendor.');
        $this->assertSame(1, (int) $fact->success);
        $this->assertSame(1, (int) $fact->has_drift);
        $this->assertGreaterThan(0, (int) $fact->drift_count);
    }

    public function test_recording_can_be_suppressed_for_a_dry_run(): void
    {
        $domain = $this->domainWithMailbox('dryrun.test', 'sales');

        $this->service->reconcile($domain, record: false);

        $this->assertSame(0, DB::table('infra_observation_facts')->where('subject', 'dryrun.test')->count());
    }

    // ── customer vs admin separation ─────────────────────────────────────────

    public function test_the_customer_view_is_a_summary_with_no_provider_detail(): void
    {
        $domain = $this->domainWithMailbox('projection.test', 'sales');
        $mailbox = WorkspaceContext::run(self::WS, fn () => EmailMailbox::query()->first());

        $ref = $this->refFor($mailbox);
        $this->fake->dropMailbox($ref);
        $this->fake->seedMailbox('intruder');

        $report = $this->service->reconcile($domain);
        $customer = $report->toCustomerArray();
        $encoded = json_encode($customer);

        $this->assertSame(
            ['domain', 'healthy', 'checked', 'issue_count', 'worst_severity', 'issues'],
            array_keys($customer)
        );

        $this->assertStringNotContainsString($ref, $encoded, 'A provider reference must never reach a customer.');
        $this->assertStringNotContainsString('fake', $encoded, 'Nor may test or provider terminology.');

        foreach ($customer['issues'] as $issue) {
            $this->assertSame(['kind', 'subject', 'severity', 'message'], array_keys($issue));
            // No structured comparison: a customer does not read a diff of our
            // binding table.
            $this->assertArrayNotHasKey('detail', $issue);
        }
    }

    public function test_the_admin_view_keeps_the_structured_comparison(): void
    {
        $domain = $this->domainWithMailbox('admin-view.test', 'sales');
        $mailbox = WorkspaceContext::run(self::WS, fn () => EmailMailbox::query()->first());
        $this->fake->mutateMailbox($this->refFor($mailbox), quotaMb: 4096);

        $admin = $this->service->reconcile($domain)->toAdminArray();

        $this->assertArrayHasKey('findings', $admin);
        $this->assertArrayHasKey('conclusive', $admin);
        $this->assertArrayHasKey('detail', $admin['findings'][0]);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function domainWithMailbox(string $name, string $localPart): EmailDomain
    {
        $domain = WorkspaceContext::run(self::WS, function () use ($name) {
            $d = EmailDomain::create(['domain' => $name, 'custody' => 'managed_by_us']);

            $d->verification_state = EmailVerificationState::VERIFIED;
            $d->dns_verified_at = now();
            $d->lifecycle_state = EmailDomainState::ACTIVE;
            $d->save();

            return $d;
        });

        $mailbox = WorkspaceContext::run(self::WS, fn () => EmailMailbox::create([
            'email_domain_id' => $domain->id,
            'local_part'      => $localPart,
            'quota_mb'        => 1024,
        ]));

        $this->engine->execute(new EmailOperationContext(
            workspaceId: self::WS,
            capability: Registry::MAILBOX_CREATE,
            domain: $domain,
            subject: $mailbox,
            actorUserId: 1,
            source: 'admin',
            entitlements: [
                Registry::ENTITLEMENT_ACCESS => true,
                Registry::ENTITLEMENT_MAILBOX_LIMIT => 20,
            ],
            idempotencyKey: 'seed-' . $localPart,
        ));

        $this->assertSame(EmailMailboxState::ACTIVE, $mailbox->refresh()->lifecycle_state);

        return $domain->refresh();
    }

    private function refFor(EmailMailbox $mailbox): string
    {
        return (string) $this->engine->providerRef($mailbox);
    }
}
