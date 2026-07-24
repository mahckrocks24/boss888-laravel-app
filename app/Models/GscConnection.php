<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Per-workspace Google Search Console connection.
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
        'workspace_id', 'provider',
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
}
