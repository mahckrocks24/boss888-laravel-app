<?php

namespace App\Engines\Infrastructure\Registry;

use App\Engines\Infrastructure\Models\InfraProvider;
use InvalidArgumentException;

/**
 * INFRA888 - the one place that maps a DEPLOYMENT environment to a PROVIDER
 * EXECUTION environment.
 *
 * THESE ARE DIFFERENT CONCEPTS, AND CONFLATING THEM CAUSED A REAL OUTAGE OF
 * GOVERNANCE.
 *
 *   app()->environment()   names the deployment: local, testing, staging, production.
 *   InfraProvider::ENV_*   names which provider tier work executes against,
 *                          and the vocabulary is deliberately only two values:
 *                          sandbox | production.
 *
 * `InstallEmailProviderCredentialCommand` stamped app()->environment() straight
 * into infra_provider_credentials.environment. That wrote 'staging' - a value
 * the provider vocabulary has never contained - and the credential became
 * invisible to ProviderResolutionService::usableCredential() while remaining
 * visible to a connector factory doing its own query. Functional, ungoverned.
 *
 * WHY 'staging' IS NOT ADDED TO THE VOCABULARY
 * The obvious fix is a third environment. The evidence says no:
 *
 *  1. CertificationLevel already has a STAGING_CERTIFIED rung
 *     (registered -> read_only_verified -> sandbox_validated -> staging_certified
 *     -> production_approved). Maturity is tracked on its OWN axis. Adding
 *     'staging' to the environment axis would duplicate that, and the two
 *     copies would disagree.
 *
 *  2. ProviderLifecycleState::TESTING is documented as "usable in sandbox only;
 *     never selectable for production". The model already has a way to express
 *     pre-production provider work: sandbox + testing.
 *
 *  3. A vendor either gives you a separate sandbox tier or it does not. That is
 *     a property of the vendor, not of which server we deployed to.
 *
 * So the deployment axis maps ONTO the provider axis, and production is the
 * only deployment that earns the production tier.
 *
 * NO SILENT FALLBACK. An unrecognised deployment name throws. Guessing
 * 'production' would route real customer work at an unknown provider tier;
 * guessing 'sandbox' would silently downgrade production. Both are worse than
 * a loud failure.
 */
final class ProviderEnvironmentResolver
{
    /**
     * Deployment name => provider execution environment.
     *
     * Every Laravel environment LevelUp actually uses is listed explicitly.
     * `staging` maps to sandbox and NOT to production even though this
     * deployment is production-facing for customers: the question here is which
     * provider tier is certified for the work, not who can see the website.
     */
    private const MAP = [
        'production' => InfraProvider::ENV_PRODUCTION,
        'staging'    => InfraProvider::ENV_SANDBOX,
        'testing'    => InfraProvider::ENV_SANDBOX,
        'local'      => InfraProvider::ENV_SANDBOX,
    ];

    /** The provider environment for the running deployment. */
    public static function current(): string
    {
        return self::for(app()->environment());
    }

    public static function for(string $deploymentEnvironment): string
    {
        $key = strtolower(trim($deploymentEnvironment));

        if (! isset(self::MAP[$key])) {
            throw new InvalidArgumentException(
                "No provider environment is defined for deployment environment '{$deploymentEnvironment}'. "
                . 'Known deployments: ' . implode(', ', array_keys(self::MAP)) . '. '
                . 'Add it to ProviderEnvironmentResolver deliberately rather than defaulting.'
            );
        }

        return self::MAP[$key];
    }

    /** @return array<string,string> */
    public static function map(): array
    {
        return self::MAP;
    }

    public static function knowsDeployment(string $deploymentEnvironment): bool
    {
        return isset(self::MAP[strtolower(trim($deploymentEnvironment))]);
    }

    /**
     * True when the running deployment executes against the production tier.
     * Callers use this to decide whether a mistake is expensive.
     */
    public static function isProductionTier(): bool
    {
        return self::current() === InfraProvider::ENV_PRODUCTION;
    }
}
