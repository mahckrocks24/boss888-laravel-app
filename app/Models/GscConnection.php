<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Google Search Console connection.
 *
 * INC-0006 - a business (one workspace) may run several websites, and Search Console properties are
 * per-site. The OAuth grant, however, is one Google account for the whole business, so it stays on the
 * business row (website_id = 0) and every per-website row inherits it. Only the PROPERTY CHOICE
 * (site_url, ga_property_id) is genuinely per-website.
 *
 * Token columns are stored ENCRYPTED (Crypt::encryptString) by GscClient;
 * they are intentionally NOT cast/decrypted here so a stray ->toArray()
 * or log never leaks plaintext tokens. Decryption happens only inside
 * GscClient where it is needed.
 */
class GscConnection extends Model
{
    protected $table = 'gsc_connections';

    protected $fillable = [
        'workspace_id', 'website_id', 'provider',
        'client_id', 'client_secret_enc',
        'access_token_enc', 'refresh_token_enc', 'token_expires_at',
        'site_url', 'connected', 'connected_email', 'last_sync_at',
        'ga_property_id', 'ga_property_name',
    ];

    protected $casts = [
        'connected'        => 'boolean',
        'token_expires_at' => 'datetime',
        'last_sync_at'     => 'datetime',
    ];

    /** Never expose encrypted secrets through serialization. */
    protected $hidden = [
        'client_secret_enc', 'access_token_enc', 'refresh_token_enc',
    ];

    /** The business-wide row: holds the one OAuth grant shared by every website in the workspace. */
    public static function business(int $workspaceId): ?self
    {
        return static::where('workspace_id', $workspaceId)
            ->where('website_id', \App\Core\Tenancy\WebsiteScope::BUSINESS_DEFAULT)
            ->first();
    }
}
