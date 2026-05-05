<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMemory extends Model
{
    protected $table = 'workspace_memory';

    protected $fillable = ['workspace_id', 'key', 'value_json', 'ttl'];

    protected function casts(): array
    {
        return ['value_json' => 'array'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
