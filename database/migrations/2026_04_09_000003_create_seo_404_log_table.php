<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("seo_404_log", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("workspace_id");
            $table->string("url");
            $table->string("referrer")->nullable();
            $table->integer("hit_count")->default(1);
            $table->string("user_agent")->nullable();
            $table->timestamp("last_hit_at")->nullable();
            $table->timestamps();

            $table->index("workspace_id");
            $table->index(["workspace_id", "url"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("seo_404_log");
    }
};
