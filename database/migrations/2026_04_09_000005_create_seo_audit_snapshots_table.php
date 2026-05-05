<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create("seo_audit_snapshots", function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger("workspace_id");
            $table->integer("score")->nullable();
            $table->integer("total_pages")->default(0);
            $table->integer("critical_issues")->default(0);
            $table->integer("high_issues")->default(0);
            $table->integer("medium_issues")->default(0);
            $table->integer("low_issues")->default(0);
            $table->decimal("meta_completion", 5, 2)->nullable();
            $table->text("notes")->nullable();
            $table->json("delta_json")->nullable();
            $table->timestamps();

            $table->index("workspace_id");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("seo_audit_snapshots");
    }
};
