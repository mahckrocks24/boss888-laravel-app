<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("seo_serp_results", function (Blueprint $table) {
            $table->id();
            $table->foreignId("audit_id")->constrained("seo_audits")->cascadeOnDelete();
            $table->integer("rank");
            $table->string("url");
            $table->string("title")->nullable();
            $table->string("domain")->nullable();
            $table->text("snippet")->nullable();
            $table->timestamp("created_at")->useCurrent();

            $table->index("audit_id");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("seo_serp_results");
    }
};
