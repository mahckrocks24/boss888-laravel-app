<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * PUBLISH-1 (2026-08-30, Owner): "publish the drafts" is answered deterministically, in two turns.
 *
 * Chef Red, 2026-08-29: the owner said "publish them" and Sarah — reading executive material about 55 failed tasks —
 * refused ("I can't publish until the blocked tasks are resolved") and queued a retry instead. Failed tasks never
 * block publishing. Now: turn 1 states exactly what would go live (how many drafts are ready, how many still need a
 * featured image), what publishing means, and asks for a yes; turn 2 yes creates the publish tasks (owner-confirmed,
 * so they run without a second approval) and starts images for the rest.
 */
class DraftPublishing
{
    public const TTL_MIN = 15;

    public static function key(int $wsId): string { return 'sarah:publishq:ws' . $wsId; }

    /** "publish the drafts / all articles / them / everything" — not a single named article (that keeps the normal path). */
    public static function asks(string $text): bool
    {
        $t = mb_strtolower($text);
        if (!preg_match('/\b(publish|push\b.{0,30}\blive|make\b.{0,30}\blive|go live with|go live)\b/', $t)) return false;
        if (preg_match('/["“”]/', $t)) return false;                       // a quoted title → the single-article path
        // RISK-0186 (2026-09-17): "write one article about X and publish it on Y" is an order to CREATE content (the publish
        // step stays gated on the owner's go-ahead once the draft exists) — not a request to publish what is already drafted
        // (EV-1056: it was answered "There are no drafts to publish" and nothing was written).
        if (self::ordersCreation($t)) return false;
        // PUBLISH-3: a negation before the verb is the opposite request — "don't publish yet", "hold off publishing".
        if (preg_match('/\b(don\'?t|do not|never|stop|hold off|not)\b[^.?!]{0,24}\b(publish|live)\b/', $t)) return false;
        if (preg_match('/\b(drafts?|articles?|posts?|them|them all|all of them|everything|the rest|the lot|pending ones|ready ones)\b/', $t)) return true;
        // PUBLISH-3: in real speech the object is usually implied — "check if you can publish now", "can you publish?".
        if (preg_match('/\bpublish(ing)?\b[^a-z0-9]{0,4}(now|yet|already|it)?\s*[?.!]*$/', $t)) return true;
        // ...or it is a count: "publish the 37".
        if (preg_match('/\bpublish\b[^.?!]{0,24}\b\d{1,4}\b/', $t)) return true;
        return false;
    }

    public static function confirms(string $text): bool
    {
        return (bool) preg_match('/^\s*(yes|yeah|yep|yup|ok|okay|sure|go ahead|do it|confirm(ed)?|please do|publish (them|it)|proceed|approved?|go live)\b/i', trim($text));
    }

    /**
     * A REFINEMENT of an offer already on the table is still an instruction.
     *
     * Chef Red, 2026-09-01: the owner said "okay. publish 5 right now", Sarah described the 38 drafts and asked
     * for a yes. He then said "only 5 as i said" and "the earlist ones written". Neither begins with a yes-word,
     * so confirms() answered false both times and she described the same thing again. Three clear instructions,
     * zero articles published, and by the third reply she was inventing article numbers to fill the silence.
     *
     * A person narrowing an offer has plainly accepted it — the narrowing IS the consent, and it carries the
     * constraint with it. This only ever applies when an offer is actually pending; with nothing on the table
     * "the earliest ones" means nothing and is left to the normal path.
     *
     * @return array{limit:?int, order:?string}|null null when the text is not a refinement
     */
    public static function refines(string $text): ?array
    {
        $t = mb_strtolower(trim($text));
        if ($t === '') {
            return null;
        }

        // A decline is never a refinement, however it is phrased.
        if (self::declines($t)) {
            return null;
        }

        $limit = null;
        $order = null;

        // "only 5", "just 5", "publish 5", and also "just the oldest 3" where two words sit between the
        // cue and the number. Bounded to a short span and never across a sentence end, so a count in one
        // sentence cannot bind to a number in the next.
        if (preg_match('/(?:only|just|first|publish|do|start with)[^.?!]{0,20}?(\d{1,4})/u', $t, $m)) {
            $limit = (int) $m[1];
        } elseif (preg_match('/(\d{1,4})\s+(?:only|of them|of those|for now|to start)/u', $t, $m)) {
            $limit = (int) $m[1];
        }

        // "the earlist ones written" — the owner's own spelling is matched deliberately.
        if (preg_match('/(earliest|earlist|oldest|first (?:ones|written|few)|from the start)/u', $t)) {
            $order = 'earliest';
        } elseif (preg_match('/(latest|newest|most recent|last (?:ones|few))/u', $t)) {
            $order = 'latest';
        }

        if ($limit === null && $order === null) {
            return null;
        }

        return ['limit' => $limit, 'order' => $order];
    }

