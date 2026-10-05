<?php
// RFC-0026 section 11a: each partner / customer email is sent once per event.
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('affiliate_email_log', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('affiliate_id')->default(0);
            $t->string('kind', 8);
            $t->string('ref', 80);
            $t->timestamp('created_at')->nullable();
            $t->unique(['kind', 'ref', 'affiliate_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('affiliate_email_log'); }
};