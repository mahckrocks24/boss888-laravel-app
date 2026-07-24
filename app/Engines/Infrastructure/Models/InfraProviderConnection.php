<?php

namespace App\Engines\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A configured provider integration.
 *
 * NOT using BelongsToWorkspace on purpose: workspace_id is nullable here, because
 * a platform-level connection (our own provider account, serving all tenants) has
 * no owning workspace. A global scope would hide exactly those rows. Access is
 * therefore scoped explicitly in the service layer instead.
 *
 * SECURITY: credential_ref is the NAME of a config key, never a secret value.
 * Nothing in this table may hold a token, password, or private key.
 */
class InfraProviderConnection extends Model
{
    protected $table = 'infra_provider_connections';

    protected $fillable = [
        'workspace_id', 'provider', 'capability', 'label', 'state',
        'credential_ref', 'last_health_check_at', 'last_health_state',
        'last_error_code', 'last_error_summary', 'settings_json',
    ];

    protected $casts = [
        'last_health_check_at' => 'datetime',
        'settings_json'        => 'array',
    ];

    /** Values are never serialized to API responses. */
    protected $hidden = ['credential_ref'];

    public const CAPABILITY_HOSTING         = 'hosting';
    public const CAPABILITY_CUSTOM_HOSTNAME = 'custom_hostname';
    public const CAPABILITY_REGISTRAR       = 'registrar';
    public const CAPABILITY_DNS             = 'dns';
    public const CAPABILITY_EMAIL           = 'email';
    public const CAPABILITY_BACKUP          = 'backup';
    public const CAPABILITY_MONITORING      = 'monitoring';
    // Phase 2B-1: certificates get their own capability. Bundling TLS into
    // custom-hostname provisioning was a Cloudflare-for-SaaS assumption that
    // would not hold for ACME, Origin CA or customer-supplied certificates.
    public const CAPABILITY_CERTIFICATE     = 'certificate';

    public static function capabilities(): array
    {
        return [
            self::CAPABILITY_HOSTING,
            self::CAPABILITY_CUSTOM_HOSTNAME,
            self::CAPABILITY_REGISTRAR,
            self::CAPABILITY_DNS,
            self::CAPABILITY_EMAIL,
            self::CAPABILITY_BACKUP,
            self::CAPABILITY_MONITORING,
            self::CAPABILITY_CERTIFICATE,
        ];
    }

    public function isUsable(): bool
    {
        return $this->state === 'active';
    }
}
