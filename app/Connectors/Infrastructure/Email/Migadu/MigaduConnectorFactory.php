<?php

namespace App\Connectors\Infrastructure\Email\Migadu;

use App\Connectors\Infrastructure\Contracts\EmailProviderConnector;
use App\Connectors\Infrastructure\Contracts\EmailProviderFactory;
use App\Connectors\Infrastructure\Contracts\EmailProviderProber;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use App\Engines\Infrastructure\Registry\ProviderEnvironmentResolver;
use App\Engines\Infrastructure\Services\ProviderResolutionService;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\States\CredentialState;
use Illuminate\Http\Client\Factory as HttpFactory;
use RuntimeException;

/**
 * INFRA888 · E5 — builds the adapter, and refuses to when it should not exist.
 *
 * WHY A FACTORY RATHER THAN A CONTAINER BINDING
 * `InfrastructureConnectorResolver` resolves `config('infrastructure.connectors.email')`
 * through `app()`. Registering this class there would make it the email
 * connector for every workspace the moment the config key existed — one edit
 * away from live provisioning. During E5 the key stays unset, the resolver
 * keeps throwing for `email`, and this factory is the only way to obtain an
 * instance. E6 can register it deliberately, having decided to.
 *
 * THE LAYERED CONTROLS, IN ORDER OF WHAT THEY STOP
 *   1. provider row must exist, be enabled, and be production-ready
 *   2. an ACTIVE credential must be installed for this environment
 *   3. outbound network calls must be explicitly enabled
 *   4. mutations must be separately enabled on top of that
 * No single flag reaches live provisioning, which is the requirement.
 */
final class MigaduConnectorFactory implements EmailProviderFactory
{
    public const PROVIDER_KEY = 'migadu';

    /** Discovered by EmailProviderRegistry, which never names a vendor. */
    public static function providerKey(): string
    {
        return self::PROVIDER_KEY;
    }

    /** Raw HTTP observation for live validation, behind a neutral interface. */
    public static function prober(): EmailProviderProber
    {
        [$email, $key] = self::credential();

        return new MigaduProber(new MigaduClient(
            http: app(HttpFactory::class),
            accountEmail: $email,
            apiKey: $key,
            networkEnabled: self::networkEnabled(),
        ));
    }

    /**
     * @throws RuntimeException when the provider is not installed and enabled.
     *         The message never names the vendor, because it can surface in an
     *         operator-facing error and the registry row is the place vendor
     *         identity belongs.
     */
    public static function make(): EmailProviderConnector
    {
        [$email, $key] = self::credential();

        return new MigaduEmailProviderConnector(
            client: new MigaduClient(
                http: app(HttpFactory::class),
                accountEmail: $email,
                apiKey: $key,
                networkEnabled: self::networkEnabled(),
            ),
            mutationsEnabled: self::mutationsEnabled(),
        );
    }

    /**
     * An adapter that can be constructed and inspected but cannot call anything.
     * This is what the provider registry and the capability matrix use, and what
     * E5 uses for every test that is not exercising the HTTP layer.
     */
    public static function inert(): EmailProviderConnector
    {
        return new MigaduEmailProviderConnector(
            client: new MigaduClient(
                http: app(HttpFactory::class),
                accountEmail: '',
                apiKey: '',
                networkEnabled: false,
            ),
            mutationsEnabled: false,
        );
    }

    public static function networkEnabled(): bool
    {
        return (bool) config('business_email.provider.network_enabled', false);
    }

    /**
     * Mutations require BOTH switches. Network access alone is read-only
     * validation; it must never imply permission to change anything.
     */
    public static function mutationsEnabled(): bool
    {
        return self::networkEnabled()
            && (bool) config('business_email.provider.mutations_enabled', false);
    }

