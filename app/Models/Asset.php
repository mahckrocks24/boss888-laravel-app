<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * STUDIO888 Phase I — minimal Eloquent view over the existing `assets` table,
 * added ONLY to express the CreativeJob relationship. The asset lifecycle is
 * unchanged: CreativeService still creates/updates assets via DB::table(); this
 * model is not used by that path.
 */
class Asset extends Model
{
    protected $table = 'assets';

    protected $guarded = [];

    protected $casts = [
        'metadata_json' => 'array',
        'tags_json'     => 'array',
    ];

    public function creativeJob(): BelongsTo { return $this->belongsTo(CreativeJob::class); }
    public function workspace(): BelongsTo   { return $this->belongsTo(Workspace::class); }
    public function task(): BelongsTo        { return $this->belongsTo(Task::class); }
}
