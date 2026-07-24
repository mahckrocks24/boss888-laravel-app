<?php

namespace App\Engines\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * A single entitlement value attached to a plan version.
 *
 * Exactly one typed column is populated, chosen by the definition's value_type.
 * Catalog data — not workspace-owned.
 */
class InfraPlanEntitlement extends Model
{
    protected $table = 'infra_plan_entitlements';

    protected $fillable = [
        'plan_id', 'entitlement_definition_id',
        'bool_value', 'int_value', 'string_value',
        'behavior', 'addon_amount_minor', 'addon_currency',
    ];

    protected $casts = [
        'bool_value'         => 'boolean',
        'int_value'          => 'integer',
        'addon_amount_minor' => 'integer',
    ];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(InfraEntitlementDefinition::class, 'entitlement_definition_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InfraPlan::class, 'plan_id');
    }

    /** Resolve the value according to the definition's declared type. */
    public function value(): bool|int|string|null
    {
        $type = $this->definition?->value_type;

        return match ($type) {
            InfraEntitlementDefinition::TYPE_BOOL   => (bool) $this->bool_value,
            InfraEntitlementDefinition::TYPE_INT    => $this->int_value === null ? null : (int) $this->int_value,
            InfraEntitlementDefinition::TYPE_STRING,
            InfraEntitlementDefinition::TYPE_ENUM   => $this->string_value,
            default => throw new RuntimeException(
                "Entitlement {$this->id} has no resolvable value type (definition missing?)."
            ),
        };
    }

    /** Plan-level override, falling back to the definition's default. */
    public function effectiveBehavior(): string
    {
        return $this->behavior ?: ($this->definition?->default_behavior ?? 'none');
    }

    public function isAddon(): bool
    {
        return $this->addon_amount_minor !== null && $this->addon_amount_minor > 0;
    }
}
