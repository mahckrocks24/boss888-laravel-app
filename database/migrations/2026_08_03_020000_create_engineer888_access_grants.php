<?php

use App\Core\Engineer888\Access\Engineer888Access;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Who may use Engineer888, written by a human hand.
 *
 * The grant lives in a table this application never writes to at runtime.
 * There is no endpoint, no service method and no UI that inserts, updates or
 * revokes a row here — changing access means a migration, reviewed like any
 * other change. That is what makes "Engineer888 cannot grant access to itself"
 * a structural fact rather than a policy somebody has to keep honouring.
 *
 * Platform-admin status is deliberately NOT sufficient. A row must exist.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineering_access_grants', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');

            // Stored alongside the ID so a grant cannot silently follow an ID
            // that was reassigned to a different person.
            $table->string('email', 255);

            $table->json('capabilities');
            $table->string('policy_version', 32);

            $table->string('granted_by', 255);
            $table->timestamp('granted_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by', 255)->nullable();
            $table->text('note')->nullable();

            $table->timestamps();

            $table->unique(['user_id', 'policy_version'], 'eag_user_policy_unique');
        });

        // The one grant V1 has. Written here, in review, and nowhere else.
        DB::table('engineering_access_grants')->insert([
            'user_id'        => Engineer888Access::CANONICAL_USER_ID,
            'email'          => Engineer888Access::CANONICAL_EMAIL,
            'capabilities'   => json_encode(Engineer888Access::capabilitiesForCanonicalAdmin()),
            'policy_version' => Engineer888Access::POLICY_VERSION,
            'granted_by'     => 'Mark (CEO) — Sprint 10.3 directive',
            'granted_at'     => now(),
            'note'           => 'Engineer888 is private to this account in V1. manage_access is '
                              . 'deliberately excluded: nobody may change access from inside the '
                              . 'application, including this account.',
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_access_grants');
    }
};
