<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Every attempt to reach Engineer888.
 *
 * No updated_at column, on purpose: a row here describes a moment that has
 * already happened and there is no legitimate reason to revise it. The absence
 * of the column makes an accidental update fail loudly rather than silently
 * rewriting history.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineering_access_audit', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('email', 255)->nullable();
            $table->string('capability', 64);
            $table->boolean('granted');

            // Null when granted. Named denial reason otherwise — recorded here
            // rather than returned to the caller, who gets an undifferentiated
            // 404 precisely so it reveals nothing.
            $table->string('reason', 64)->nullable();

            $table->string('route', 255);
            $table->string('method', 10);
            $table->string('ip', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->string('auth_via', 32)->nullable();
            $table->unsignedBigInteger('session_id')->nullable();
            $table->string('device_id', 64)->nullable();
            $table->text('mfa_state')->nullable();
            $table->string('policy_version', 32);

            $table->timestamp('created_at');

            $table->index(['user_id', 'created_at'], 'eaa_user_time_idx');
            $table->index(['granted', 'created_at'], 'eaa_granted_time_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineering_access_audit');
    }
};
