<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// RFC-0028 D22 (Owner: "records stays for all but there must be a badge saying it has upgraded and TL is informed via email too"):
// a member who leaves a team (upgraded, removed, moved) stays in that leader's records, frozen at the day they left.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('affiliate_team_history', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('leader_id')->index();
            $t->unsignedBigInteger('recruit_id');
            $t->string('reason', 16);                  // upgraded | removed | moved
            $t->timestamp('joined_at')->nullable();
            $t->timestamp('left_at');
            $t->timestamp('returned_at')->nullable();   // Owner: "if it rolls back to regular affiliate he goes back to his old TL"
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('affiliate_team_history');
    }
};