    /**
     * Apply a refinement to the offer already described, so the owner gets what they narrowed it to.
     *
     * The pending set is the authority on WHICH articles are eligible — the refinement may only reorder it and
     * cut it short. Nothing new can enter here, so a narrowing can never widen what was offered.
     */
    public function narrow(int $wsId, array $pending, array $refine): array
    {
        $ids = array_values(array_map('intval', (array) ($pending['ready'] ?? [])));
        if (! $ids) {
            return $pending;
        }

        if (($refine['order'] ?? null) !== null) {
            $rows = DB::table('articles')
                ->where('workspace_id', $wsId)
                ->whereIn('id', $ids)
                ->orderBy('created_at', $refine['order'] === 'latest' ? 'desc' : 'asc')
                ->pluck('id')->all();

            if ($rows) {
                $ids = array_map('intval', $rows);
            }
        }

        if (($refine['limit'] ?? null) !== null && $refine['limit'] > 0) {
            $ids = array_slice($ids, 0, $refine['limit']);
        }

        $pending['ready'] = $ids;

        // Images are only started for what is actually going live now.
        if (($refine['limit'] ?? null) !== null) {
            $pending['missing'] = [];
        }

        return $pending;
    }

    public static function declines(string $text): bool
    {
        return (bool) preg_match('/^\s*(no|nope|don\'?t|do not|stop|not yet|hold|wait|leave (it|them)|never ?mind|cancel that)\b/i', trim($text));
    }

    /** @return array{ready:\Illuminate\Support\Collection, missing:\Illuminate\Support\Collection} */
    public function scope(int $wsId): array
    {
        $drafts = DB::table('articles')->where('workspace_id', $wsId)->where('status', 'draft')->whereNull('deleted_at')
            ->orderBy('updated_at')->get(['id', 'title', 'featured_image_url', 'website_id', 'brief_json']);
        // INC-0007: task-shaped titles never ride a bulk publish; unattached drafts in a multi-site workspace neither.
        $__sites = \App\Core\Tenancy\WebsiteScope::idsIn($wsId);
        $__qaRejected = function ($a): bool { $b = json_decode((string) ($a->brief_json ?? ''), true) ?: []; return in_array($b['qa']['status'] ?? '', ['rejected', 'needs_owner'], true); }; // SARAH-QA-1
        $suspect = $drafts->filter(fn ($a) => ArticleTopicGuard::looksLikeTask((string) $a->title) || $__qaRejected($a))->values();
        $rest = $drafts->reject(fn ($a) => ArticleTopicGuard::looksLikeTask((string) $a->title) || $__qaRejected($a));
        $unattached = count($__sites) > 1 ? $rest->filter(fn ($a) => empty($a->website_id))->values() : collect();
        if (count($__sites) > 1) $rest = $rest->reject(fn ($a) => empty($a->website_id));
        $ready = $rest->filter(fn ($a) => trim((string) $a->featured_image_url) !== '')->values();
        $missing = $rest->filter(fn ($a) => trim((string) $a->featured_image_url) === '')->values();
        return ['ready' => $ready, 'missing' => $missing, 'suspect' => $suspect, 'unattached' => $unattached];
    }

