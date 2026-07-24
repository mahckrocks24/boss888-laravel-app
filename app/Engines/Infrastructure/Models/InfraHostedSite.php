<?php

namespace App\Engines\Infrastructure\Models;

use App\Core\Tenancy\BelongsToWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A site running under a hosting entitlement.
 *
 * workspace_id is denormalized rather than derived through hosting_account_id.
 * That is deliberate: the 2026-07-15 IDOR sweep showed parent-join-only ownership
 * (pages -> websites) is exactly where workspace scoping gets forgotten.
 */
class InfraHostedSite extends Model
{
    use BelongsToWorkspace;
    use SoftDeletes;

    protected $table = 'infra_hosted_sites';

    protected $fillable = [
        'asset_id', 'workspace_id', 'hosting_account_id', 'website_id', 'name',
        'primary_hostname', 'state', 'previous_state', 'ssl_state',
        'ssl_expires_at', 'deployment_state', 'last_deployed_at',
        'storage_mb', 'bandwidth_mb', 'metadata_json',
    ];

    protected $casts = [
        'ssl_expires_at'   => 'datetime',
        'last_deployed_at' => 'datetime',
        'storage_mb'       => 'integer',
        'bandwidth_mb'     => 'integer',
        'metadata_json'    => 'array',
    ];

    public function hostingAccount(): BelongsTo
    {
        return $this->belongsTo(InfraHostingAccount::class, 'hosting_account_id');
    }

    /** Days until the certificate expires; null when no expiry is known. */
    public function sslDaysRemaining(): ?int
    {
        if (!$this->ssl_expires_at) {
            return null;
        }

        return (int) now()->diffInDays($this->ssl_expires_at, false);
    }
}
