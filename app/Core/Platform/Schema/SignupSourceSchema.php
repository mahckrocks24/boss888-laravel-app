<?php

namespace App\Core\Platform\Schema;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Is users.signup_source actually present?
 *
 * WHY THIS EXISTS — TEMPORARY COMPATIBILITY GUARD, NOT A FEATURE.
 * The column is read by two admin call sites but has never existed on this
 * platform: no migration adds it, and it is absent from all 88 database backups
 * on this server spanning 2026-05-09 to 2026-08-04. The unconditional read in
 * listWorkspaces is what kept GET /api/admin/workspaces returning 500 after the
 * WP Connector guard had fixed everything else on that endpoint.
 *
 * DELIBERATELY SEPARATE FROM WpConnectorSchema. They are different features
 * that happen to share a cause — the same 2026-05-01 "SEO-only product mode"
 * batch shipped consumer code without schema. Folding this into the connector
 * helper would make that class misrepresent what it governs, and would couple
 * two remediations that must be able to end independently.
 *
 * THIS IS NOT THE FIX. The fix is an authoritative specification, tracked as
 * "Signup Source Schema Recovery" (BLOCKED_PENDING_AUTHORITATIVE_SPEC). When
 * the column lands, available() starts returning true and both call sites
 * resume their original behaviour with no further change.
 */
final class SignupSourceSchema
{
    /** The structured marker every absence is reported under. */
    public const MISSING_EVENT = 'ADMIN_SIGNUP_SOURCE_SCHEMA_MISSING';

    /** Resolved once per process; a column cannot appear mid-request. */
    private static ?bool $available = null;

    /** @var array<string,true> operations already reported, so logs do not flood */
    private static array $warned = [];

    /** Is the column there? */
    public static function available(): bool
    {
        return self::$available ??= Schema::hasColumn('users', 'signup_source');
    }

    /**
     * May this operation read the column, and if not, say so once.
     *
     * Deduplicated per operation per process: a Workspaces page load enriches
     * every row in the paginated window, and one absent column must not become
     * one log line per row.
     *
     * Logs the operation and the missing object only — never a request body,
     * never a filter value, never anything user-supplied.
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
                'missing' => ['column:users.signup_source'],
                // Stated for both call sites rather than for one of them: a
                // reader of the filter warning must not be told about
                // owner_source and conclude the filter was applied.
                'effect' => 'owner_source is null; a requested signup_source filter is not applied; the operation itself continues',
                'remediation' => 'Signup Source Schema Recovery (BLOCKED_PENDING_AUTHORITATIVE_SPEC)',
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
