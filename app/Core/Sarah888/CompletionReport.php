<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * RISK-0186 (2026-09-17) — what Sarah tells the owner when an article chain finishes, read from the row, not assumed.
 *
 * EV-1056: a yoga article written onto the bakery site was rejected by SarahQA as off-topic, the task row still said
 * completed (SARAH-QA-1 puts the verdict on the row, the status is the specialist's), and the owner was told
 * "Your article … is ready — with a featured image". READY is said only when the verdict allows it; a rejected or
 * owner-needed verdict is reported as exactly that, with the reasons, and the website the piece was written for is
 * named so a wrong target is visible at once.
 */
final class CompletionReport
{
    public static function forArticle(int $rootTaskId, string $title, string $imgTxt, string $linkTxt): string
    {
        $qaStatus = null; $reasons = []; $siteName = null;
        try {
            $row = DB::table('tasks')->where('id', $rootTaskId)->first(['qa_status', 'qa_json', 'payload_json']);
            if ($row) {
                $qaStatus = $row->qa_status !== null ? (string) $row->qa_status : null;
                $qa = json_decode((string) ($row->qa_json ?? ''), true) ?: [];
                $reasons = array_values(array_filter(array_map('strval', (array) ($qa['reasons'] ?? []))));
                $p = json_decode((string) ($row->payload_json ?? ''), true) ?: [];
                $wid = (int) ($p['website_id'] ?? 0);
                if ($wid > 0) { $siteName = DB::table('websites')->where('id', $wid)->value('name'); $siteName = $siteName !== null ? (string) $siteName : null; }
            }
        } catch (\Throwable) { /* report from what is known */ }
        return self::compose($title, $imgTxt, $linkTxt, $qaStatus, $reasons, $siteName);
    }

    /** Pure composition — tested directly. */
    public static function compose(string $title, string $imgTxt, string $linkTxt, ?string $qaStatus, array $reasons, ?string $siteName): string
    {
        $for = $siteName !== null && $siteName !== '' ? " for {$siteName}" : '';
        $why = $reasons !== [] ? ' ' . rtrim(implode(' ', array_slice($reasons, 0, 2)), '.') . '.' : '';
        $status = strtolower((string) $qaStatus);
        if (in_array($status, [SarahQaGate::REJECTED, 'rejected'], true)) {
            return "The draft \"{$title}\"{$for} did not pass my QA, so it is NOT ready — it stays a draft and is held out of publishing.{$why} Tell me to rewrite it, move it to another website, or drop it.";
        }
        if (in_array($status, [SarahQaGate::NEEDS_OWNER, 'needs_owner'], true)) {
            return "The draft \"{$title}\"{$for} is written but needs your eyes before it counts as ready — my QA could not accept it on its own.{$why} It stays a draft until you decide.";
        }
        return "Your article \"{$title}\"{$for} is ready — {$imgTxt}, {$linkTxt}. You'll find it in your drafts.";
    }
}
