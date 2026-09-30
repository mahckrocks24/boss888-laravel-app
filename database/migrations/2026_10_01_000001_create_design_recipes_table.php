<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN-LIBRARY-2 (Owner 2026-10-01: "transform the 10 design inspirations choices in the business profile setting into a
 * library, where they could search from all those 330 designs, with a filter and search function"). One row per reference
 * design recipe: the searchable face (title, industry, archetype, format, mood, tags), the private recipe and prompt
 * template, and our own rendering of it as the thumbnail (the reference images never enter the product).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('design_recipes', function (Blueprint $table) {
            $table->id();
            $table->string('key', 120)->unique();
            $table->string('industry', 80)->index();
            $table->string('title', 120);
            $table->string('archetype', 60)->index();
            $table->string('format', 20)->index();
            $table->json('direction_fit')->nullable();
            $table->string('mood', 160)->nullable();
            $table->json('colour_json')->nullable();
            $table->text('tags');
            $table->boolean('has_people')->default(false)->index();
            $table->json('recipe_json');
            $table->text('prompt_template');
            $table->json('variables_json')->nullable();
            $table->string('thumb_path', 200)->nullable();
            $table->string('thumb_status', 20)->default('pending');
            $table->boolean('active')->default(true)->index();
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('design_recipes');
    }
};
