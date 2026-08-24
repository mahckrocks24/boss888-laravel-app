<?php

namespace App\Console\Commands;

use App\Engines\Infrastructure\Email\Provider\EmailProviderRegistry;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\States\CredentialState;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * INFRA888 · E6 — install the Business Email provider credential.
 *
 * THE SECRET IS READ FROM A FILE AND FROM NOWHERE ELSE.
 *
 * Not an argument, because arguments land in shell history, in `ps` output
 * while the command runs, and in any process listing an operator happens to
 * take. Not an env var, because those leak into child processes and crash
 * dumps. A file with 0600 permissions, read once, and shredded by the caller.
 *
 * NOTHING THIS COMMAND PRINTS CAN RECONSTRUCT THE KEY. It emits the
 * fingerprint prefix and the model's own four-character hint, which is exactly
 * enough to confirm that the key installed is the key you generated.
 */
class InstallEmailProviderCredentialCommand extends Command
{
    protected $signature = 'business-email:install-credential
        {--account= : the provider account email, which is also the API username}
        {--secret-file= : path to a 0600 file containing ONLY the API key}
        {--environment= : defaults to the current app environment}
        {--role=diagnostics : CredentialRoleRegistry role. Defaults to the least-privileged one; pass provisioning explicitly to allow customer mutations}
        {--expires-days=365 : we set an expiry the vendor does not}
        {--force : replace an existing active credential by superseding it}';

    protected $description = 'Install the Business Email provider API credential into the encrypted store.';

    public function handle(): int
    {
        $account = trim((string) $this->option('account'));
        $file = (string) $this->option('secret-file');
        $environment = trim((string) $this->option('environment')) ?: \App\Engines\Infrastructure\Registry\ProviderEnvironmentResolver::current();
        $role = (string) $this->option('role');

        if ($account === '' || $file === '') {
            $this->error('Both --account and --secret-file are required.');

            return self::FAILURE;
        }

        if (! is_file($file) || ! is_readable($file)) {
            $this->error('The secret file does not exist or cannot be read.');

            return self::FAILURE;
        }

        // A world-readable secret file is a finding, not a warning.
        $mode = fileperms($file) & 0777;

        if ($mode & 0077) {
            $this->error(sprintf(
                'Refusing to read a secret file with permissions %04o. Run: chmod 600 %s',
                $mode,
                $file
            ));

            return self::FAILURE;
        }

        $secret = trim((string) file_get_contents($file));

        if ($secret === '') {
            $this->error('The secret file is empty.');

            return self::FAILURE;
        }

        // A pasted-in "Authorization: Basic ..." or a full curl line is a
        // common mistake and would install a credential that silently fails.
        if (preg_match('/\s/', $secret) === 1 || stripos($secret, 'basic ') === 0 || stripos($secret, 'bearer ') === 0) {
            $this->error('The secret file must contain the API key alone — no header, no whitespace, no quotes.');

            return self::FAILURE;
        }

        $provider = $this->provider();

        $existing = InfraProviderCredential::query()
            ->where('provider_id', $provider->id)
            ->where('environment', $environment)
            ->whereIn('state', array_merge(CredentialState::usable(), [CredentialState::PENDING_VERIFICATION]))
            ->orderByDesc('id')
            ->first();

        if ($existing !== null && ! $this->option('force')) {
            $this->error("A usable credential already exists (id {$existing->id}). Re-run with --force to supersede it.");

            return self::FAILURE;
        }

        // GOVERNANCE (2026-08-11). This command builds the model directly instead
        // of going through ProviderCredentialService::install(), and so skipped the
        // registry check that service performs. A credential was installed with
        // credential_role = 'read_only' - a value CredentialRoleRegistry does not
        // define - which left ProviderResolutionService able to find ZERO usable
        // provisioning credentials while the connector happily used it anyway.
        // The role column is governed; an ungoverned writer is a hole in it.
        \App\Engines\Infrastructure\Registry\CredentialRoleRegistry::assertKnown($role);

        $fingerprint = InfraProviderCredential::fingerprint($secret);

        if ($existing !== null && $existing->secret_fingerprint === $fingerprint) {
            $this->info('That exact key is already installed. Nothing to do.');

            return self::SUCCESS;
        }

        $credential = DB::transaction(function () use ($provider, $account, $secret, $environment, $role, $existing, $fingerprint) {
            $new = new InfraProviderCredential();

            $new->fill([
                'provider_id'       => $provider->id,
                'credential_key'    => 'email.api.' . $environment,
                'environment'       => $environment,
                'label'             => 'Business Email provider API key',
                'purpose'           => 'Business Email provisioning and observation',
                'credential_role'   => $role,
                // Fails closed when empty, so it must be set explicitly.
                'capability_scope_json' => ['email'],
                'region_scope_json' => [],
                'account_identifier' => $account,
                'secret_encrypted'  => $secret,
                'secret_fingerprint' => $fingerprint,
                'secret_hint'       => InfraProviderCredential::hint($secret),
                // PENDING_VERIFICATION, not ACTIVE. A key nobody has proven
                // against the provider has not earned `active`; the live
                // read-only run promotes it.
                'state'             => CredentialState::PENDING_VERIFICATION,
                'valid_from'        => now(),
                'expires_at'        => now()->addDays((int) $this->option('expires-days')),
                'supersedes_id'     => $existing?->id,
            ]);

            $new->save();

            if ($existing !== null) {
                $existing->forceFill([
                    'state'            => CredentialState::SUPERSEDED,
                    'superseded_by_id' => $new->id,
                    'revoked_at'       => now(),
                    'revoked_reason'   => 'Superseded by a newly installed credential.',
                ])->save();
            }

            return $new;
        });

        // Everything below is safe to read over someone's shoulder.
        $this->info('Credential installed.');
        $this->table(['field', 'value'], [
            ['id', $credential->id],
            ['provider', $provider->provider_key],
            ['environment', $credential->environment],
            ['role', $credential->credential_role],
            ['state', $credential->state],
            ['capability scope', implode(',', $credential->capability_scope_json ?? [])],
            ['account identifier', $credential->account_identifier],
            ['fingerprint (first 12)', substr((string) $credential->secret_fingerprint, 0, 12)],
            ['hint', (string) $credential->secret_hint],
            ['expires', optional($credential->expires_at)->toDateString()],
            ['supersedes', (string) ($credential->supersedes_id ?? '—')],
        ]);

        $this->newLine();
        $this->warn('Now shred the secret file. It has served its purpose:');
        $this->line('  shred -u ' . $file);
        $this->newLine();
        $this->line('Gates remain closed. Nothing will call the provider until network access is enabled.');
        $this->line('  network_enabled   : ' . var_export(EmailProviderRegistry::networkEnabled(), true));
        $this->line('  mutations_enabled : ' . var_export(EmailProviderRegistry::mutationsEnabled(), true));

        return self::SUCCESS;
    }

