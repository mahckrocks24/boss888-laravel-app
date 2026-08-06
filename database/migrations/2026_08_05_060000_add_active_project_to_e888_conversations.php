<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The conversation's active project — explicit, never inferred.
 *
 * WHY. Task creation previously resolved a project with
 * `engineering_projects->orderBy('id')->first()`. There is one project today, so
 * it worked; the moment a second is registered that ordering silently decides
 * which repository receives engineering work. Database order is not product
 * intent, and the failure would be a task filed against the wrong codebase —
 * discovered after something was written there.
 *
 * NULLABLE ON PURPOSE. No project until one is chosen. A default would recreate
 * exactly the problem being fixed, so the only default permitted is one that
 * somebody explicitly configured (config/engineer888_chat.php), and it is null
 * out of the box.
 *
 * nullOnDelete: a deregistered project must not cascade a conversation away. The
 * conversation returns to "no project selected", which is the truthful state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('e888_conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('active_project_id')->nullable()->after('owner_user_id');

            $table->foreign('active_project_id')
                ->references('id')->on('engineering_projects')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('e888_conversations', function (Blueprint $table) {
            $table->dropForeign(['active_project_id']);
            $table->dropColumn('active_project_id');
        });
    }
};
