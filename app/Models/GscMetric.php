<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Raw Google Search Console performance row (one per page+query+date).
 *
 * Written by the sync job, read by the runtime intelligence layer.
 * Laravel never scores these — see gsc_metrics migration note.
 */
class GscMetric extends Model
{
    protected $table = 'gsc_metrics';

    protected $fillable = [
        'workspace_id', 'site_url', 'page', 'query', 'row_hash',
        'clicks', 'impressions', 'ctr', 'position', 'date', 'synced_at',
    ];

    protected $casts = [
        'clicks'      => 'integer',
        'impressions' => 'integer',
        'ctr'         => 'float',
        'position'    => 'float',
        'date'        => 'date',
        'synced_at'   => 'datetime',
    ];
}
