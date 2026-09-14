<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PROPERTY LISTINGS (DEC-0048, 2026-09-14). Owner: "It should have a backend of its own … inside laravel, no separate
 * login … this should not be visible to other users that do not sell properties."
 * One row per property a website sells or lets; the deployed site is a projection of these rows
 * (ListingsService::sync). Only designs whose manifest declares `catalogue.kind = listings` ever read this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('property_listings')) return;
        Schema::create('property_listings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('website_id')->index();
            $table->string('slug', 190);                                      // page slug part: /property-<slug>/
            $table->string('title', 190);
            $table->string('status', 20)->default('for_sale')->index();       // for_sale | to_let | under_offer | sold | let | withdrawn
            $table->decimal('price', 14, 2)->nullable();                      // null = price on request (never invented)
            $table->char('currency', 3)->default('USD');
            $table->string('price_period', 12)->nullable();                   // null (total) | month | week | year — for lets
            $table->string('price_label', 80)->nullable();                    // verbatim override: "From $2,500 / month", "POA"
            $table->string('location', 190)->nullable();                      // "Travis Heights, Austin, TX"
            $table->string('property_type', 60)->nullable();                  // house, apartment, villa, land…
            $table->decimal('beds', 4, 1)->nullable();
            $table->decimal('baths', 4, 1)->nullable();
            $table->decimal('size_value', 12, 2)->nullable();
            $table->string('size_unit', 12)->nullable();                      // sq ft | sq m | acres | ha
            $table->string('specs_text', 190)->nullable();                    // verbatim override of "3 beds • 2 baths • 1,850 sq ft"
            $table->text('description')->nullable();                          // plain text; escaped on render
            $table->json('photos_json')->nullable();                          // ["/storage/sites/890/listings/a.jpg", …] first = card photo
            $table->json('features_json')->nullable();                        // ["Pool", "Garage"]
            $table->boolean('featured')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->string('sold_note', 190)->nullable();                     // only what the customer stated about the outcome
            $table->timestamp('sold_at')->nullable();
            $table->string('source', 20)->default('editor');                  // editor | arthur | seed
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['website_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('property_listings');
    }
};
