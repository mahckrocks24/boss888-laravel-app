<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CATALOGUE888 (DEC-0049, 2026-09-14): one table for everything a website sells or lists — property listings,
 * services and prices, menu items, rooms, plans … — distinguished by `kind`; kind-specific fields live in attrs_json
 * and are described by App\Engines\Builder\Support\CatalogueKinds. The DEC-0048 property_listings rows move in as
 * kind `listing`; that table is left in place, unused, for one release.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('catalogue_items')) {
            Schema::create('catalogue_items', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('workspace_id')->index();
                $table->unsignedBigInteger('website_id')->index();
                $table->string('kind', 24)->index();                              // listing | service | menu | room | plan | …
                $table->string('slug', 190);
                $table->string('title', 190);
                $table->string('status', 20)->default('active')->index();         // per kind (CatalogueKinds)
                $table->decimal('price', 14, 2)->nullable();                      // null = price on request (never invented)
                $table->char('currency', 3)->default('USD');
                $table->string('price_period', 12)->nullable();                   // month | week | night | hour | person | session
                $table->string('price_label', 80)->nullable();                    // verbatim override: "From $120", "POA"
                $table->string('summary', 300)->nullable();                       // card text
                $table->text('description')->nullable();                          // page text, plain; escaped on render
                $table->json('photos_json')->nullable();
                $table->json('attrs_json')->nullable();                           // kind-specific: beds/baths/size, duration, tag …
                $table->json('features_json')->nullable();
                $table->boolean('featured')->default(false);
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('closed_note', 190)->nullable();                   // only what the customer stated
                $table->timestamp('closed_at')->nullable();
                $table->string('source', 20)->default('editor');                  // editor | arthur | seed | migrated
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->unique(['website_id', 'kind', 'slug']);
            });
        }
        // DEC-0048 rows → kind listing (idempotent: only when nothing of that kind exists yet for the website)
        if (Schema::hasTable('property_listings')) {
            foreach (DB::table('property_listings')->orderBy('id')->get() as $r) {
                $exists = DB::table('catalogue_items')->where('website_id', $r->website_id)->where('kind', 'listing')->where('slug', $r->slug)->exists();
                if ($exists) continue;
                $attrs = array_filter([
                    'location' => $r->location, 'property_type' => $r->property_type,
                    'beds' => $r->beds !== null ? (float) $r->beds : null, 'baths' => $r->baths !== null ? (float) $r->baths : null,
                    'size_value' => $r->size_value !== null ? (float) $r->size_value : null, 'size_unit' => $r->size_unit, 'specs_text' => $r->specs_text,
                ], fn($v) => $v !== null && $v !== '');
                DB::table('catalogue_items')->insert([
                    'workspace_id' => $r->workspace_id, 'website_id' => $r->website_id, 'kind' => 'listing', 'slug' => $r->slug, 'title' => $r->title,
                    'status' => $r->status, 'price' => $r->price, 'currency' => $r->currency, 'price_period' => $r->price_period, 'price_label' => $r->price_label,
                    'summary' => null, 'description' => $r->description, 'photos_json' => $r->photos_json, 'attrs_json' => json_encode($attrs), 'features_json' => $r->features_json,
                    'featured' => $r->featured, 'sort_order' => $r->sort_order, 'closed_note' => $r->sold_note, 'closed_at' => $r->sold_at,
                    'source' => $r->source === 'seed' ? 'seed' : 'migrated', 'created_by' => $r->created_by,
                    'created_at' => $r->created_at, 'updated_at' => $r->updated_at, 'deleted_at' => $r->deleted_at,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('catalogue_items');
    }
};
