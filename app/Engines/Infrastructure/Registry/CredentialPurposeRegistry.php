<?php

namespace App\Engines\Infrastructure\Registry;

use App\Engines\Infrastructure\Models\InfraProviderConnection;
use InvalidArgumentException;

/**
 * Binds each credential to a PURPOSE, and each purpose to the capabilities it
 * may serve (Phase 2B-G, Workstream 7).
 *
 * WHY CAPABILITY SCOPE ALONE WAS NOT ENOUGH
 * -----------------------------------------
 * `InfraProviderCredential::coversCapability()` already prevents a DNS request
 * from selecting a certificate-scoped credential. That closes the runtime path.
 * It does NOT stop the credential from being CREATED over-broad in the first
 * place — nothing prevented an operator storing one credential scoped to
 * [dns, certificate, registrar] and letting every adapter share it.
 *
 * Boss's requirement is stricter and correct: "A DNS adapter should never be
 * able to request the certificate credential merely because both belong to
 * Cloudflare." That must be structural, not a convention an operator remembers.
 *
 * So a purpose is declared at creation, the purpose fixes the maximum capability
 * set, and a credential may never be scoped beyond it. Three narrow purposes
 * cannot be merged into one powerful credential even deliberately.
 *
 * PROVIDER-NEUTRAL. These purposes describe operational ROLES, not any vendor's
 * token taxonomy. Cloudflare's three-token split happens to map onto them
 * cleanly, which is evidence the roles are natural rather than evidence they
 * were copied.
 */
final class CredentialPurposeRegistry
{
    /** Zone reads plus DNS record CRUD. No certificate authority whatsoever. */
    public const PURPOSE_DNS = 'dns_operations';

    /** Certificates and custom hostnames. Cannot touch DNS records. */
    public const PURPOSE_CERTIFICATE = 'certificate_operations';

    /** Read-only diagnostics. No mutation rights at all. */
    public const PURPOSE_DIAGNOSTICS = 'readonly_diagnostics';

    /** Domain registration/transfer. Distinct from DNS hosting. */
    public const PURPOSE_REGISTRAR = 'registrar_operations';

    /** Server/site provisioning. */
    public const PURPOSE_HOSTING = 'hosting_operations';

    /** Mailbox and domain-email administration. */
    public const PURPOSE_EMAIL = 'email_operations';

    /** Backup creation, verification and restore. */
    public const PURPOSE_BACKUP = 'backup_operations';

    /** Uptime and incident monitoring. */
    public const PURPOSE_MONITORING = 'monitoring_operations';

    /**
     * purpose => the ONLY capabilities a credential of that purpose may serve.
     *
     * Note `dns_operations` does NOT include `certificate`, and
     * `certificate_operations` does NOT include `dns` — even though a single
     * vendor token could technically do both. That separation is the entire
     * point: it caps the blast radius of one leaked credential.
     */
    private const MAP = [
        self::PURPOSE_DNS => [
            'capabilities' => [InfraProviderConnection::CAPABILITY_DNS],
            'mutating'     => true,
            'description'  => 'Zone identity reads and DNS record create/update/delete.',
        ],
        self::PURPOSE_CERTIFICATE => [
            // Custom hostnames travel with certificates because on some providers
            // they are one object. They are still separate CAPABILITIES with
            // separate contracts (Phase 2B-1); this only says one credential
            // purpose may serve both.
            'capabilities' => [
                InfraProviderConnection::CAPABILITY_CERTIFICATE,
                InfraProviderConnection::CAPABILITY_CUSTOM_HOSTNAME,
            ],
            'mutating'     => true,
            'description'  => 'Certificate issuance/renewal and custom-hostname management.',
        ],
        self::PURPOSE_DIAGNOSTICS => [
            // Deliberately EMPTY. A diagnostics credential may never be selected
            // to perform a capability — it exists only for health probes and
            // scope inspection. This is why resolution requires a non-empty
            // capability scope: a read-only credential must never satisfy a
            // provisioning request.
            'capabilities' => [],
            'mutating'     => false,
            'description'  => 'Read-only zone/account settings and health diagnostics. No mutation.',
        ],
        self::PURPOSE_REGISTRAR => [
            'capabilities' => [InfraProviderConnection::CAPABILITY_REGISTRAR],
            'mutating'     => true,
            'description'  => 'Domain registration, renewal and transfer.',
        ],
        self::PURPOSE_HOSTING => [
            'capabilities' => [InfraProviderConnection::CAPABILITY_HOSTING],
            'mutating'     => true,
            'description'  => 'Hosting account and site provisioning.',
        ],
        self::PURPOSE_EMAIL => [
            'capabilities' => [InfraProviderConnection::CAPABILITY_EMAIL],
            'mutating'     => true,
            'description'  => 'Mailbox and domain-email administration.',
        ],
        self::PURPOSE_BACKUP => [
            'capabilities' => [InfraProviderConnection::CAPABILITY_BACKUP],
            'mutating'     => true,
            'description'  => 'Backup creation, verification and restore.',
        ],
        self::PURPOSE_MONITORING => [
            'capabilities' => [InfraProviderConnection::CAPABILITY_MONITORING],
            'mutating'     => true,
            'description'  => 'Uptime checks and incident history.',
        ],
    ];

