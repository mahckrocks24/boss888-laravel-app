<?php

namespace App\Engines\Infrastructure\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A sellable infrastructure category. Catalog data — platform-owned, NOT
 * workspace-owned, so it deliberately does not use BelongsToWorkspace.
 */
class InfraProduct extends Model
{
    protected $table = 'infra_products';

    public const CATEGORY_HOSTING = 'hosting';
    public const CATEGORY_DOMAIN  = 'domain';
    public const CATEGORY_EMAIL   = 'email';

    protected $fillable = [
        'slug', 'name', 'category', 'description',
        'is_active', 'sort_order', 'metadata_json',
        // Phase 2A-1 catalog lifecycle
        'version', 'lifecycle_status', 'sku', 'successor_product_id',
        'visibility', 'available_from', 'available_until',
        // Phase 2A-2. 'created_by' is passed through create() in
        // CatalogAuthoringService, so omitting it here silently discarded
        // catalog authorship. Caught by MassAssignmentDriftTest.
        'published_at', 'retired_at', 'created_by',
    ];

    protected $casts = [
        'is_active'       => 'boolean',
        'sort_order'      => 'integer',
        'metadata_json'   => 'array',
        'version'         => 'integer',
        'available_from'  => 'datetime',
        'available_until' => 'datetime',
        'published_at'    => 'datetime',
        'retired_at'      => 'datetime',
    ];

    public function plans(): HasMany
    {
        return $this->hasMany(InfraPlan::class, 'product_id');
    }

    public function currentPlans(): HasMany
    {
        return $this->plans()->where('is_current', true);
    }

    public static function categories(): array
    {
        return [self::CATEGORY_HOSTING, self::CATEGORY_DOMAIN, self::CATEGORY_EMAIL];
    }
}
