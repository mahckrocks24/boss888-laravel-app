<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("seo_audit_items", function (Blueprint $table) {
            $table->id();
            $table->foreignId("audit_id")->constrained("seo_audits")->cascadeOnDelete();
            $table->string("url");
            $table->integer("score")->nullable();
            $table->json("issues_json")->nullable();
            $table->string("meta_title")->nullable();
            $table->text("meta_description")->nullable();
            $table->integer("meta_title_length")->nullable();
            $table->integer("meta_description_length")->nullable();
            $table->timestamps();

            $table->index("audit_id");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("seo_audit_items");
    }
};
