<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * INFRA888 Phase 1D §8 — additive repair for environments that never received
 * the seo_images / seo_outbound_links uniqueness constraints.
 *
 * BACKGROUND
 * ----------
 * Both create-migrations open with `if (Schema::hasTable(...)) { return; }`.
 * Their composite unique indexes needed 8 + 2000 + 2000 = 4008 bytes against
 * InnoDB's 3072-byte limit, so on a FRESH database they aborted the chain — the
 * reason the documented disaster-recovery "rebuild" path could not complete.
 *
 * On existing environments the guard returned early instead, so the migrations
 * recorded themselves as run while creating nothing. Verified on staging
 * 2026-07-18: NEITHER unique index existed, and neither ever had.
 *
 * The create-migrations were repaired to use prefix indexes for fresh installs.
 * THIS migration repairs databases that are already migrated.
 *
 * SAFETY — never destroys or edits data:
 *   - skips a table that does not exist
 *   - skips an index that already exists (fresh installs have it)
 *   - skips WITH A WARNING if duplicates would violate the constraint, rather
 *     than deleting rows to force it through
 *
 * Duplicate cleanup is a deliberate data decision, not something a migration
 * should make silently.
 */
return new class extends Migration
{
    /** table => [index name, columns with prefix lengths] */
    private const REPAIRS = [
        'seo_images' => [
            'index'   => 'unique_page_image',
            'columns' => ['workspace_id' => null, 'page_url' => 255, 'image_url' => 255],
        ],
        'seo_outbound_links' => [
            'index'   => 'unique_outbound_link',
            'columns' => ['workspace_id' => null, 'source_url' => 255, 'target_url' => 255],
        ],
    ];

    public function up(): void
    {
        foreach (self::REPAIRS as $table => $spec) {
            if (!Schema::hasTable($table) || $this->indexExists($table, $spec['index'])) {
                continue;
            }

            if ($this->duplicateGroups($table, $spec['columns']) > 0) {
                Log::warning("[migration] {$table} unique index NOT applied: duplicate rows present", [
                    'index'           => $spec['index'],
                    'action_required' => 'resolve duplicates, then re-run this repair',
                ]);
                continue;
            }

            DB::statement(
                "ALTER TABLE `{$table}` ADD UNIQUE INDEX `{$spec['index']}` (" .
                $this->columnList($spec['columns']) . ')'
            );
        }
    }

    public function down(): void
    {
        foreach (self::REPAIRS as $table => $spec) {
            if (Schema::hasTable($table) && $this->indexExists($table, $spec['index'])) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$spec['index']}`");
            }
        }
    }

    private function columnList(array $columns): string
    {
        $parts = [];
        foreach ($columns as $col => $prefix) {
            $parts[] = $prefix === null ? "`{$col}`" : "`{$col}`({$prefix})";
        }

        return implode(', ', $parts);
    }

    private function duplicateGroups(string $table, array $columns): int
    {
        $select = [];
        foreach ($columns as $col => $prefix) {
            $select[] = $prefix === null ? "`{$col}`" : "LEFT(`{$col}`, {$prefix})";
        }
        $expr = implode(', ', $select);

        $rows = DB::select(
            "SELECT COUNT(*) AS c FROM (SELECT {$expr} FROM `{$table}` GROUP BY {$expr} HAVING COUNT(*) > 1) x"
        );

        return (int) ($rows[0]->c ?? 0);
    }

    private function indexExists(string $table, string $index): bool
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->where('TABLE_NAME', $table)
            ->where('INDEX_NAME', $index)
            ->exists();
    }
};