    /** Reports installation state without ever returning or logging a secret. */
    public static function status(): array
    {
        $provider = self::providerRow();
        // Diagnostics must report even when governance refuses - that refusal
        // IS the diagnosis - so this reads through the same door and degrades
        // to null rather than inventing a second lookup.
        try {
            $credential = $provider ? self::governedCredential() : null;
        } catch (\Throwable) {
            $credential = null;
        }

        return [
            'provider_key'        => self::PROVIDER_KEY,
            'provider_registered' => $provider !== null,
            'provider_enabled'    => (bool) ($provider->enabled ?? false),
            'production_ready'    => (bool) ($provider->production_ready ?? false),
            'credential_present'  => $credential !== null,
            'credential_role'     => $credential->credential_role ?? null,
            'credential_state'    => $credential->state ?? null,
            // A fingerprint identifies WHICH key is installed without being one.
            'credential_fingerprint' => $credential?->secret_fingerprint,
            'account_identifier'  => $credential?->account_identifier,
            'network_enabled'     => self::networkEnabled(),
            'mutations_enabled'   => self::mutationsEnabled(),
            'capability_matrix_verified_on' => MigaduCapabilityMap::VERIFIED_ON,
        ];
    }

    /**
     * @return array{0:string,1:string} [accountEmail, apiKey]
     *
     * @throws RuntimeException
     */
    private static function credential(): array
    {
        $provider = self::providerRow();

        if ($provider === null) {
            throw new RuntimeException('The email provider is not registered in this environment.');
        }

        if (! $provider->enabled) {
            throw new RuntimeException('The email provider is registered but not enabled.');
        }

        // GOVERNANCE (2026-08-11). The credential is chosen by
        // ProviderResolutionService, never here. That service applies the gates
        // this adapter has no business re-implementing: capability declared and
        // enabled, health recorded from a real probe, provider lifecycle and
        // environment eligibility, credential role, scope, validity and
        // revocation. Selecting one here as well is what made Business Email
        // functional but ungoverned throughout E1-E7.
        $credential = self::governedCredential();

        if ($credential === null) {
            throw new RuntimeException('No active credential is installed for the email provider.');
        }

        // account_identifier holds the login the API authenticates as. It is an
        // identifier, not a secret, which is why it is stored in the clear and
        // the key beside it is not.
        $accountEmail = (string) $credential->account_identifier;

        // secret() — NOT ->secret_encrypted.
        //
        // E5 read the raw attribute, which quietly bypassed the model's guard:
        // a revoked or superseded credential would still have handed over its
        // key. Revocation that can be sidestepped by reading a property is not
        // revocation. secretForVerification() is the one legitimate bypass, and
        // only for a credential that has not yet been proven.
        $apiKey = (string) ($credential->state === CredentialState::PENDING_VERIFICATION
            ? $credential->secretForVerification()
            : $credential->secret());

        if ($accountEmail === '' || $apiKey === '') {
            throw new RuntimeException('The installed email provider credential is incomplete.');
        }

        // Scope fails closed by design: a credential with no capability scope
        // covers nothing. Checking it here means a wrongly-scoped key is refused
        // at construction rather than discovered mid-call.
        if (! $credential->coversCapability('email')) {
            throw new RuntimeException('The installed credential is not scoped to the email capability.');
        }

        return [$accountEmail, $apiKey];
    }

    private static function providerRow(): ?InfraProvider
    {
        return InfraProvider::query()
            ->where('provider_key', self::PROVIDER_KEY)
            ->first();
    }

    /**
     * The ONE way this adapter obtains a credential.
     *
     * It does not query. It asks the governance service, which either returns a
     * fully-qualified selection or refuses with the reason. There is deliberately
     * no fallback: an adapter that can still find a key after governance says no
     * is not governed, it is merely supervised.
     */
    private static function governedCredential(): InfraProviderCredential
    {
        $resolved = app(ProviderResolutionService::class)->resolve(
            InfraProviderConnection::CAPABILITY_EMAIL,
            ProviderEnvironmentResolver::current()
        );

        $credential = $resolved['credential'] ?? null;

        if (! $credential instanceof InfraProviderCredential) {
            throw new RuntimeException('Provider resolution returned no usable credential.');
        }

        if ((string) $resolved['provider']->provider_key !== self::PROVIDER_KEY) {
            // Another adapter won resolution. Building this one anyway would hand
            // a customer mailbox to the wrong vendor.
            throw new RuntimeException('Provider resolution selected a different adapter.');
        }

        return $credential;
    }
}
