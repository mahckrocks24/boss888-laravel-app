<?php

namespace App\Console\Commands;

use App\Connectors\Infrastructure\BusinessEmail\Values\MailboxSpec;
use App\Connectors\Infrastructure\BusinessEmail\Values\ProviderCallContext;
use App\Connectors\Infrastructure\Contracts\EmailProviderConnector;
use App\Connectors\Infrastructure\Contracts\EmailProviderProber as Probe;
use App\Connectors\Infrastructure\ProviderResult;
use App\Engines\Infrastructure\Email\Provider\EmailProviderRegistry;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\States\CredentialState;
use Illuminate\Console\Command;
use Throwable;

/**
 * INFRA888 · E6 — live validation against the real provider.
 *
 * THE ONLY OPERATION IN THIS PLATFORM THAT TALKS TO A REAL EMAIL VENDOR.
 *
 * Everything E1–E5 asserted was inferred from documentation the vendor itself
 * calls "early beta", and from a fake built to match those assumptions. This
 * command replaces assumption with evidence, and is built to report a
 * discrepancy rather than paper over one: where the live API disagrees with the
 * documentation, the live API is right and the difference gets recorded.
 *
 * IT DOES NOT KNOW WHICH VENDOR IT IS TALKING TO. Everything goes through the
 * registry and the neutral prober. The first version imported the adapter by
 * name and the white-label guards failed the build — correctly, because a
 * console command is application code.
 *
 * SAFETY, IN ORDER OF WHAT EACH STOPS
 *   · reads only, unless --with-write is passed
 *   · --with-write may create exactly ONE mailbox, at one hard-coded address
 *   · protected local parts are refused outright, so a typo cannot hit a real one
 *   · a pre-check refuses to touch the address if it already exists
 *   · deletion runs in a finally block, so a failed verify still cleans up
 *   · a before/after census must come back identical
 *   · no secret is printed, logged, or written to the evidence file
 */
class ValidateEmailProviderLiveCommand extends Command
{
    protected $signature = 'business-email:validate-live
        {--domain= : the domain to read}
        {--with-write : perform the single authorised create-verify-delete}
        {--evidence= : path to write the JSON evidence file}';

    protected $description = 'Validate the Business Email provider adapter against the real API (read-only by default).';

    /**
     * The ONLY address this command may create or delete. A constant, not an
     * option: an option can be mistyped, and the difference between this address
     * and a real one is somebody's mail.
     */
    private const VALIDATION_LOCAL_PART = 'e6-validation';

    /** Never touched, checked by name before any write. */
    private const PROTECTED_LOCAL_PARTS = ['support', 'info', 'admin', 'postmaster', 'abuse', 'hello', 'billing'];

    private array $evidence = [];
    private int $failures = 0;

