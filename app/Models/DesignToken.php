<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DesignToken extends Model
{
    protected $fillable = ['workspace_id', 'tokens_json'];

    protected function casts(): array
    {
        return ['tokens_json' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