    /**
     * The provider row, created disabled — registration is not activation.
     *
     * Everything vendor-specific is DISCOVERED rather than written here: the key
     * and adapter class come from whichever factory the registry found on disk.
     * That is what lets this command install a credential for a provider whose
     * name it does not know, which is the rule the white-label guards enforce.
     */
    private function provider(): InfraProvider
    {
        $key = $this->providerKey();

        $provider = InfraProvider::query()->where('provider_key', $key)->first();

        if ($provider !== null) {
            return $provider;
        }

        $provider = new InfraProvider();

        $provider->fill([
            'provider_key'      => $key,
            // Title-cased key, not a hard-coded brand string. An operator sees
            // a readable name; this file still contains no vendor identity.
            'display_name'      => ucfirst($key),
            'provider_type'     => 'email',
            'adapter_class'     => $this->adapterClass(),
            'adapter_version'   => '1.0.0-e6',
            'lifecycle_state'   => 'draft',
            'enabled'           => false,
            'production_ready'  => false,
            'sandbox_ready'     => false,
            'priority'          => 100,
            // Provider ENVIRONMENTS, not deployment names. 'staging' here was a
                // fourth instance of the same confusion and made this provider
                // undeclarable for the environment it actually runs in.
                'environments_json' => \App\Engines\Infrastructure\Models\InfraProvider::environments(),
            'regions_json'      => [],
            'currencies_json'   => ['USD'],
            'feature_flags_json' => [],
            'metadata_json'     => [
                'registered_by'             => 'business-email:install-credential',
                'read_only_scope_available' => false,
            ],
            'operational_notes' => 'Registered for INFRA888 E6 live read-only validation. NOT enabled. '
                . 'The vendor issues one full-authority key type; read-only is enforced by the '
                . 'INFRA888 mutation gate, not by the credential.',
        ]);

        $provider->save();

        $this->line("Registered provider '{$provider->provider_key}' (disabled).");

        return $provider;
    }

    /**
     * The provider key of whichever adapter is installed on disk.
     *
     * Exactly one email adapter is expected. More than one is an operator
     * decision, not something a credential-install command should guess at.
     */
    private function providerKey(): string
    {
        $available = array_keys(EmailProviderRegistry::available());

        if ($available === []) {
            throw new \RuntimeException('No email provider adapter is installed on disk.');
        }

        if (count($available) > 1) {
            throw new \RuntimeException(
                'More than one email provider adapter is installed: ' . implode(', ', $available)
                . '. Registering a credential requires an unambiguous target.'
            );
        }

        return $available[0];
    }

    private function adapterClass(): string
    {
        $factory = EmailProviderRegistry::available()[$this->providerKey()];

        // The factory sits beside its connector in the same vendor directory.
        return str_replace('ConnectorFactory', 'EmailProviderConnector', $factory);
    }
}
