<?php

namespace App\Engines\Mention\Services;

use Illuminate\Support\Facades\DB;

/**
 * MentionTriageService — transitions for brand_mentions.status.
 *
 * Status lifecycle:
 *
 *   new → triaged | responded | spam | ignored | resolved
 *   triaged → responded | spam | ignored | resolved | new (re-open)
 *   responded → resolved | new (re-open)
 *   spam | ignored | resolved → new (re-open only)
 *
 * Rationale for "re-open to new": user might mis-triage (e.g. mark a real
 * mention as spam). Allowing reopen-to-new keeps the audit trail without
 * stranding rows in a terminal state forever.
 *
 * Audit: every transition stamps triaged_by (user_id) + triaged_at and
 * appends a row in metadata_json.transitions[] so the history is visible
 * on the single-mention view.
 */
class MentionTriageService
{
    public const STATUSES = ['new', 'triaged', 'responded', 'spam', 'ignored', 'resolved'];

    private const TRANSITIONS = [
        'new'       => ['triaged','responded','spam','ignored','resolved'],
        'triaged'   => ['responded','spam','ignored','resolved','new'],
        'responded' => ['resolved','new','triaged'],
        'spam'      => ['new'],
        'ignored'   => ['new'],
        'resolved'  => ['new'],
    ];

    public function transition(int $wsId, int $mentionId, string $newStatus, ?int $userId, ?string $notes = null): array
    {
        if (!in_array($newStatus, self::STATUSES, true)) {
            return ['success' => false, 'error' => "invalid status: $newStatus"];
        }

        $row = DB::table('brand_mentions')
            ->where('id', $mentionId)->where('workspace_id', $wsId)->first();
        if (!$row) return ['success' => false, 'error' => 'mention not found'];

        if ($row->status === $newStatus) {
            return [
                'success' => false,
                'error'   => "mention already in status '{$newStatus}'",
                'current' => $newStatus,
            ];
        }

        $allowed = self::TRANSITIONS[$row->status] ?? [];
        if (!in_array($newStatus, $allowed, true)) {
            return [
                'success'     => false,
                'error'       => "cannot transition '{$row->status}' → '{$newStatus}'",
                'allowed_from_current' => $allowed,
            ];
        }

        $now = now();
        $meta = json_decode($row->metadata_json ?? '{}', true) ?: [];
        $meta['transitions'] = $meta['transitions'] ?? [];
        $meta['transitions'][] = [
            'from'  => $row->status,
            'to'    => $newStatus,
            'by'    => $userId,
            'at'    => $now->toIso8601String(),
            'notes' => $notes ?: null,
        ];
        // Cap transitions history at 50 to bound row size on a busy mention
        if (count($meta['transitions']) > 50) {
            $meta['transitions'] = array_slice($meta['transitions'], -50);
        }

        DB::table('brand_mentions')->where('id', $mentionId)->update([
            'status'        => $newStatus,
            'triage_notes'  => $notes ? mb_substr($notes, 0, 2000) : $row->triage_notes,
            'triaged_by'    => $userId,
            'triaged_at'    => $now,
            'metadata_json' => json_encode($meta),
            'updated_at'    => $now,
        ]);

        return [
            'success'  => true,
            'previous' => $row->status,
            'current'  => $newStatus,
            'mention_id' => $mentionId,
        ];
    }

    /**
     * Bulk transition — handy for "select all visible, mark as spam".
     *
     * Returns: ['success' => bool, 'updated' => N, 'failed' => [{id, error}]]
     */
    public function bulkTransition(int $wsId, array $mentionIds, string $newStatus, ?int $userId, ?string $notes = null): array
    {
        $updated = 0;
        $failed = [];
        foreach ($mentionIds as $id) {
            $res = $this->transition($wsId, (int) $id, $newStatus, $userId, $notes);
            if (!empty($res['success'])) $updated++;
            else $failed[] = ['id' => $id, 'error' => $res['error'] ?? 'unknown'];
        }
        return [
            'success' => empty($failed),
            'updated' => $updated,
            'failed'  => $failed,
        ];
    }
}