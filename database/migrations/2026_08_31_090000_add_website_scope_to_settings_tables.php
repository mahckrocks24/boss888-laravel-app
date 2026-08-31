<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * INC-0006 — give the three workspace-unique settings tables a WEBSITE dimension.
 *
 * The canonical model is ONE WORKSPACE PER BUSINESS, MANY WEBSITES INSIDE IT. These three tables were written
 * when a website was (wrongly) its own workspace, so each is UNIQUE on workspace_id alone. That makes a second
 * website in the same business impossible to configure: it would silently share the first site's chatbot
 * greeting and business context, its WordPress credentials, and its Search Console property.
 *
 * Shape: website_id NOT NULL DEFAULT 0, where 0 means "the business-wide default". NOT NULL (rather than
 * nullable) is deliberate — MySQL treats NULLs as distinct inside a UNIQUE index, so a nullable column would
 * silently permit many "default" rows per workspace and reintroduce the ambiguity this migration exists to remove.
 *
 * Provenance: every existing row is left at website_id = 0. The Owner's rule for INC-0006 is that a row whose
 * website cannot be established must NEVER be assigned to an arbitrary "first" website. Leaving them at 0
 * preserves exactly today's behaviour — every website in the workspace resolves to the same row — while making
 * per-website override possible from here on. This migration therefore changes no observable behaviour.
 */
return new class extends Migration
{
    /** table => the unique index that currently pins it to one row per workspace */
    private const TARGETS = [
        'chatbot_settings' => ['old' => 'chatbot_settings_workspace_id_unique',  'cols' => ['workspace_id', 'website_id']],
        'gsc_connections'  => ['old' => 'gsc_connections_workspace_id_unique',   'cols' => ['workspace_id', 'website_id']],
        'seo_settings'     => ['old' => 'seo_settings_workspace_id_key_unique',  'cols' => ['workspace_id', 'website_id', 'key']],
    ];

    public function up(): void
    {
        foreach (self::TARGETS as $table => $spec) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'website_id')) {
                // Placed after workspace_id so the tenancy pair reads together in a DESCRIBE.
                DB::statement("ALTER TABLE `{$table}`
                               ADD COLUMN `website_id` BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER `workspace_id`");
            }

            $cols = '`' . implode('`, `', $spec['cols']) . '`';
            $new  = $table . '_ws_website_unique';

            if (! $this->hasIndex($table, $new)) {
                // Added BEFORE the old index is dropped, and led by workspace_id, so the foreign key on
                // chatbot_settings.workspace_id always has an index to lean on. Dropping first would fail.
                DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$new}` ({$cols})");
            }

            if ($this->hasIndex($table, $spec['old'])) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$spec['old']}`");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TARGETS as $table => $spec) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            // Only reversible while no workspace has diverging per-website rows — collapsing them would
            // destroy configuration. Refuse rather than silently drop a website's settings.
            $dupes = DB::table($table)
                ->select('workspace_id')
                ->groupBy('workspace_id')
                ->havingRaw('COUNT(DISTINCT website_id) > 1')
                ->count();

            if ($dupes > 0) {
                throw new RuntimeException(
                    "Cannot roll back: {$table} holds per-website rows in {$dupes} workspace(s). "
                    . 'Collapsing them would discard configuration; resolve by hand.'
                );
            }

            if ($this->hasIndex($table, $spec['old'])) {
                continue;
            }

            $oldCols = $table === 'seo_settings' ? '`workspace_id`, `key`' : '`workspace_id`';
            DB::statement("ALTER TABLE `{$table}` ADD UNIQUE `{$spec['old']}` ({$oldCols})");
            DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$table}_ws_website_unique`");
            DB::statement("ALTER TABLE `{$table}` DROP COLUMN `website_id`");
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
