<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Step 1: relax workspace_id NOT NULL → NULL (FK preserved by MySQL MODIFY)
        DB::statement('ALTER TABLE notifications MODIFY workspace_id BIGINT UNSIGNED NULL');

        // Step 2: add new columns
        Schema::table('notifications', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('workspace_id')
                  ->constrained('users')->nullOnDelete();
            $table->string('category', 50)->nullable()->after('type');
            $table->string('title')->nullable()->after('category');
            $table->text('body')->nullable()->after('title');
            $table->string('action_url')->nullable()->after('body');
            $table->string('icon', 50)->nullable()->after('action_url');
            $table->enum('severity', ['info', 'success', 'warning', 'error'])
                  ->default('info')->after('icon');
            $table->timestamp('emailed_at')->nullable()->after('read_at');
            $table->boolean('email_required')->default(false)->after('emailed_at');
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'read_at']);
            $table->dropForeign(['user_id']);
            $table->dropColumn([
                'user_id', 'category', 'title', 'body',
                'action_url', 'icon', 'severity',
                'emailed_at', 'email_required',
            ]);
        });
        DB::statement('ALTER TABLE notifications MODIFY workspace_id BIGINT UNSIGNED NOT NULL');
    }
};
