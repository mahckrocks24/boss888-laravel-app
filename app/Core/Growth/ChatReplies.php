<?php

namespace App\Core\Growth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * CHAT-FIRST-1 (Owner 2026-09-27: "remember that Sarah LLM first approach. a busy user will most likely use just the
 * companion app. so make sure all these things are constantly and proactively being communicated via chat").
 *
 * Every question Sarah asks with a card can be answered in plain words, so the companion app (which shows her text and
 * quick-reply chips, not web cards) is a complete surface:
 *   watch_ask        "twice a week" / "weekly" / "every day" / "yes" / "not now"
 *   campaign_ideas   "launch 2" / "the second one" / "launch it" (one idea) / "not now"
 *   campaign_change  "approve" / "yes" / "keep as is" / "no"
 *   brand_intake     "2, 4 and 6" / "clean minimal and warm organic" / "skip"
 *   brand_summary    "save" / "yes" / "discard"
 *   campaign_step    "done" / "skip"
 * The open question is the newest of Sarah's messages of these kinds whose subject is still undecided. A bare "yes" or
 * "no" only counts when that question is her latest message; specific answers ("launch 2", "done") count for 3 days.
 * The chips the app shows under her message come from the same place (chips()).
 */
final class ChatReplies
{
    public const TYPES = ['watch_ask', 'campaign_ideas', 'campaign_change', 'brand_intake', 'brand_summary', 'campaign_step'];
    /** Text after this marker is the plain-words version of a card: the app shows it, the web hides it next to the card. */
    public const APP_PART = "\n\n\u{200B}";

    private const YES = '/^\s*(yes|yep|yeah|yup|sure|ok(ay)?|go( ahead)?|do it|approve[d]?|sounds good|perfect|great|let\'?s do it|please do|go for it)\b[\s.!]*$/iu';
    private const NO = '/^\s*(no|nope|not now|not yet|no thanks|maybe later|later|skip( it)?|pass|keep( it)? as is|leave it|don\'?t)\b[\s.!]*$/iu';

    /** "every 2 days", "every other day", "3 times a week", "weekly"… → the nearest schedule. */
    public static function frequencyFrom(string $t): ?string
    {
        $t = mb_strtolower($t);
        if (preg_match('/\bevery\s*(2|two|other|second)\s*(days?|nights?)\b|\bevery other day\b|\b(3|three|4|four) times a week\b|\balternate days\b/', $t)) return 'every_2_days';
        if (preg_match('/\bevery\s*(3|three|4|four)\s*days?\b|\btwice\b|\b(2|two) times a week\b/', $t)) return 'twice_weekly';
        if (preg_match('/\b(every ?day|daily|each day|every morning)\b/', $t)) return 'daily';
        if (preg_match('/\b(once a week|weekly|every week|once|every\s*(7|seven|5|five|6|six)\s*days)\b/', $t)) return 'weekly';
        return null;
    }

    /** The question still waiting on the owner, if any. */
    public function openQuestion(int $wsId): ?array
    {
        $rows = DB::table('agent_messages')->where('workspace_id', $wsId)->where('agent_slug', 'sarah')->where('role', 'agent')->where('created_at', '>=', now()->subDays(3))
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.notification_type'))"), self::TYPES)->orderByDesc('id')->limit(8)->get(['id', 'metadata_json']);
        $latest = (int) DB::table('agent_messages')->where('workspace_id', $wsId)->where('agent_slug', 'sarah')->where('role', 'agent')
            ->where(fn ($q) => $q->whereNull('metadata_json')->orWhereRaw("COALESCE(JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.phase')), '') <> 'ack'"))->max('id');
        foreach ($rows as $m) {
            $meta = json_decode((string) $m->metadata_json, true) ?: [];
            $type = (string) ($meta['notification_type'] ?? '');
            $card = is_array($meta['card'] ?? null) ? $meta['card'] : [];
            $q = ['type' => $type, 'message_id' => (int) $m->id, 'latest' => (int) $m->id >= $latest, 'card' => $card, 'meta' => $meta];
            if ($this->stillOpen($wsId, $q)) return $q;
        }
        return null;
    }

    private function stillOpen(int $wsId, array &$q): bool
    {
        $c = $q['card'];
        switch ($q['type']) {
            case 'watch_ask':
                $row = app(WatchService::class)->row($wsId, isset($c['business_id']) ? (int) $c['business_id'] : null);
                return $row && $row->status === 'asked';
            case 'campaign_change':
                return DB::table('campaign_changes')->where('id', (int) ($c['change_id'] ?? 0))->where('workspace_id', $wsId)->where('status', 'proposed')->exists();
            case 'campaign_ideas':
                $ids = array_map(fn ($i) => (int) ($i['id'] ?? 0), (array) ($c['ideas'] ?? []));
                $open = DB::table('marketing_campaigns')->where('workspace_id', $wsId)->whereIn('id', $ids)->where('status', 'idea')->whereNull('deleted_at')->pluck('id')->map(fn ($x) => (int) $x)->all();
                $q['open_ids'] = array_values(array_filter($ids, fn ($i) => in_array($i, $open, true)));   // in the order Sarah numbered them
                $q['all_ids'] = $ids;
                return (bool) $q['open_ids'];
            case 'brand_intake':
                $row = app(\App\Core\Brand\BrandProfileService::class)->row($wsId, app(\App\Core\Brand\BrandProfileService::class)->business($wsId, isset($c['business_id']) ? (int) $c['business_id'] : null));
                return $row && $row->intake_status === 'asked';
            case 'brand_summary':
                return app(\App\Core\Brand\BrandProfileService::class)->proposalStatus($wsId, (string) ($c['token'] ?? '')) === 'pending';
            case 'campaign_step':
                return DB::table('campaign_items')->where('id', (int) ($q['meta']['item_id'] ?? 0))->where('workspace_id', $wsId)->whereIn('status', ['needs_you', 'planned', 'held', 'failed'])->exists();
        }
        return false;
    }

    /** Tap-to-answer chips for the app (they send text, so typing works the same). */
    public function chips(int $wsId): array
    {
        $q = $this->openQuestion($wsId);
        if (! $q || ! $q['latest']) return [];
        return match ($q['type']) {
            'watch_ask' => [['label' => 'Every 2 days', 'text' => 'Every 2 days'], ['label' => 'Twice a week', 'text' => 'Twice a week'], ['label' => 'Once a week', 'text' => 'Once a week'], ['label' => 'Not now', 'text' => 'Not now']],
            'campaign_change' => [['label' => 'Approve', 'text' => 'Approve'], ['label' => 'Keep as is', 'text' => 'Keep as is']],
            'campaign_ideas' => array_merge(count($q['open_ids']) === 1
                ? [['label' => 'Launch it', 'text' => 'Launch it']]
                : array_map(fn ($id) => ['label' => 'Launch #' . (array_search($id, $q['all_ids'], true) + 1), 'text' => 'Launch #' . (array_search($id, $q['all_ids'], true) + 1)], array_slice($q['open_ids'], 0, 4)),
                [['label' => 'Not now', 'text' => 'Not now']]),
            'brand_intake' => [['label' => 'Use my website', 'text' => 'Skip, use my website']],
            'brand_summary' => [['label' => 'Save', 'text' => 'Save'], ['label' => 'Discard', 'text' => 'Discard']],
            'campaign_step' => [['label' => 'Done', 'text' => 'Done'], ['label' => 'Skip', 'text' => 'Skip this step']],
            default => [],
        };
    }

    /**
     * The owner's message answers the open question? Act on it and return what happened (for Sarah's reply and the
     * completion guards), or null to leave the message to the normal chat.
     * @return array{turn:string, note:string, verified:string[]}|null
     */
    public function handle(int $wsId, ?int $userId, string $text): ?array
    {
        $t = trim($text);
        if ($t === '' || mb_strlen($t) > 160) return null;
        $q = $this->openQuestion($wsId);
        if (! $q) return null;
        $yes = (bool) preg_match(self::YES, $t);
        $no = (bool) preg_match(self::NO, $t);
        if (($yes || $no) && ! $q['latest']) return null;   // a bare yes/no only answers Sarah's latest message
        $c = $q['card'];
        try {
            switch ($q['type']) {
                case 'watch_ask': {
                    $bizId = isset($c['business_id']) ? (int) $c['business_id'] : null;
                    $f = self::frequencyFrom($t);
                    if (! $f && $yes) $f = 'twice_weekly';
                    if ($f) {
                        $r = app(WatchService::class)->setup($wsId, $bizId, $f, ['trends' => 1, 'competitors' => 1, 'listening' => 1], $userId, 'chat');
                        if (! empty($r['success'])) return ['turn' => 'watch_on:' . $f, 'note' => '', 'verified' => ['monitoring', 'watching', 'market watch', 'competitors', 'trends']];
                    }
                    if ($no) { app(WatchService::class)->decline($wsId, $bizId); return ['turn' => 'reply', 'note' => 'The owner said not now to the market watch: nothing will be spent on it. Acknowledge in one short line and mention they can ask you to start it any time.', 'verified' => []]; }
                    return null;
                }
                case 'campaign_change': {
                    $id = (int) ($c['change_id'] ?? 0);
                    if ($yes || preg_match('/^\s*(approve|approved|update it|make the change|go with (it|that))\b/i', $t)) {
                        $r = app(SignalReactor::class)->applyChange($wsId, $id, (int) $userId, false);
                        if (! empty($r['success'])) return ['turn' => 'reply', 'note' => 'The owner approved your update to the campaign "' . ($c['campaign_title'] ?? '') . '" and it is applied. The changes, exactly: ' . implode('; ', array_map(fn ($x) => ucfirst((string) ($x['op'] ?? '')) . ' ' . ($x['title'] ?? '') . (isset($x['date']) ? ' (' . $x['date'] . ')' : ''), (array) ($c['changes'] ?? []))) . '. Confirm in one or two warm lines naming only these changes and what comes first; do not mention anything else.', 'verified' => ['campaign', 'update', 'updated', (string) ($c['campaign_title'] ?? '')]];
                        return ['turn' => 'reply', 'note' => 'The owner approved your campaign update but it could not be applied: ' . ($r['error'] ?? 'unknown') . '. Say so plainly in one line.', 'verified' => []];
                    }
                    if ($no) { app(SignalReactor::class)->declineChange($wsId, $id, (int) $userId, false); return ['turn' => 'reply', 'note' => 'The owner chose to keep the campaign "' . ($c['campaign_title'] ?? '') . '" as it is. Acknowledge in one line; you will learn from it.', 'verified' => []]; }
                    return null;
                }
                case 'campaign_ideas': {
                    $pick = null;
                    $ordinals = ['first' => 1, 'second' => 2, 'third' => 3, 'fourth' => 4, 'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4];
                    if (preg_match('/\b(?:launch|start|run|go with|do|pick|choose|option|number|idea|#)\s*(?:the\s+)?#?\s*(\d|first|second|third|fourth)\b/i', $t, $m) || preg_match('/^\s*#?(\d)\s*[.!]?\s*$/', $t, $m) || preg_match('/\b(first|second|third|fourth) one\b/i', $t, $m)) {
                        $n = ctype_digit($m[1]) ? (int) $m[1] : ($ordinals[strtolower($m[1])] ?? 0);
                        $pick = $q['all_ids'][$n - 1] ?? null;
                    } elseif (count($q['open_ids']) === 1 && ($yes || preg_match('/^\s*(launch|start|run) it\b/i', $t))) {
                        $pick = $q['open_ids'][0];
                    } elseif (preg_match('/\b(launch|start|run|go with)\b/i', $t)) {
                        foreach (DB::table('marketing_campaigns')->whereIn('id', $q['open_ids'])->get(['id', 'title']) as $cm) {
                            $words = array_filter(preg_split('/\W+/u', mb_strtolower($cm->title)), fn ($w) => mb_strlen($w) >= 5);
                            foreach ($words as $w) if (str_contains(mb_strtolower($t), $w)) { $pick = (int) $cm->id; break 2; }
                        }
                    }
                    if ($pick && in_array($pick, $q['open_ids'], true)) {
                        $title = (string) DB::table('marketing_campaigns')->where('id', $pick)->value('title');
                        \Illuminate\Support\Facades\Cache::put('campaign-launch-quiet:' . $pick, 1, now()->addMinutes(30));
                        $r = app(\App\Core\Campaigns\CampaignService::class)->launch($wsId, $pick, (int) $userId);
                        if (! empty($r['success'])) return ['turn' => 'reply', 'note' => 'The owner launched the campaign "' . $title . '" from your ideas; it is approved as one plan and your team runs each step on its date; each post still gets their OK before it goes out. Confirm in one or two warm lines.', 'verified' => ['launched', 'campaign', $title]];
                        return ['turn' => 'reply', 'note' => 'The owner asked to launch "' . $title . '" but it could not start: ' . ($r['error'] ?? 'unknown') . '. Say so plainly.', 'verified' => []];
                    }
                    if ($no || preg_match('/^\s*(none|none of them|neither)\b/i', $t)) {
                        foreach ($q['open_ids'] as $id) app(\App\Core\Campaigns\CampaignService::class)->decline($wsId, $id, (int) $userId, 'Not now (chat)');
                        return ['turn' => 'reply', 'note' => 'The owner passed on these campaign ideas for now. Acknowledge in one line, and ask in a few words what would suit them better so the next ideas fit.', 'verified' => []];
                    }
                    return null;
                }
                case 'brand_intake': {
                    $bp = app(\App\Core\Brand\BrandProfileService::class);
                    $bizId = isset($c['business_id']) ? (int) $c['business_id'] : null;
                    if ($no || preg_match('/\b(use (my|our) (website|site)|skip)\b/i', $t)) { $bp->skip($wsId, $bizId); return ['turn' => 'reply', 'note' => 'The owner skipped the design styles; you will work from their website. Acknowledge in one line; they can tell you styles any time.', 'verified' => []]; }
                    $ids = array_keys(\App\Core\Brand\DesignDirections::ALL);
                    $picks = [];
                    if (preg_match_all('/\b(10|[1-9])\b/', $t, $mm) && preg_match('/^[\s\d,&+.and]+$|^(i like|i\'?d like|pick|choose|go with|use|love)\b/i', $t)) foreach ($mm[1] as $n) { if (isset($ids[(int) $n - 1])) $picks[] = $ids[(int) $n - 1]; }
                    if (! $picks) foreach (\App\Core\Brand\DesignDirections::ALL as $id => $d) { if (str_contains(mb_strtolower($t), mb_strtolower($d['name']))) $picks[] = $id; }
                    if (! $picks) return null;
                    $r = $bp->setDirections($wsId, $bizId, array_slice(array_values(array_unique($picks)), 0, 4), [], 'chat');
                    if (! empty($r['success'])) return ['turn' => 'reply', 'note' => 'The owner chose these design styles and they are saved: ' . implode(', ', $r['names']) . '. Every banner, image and video will follow them. Confirm in one warm line, naming the styles.', 'verified' => array_merge(['styles', 'saved'], $r['names'])];
                    return null;
                }
                case 'brand_summary': {
                    $bp = app(\App\Core\Brand\BrandProfileService::class);
                    if ($yes || preg_match('/^\s*(save|save it|looks (good|right)|correct|that\'?s right)\b/i', $t)) {
                        $r = $bp->confirm($wsId, (string) ($c['token'] ?? ''));
                        if (! empty($r['success'])) return ['turn' => 'reply', 'note' => 'The owner confirmed the brand details you picked up and they are saved; every banner, image and video will use them. Confirm in one warm line.', 'verified' => ['brand', 'saved', 'styles', 'colours', 'colors']];
                        return null;
                    }
                    if ($no || preg_match('/^\s*(discard|wrong|that\'?s wrong)\b/i', $t)) { $bp->discard($wsId, (string) ($c['token'] ?? '')); return ['turn' => 'reply', 'note' => 'The owner discarded the brand summary; nothing was saved. Acknowledge in one line and invite them to tell you what is right.', 'verified' => []]; }
                    return null;
                }
                case 'campaign_step': {
                    $item = (int) ($q['meta']['item_id'] ?? 0);
                    $title = (string) DB::table('campaign_items')->where('id', $item)->value('title');
                    $title .= '" in the campaign "' . (string) DB::table('marketing_campaigns')->where('id', (int) ($q['meta']['campaign_id'] ?? 0))->value('title');   // name the right campaign
                    if ($yes || preg_match('/^\s*(done|did it|finished|all done|completed|sent( it)?|posted( it)?)\b/i', $t)) {
                        $r = app(\App\Core\Campaigns\CampaignService::class)->updateItem($wsId, $item, ['done' => true]);
                        if (! empty($r['success'])) return ['turn' => 'reply', 'note' => 'The owner finished their campaign step "' . $title . '" and it is marked done. Thank them in one warm line.', 'verified' => ['done', $title]];
                    }
                    if (preg_match('/^\s*(skip|skip (it|this( step)?)|can\'?t|won\'?t)\b/i', $t) || $no) {
                        $r = app(\App\Core\Campaigns\CampaignService::class)->updateItem($wsId, $item, ['skip' => true]);
                        if (! empty($r['success'])) return ['turn' => 'reply', 'note' => 'The owner skipped their campaign step "' . $title . '". Acknowledge in one line without judgement.', 'verified' => ['skipped', $title]];
                    }
                    return null;
                }
            }
        } catch (\Throwable $e) {
            Log::warning('[CHAT-FIRST-1] reply failed', ['ws' => $wsId, 'type' => $q['type'], 'e' => $e->getMessage()]);
        }
        return null;
    }
}
