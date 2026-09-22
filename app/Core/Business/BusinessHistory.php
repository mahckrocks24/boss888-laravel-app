<?php

namespace App\Core\Business;

use Illuminate\Database\Query\Builder;

/**
 * RFC-0011 U4a: the conversation history Sarah is shown is the ACTIVE business's (plus rows from before businesses
 * existed, which count as portfolio). Audit rows ('agent.direct_message') are stamped with the business at write
 * time; the read filters by it only for a single-business turn in a multi-business workspace.
 */
class BusinessHistory
{
    /** The business bound for this turn by the chat route (null when the turn is portfolio/ambiguous or single). */
    public static function current(): ?int
    {
        try { return app()->bound('sarah.business_id') ? ((int) app('sarah.business_id') ?: null) : null; } catch (\Throwable $e) { return null; }
    }

    /** Filter a query over audit_logs / agent_messages metadata_json to this turn's business (untagged rows kept). */
    public static function apply(Builder $q, ?int $businessId): Builder
    {
        if (! $businessId) { return $q; }
        return $q->whereRaw("(JSON_EXTRACT(metadata_json, '$.business_id') IS NULL OR JSON_TYPE(JSON_EXTRACT(metadata_json, '$.business_id')) = 'NULL' OR JSON_EXTRACT(metadata_json, '$.business_id') = ?)", [$businessId]);
    }
}
