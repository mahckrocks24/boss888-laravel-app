<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingMessage extends Model
{
    protected $fillable = [
        'meeting_id', 'sender_type', 'sender_id', 'message', 'attachments_json',
    ];

    protected function casts(): array
    {
        return ['attachments_json' => 'array'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }
}
