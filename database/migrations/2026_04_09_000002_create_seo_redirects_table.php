<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("seo_redirects", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("workspace_id");
            $table->string("source_url");
            $table->string("target_url");
            $table->enum("type", ["301", "302", "307"])->default("301");
            $table->boolean("is_regex")->default(false);
            $table->integer("hit_count")->default(0);
            $table->string("status")->default("active");
            $table->timestamps();

            $table->index("workspace_id");
            $table->index(["workspace_id", "source_url"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("seo_redirects");
    }
};
