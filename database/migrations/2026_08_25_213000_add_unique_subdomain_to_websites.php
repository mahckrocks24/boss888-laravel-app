<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * BUILDER888 B9/B2 — websites.subdomain had no uniqueness, so the set-subdomain
 * route's read-then-write availability check was TOCTOU-racy: two concurrent claims
 * could both write the same subdomain -> cross-tenant serving collision. This adds the
 * authoritative DB UNIQUE guard. Applied live on the droplet 2026-08-25 (EV-0718);
 * guarded so it is a no-op where it already exists (reproducible on a fresh DB, safe to
 * re-run). NULLs are allowed (MySQL permits multiple NULLs), so unset subdomains coexist.
 */
return new class extends Migration {
    private function indexExists(): bool
    {
        return collect(DB::select(
            "SHOW INDEX FROM websites WHERE Key_name = 'websites_subdomain_unique'"
        ))->isNotEmpty();
    }

    public function up(): void
    {
        if (! $this->indexExists()) {
            Schema::table('websites', function ($t) {
                $t->unique('subdomain', 'websites_subdomain_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists()) {
            Schema::table('websites', function ($t) {
                $t->dropUnique('websites_subdomain_unique');
            });
        }
    }
};
