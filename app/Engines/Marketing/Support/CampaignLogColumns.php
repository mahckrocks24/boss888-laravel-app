<?php

namespace App\Engines\Marketing\Support;

/**
 * EM-8 — the storage boundary for `email_campaigns_log`.
 *
 * WHY THIS EXISTS FOR ONE CONSTANT
 * The column is called `postmark_message_id`. It was created in April 2026, it
 * holds real production data, and renaming it is a migration with real risk for
 * a purely cosmetic gain — so it stays.
 *
 * What does NOT have to stay is producers naming a vendor. Two of them wrote
 * that column name directly, which meant a Postmark reference sat in the
 * campaign fan-out and in the queued job: places that must not know who the
 * provider is. The name now appears exactly once, here, at the storage
 * boundary, labelled as debt rather than left looking deliberate.
 *
 * The DOMAIN concept is `provider_message_id` — which is what the Email888
 * ledger, the webhook receipts and every contract already call it.
 *
 * STORAGE DEBT, recorded: if the column is ever renamed, this constant is the
 * only line that changes.
 */
final class CampaignLogColumns
{
    /**
     * Legacy storage name for the provider's message identifier.
     *
     * @deprecated The domain concept is provider_message_id; this is the column
     *             it currently lives in. Do not read the vendor name from here
     *             as a statement about which provider sent anything.
     */
    public const PROVIDER_MESSAGE_ID = 'postmark_message_id';
}
