<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PUBLISHER888 Unit 2 (2026-09-04) — enterprise hardening: append-only audit log, idempotency store, hot-path indexes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('desk_audit_log')) {
            Schema::create('desk_audit_log', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('workspace_id');
                $t->unsignedBigInteger('website_id');
                $t->unsignedBigInteger('user_id')->nullable();
                $t->string('role', 20)->nullable();
                $t->string('action', 64);
                $t->string('entity_type', 32)->nullable();
                $t->unsignedBigInteger('entity_id')->nullable();
                $t->string('summary', 255)->nullable();
                $t->json('before_json')->nullable();
                $t->json('after_json')->nullable();
                $t->string('ip', 45)->nullable();
                $t->string('user_agent', 255)->nullable();
                $t->string('request_id', 36)->nullable();
                $t->timestamp('created_at')->useCurrent();
                $t->index(['website_id', 'created_at']);
                $t->index(['entity_type', 'entity_id']);
                $t->index(['user_id', 'created_at']);
            });
        }
        if (!Schema::hasTable('desk_idempotency')) {
            Schema::create('desk_idempotency', function (Blueprint $t) {
                $t->id();
                $t->unsignedBigInteger('website_id');
                $t->unsignedBigInteger('user_id');
                $t->string('idem_key', 80);
                $t->string('route', 120);
                $t->unsignedSmallInteger('status_code');
                $t->json('response_json');
                $t->timestamp('created_at')->useCurrent();
                $t->unique(['website_id', 'user_id', 'idem_key']);
                $t->index('created_at');
            });
        }
        foreach ([
            ['articles', 'ix_articles_desk_list', ['website_id', 'status', 'updated_at']],
            ['articles', 'ix_articles_desk_section', ['website_id', 'blog_category']],
            ['leads', 'ix_leads_desk_inbox', ['website_id', 'status', 'source']],
            ['desk_commissions', 'ix_desk_commissions_open', ['website_id', 'status']],
            ['article_versions', 'ix_article_versions_order', ['article_id', 'version_number']],
        ] as [$table, $name, $cols]) {
            if (!Schema::hasTable($table)) continue;
            $exists = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name]);
            if ($exists) continue;
            Schema::table($table, function (Blueprint $t) use ($name, $cols) { $t->index($cols, $name); });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('desk_idempotency');
        Schema::dropIfExists('desk_audit_log');
        foreach ([['articles', 'ix_articles_desk_list'], ['articles', 'ix_articles_desk_section'], ['leads', 'ix_leads_desk_inbox'], ['desk_commissions', 'ix_desk_commissions_open'], ['article_versions', 'ix_article_versions_order']] as [$table, $name]) {
            if (!Schema::hasTable($table)) continue;
            if (DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$name])) Schema::table($table, fn (Blueprint $t) => $t->dropIndex($name));
        }
    }
};
