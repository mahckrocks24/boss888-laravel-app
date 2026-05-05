<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('studio_design_history', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('design_id');
            $t->longText('snapshot_json');
            $t->timestamp('created_at')->useCurrent();

            $t->index('design_id');
            $t->index(['design_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_design_history');
    }
};
