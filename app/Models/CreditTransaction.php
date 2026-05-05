<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditTransaction extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'type', 'amount',
        'reference_type', 'reference_id', 'metadata_json',
        // Phase 3 fix: these 4 columns were in the DB schema but missing from
        // $fillable, so every CreditTransaction::create() silently dropped them.
        // Result: reservation_reference was always NULL, so commitReservedCredits()
        // could never find the reservation to commit. The entire reserve/commit/
        // release credit pattern was silently broken since deploy.
        'reservation_status', 'reservation_reference', 'finalized_at', 'released_at',
    ];

    protected function casts(): array
    {
        return [
            'metadata_json' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
