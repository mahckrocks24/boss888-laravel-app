<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PUBLISHER888 Unit 1 (2026-09-04) — Publisher Desk tables.
 *  desk_members      : per-website desk role overrides (owner|editor|moderator|viewer); workspace role is the default.
 *  desk_commissions  : stories commissioned to Sarah/Priya from the desk, reconciled against tasks → articles.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('desk_members')) {
            Schema::create('desk_members', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('website_id');
                $t->unsignedBigInteger('user_id')->nullable(); // null = pre-assigned by email (invite not yet accepted)
                $t->string('email', 190)->nullable()->index();
                $t->string('role', 20)->default('editor');
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
                $t->unique(['website_id', 'user_id']);
            });
        }
        if (!Schema::hasTable('desk_commissions')) {
            Schema::create('desk_commissions', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id')->index();
                $t->unsignedBigInteger('website_id')->index();
                $t->unsignedBigInteger('task_id')->nullable()->index();
                $t->unsignedBigInteger('approval_id')->nullable();
                $t->unsignedBigInteger('article_id')->nullable()->index();
                $t->string('title', 255);
                $t->text('brief')->nullable();
                $t->string('section_slug', 100)->nullable();
                $t->string('type', 30)->default('article');
                $t->string('status', 20)->default('queued'); // queued | awaiting_approval | ready | failed
                $t->text('error_text')->nullable();
                $t->unsignedBigInteger('requested_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_commissions');
        Schema::dropIfExists('desk_members');
    }
};
