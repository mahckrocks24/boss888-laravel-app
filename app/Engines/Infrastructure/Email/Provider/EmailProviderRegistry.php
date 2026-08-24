<?php

namespace App\Engines\Infrastructure\Email\Provider;

use App\Connectors\Infrastructure\Contracts\EmailProviderConnector;
use App\Connectors\Infrastructure\Contracts\EmailProviderFactory;
use App\Connectors\Infrastructure\Contracts\EmailProviderProber;
use App\Engines\Infrastructure\Models\InfraProvider;
use RuntimeException;

/**
 * INFRA888 · E6 — the provider-neutral way to reach whichever vendor is installed.
 *
 * DISCOVERY, NOT A HARD-CODED MAP.
 *
 * Vendor adapters live one directory deep under a fixed root. This class reads
 * that directory, keeps the classes implementing EmailProviderFactory, and picks
 * the one whose providerKey() matches the enabled row in infra_providers.
 *
 * The consequence is the point: no file outside the vendor's own directory ever
 * contains its name. Adding a second provider is a new directory and a new
 * database row, with no edit here and none in any caller — which is the promise
 * the whole connector seam was built to make.
 */
final class EmailProviderRegistry
{
    /** The one directory permitted to contain vendor-specific code. */
    private const VENDOR_ROOT = 'Connectors/Infrastructure/Email';

    private const VENDOR_NAMESPACE = 'App\\Connectors\\Infrastructure\\Email\\';

    /** @var array<string,class-string<EmailProviderFactory>>|null */
    private static ?array $discovered = null;

    /**
     * Every installed adapter factory, keyed by provider key.
     *
     * @return array<string,class-string<EmailProviderFactory>>
     */
    public static function available(): array
    {
        if (self::$discovered !== null) {
            return self::$discovered;
        }

        $factories = [];
        $root = app_path(self::VENDOR_ROOT);

        foreach (glob($root . '/*', GLOB_ONLYDIR) ?: [] as $vendorDir) {
            $vendor = basename($vendorDir);

            foreach (glob($vendorDir . '/*.php') ?: [] as $file) {
                $class = self::VENDOR_NAMESPACE . $vendor . '\\' . basename($file, '.php');

                if (! class_exists($class)) {
                    continue;
                }

                if (! is_subclass_of($class, EmailProviderFactory::class)) {
                    continue;
                }

                $factories[$class::providerKey()] = $class;
            }
        }

        return self::$discovered = $factories;
    }

    /** Test seam, and a guard against a stale cache between console commands. */
    public static function forget(): void
    {
        self::$discovered = null;
    }

    /**
     * The provider row this platform should be using: enabled, and with an
     * adapter actually present on disk.
     */
    public static function activeProvider(): ?InfraProvider
    {
        $available = array_keys(self::available());

        if ($available === []) {
            return null;
        }

        return InfraProvider::query()
            ->where('provider_type', 'email')
            ->whereIn('provider_key', $available)
            ->orderByDesc('enabled')
            ->orderBy('priority')
            ->first();
    }

    /** @return class-string<EmailProviderFactory> */
    private static function factoryClass(): string
    {
        $provider = self::activeProvider();

        if ($provider === null) {
            throw new RuntimeException('No email provider is installed in this environment.');
        }

        $factories = self::available();

        if (! isset($factories[$provider->provider_key])) {
            throw new RuntimeException('The installed email provider has no adapter on disk.');
        }

        return $factories[$provider->provider_key];
    }

    public static function connector(): EmailProviderConnector
    {
        return (self::factoryClass())::make();
    }

    public static function inertConnector(): EmailProviderConnector
    {
        return (self::factoryClass())::inert();
    }

    /** Raw HTTP observation for live validation, without naming a vendor. */
    public static function prober(): EmailProviderProber
    {
        return (self::factoryClass())::prober();
    }

    /**
     * Installation state for an operator surface.
     *
     * Admin may see provider identity in provider-management diagnostics — that
     * is the one place the rule permits it — so `provider_key` is included here
     * and must not be forwarded to a customer surface.
     */
    public static function status(): array
    {
        try {
            return (self::factoryClass())::status();
        } catch (RuntimeException $e) {
            return [
                'installed' => false,
                'reason'    => $e->getMessage(),
            ];
        }
    }

    public static function networkEnabled(): bool
    {
        try {
            return (self::factoryClass())::networkEnabled();
        } catch (RuntimeException) {
            return false;
        }
    }

    public static function mutationsEnabled(): bool
    {
        try {
            return (self::factoryClass())::mutationsEnabled();
        } catch (RuntimeException) {
            return false;
        }
    }

    /**
     * The key of whichever provider is installed. An identifier for operator
     * surfaces and provider-management diagnostics — never a customer string.
     */
    public static function activeProviderKey(): ?string
    {
        return self::activeProvider()?->provider_key;
    }
}
