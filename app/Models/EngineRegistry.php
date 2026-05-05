<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EngineRegistry extends Model
{
    protected $table = 'engine_registry';

    protected $fillable = [
        'name', 'slug', 'version', 'status',
        'capabilities_json', 'metadata_json',
    ];

    protected function casts(): array
    {
        return [
            'capabilities_json' => 'array',
            'metadata_json' => 'array',
        ];
    }
}
