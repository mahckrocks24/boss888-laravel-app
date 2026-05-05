<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("seo_settings", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("workspace_id");
            $table->string("key");
            $table->text("value")->nullable();
            $table->string("group")->default("general");
            $table->timestamps();

            $table->unique(["workspace_id", "key"]);
            $table->index("workspace_id");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("seo_settings");
    }
};
