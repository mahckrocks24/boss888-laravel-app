<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Contact extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'name', 'full_name', 'first_name', 'last_name', 'email', 'phone',
        'company', 'company_name', 'position', 'job_title', 'source', 'stage', 'status', 'category', 'priority', 'estimated_value', 'metadata_json', 'tags_json',
    ];

    protected function casts(): array
    {
        return ['metadata_json' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'notable');
    }

    public function activities(): MorphMany
    {
        return $this->morphMany(Activity::class, 'activitable');
    }
}