    public function describe(int $wsId, array $scope): string
    {
        $r = $scope['ready']->count(); $m = $scope['missing']->count();
        $__sus0 = $scope['suspect'] ?? collect(); $__un0 = $scope['unattached'] ?? collect();
        if ($r + $m === 0 && $__sus0->count() + $__un0->count() === 0) return "There are no drafts to publish — everything you've written is already live.";
        if ($r + $m === 0) { // INC-0007: drafts exist but none may go live in a batch — say exactly why
            $s0 = 'You have ' . ($__sus0->count() + $__un0->count()) . ' draft' . ($__sus0->count() + $__un0->count() === 1 ? '' : 's') . ', but none can go live in a batch.';
            if ($__sus0->count() > 0) $s0 .= ' ' . $__sus0->count() . ' read' . ($__sus0->count() === 1 ? 's' : '') . ' like a task or a note rather than an article (' . $__sus0->take(4)->map(fn ($a) => '"' . mb_substr(trim((string) $a->title), 0, 40) . '"')->implode(', ') . ($__sus0->count() > 4 ? ', …' : '') . ') — delete them from Advanced → Write, or ask me to publish one by name.';
            if ($__un0->count() > 0) $s0 .= ' ' . $__un0->count() . ' ' . ($__un0->count() === 1 ? 'is' : 'are') . ' not attached to any of your websites (' . $__un0->take(4)->map(fn ($a) => '"' . mb_substr(trim((string) $a->title), 0, 40) . '"')->implode(', ') . ($__un0->count() > 4 ? ', …' : '') . ') — tell me which site each belongs on and I will publish it there.';
            return $s0;
        }
        // INC-0006: only name a site when the business has exactly one. Taking the oldest row made
        // Sarah tell a multi-site owner their drafts were going live on a site that was not the one
        // she was about to publish to.
        $__siteIds = \App\Core\Tenancy\WebsiteScope::idsIn($wsId);
        $site = count($__siteIds) === 1
            ? DB::table('websites')->where('id', $__siteIds[0])->value('name')
            : null;
        $where = $site ? "on {$site}" : (count($__siteIds) > 1 ? 'on the site each one belongs to' : 'on your website');
        $s = "You have " . ($r + $m) . " draft" . ($r + $m === 1 ? '' : 's') . ". ";
        if ($r > 0) $s .= "{$r} " . ($r === 1 ? 'is' : 'are') . " ready to go live now" . ($m > 0 ? "; {$m} still " . ($m === 1 ? 'needs' : 'need') . " a featured image, which I'll generate first and publish as soon as it's attached." : ".");
        else $s .= "None can go live yet — all {$m} still need a featured image. I'll generate " . ($m === 1 ? 'it' : 'them') . " first and then publish.";
        // INC-0007: the owner must SEE what goes live — every title up to 12, then the first 5 and a count.
        $__show = $r <= 12 ? $r : 5;
        $titles = $scope['ready']->take($__show)->map(fn ($a) => '"' . mb_substr(trim((string) $a->title), 0, 70) . '"')->all();
        if ($titles) $s .= "\n\n" . ($r <= 12 ? 'Going live: ' : 'First up: ') . implode(', ', $titles) . ($r > $__show ? " and " . ($r - $__show) . " more" : '') . '.';
        $__sus = $scope['suspect'] ?? collect(); $__un = $scope['unattached'] ?? collect();
        if ($__sus->count() > 0) $s .= "\n\nLeft out: " . $__sus->count() . ' draft' . ($__sus->count() === 1 ? '' : 's') . ' that read like tasks or notes rather than articles (' . $__sus->take(4)->map(fn ($a) => '"' . mb_substr(trim((string) $a->title), 0, 40) . '"')->implode(', ') . ($__sus->count() > 4 ? ', …' : '') . '). They will not go live in this batch — delete them from Advanced → Write, or ask me to publish one by name.';
        if ($__un->count() > 0) $s .= "\n\nAlso left out: " . $__un->count() . ' draft' . ($__un->count() === 1 ? '' : 's') . ' not attached to any of your websites — tell me which site each belongs on first.';
        $s .= "\n\nWhat this means: " . ($r > 0 ? "those {$r} go live {$where} straight away and become visible to visitors and search engines. " : '')
            . "Publishing costs no credits" . ($m > 0 ? "; each featured image costs 2 credits (" . ($m * 2) . " in total)" : '') . ". You can unpublish any of them later from Advanced → Write."
            . "\n\nSay **yes** to publish" . ($r > 0 && $m > 0 ? " the {$r} and start the images" : '') . ", or **no** to leave them as drafts.";
        return $s;
    }

