<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INC-0006 — seo_content_index is website-specific, so it gets a website.
 *
 * Every row in this table is one page, identified by its URL. A page belongs to exactly one website; that is
 * not a modelling preference, it is what the row already is. The evidence that this matters is in the data:
 * workspace 1 holds pages across five distinct hosts, workspace 26 across two, workspace 999993 across three.
 * Without a website column, one business's SEO inventory is the union of all its sites and every per-site view
 * is contaminated by its siblings.
 *
 * Provenance follows the Owner's rule for INC-0006. `website_id` is NOT NULL DEFAULT 0, where 0 means
 * "unattributed". This migration attributes nothing: the backfill is a separate, dry-runnable command that
 * derives the website from the URL's own host, which is derivation from the row's own data rather than
 * invention. Anything whose host matches no website in the workspace stays at 0, permanently if need be.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('seo_content_index')) {
            return;
        }

        if (! Schema::hasColumn('seo_content_index', 'website_id')) {
            DB::statement('ALTER TABLE `seo_content_index`
                           ADD COLUMN `website_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `workspace_id`');
        }

        $hasIndex = DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', 'seo_content_index')
            ->where('INDEX_NAME', 'seo_content_index_ws_website_index')
            ->exists();

        if (! $hasIndex) {
            // Per-site views filter on the pair; business-wide views drop the second column and still hit it.
            DB::statement('ALTER TABLE `seo_content_index`
                           ADD INDEX `seo_content_index_ws_website_index` (`workspace_id`, `website_id`)');
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('seo_content_index', 'website_id')) {
            return;
        }

        DB::statement('ALTER TABLE `seo_content_index` DROP INDEX `seo_content_index_ws_website_index`');
        DB::statement('ALTER TABLE `seo_content_index` DROP COLUMN `website_id`');
    }
};
