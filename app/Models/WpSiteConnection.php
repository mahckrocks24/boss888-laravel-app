<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * WP-1 (2026-08-29) — a customer's WordPress site connected through the LevelUp Growth plugin.
 * One row per (workspace, host). Created/refreshed by POST /connector/register-site; touched by
 * every authenticated plugin call (last_seen_at) and by every Laravel → WP push (last_push_*).
 */
class WpSiteConnection extends Model
{
    public const STATUS_ACTIVE            = 'active';
    public const STATUS_DISCONNECTED      = 'disconnected';
    public const STATUS_FAILED            = 'failed';
    public const STATUS_BILLING_SUSPENDED = 'billing_suspended';

    protected $table = 'wp_site_connections';

    protected $fillable = [
        'workspace_id', 'website_id', 'api_key_id', 'site_url', 'site_host', 'site_name', 'webhook_secret',
        'plugin_version', 'wp_version', 'status', 'last_seen_at', 'last_push_at', 'last_push_status', 'last_error', 'meta_json',
    ];

    protected $casts = [
        'meta_json'    => 'array',
        'last_seen_at' => 'datetime',
        'last_push_at' => 'datetime',
    ];

    protected $hidden = ['webhook_secret'];

    public static function hostOf(string $url): string
    {
        $h = strtolower((string) parse_url(str_contains($url, '://') ? $url : 'https://' . $url, PHP_URL_HOST));
        return preg_replace('/^www\./', '', $h) ?: '';
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function website(): BelongsTo
    {
        return $this->belongsTo(Website::class);
    }
}
