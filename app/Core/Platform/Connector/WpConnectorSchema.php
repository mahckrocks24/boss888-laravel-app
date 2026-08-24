<?php

namespace App\Core\Platform\Connector;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Is the WP Connector's storage actually present?
 *
 * WHY THIS EXISTS — TEMPORARY COMPATIBILITY GUARD, NOT A FEATURE.
 * The WP Connector's consumer code shipped on 2026-05-05 (commit 66146f2,
 * "SEO-only product mode") without its schema. Nothing creates it: there is no
 * migration for `wp_site_connections`, no `api_keys.site_connection_id` column
 * and no `App\Models\WpSiteConnection` class. A scan of all 88 database backups
 * on this server, 2026-05-09 through 2026-08-04, found the table in none of
 * them — it has never existed here.
 *
 * The consequence was a 500 on GET /api/admin/users and /api/admin/workspaces
 * for three months. It stayed invisible because until the 2026-07-29 multi-page
 * refactor every /admin/* URL rendered the same hardcoded dashboard, so those
 * pages were never actually opened. The Platform Security browser certification
 * on 2026-08-04 is what finally opened them.
 *
 * ONE TRUTH, ASKED IN ONE PLACE. Six call sites need this answer. Six copies of
 * Schema::hasTable() would be six chances to disagree, and the disagreement
 * would surface as a 500 on whichever one was forgotten.
 *
 * THIS IS NOT THE FIX. The fix is an authoritative schema, tracked as
 * "WP Connector Schema Recovery" (BLOCKED_PENDING_AUTHORITATIVE_SPEC). This
 * class exists so an absent optional feature degrades instead of throwing, and
 * so its absence stays visible rather than being silently swallowed. When the
 * schema lands, available() starts returning true and every call site resumes
 * its original behaviour with no further change.
 */
final class WpConnectorSchema
{
    /** The structured marker every absence is reported under. */
    public const MISSING_EVENT = 'WP_CONNECTOR_SCHEMA_MISSING';

    /** Resolved once per process; the schema cannot appear mid-request. */
    private static ?bool $available = null;

    /** @var array<string,true> operations already reported, so logs do not flood */
    private static array $warned = [];

    /**
     * Every part of the connector's storage, and what is absent right now.
     *
     * The model is checked alongside the tables on purpose: the Stripe billing
     * helpers reference WpSiteConnection::STATUS_ACTIVE, so a present table with
     * an absent model is still an unusable feature — and would still throw.
     *
     * @return array<int,string>
     */
    public static function missing(): array
    {
        $missing = [];

        if (! Schema::hasTable('wp_site_connections')) {
            $missing[] = 'table:wp_site_connections';
        }

        if (! Schema::hasTable('api_keys') || ! Schema::hasColumn('api_keys', 'site_connection_id')) {
            $missing[] = 'column:api_keys.site_connection_id';
        }

        if (! class_exists(\App\Models\WpSiteConnection::class)) {
            $missing[] = 'class:App\Models\WpSiteConnection';
        }

        return $missing;
    }

    /** May connector storage be queried at all? */
    public static function available(): bool
    {
        return self::$available ??= self::missing() === [];
    }

    /**
     * The question a call site should ask: may I query, and if not, say so once.
     *
     * Deduplicated per operation per process. A webhook storm must not turn one
     * missing table into thousands of identical log lines, but the first
     * occurrence in each process must still be visible.
     *
     * Never logs credentials, request bodies or site URLs — only the operation
     * name and which schema objects are absent.
     */
    public static function availableFor(string $operation): bool
    {
        if (self::available()) {
            return true;
        }

        if (! isset(self::$warned[$operation])) {
            self::$warned[$operation] = true;

            Log::warning(self::MISSING_EVENT, [
                'operation' => $operation,
                'missing' => self::missing(),
                'effect' => 'connector data reported as empty; the operation itself continues',
                'remediation' => 'WP Connector Schema Recovery (BLOCKED_PENDING_AUTHORITATIVE_SPEC)',
            ]);
        }

        return false;
    }

    /** Test seam. Production never needs this; the schema does not change mid-process. */
    public static function forget(): void
    {
        self::$available = null;
        self::$warned = [];
    }
}
