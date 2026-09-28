<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// DIGEST-1: finished-work reports waiting to go out as one Sarah message
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('sarah_digest_items')) return;
        Schema::create('sarah_digest_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('workspace_id');
            $t->mediumText('content');
            $t->json('metadata_json')->nullable();
            $t->timestamp('flushed_at')->nullable();
            $t->unsignedBigInteger('message_id')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'flushed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sarah_digest_items');
    }
};