    public function remember(int $wsId, array $scope, string $ownerText): void
    {
        Cache::put(self::key($wsId), ['ready' => $scope['ready']->pluck('id')->map(fn ($i) => (int) $i)->all(), 'missing' => $scope['missing']->pluck('id')->map(fn ($i) => (int) $i)->all(), 'asked_at' => time(), 'owner_text' => $ownerText], now()->addMinutes(self::TTL_MIN));
    }

    public function pending(int $wsId): ?array { $v = Cache::get(self::key($wsId)); return is_array($v) ? $v : null; }

    public function forget(int $wsId): void { Cache::forget(self::key($wsId)); }

    /** @return array{published_queued:int, images_started:int, skipped:int, errors:array<int,string>} */
    public function execute(int $wsId, array $pending, ?int $userId, string $ownerText): array
    {
        $queued = 0; $skipped = 0; $errors = [];
        // The owner's yes to a concrete, described offer IS the commission: the bare word "yes" classifies as a
        // statement on its own, and TaskService would refuse (UNCOMMISSIONED_TURN). Bind the turn before creating.
        try {
            app(SpendContext::class)->setTurn(['specifies_action' => true, 'authorized' => true, 'classification' => 'authorisation',
                'reason' => 'the owner said yes to Sarah\'s publish offer (PUBLISH-1)'], $wsId);
        } catch (\Throwable $e) { /* non-fatal */ }
        $ids = array_map('intval', (array) ($pending['ready'] ?? []));
        $rows = DB::table('articles')->where('workspace_id', $wsId)->whereIn('id', $ids)->where('status', 'draft')->get(['id', 'title', 'featured_image_url', 'website_id']);
        $__sites = \App\Core\Tenancy\WebsiteScope::idsIn($wsId);
        foreach ($rows as $a) {
            if (trim((string) $a->featured_image_url) === '') { $skipped++; continue; }
            if (ArticleTopicGuard::looksLikeTask((string) $a->title)) { $skipped++; $errors[] = 'article ' . $a->id . ': ' . ArticleTopicGuard::reason((string) $a->title); continue; } // INC-0007: never in a bulk publish
            if (empty($a->website_id) && count($__sites) === 1) DB::table('articles')->where('id', $a->id)->update(['website_id' => $__sites[0]]); // INC-0007: attach to the only site
            elseif (empty($a->website_id) && count($__sites) > 1) { $skipped++; $errors[] = 'article ' . $a->id . ': not attached to a website'; continue; }
            try {
                app(\App\Core\TaskSystem\TaskService::class)->create($wsId, [
                    'engine' => 'write', 'action' => 'publish_article', 'source' => 'agent', 'assigned_agents' => ['priya'],
                    'auto_approve' => true, 'requires_approval' => false, 'user_confirmed' => true, 'credit_cost' => 0, 'priority' => 'normal',
                    'payload' => ['article_id' => (int) $a->id, 'title' => 'Publish "' . mb_substr((string) $a->title, 0, 80) . '"', 'created_via' => 'sarah_router', 'user_request' => $ownerText],
                ]);
                $queued++;
            } catch (\Throwable $e) {
                $errors[] = 'article ' . $a->id . ': ' . $e->getMessage();
                Log::warning('[Sarah888] PUBLISH-1 could not queue a publish', ['ws' => $wsId, 'article' => $a->id, 'error' => $e->getMessage()]);
            }
        }
        $skipped += count($ids) - $rows->count();
        $images = 0;
        if (!empty($pending['missing'])) {
            try { $fill = app(\App\Engines\Write\Services\WriteService::class)->fillMissingImages($wsId, ['limit' => min(15, count($pending['missing']))]); $images = (int) ($fill['created'] ?? 0); }
            catch (\Throwable $e) { $errors[] = 'images: ' . $e->getMessage(); }
        }
        Log::info('[Sarah888] PUBLISH-1 executed', ['ws' => $wsId, 'user' => $userId, 'queued' => $queued, 'images' => $images, 'skipped' => $skipped]);
        return ['published_queued' => $queued, 'images_started' => $images, 'skipped' => $skipped, 'errors' => $errors];
    }

