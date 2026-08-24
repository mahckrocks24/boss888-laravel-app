<?php

namespace App\Core\Experience888;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * EXPERIENCE888 — ingestion eligibility.
 *
 * WHY THIS IS EXPLICIT OPT-IN RATHER THAN is_house_account.
 *
 * The platform already has an internal classification, `workspaces.is_house_account`,
 * and reusing it was the obvious move. The audit killed it: ws 2 (Chef Red
 * Raymundo) carries is_house_account = true while being a real customer
 * workspace with live content. Enrolling "house accounts" would therefore have
 * enrolled exactly the tenant that must stay out during owner beta.
 *
 * That flag also drives unrelated behaviour — weekly proactive SEO audits and
 * credit replenishment — so it is READ-ONLY here and must never be set to
 * enable Experience888.
 *
 * So eligibility is its own explicit, workspace-scoped opt-in, stored in the
 * existing `workspaces.settings_json`. No new table, no second tenancy system,
 * and the safe default is OFF: a workspace nobody has deliberately enrolled is
 * never learned from.
 */
final class ExperienceEligibility
{
    /** Where the opt-in lives inside settings_json. */
    public const SETTINGS_KEY = 'experience888';

    /** Gates chat-time capture of the owner's corrections and standing rules. */
    public const FEEDBACK_KEY  = 'owner_feedback_capture_enabled';

    /** Gates the automated sweep of completed operational work. */
    public const INGESTION_KEY = 'automated_operational_ingestion_enabled';

    public function isEnabled(int $wsId): bool
    {
        return ($this->block($wsId)['enabled'] ?? false) === true;
    }

    /**
     * May Sarah learn from what the OWNER says here?
     *
     * ws 2 is Chef Red's live workspace AND the room Boss runs the owner beta
     * in. A correction Boss gives — "keep captions short from now on" — is
     * exactly what Experience888 exists to retain, and T020 measured the cost
     * of not retaining it: the rule was given at turn 6, fell out of the
     * 20-message history window, and by turn 20 Sarah had no record of it.
     * That is a separate question from whether her 2,000 automated task
     * outcomes should form patterns, so it is a separate flag.
     */
    public function isFeedbackEnabled(int $wsId): bool
    {
        $b = $this->block($wsId);
        if (array_key_exists(self::FEEDBACK_KEY, $b)) return $b[self::FEEDBACK_KEY] === true;
        return ($b['enabled'] ?? false) === true;      // unrefined: follow the opt-in
    }

    /** May the automated sweep ingest this workspace's completed work? */
    public function isIngestionEnabled(int $wsId): bool
    {
        $b = $this->block($wsId);
        if (array_key_exists(self::INGESTION_KEY, $b)) return $b[self::INGESTION_KEY] === true;
        return ($b['enabled'] ?? false) === true;
    }

    /** @return int[] workspaces the automated sweep may touch. */
    public function ingestionEnabledWorkspaceIds(): array
    {
        return array_values(array_filter($this->enabledWorkspaceIds(),
            fn (int $id) => $this->isIngestionEnabled($id)));
    }

    /** The experience888 settings block, or [] when absent/unreadable. */
    private function block(int $wsId): array
    {
        if ($wsId <= 0) return [];
        $raw = DB::table('workspaces')->where('id', $wsId)->value('settings_json');
        if ($raw === null) return [];
        $s = json_decode((string) $raw, true);
        if (!is_array($s)) return [];
        $b = $s[self::SETTINGS_KEY] ?? [];
        return is_array($b) ? $b : [];
    }

    /** @return int[] every workspace explicitly enrolled */
    public function enabledWorkspaceIds(): array
    {
        $out = [];
        foreach (DB::table('workspaces')->whereNotNull('settings_json')
                   ->get(['id', 'settings_json']) as $w) {
            $s = json_decode((string) $w->settings_json, true);
            if (is_array($s) && (($s[self::SETTINGS_KEY]['enabled'] ?? false) === true)) {
                $out[] = (int) $w->id;
            }
        }
        return $out;
    }

    /**
     * Enrol a workspace. Deliberately a deliberate act: it is recorded with who
     * did it and why, because enrolling a real customer is a decision.
     */
    public function enable(int $wsId, string $reason, string $by = 'engineering',
                           array $refinements = []): bool
    {
        return $this->write($wsId, true, $reason, $by, $refinements);
    }

    public function disable(int $wsId, string $reason, string $by = 'engineering'): bool
    {
        return $this->write($wsId, false, $reason, $by);
    }

    private function write(int $wsId, bool $enabled, string $reason, string $by,
                           array $refinements = []): bool
    {
        $row = DB::table('workspaces')->where('id', $wsId)->first(['id', 'settings_json']);
        if (!$row) return false;

        $s = json_decode((string) $row->settings_json, true);
        if (!is_array($s)) $s = [];

        // Merge, never replace: settings_json is shared with unrelated features.
        $s[self::SETTINGS_KEY] = array_merge($s[self::SETTINGS_KEY] ?? [], $refinements, [
            'enabled'    => $enabled,
            'reason'     => $reason,
            'changed_by' => $by,
            'changed_at' => now()->toDateTimeString(),
        ]);

        DB::table('workspaces')->where('id', $wsId)
            ->update(['settings_json' => json_encode($s), 'updated_at' => now()]);

        Log::info('[Experience888] eligibility changed', [
            'ws' => $wsId, 'enabled' => $enabled, 'reason' => $reason, 'by' => $by,
        ]);
        return true;
    }
}
