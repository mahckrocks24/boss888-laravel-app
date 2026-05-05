<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Deal extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'workspace_id', 'lead_id', 'contact_id', 'title',
        'value', 'currency', 'stage', 'probability',
        'expected_close', 'assigned_to', 'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:2',
            'metadata_json' => 'array',
            'expected_close' => 'date',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
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
