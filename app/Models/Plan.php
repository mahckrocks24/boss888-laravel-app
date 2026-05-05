<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Plan extends Model
{
    protected $fillable = [
        'name', 'slug', 'price', 'billing_period', 'credit_limit',
        'ai_access', 'includes_dmm', 'agent_count', 'agent_level', 'agent_addon_price',
        'max_websites', 'max_team_members', 'companion_app', 'white_label',
        'priority_processing', 'features_json', 'stripe_price_id', 'is_public',
    ];

    protected function casts(): array
    {
        return [
            'price'              => 'decimal:2',
            'features_json'      => 'array',
            'includes_dmm'       => 'boolean',
            'companion_app'      => 'boolean',
            'white_label'        => 'boolean',
            'priority_processing'=> 'boolean',
            'is_public'          => 'boolean',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
