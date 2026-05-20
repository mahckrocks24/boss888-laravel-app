<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('aeo_audits', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->string('url', 2048);
            $t->unsignedTinyInteger('score')->default(0);
            $t->json('checks_json')->nullable();
            $t->unsignedSmallInteger('http_status')->nullable();
            $t->unsignedInteger('html_bytes')->nullable();
            $t->text('error_text')->nullable();
            $t->timestamp('last_audited_at')->nullable();
            $t->timestamps();

            $t->index(['workspace_id', 'score']);
            $t->index('workspace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aeo_audits');
    }
};
