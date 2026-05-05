<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("seo_score_weights", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("workspace_id");
            $table->string("factor");
            $table->integer("weight")->default(0);
            $table->timestamps();

            $table->unique(["workspace_id", "factor"]);
            $table->index("workspace_id");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("seo_score_weights");
    }
};
