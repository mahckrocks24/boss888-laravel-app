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
        if (!preg_match('/\b(publish|push\b.{0,30}\blive|make\b.{0,30}\blive|go live with)\b/', $t)) return false;
        if (preg_match('/["“”]/', $t)) return false;                       // a quoted title → the single-article path
        return (bool) preg_match('/\b(drafts?|articles?|posts?|them|them all|all of them|everything|the rest|the lot|pending ones|ready ones)\b/', $t);
    }

    public static function confirms(string $text): bool
    {
        return (bool) preg_match('/^\s*(yes|yeah|yep|yup|ok|okay|sure|go ahead|do it|confirm(ed)?|please do|publish (them|it)|proceed|approved?|go live)\b/i', trim($text));
    }

    public static function declines(string $text): bool
    {
        return (bool) preg_match('/^\s*(no|nope|don\'?t|do not|stop|not yet|hold|wait|leave (it|them)|never ?mind|cancel that)\b/i', trim($text));
    }

    /** @return array{ready:\Illuminate\Support\Collection, missing:\Illuminate\Support\Collection} */
    public function scope(int $wsId): array
    {
        $drafts = DB::table('articles')->where('workspace_id', $wsId)->where('status', 'draft')->whereNull('deleted_at')
            ->orderBy('updated_at')->get(['id', 'title', 'featured_image_url', 'website_id']);
        $ready = $drafts->filter(fn ($a) => trim((string) $a->featured_image_url) !== '')->values();
        $missing = $drafts->filter(fn ($a) => trim((string) $a->featured_image_url) === '')->values();
        return ['ready' => $ready, 'missing' => $missing];
    }

    public function describe(int $wsId, array $scope): string
    {
        $r = $scope['ready']->count(); $m = $scope['missing']->count();
        if ($r + $m === 0) return "There are no drafts to publish — everything you've written is already live.";
        $site = DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderBy('id')->value('name');
        $where = $site ? "on {$site}" : 'on your website';
        $s = "You have " . ($r + $m) . " draft" . ($r + $m === 1 ? '' : 's') . ". ";
        if ($r > 0) $s .= "{$r} " . ($r === 1 ? 'is' : 'are') . " ready to go live now" . ($m > 0 ? "; {$m} still " . ($m === 1 ? 'needs' : 'need') . " a featured image, which I'll generate first and publish as soon as it's attached." : ".");
        else $s .= "None can go live yet — all {$m} still need a featured image. I'll generate " . ($m === 1 ? 'it' : 'them') . " first and then publish.";
        $titles = $scope['ready']->take(3)->map(fn ($a) => '"' . mb_substr(trim((string) $a->title), 0, 60) . '"')->all();
        if ($titles) $s .= "\n\nFirst up: " . implode(', ', $titles) . ($r > 3 ? " and " . ($r - 3) . " more" : '') . '.';
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
        $rows = DB::table('articles')->where('workspace_id', $wsId)->whereIn('id', $ids)->where('status', 'draft')->get(['id', 'title', 'featured_image_url']);
        foreach ($rows as $a) {
            if (trim((string) $a->featured_image_url) === '') { $skipped++; continue; }
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
}
