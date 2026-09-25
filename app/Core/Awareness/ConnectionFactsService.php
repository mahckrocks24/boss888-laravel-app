<?php

namespace App\Core\Awareness;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * SARAH-AWARE-1 (Owner 2026-09-25: "Sarah seems not connected to social engine. She has to notice all important things
 * like this … She has to get notified automatically whenever things like this happens").
 *
 * Sarah reads a workspace_memory fact called `disconnected_engines` on every turn as a HARD CONSTRAINT. For ws 2 it was a
 * sentence typed by hand on 2026-07-19 ("zero connected social accounts … both channels are dead") and nothing ever
 * recomputed it — so a Facebook Page connected today did not exist for her. This service is the truth: it recomputes
 * that fact from the live tables (social_accounts, email_domains/mailboxes, gsc_connections), rewrites it in the same
 * shape the turn expects (a JSON string), keeps a machine snapshot beside it, and when something CHANGES it writes a
 * notification the customer and Sarah's briefing both see. Runs on connect/disconnect and every ten minutes.
 */
final class ConnectionFactsService
{
    public const FACT_KEY     = 'disconnected_engines';   // the key the chat turn injects (routes/api/authenticated/agents-01.php)
    public const SNAPSHOT_KEY = 'channel_status_json';    // machine-readable, for diffs

    /** The live state of every customer-facing channel for a workspace. */
    public function facts(int $wsId): array
    {
        $social = DB::table('social_accounts as s')->leftJoin('businesses as b', 'b.id', '=', 's.business_id')
            ->where('s.workspace_id', $wsId)->where('s.status', 'connected')
            ->orderBy('s.id')->get(['s.id', 's.platform', 's.account_name', 's.business_id', 'b.name as business_name', 's.health_state', 's.updated_at'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'platform' => (string) $r->platform, 'account_name' => (string) $r->account_name,
                'business_id' => $r->business_id ? (int) $r->business_id : null, 'business_name' => $r->business_name ? (string) $r->business_name : null,
                'health' => $r->health_state ? (string) $r->health_state : null, 'since' => (string) $r->updated_at])->values()->all();

        $emailDomains = DB::table('email_domains')->where('workspace_id', $wsId)->whereNull('deleted_at')
            ->whereIn('lifecycle_state', ['connected', 'active', 'live', 'verified', 'ready'])->pluck('domain')->map(fn ($d) => (string) $d)->values()->all();
        $mailboxes = (int) DB::table('email_mailboxes')->where('workspace_id', $wsId)->whereNull('deleted_at')
            ->whereNotIn('lifecycle_state', ['deleted', 'terminated', 'suspended'])->count();

        $gsc = DB::table('gsc_connections')->where('workspace_id', $wsId)->where('connected', 1)->orderByDesc('id')->first(['site_url', 'last_sync_at', 'connected_email']);

        return [
            'social'  => $social,
            'email'   => ['domains' => $emailDomains, 'mailboxes' => $mailboxes],
            'gsc'     => $gsc ? ['site_url' => (string) $gsc->site_url, 'last_sync_at' => $gsc->last_sync_at ? (string) $gsc->last_sync_at : null] : null,
            'computed_at' => now()->toDateTimeString(),
        ];
    }

    /** The sentence Sarah reads. Plain words, current, and honest about what is and is not connected. */
    public function sentence(array $f): string
    {
        $parts = [];
        if ($f['social']) {
            $parts[] = 'Social — CONNECTED: ' . implode('; ', array_map(function ($a) {
                $p = ucfirst($a['platform']) . ($a['platform'] === 'facebook' ? ' Page' : '') . ' "' . $a['account_name'] . '"';
                if ($a['business_name']) $p .= ' for the business ' . $a['business_name'];
                $p .= ' (since ' . \Carbon\Carbon::parse($a['since'])->toFormattedDateString() . ')';
                return $p;
            }, $f['social'])) . '. Posting there needs the customer\'s approval of a plan, then Sarah\'s team can publish.';
        } else {
            $parts[] = 'Social — NOT connected: no social account is linked yet (the customer connects one under Social).';
        }
        if ($f['email']['domains'] || $f['email']['mailboxes'] > 0) {
            $parts[] = 'Business email — connected: ' . ($f['email']['domains'] ? implode(', ', $f['email']['domains']) : 'mailboxes in place') . ($f['email']['mailboxes'] > 0 ? ' (' . $f['email']['mailboxes'] . ' mailbox' . ($f['email']['mailboxes'] === 1 ? '' : 'es') . ')' : '') . '.';
        } else {
            $parts[] = 'Business email — NOT connected.';
        }
        $parts[] = $f['gsc'] ? ('Google Search Console — connected (' . $f['gsc']['site_url'] . ($f['gsc']['last_sync_at'] ? ', last sync ' . \Carbon\Carbon::parse($f['gsc']['last_sync_at'])->toFormattedDateString() : '') . ').') : 'Google Search Console — NOT connected.';
        return 'CHANNEL STATUS (live, recomputed ' . $f['computed_at'] . ' UTC): ' . implode(' ', $parts)
            . ' Only the channels marked NOT connected are off limits; never call a CONNECTED channel disconnected.';
    }

    /**
     * Recompute and persist. Returns the facts, the sentence, and the changes noticed against the previous snapshot
     * (newly connected / disconnected social accounts), which become notifications when $notify is true.
     */
    public function recompute(int $wsId, bool $notify = false): array
    {
        $facts = $this->facts($wsId);
        $sentence = $this->sentence($facts);
        $prevRow = DB::table('workspace_memory')->where('workspace_id', $wsId)->where('key', self::SNAPSHOT_KEY)->value('value_json');
        $prev = is_string($prevRow) ? (json_decode($prevRow, true) ?: null) : null;
        $changes = $this->diff(is_array($prev) ? $prev : null, $facts);

        $now = now();
        // the turn expects a JSON STRING (it keeps only is_string() values) — write exactly that shape
        DB::table('workspace_memory')->updateOrInsert(['workspace_id' => $wsId, 'key' => self::FACT_KEY],
            ['value_json' => json_encode($sentence), 'ttl' => null, 'updated_at' => $now, 'created_at' => $now]);
        DB::table('workspace_memory')->updateOrInsert(['workspace_id' => $wsId, 'key' => self::SNAPSHOT_KEY],
            ['value_json' => json_encode($facts), 'ttl' => null, 'updated_at' => $now, 'created_at' => $now]);

        if ($notify && $prev !== null) {
            foreach ($changes as $c) $this->notify($wsId, $c);
        }
        if ($changes) Log::info('[Awareness] channel status changed', ['workspace_id' => $wsId, 'changes' => $changes, 'notified' => $notify && $prev !== null]);
        return ['facts' => $facts, 'sentence' => $sentence, 'changes' => $changes, 'first_run' => $prev === null];
    }

    public function recomputeAll(int $limit = 5000): int
    {
        $n = 0;
        $ids = DB::table('workspaces')->orderBy('id')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            try { $this->recompute((int) $id, true); $n++; } catch (\Throwable $e) { Log::warning('[Awareness] recompute failed', ['workspace_id' => $id, 'error' => $e->getMessage()]); }
        }
        return $n;
    }

    /** Newly connected / disconnected social accounts between two snapshots. */
    public function diff(?array $prev, array $now): array
    {
        $out = [];
        $before = []; foreach (($prev['social'] ?? []) as $a) $before[$a['id']] = $a;
        $after  = []; foreach (($now['social'] ?? []) as $a) $after[$a['id']] = $a;
        foreach ($after as $id => $a) if (! isset($before[$id])) $out[] = ['kind' => 'social.account.connected', 'account' => $a];
        foreach ($before as $id => $a) if (! isset($after[$id])) $out[] = ['kind' => 'social.account.disconnected', 'account' => $a];
        $gPrev = ! empty($prev['gsc']); $gNow = ! empty($now['gsc']);
        if ($prev !== null && $gPrev !== $gNow) $out[] = ['kind' => $gNow ? 'gsc.connected' : 'gsc.disconnected', 'account' => ['account_name' => $now['gsc']['site_url'] ?? ($prev['gsc']['site_url'] ?? ''), 'platform' => 'google search console', 'business_name' => null]];
        return $out;
    }

    /** One notification per change — in the customer's words, with a door to the right screen. */
    private function notify(int $wsId, array $change): void
    {
        $a = $change['account'];
        $who = (isset($a['platform']) && $a['platform'] === 'facebook' ? 'Facebook Page' : ucfirst((string) ($a['platform'] ?? 'account'))) . ' "' . ($a['account_name'] ?? '') . '"';
        $for = ! empty($a['business_name']) ? ' for ' . $a['business_name'] : '';
        [$body, $url] = match ($change['kind']) {
            'social.account.connected'    => [$who . ' is now connected' . $for . '. Sarah can include it in her plans; nothing is posted without your approval.', '/app/social'],
            'social.account.disconnected' => [$who . ' was disconnected' . $for . '. Sarah will not plan posts there until it is connected again.', '/app/social'],
            'gsc.connected'               => ['Google Search Console is connected (' . ($a['account_name'] ?? '') . '). Search performance will start flowing into Sarah\'s briefings.', '/app/seo'],
            'gsc.disconnected'            => ['Google Search Console is no longer connected. Sarah loses search performance data until it is reconnected.', '/app/seo'],
            default                       => ['A connection changed.', '/app/settings'],
        };
        try {
            DB::table('notifications')->insert([
                'workspace_id' => $wsId, 'user_id' => null, 'channel' => 'social', 'type' => $change['kind'], 'category' => 'connection',
                'title' => 'LevelUpGrowth', 'body' => $body, 'action_url' => $url, 'icon' => null, 'severity' => 'info',
                'data_json' => json_encode(['kind' => $change['kind'], 'account' => $a]), 'created_at' => now(), 'updated_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('[Awareness] notification failed', ['workspace_id' => $wsId, 'error' => $e->getMessage()]);
        }
    }
}
