<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Lead extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'name', 'email', 'phone', 'company',
        'source', 'status', 'score', 'deal_value',
        'metadata_json', 'assigned_to',
        'website', 'city', 'country', 'tags_json', 'last_contacted_at', 'converted_at', // CRM-FIX-0: were silently dropped
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'deal_value' => 'decimal:2',
            'tags_json' => 'array', // CRM-FIX-0
            'converted_at' => 'datetime',
            'last_contacted_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function deals(): HasMany
    {
        return $this->hasMany(Deal::class);
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