    public static function all(): array
    {
        return self::MAP;
    }

    public static function purposes(): array
    {
        return array_keys(self::MAP);
    }

    public static function isKnown(string $purpose): bool
    {
        return array_key_exists($purpose, self::MAP);
    }

    /** @return array<int,string> */
    public static function capabilitiesFor(string $purpose): array
    {
        if (!self::isKnown($purpose)) {
            throw new InvalidArgumentException(
                "Unknown credential purpose '{$purpose}'. Known: " . implode(', ', self::purposes())
            );
        }

        return self::MAP[$purpose]['capabilities'];
    }

    public static function isMutating(string $purpose): bool
    {
        return (bool) (self::MAP[$purpose]['mutating'] ?? true);
    }

    /**
     * Which purpose may serve a capability. FAILS CLOSED on unknown.
     *
     * A capability with no purpose cannot have a credential created for it at
     * all, which is the correct outcome — it means nobody has decided what kind
     * of authority that capability needs.
     */
    public static function purposeForCapability(string $capability): ?string
    {
        foreach (self::MAP as $purpose => $meta) {
            if (in_array($capability, $meta['capabilities'], true)) {
                return $purpose;
            }
        }

        return null;
    }

    /**
     * Assert a requested capability scope is legal for a purpose.
     *
     * @throws InvalidArgumentException listing exactly which capabilities exceed
     *         the purpose, so the operator is told what to fix rather than that
     *         something was wrong.
     */
    public static function assertScopeAllowed(string $purpose, array $capabilityScope): void
    {
        $allowed = self::capabilitiesFor($purpose);

        if ($allowed === []) {
            throw new InvalidArgumentException(
                "Credential purpose '{$purpose}' is read-only and may not be scoped to any "
                . 'capability. It exists for health probes and scope inspection only.'
            );
        }

        $excess = array_values(array_diff($capabilityScope, $allowed));

        if ($excess !== []) {
            throw new InvalidArgumentException(
                "Credential purpose '{$purpose}' may only cover [" . implode(', ', $allowed)
                . ']; requested scope exceeds it with [' . implode(', ', $excess) . ']. '
                . 'Issue a separate credential with the appropriate purpose instead of '
                . 'widening this one.'
            );
        }
    }

    /**
     * Detect a credential granted MORE authority than its purpose requires.
     *
     * This is the "unexpected excess scopes" check Boss asked for. It is a
     * distinct problem from a credential that is too NARROW: a too-narrow
     * credential fails loudly at the first call, whereas an over-privileged one
     * works perfectly and silently carries unnecessary blast radius until the
     * day it leaks.
     *
     * @param  array<int,string>  $granted  what the provider actually confirmed
     * @return array<int,string>  granted capabilities beyond the purpose
     */
    public static function excessGrants(string $purpose, array $granted): array
    {
        if (!self::isKnown($purpose)) {
            return [];
        }

        return array_values(array_diff($granted, self::capabilitiesFor($purpose)));
    }
}