    public function handle(): int
    {
        $domain = strtolower(trim((string) $this->option('domain')));

        if ($domain === '') {
            $this->error('A --domain is required.');

            return self::FAILURE;
        }

        if (! EmailProviderRegistry::networkEnabled()) {
            $this->error('Outbound provider calls are disabled. Enable BUSINESS_EMAIL_PROVIDER_NETWORK_ENABLED for this run.');

            return self::FAILURE;
        }

        $credential = $this->credential();

        if ($credential === null) {
            $this->error('No installed credential. Run business-email:install-credential first.');

            return self::FAILURE;
        }

        $connector = EmailProviderRegistry::connector();
        $prober = EmailProviderRegistry::prober();

        $this->line('Provider validation — live');
        $this->line('  provider   : ' . (EmailProviderRegistry::activeProviderKey() ?? 'unknown'));
        $this->line('  domain     : ' . $domain);
        $this->line('  credential : id ' . $credential->id . ', state ' . $credential->state
            . ', fp ' . substr((string) $credential->secret_fingerprint, 0, 12));
        $this->line('  writes     : ' . ($this->option('with-write') ? 'ONE authorised create/verify/delete' : 'none'));
        $this->newLine();

        $before = $this->census($prober, $domain);
        $this->record('census.before', true, 'baseline captured', $before);

        $this->section('Authentication and account');
        $this->check('authenticate', fn () => $connector->healthCheck());
        $this->probeCheck($prober, 'account.list', Probe::TARGET_ACCOUNT, ['domain' => $domain]);

        $this->section('Domain');
        $this->probeCheck($prober, 'domain.get', Probe::TARGET_DOMAIN, ['domain' => $domain]);
        $this->check('domain.auth_status', fn () => $connector->getDomainAuthStatus($domain, $this->callContext()));
        $this->check('domain.dns_requirements', fn () => $connector->getDnsRequirements($domain, $this->callContext()));
        $this->probeCheck($prober, 'domain.records', Probe::TARGET_DOMAIN_RECORDS, ['domain' => $domain]);
        $this->probeCheck($prober, 'domain.diagnostics', Probe::TARGET_DOMAIN_DIAGNOSE, ['domain' => $domain]);
        $this->probeCheck($prober, 'domain.usage', Probe::TARGET_DOMAIN_USAGE, ['domain' => $domain]);

        $this->section('Objects');
        $this->probeCheck($prober, 'mailboxes.list', Probe::TARGET_MAILBOXES, ['domain' => $domain]);
        $this->probeCheck($prober, 'aliases.list', Probe::TARGET_ALIASES, ['domain' => $domain]);
        $this->probeCheck($prober, 'rewrites.list', Probe::TARGET_REWRITES, ['domain' => $domain]);
        $this->check('catchall.get', fn () => $connector->getCatchAll($domain, $this->callContext()));
        $this->check('usage.get', fn () => $connector->getUsage($domain, $this->callContext()));
        $this->check('inventory.get', fn () => $connector->getInventory($domain, $this->callContext()));

        $firstMailbox = $this->firstMailboxLocalPart($connector, $domain);

        if ($firstMailbox !== null) {
            $this->check('mailbox.status', fn () => $connector->getMailboxStatus($domain . '/' . $firstMailbox, $this->callContext()));
            $this->probeCheck($prober, 'forwardings.list', Probe::TARGET_FORWARDINGS,
                ['domain' => $domain, 'local_part' => $firstMailbox]);
        } else {
            $this->record('mailbox.status', false, 'no mailbox found to read', []);
            $this->failures++;
        }

        $this->section('Error mapping');
        $this->probeCheck($prober, 'error.domain_not_found', Probe::TARGET_MISSING_DOMAIN, [], expectFailure: true);
        $this->probeCheck($prober, 'error.mailbox_not_found', Probe::TARGET_MISSING_MAILBOX,
            ['domain' => $domain], expectFailure: true);

        $unauth = $prober->probeUnauthenticated();
        $authOk = in_array($unauth['status'], [401, 403], true);
        $this->record('error.auth_failed', $authOk, 'HTTP ' . $unauth['status'], $unauth);

        if (! $authOk) {
            $this->failures++;
        }

        $this->section('Unsupported capabilities');
        $this->check('unsupported.password_reset',
            fn () => $connector->requestPasswordReset($domain . '/' . ($firstMailbox ?? 'x'), $this->callContext()));

        if ($this->option('with-write')) {
            $this->section('Authorised write validation');
            $this->writeValidation($connector, $domain);
        }

        $after = $this->census($prober, $domain);
        $identical = $before === $after;

        $this->record('census.after', $identical,
            $identical ? 'counts identical to baseline' : 'COUNTS CHANGED', $after);

        if (! $identical) {
            $this->failures++;
        }

        $this->promoteCredential($credential, $this->failures === 0);

        $this->newLine();
        $this->line('census before : ' . json_encode($before));
        $this->line('census after  : ' . json_encode($after));
        $this->line($identical ? 'UNCHANGED — no provider state moved.' : 'CHANGED — investigate before proceeding.');

        $path = (string) ($this->option('evidence') ?: storage_path('logs/e6-live-evidence.json'));

        file_put_contents($path, json_encode([
            'provider'   => EmailProviderRegistry::activeProviderKey(),
            'domain'     => $domain,
            'ran_at'     => now()->toIso8601String(),
            'with_write' => (bool) $this->option('with-write'),
            'census'     => ['before' => $before, 'after' => $after, 'identical' => $identical],
            'checks'     => $this->evidence,
            'failures'   => $this->failures,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->line('evidence: ' . $path);
        $this->line($this->failures === 0 ? 'RESULT: PASS' : 'RESULT: ' . $this->failures . ' check(s) need review');

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    // ═══ THE WRITE ═══════════════════════════════════════════════════════════

    private function writeValidation(EmailProviderConnector $connector, string $domain): void
    {
        $localPart = self::VALIDATION_LOCAL_PART;
        $ref = $domain . '/' . $localPart;

        if (in_array($localPart, self::PROTECTED_LOCAL_PARTS, true)) {
            $this->record('write.refused', false, 'validation address collides with a protected mailbox', []);
            $this->failures++;

            return;
        }

        if (! EmailProviderRegistry::mutationsEnabled()) {
            $this->record('write.blocked', false,
                'mutations are disabled; enable BUSINESS_EMAIL_PROVIDER_MUTATIONS_ENABLED for this run only', []);
            $this->failures++;

            return;
        }

        // Refuse if it already exists — deleting a pre-existing mailbox of that
        // name is not ours to do.
        if ($connector->getMailboxStatus($ref, $this->callContext())->success) {
            $this->record('write.precheck', false,
                'a mailbox already exists at the validation address; refusing to create or delete it', []);
            $this->failures++;

            return;
        }

        $created = false;

        try {
            $result = $connector->createMailbox(
                $domain,
                new MailboxSpec($localPart, 'E6 Validation'),
                $this->callContext('create')
            );

            $created = $result->success;

            $this->record('write.create', $result->success,
                $result->success ? 'accepted (' . $result->normalizedState . ')' : 'failed: ' . $result->errorCode,
                $this->resultFacts($result));

            if (! $result->success) {
                $this->failures++;

                return;
            }

            // ACCEPTED becomes VERIFIED only by reading it back.
            $verify = $connector->verify($ref, ['local_part' => $localPart]);

            $this->record('write.verify', $verify->success && $verify->verified,
                $verify->verified ? 'read-back confirmed' : 'read-back did NOT confirm: ' . $verify->normalizedState,
                $this->resultFacts($verify));

            if (! ($verify->success && $verify->verified)) {
                $this->failures++;
            }

            $repeat = $connector->createMailbox(
                $domain,
                new MailboxSpec($localPart, 'E6 Validation'),
                $this->callContext('create')
            );

            $this->record('write.idempotency', true,
                'repeat create returned ' . ($repeat->success ? 'success/' . $repeat->normalizedState : $repeat->errorCode),
                $this->resultFacts($repeat));
        } catch (Throwable $e) {
            $this->record('write.exception', false, 'threw: ' . get_class($e), []);
            $this->failures++;
        } finally {
            if ($created) {
                $delete = $connector->deleteMailbox($ref, $this->callContext('delete'));

                $this->record('write.delete', $delete->success,
                    $delete->success ? 'deleted (' . $delete->normalizedState . ')' : 'DELETE FAILED: ' . $delete->errorCode,
                    $this->resultFacts($delete));

                if (! $delete->success) {
                    $this->failures++;
                    $this->error('MANUAL CLEANUP REQUIRED: ' . $localPart . '@' . $domain);
                }

                $gone = $connector->getMailboxStatus($ref, $this->callContext());

                $this->record('write.delete_confirmed', ! $gone->success,
                    $gone->success ? 'STILL PRESENT after delete' : 'confirmed absent', []);

                if ($gone->success) {
                    $this->failures++;
                }
            }
        }
    }

    // ═══ CHECKS ══════════════════════════════════════════════════════════════

    private function check(string $name, callable $call): void
    {
        $started = microtime(true);

        try {
            /** @var ProviderResult $result */
            $result = $call();
            $ms = (int) ((microtime(true) - $started) * 1000);

            // `unsupported` is the CORRECT outcome for a capability the provider
            // lacks, not a failure of the check.
            $expectUnsupported = str_starts_with($name, 'unsupported.');
            $ok = $expectUnsupported ? ($result->normalizedState === 'unsupported') : $result->success;

            $this->record($name, $ok,
                ($result->success ? 'success' : $result->errorCode) . ' / ' . $result->normalizedState,
                $this->resultFacts($result) + ['latency_ms' => $ms]);

            if (! $ok) {
                $this->failures++;
            }
        } catch (Throwable $e) {
            $this->record($name, false, 'threw ' . get_class($e), []);
            $this->failures++;
        }
    }

    private function probeCheck(Probe $prober, string $name, string $target, array $args, bool $expectFailure = false): void
    {
        $facts = $prober->probe($target, $args);
        $ok = $expectFailure ? $facts['status'] >= 400 : $facts['status'] === 200;

        $this->record($name, $ok, 'HTTP ' . $facts['status'] . ' / ' . $facts['shape'], $facts);

        if (! $ok) {
            $this->failures++;
        }
    }

    // ═══ SUPPORT ═════════════════════════════════════════════════════════════

    private function census(Probe $prober, string $domain): array
    {
        $count = function (string $target) use ($prober, $domain): ?int {
            $facts = $prober->probe($target, ['domain' => $domain]);

            return $facts['status'] === 200 ? $facts['row_count'] : null;
        };

        return [
            'domains'   => $count(Probe::TARGET_ACCOUNT),
            'mailboxes' => $count(Probe::TARGET_MAILBOXES),
            'aliases'   => $count(Probe::TARGET_ALIASES),
            'rewrites'  => $count(Probe::TARGET_REWRITES),
        ];
    }

    /** Through the connector, so no vendor payload shape is assumed here. */
    private function firstMailboxLocalPart(EmailProviderConnector $connector, string $domain): ?string
    {
        $result = $connector->getInventory($domain, $this->callContext());

        if (! $result->success) {
            return null;
        }

        $inventory = $result->data['inventory'] ?? null;

        foreach ($inventory?->mailboxes ?? [] as $mailbox) {
            if ($mailbox->localPart !== '') {
                return $mailbox->localPart;
            }
        }

        return null;
    }

    private function credential(): ?InfraProviderCredential
    {
        $key = EmailProviderRegistry::activeProviderKey();

        if ($key === null) {
            return null;
        }

        return InfraProviderCredential::query()
            ->whereHas('provider', fn ($q) => $q->where('provider_key', $key))
            ->whereIn('state', array_merge(CredentialState::usable(), [CredentialState::PENDING_VERIFICATION]))
            ->orderByDesc('id')
            ->first();
    }

    /**
     * A credential that has authenticated and read back is proven; one that has
     * not stays pending. Promotion on evidence, never on elapsed time.
     */
    private function promoteCredential(InfraProviderCredential $credential, bool $passed): void
    {
        $credential->forceFill([
            'last_verified_at'          => now(),
            'last_verification_ok'      => $passed,
            'last_verification_code'    => $passed ? 'live_validation_passed' : 'live_validation_failed',
            'last_verification_summary' => $passed
                ? 'Live validation succeeded against the real API.'
                : 'Live validation reported ' . $this->failures . ' check(s) needing review.',
        ]);

        if ($passed && $credential->state === CredentialState::PENDING_VERIFICATION) {
            $credential->state = CredentialState::ACTIVE;
            $credential->activated_at = now();
            $this->line('Credential promoted to active on evidence.');
        }

        $credential->save();
    }

    private function callContext(string $intent = 'read'): ProviderCallContext
    {
        return new ProviderCallContext(
            workspaceId: 1,
            ownerType: 'email_validation',
            ownerId: null,
            idempotencyKey: 'e6-live-' . $intent . '-' . date('Ymd'),
        );
    }

    /** Never a payload, a credential, or a customer address. */
    private function resultFacts(ProviderResult $result): array
    {
        return [
            'success'          => $result->success,
            'verified'         => $result->verified,
            'normalized_state' => $result->normalizedState,
            'error_code'       => $result->errorCode,
            'retryable'        => $result->isRetryable(),
        ];
    }

    private function section(string $title): void
    {
        $this->newLine();
        $this->line('── ' . $title . ' ' . str_repeat('─', max(0, 60 - strlen($title))));
    }

    private function record(string $name, bool $ok, string $detail, array $facts): void
    {
        $this->evidence[] = ['check' => $name, 'ok' => $ok, 'detail' => $detail, 'facts' => $facts];

        $this->line(sprintf('  %-28s %s  %s', $name, $ok ? 'ok  ' : 'FAIL', $detail));
    }
}