    public function report(array $res, int $missingCount): string
    {
        $q = $res['published_queued']; $i = $res['images_started'];
        $s = $q > 0 ? "Done — Priya is publishing {$q} article" . ($q === 1 ? '' : 's') . " now; " . ($q === 1 ? "it'll" : "they'll") . " be live in a moment and you'll see " . ($q === 1 ? 'it' : 'them') . " under Results." : "Nothing was published";
        if ($i > 0) $s .= ($q > 0 ? ' ' : ' — ') . "I've started featured images for {$i} draft" . ($i === 1 ? '' : 's') . "; say \"publish the rest\" once " . ($i === 1 ? "it's" : "they're") . " attached.";
        elseif ($missingCount > 0 && $q === 0) $s .= " — the remaining drafts still need a featured image and I couldn't start those images. Say \"add the missing images\" and I'll try again.";
        if ($res['skipped'] > 0) $s .= " {$res['skipped']} " . ($res['skipped'] === 1 ? 'draft was' : 'drafts were') . " no longer publishable (already published, deleted, or lost its image) and " . ($res['skipped'] === 1 ? 'was' : 'were') . " left alone.";
        if ($res['errors']) $s .= " " . count($res['errors']) . " couldn't be queued (logged for the team).";
        return $s;
    }
    /**
     * B2 (2026-09-25 certification): is this an order to CREATE content, whatever the word order?
     *
     * RISK-0186's guard required "publish" to FOLLOW the content noun, so it caught
     * "write one article about X and publish it" and walked straight past
     * "write and publish an article about X" — the more natural phrasing of the same order.
     * Both were then handled as "publish what is already drafted" and nothing was written
     * (EV-1056, and again in the 2026-09-25 Sarah certification).
     *
     * Three signals, none of which can be true of drafts that already exist:
     *   a creation verb applied to a content noun, in either order;
     *   a subject — you cannot name a topic for something already written;
     *   "a new <content noun>".
     */
    private static function ordersCreation(string $t): bool
    {
        $verb    = '(?:write|create|produce|prepare|compose|generate|author|draft)';
        $content = '(?:articles?|posts?|blogs?|blog posts?|pieces?|guides?|content)';

        // "write … article", "write and publish an article". Excludes "draft" used as an
        // ADJECTIVE — "publish my draft articles" names existing work, it does not order new work.
        if (preg_match('/\b' . $verb . '\b[^.?!]{0,80}\b' . $content . '\b/u', $t)
            && ! preg_match('/\bdraft\s+' . $content . '\b/u', $t)) return true;

        // "publish a new article about X" — no creation verb, but a subject gives it away.
        if (preg_match('/\b' . $content . '\b[^.?!]{0,40}\b(?:about|on|covering|regarding)\b\s*\S/u', $t)) return true;

        // "publish a new post" — "new" cannot describe something already drafted.
        if (preg_match('/\bnew\b[^.?!]{0,20}\b' . $content . '\b/u', $t)) return true;

        // "draft this and put it live" — a creation verb on a demonstrative object.
        if (preg_match('/\b' . $verb . '\b\s+(?:this|that|it)\b/u', $t)) return true;

        return false;
    }
}
