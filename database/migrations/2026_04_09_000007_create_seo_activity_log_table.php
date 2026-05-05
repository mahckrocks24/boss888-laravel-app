<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("seo_activity_log", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("workspace_id");
            $table->unsignedBigInteger("user_id")->nullable();
            $table->string("action");
            $table->string("object_type")->nullable();
            $table->unsignedBigInteger("object_id")->nullable();
            $table->json("meta_json")->nullable();
            $table->timestamp("created_at")->useCurrent();

            $table->index("workspace_id");
            $table->index(["workspace_id", "action"]);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("seo_activity_log");
    }
};
