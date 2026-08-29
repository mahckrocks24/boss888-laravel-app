<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * CONTENT-2 (2026-08-29) — an article belongs to ONE website. Articles were workspace-scoped, so a
 * workspace with two sites listed every article on both (RISK-0127 q). Backfill: every published
 * article without a website goes to its workspace's OLDEST published LevelUp site (the one that
 * existed when it was published); drafts stay NULL and are bound at first-time publish.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('articles', 'website_id')) {
            Schema::table('articles', function (Blueprint $t) {
                $t->unsignedBigInteger('website_id')->nullable()->after('workspace_id')->index();
            });
        }
        $rows = DB::table('articles')->whereNull('website_id')->where('status', 'published')->whereNull('deleted_at')
            ->select('workspace_id')->distinct()->pluck('workspace_id');
        foreach ($rows as $wsId) {
            $siteId = DB::table('websites')->where('workspace_id', $wsId)->where('status', 'published')->whereNull('deleted_at')
                ->where(fn($q) => $q->whereNull('platform')->orWhere('platform', '!=', 'wordpress'))
                ->orderBy('id')->value('id');
            if ($siteId) {
                DB::table('articles')->where('workspace_id', $wsId)->whereNull('website_id')->where('status', 'published')
                    ->update(['website_id' => $siteId]);
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('articles', 'website_id')) {
            Schema::table('articles', function (Blueprint $t) { $t->dropColumn('website_id'); });
        }
    }
};
