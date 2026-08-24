<?php

namespace App\Connectors\Infrastructure\Contracts;

/**
 * INFRA888 · E6 — how the platform obtains an email provider without naming one.
 *
 * WHY THIS EXISTS
 * E6 added two operator commands — install a credential, validate against the
 * live API — and both imported the vendor's factory by name. The white-label
 * guards failed the build immediately, and they were right to: a console command
 * is application code, and application code may not know which vendor we use.
 *
 * The seam is this interface plus EmailProviderRegistry, which discovers
 * implementations by scanning the vendor directory. Vendor identity therefore
 * lives in a directory name and a database row, and nowhere in the code that
 * uses it.
 */
interface EmailProviderFactory
{
    /** The registry key this factory serves, matching infra_providers.provider_key. */
    public static function providerKey(): string;

    /** A fully configured connector. Throws when the provider is not installed. */
    public static function make(): EmailProviderConnector;

    /** A connector that can be inspected but cannot call anything. */
    public static function inert(): EmailProviderConnector;

    /** Installation state. Never returns secret material. */
    public static function status(): array;

    public static function networkEnabled(): bool;

    public static function mutationsEnabled(): bool;
}
