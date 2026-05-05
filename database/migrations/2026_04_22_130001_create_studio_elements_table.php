<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('studio_elements', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->unsignedBigInteger('design_id')->nullable();
            $t->string('element_type', 60);
            $t->longText('properties_json')->nullable();
            $t->unsignedInteger('layer_order')->default(0);
            $t->timestamps();

            $t->index('workspace_id');
            $t->index('design_id');
            $t->index(['design_id', 'layer_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_elements');
    }
};
