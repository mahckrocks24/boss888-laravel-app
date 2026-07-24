<?php

namespace App\Engines\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The controlled registry of supplemental entitlements.
 *
 * Catalog data — platform-owned, NOT workspace-owned, so deliberately no
 * BelongsToWorkspace.
 *
 * This registry is what keeps the entitlement model out of EAV territory: every
 * key is a row a human created on purpose, with a declared value type and a
 * declared limit behaviour. Values live in TYPED columns on
 * infra_plan_entitlements, never in an untyped blob.
 */
class InfraEntitlementDefinition extends Model
{
    protected $table = 'infra_entitlement_definitions';

    public const TYPE_BOOL   = 'bool';
    public const TYPE_INT    = 'int';
    public const TYPE_STRING = 'string';
    public const TYPE_ENUM   = 'enum';

    protected $fillable = [
        'key', 'name', 'description', 'value_type', 'unit', 'allowed_values',
        'default_behavior', 'category', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'allowed_values' => 'array',
        'is_active'      => 'boolean',
        'sort_order'     => 'integer',
    ];

    public function planEntitlements(): HasMany
    {
        return $this->hasMany(InfraPlanEntitlement::class, 'entitlement_definition_id');
    }

    public static function valueTypes(): array
    {
        return [self::TYPE_BOOL, self::TYPE_INT, self::TYPE_STRING, self::TYPE_ENUM];
    }
}
