<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\DB;

/**
 * EV-1045 (2026-09-16) — which website does content work belong to?
 *
 * The Owner's own thread on a seven-site workspace: "Let's write 5 articles" ran with no website at all, and
 * "write for chef red" still attached nothing because the matcher wanted the full site name ("Chef Red Raymundo")
 * inside the message. Publishing then refused the drafts as "not attached to a website" and asked again.
 *
 * Resolution order, all deterministic, never a guess:
 *   1. a site the owner NAMED in this message (full name, its first two words, or its subdomain label);
 *   2. the only site, when the workspace has one;
 *   3. the site the app has open (site_url / X-Lgse-Active-Site), when it belongs to this workspace;
 *   4. otherwise null — the caller must ASK before creating content work.
 */
class ContentTarget
{
    public const CONTENT_ACTIONS = ['write_article', 'generate_meta', 'generate_image_mini', 'generate_image', 'aeo_enrich', 'link_suggestions', 'insert_link', 'publish_article', 'fix_orphans'];

    /** @return array<int, array{id:int,name:string,subdomain:?string,domain:?string}> */
    public static function sites(int $wsId): array
    {
        return DB::table('websites')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderBy('id')
            ->get(['id', 'name', 'subdomain', 'domain'])->map(fn ($w) => ['id' => (int) $w->id, 'name' => (string) $w->name, 'subdomain' => $w->subdomain, 'domain' => $w->domain])->all();
    }

    /** Sites the owner named in the text. Full name, first two words (≥ 6 chars together), or the subdomain label. */
    public static function namedIn(string $text, array $sites): array
    {
        $t = ' ' . preg_replace('/\s+/', ' ', mb_strtolower(trim($text))) . ' ';
        $t = str_replace(["'", '’'], '', $t);
        $out = [];
        foreach ($sites as $s) {
            $name = mb_strtolower(trim((string) ($s['name'] ?? '')));
            $name = str_replace(["'", '’'], '', $name);
            $cands = [];
            if (mb_strlen($name) >= 3) $cands[] = $name;
            $words = preg_split('/\s+/', $name) ?: [];
            if (count($words) >= 3) { $two = $words[0] . ' ' . $words[1]; if (mb_strlen($two) >= 6 && mb_strlen($words[0]) >= 3) $cands[] = $two; }
            foreach (['subdomain', 'domain'] as $k) {
                $h = mb_strtolower(trim((string) ($s[$k] ?? '')));
                if ($h === '') continue;
                $label = explode('.', $h)[0];
                if (mb_strlen($label) >= 5) { $cands[] = str_replace('-', ' ', $label); $cands[] = $label; }
            }
            foreach (array_unique($cands) as $c) {
                if (preg_match('/(^|[^a-z0-9])' . preg_quote($c, '/') . '([^a-z0-9]|$)/u', $t)) { $out[$s['id']] = ['site' => $s, 'len' => mb_strlen($c), 'full' => $c === $name, 'text' => $c]; break; }
            }
        }
        if ($out === []) return [];
        // "boss mac gym" names ONE of the three Boss Mac sites: a full-name match beats a prefix match, and the
        // longest match beats a shorter one, so sibling sites that share a prefix do not all light up.
        // RISK-0186 (2026-09-17): the "longest match wins" rule also dropped a DIFFERENT site named in full — "the sourdough
        // article for QA Signup Bakery and the vinyasa article for QA Harbour Yoga" resolved to the bakery alone (16 > 15
        // characters) and both pieces were written there (EV-1056). Only a match CONTAINED in another match (the prefix
        // sibling) gives way; distinct names all count, and several names is the caller's cue to bind per piece or ask.
        $full = array_filter($out, fn ($m) => $m['full']);
        if ($full) $out = $full;
        $out = array_filter($out, function ($m) use ($out) {
            foreach ($out as $o) { if ($o['text'] !== $m['text'] && str_contains($o['text'], $m['text'])) return false; }
            return true;
        });
        return array_values(array_map(fn ($m) => $m['site'], $out));
    }

    /** @return array{site:?array, sites:array, named:array, reason:string} */
    public static function resolve(int $wsId, string $message, string $uiSiteUrl = ''): array
    {
        $sites = self::sites($wsId);
        $named = self::namedIn($message, $sites);
        if (count($named) === 1) return ['site' => $named[0], 'sites' => $sites, 'named' => $named, 'reason' => 'named'];
        if (count($sites) === 1) return ['site' => $sites[0], 'sites' => $sites, 'named' => $named, 'reason' => 'only_site'];
        // RISK-0186 (2026-09-17): the owner named SEVERAL sites ("X for A and Y for B"). An explicit target exists for
        // each piece, so the site the app happens to have open is NOT a fallback — bindTask() reads the owner's words
        // per task and anything it cannot place is held and asked about (EV-1056: the yoga article landed on the bakery).
        if (count($named) > 1) return ['site' => null, 'sites' => $sites, 'named' => $named, 'reason' => 'multiple_named'];
        if ($uiSiteUrl !== '') {
            try {
                $id = (int) \App\Core\Tenancy\WebsiteScope::websiteForUrl($wsId, $uiSiteUrl);
                foreach ($sites as $s) { if ((int) $s['id'] === $id) return ['site' => $s, 'sites' => $sites, 'named' => $named, 'reason' => 'ui_site']; }
            } catch (\Throwable) {}
        }
        return ['site' => null, 'sites' => $sites, 'named' => $named, 'reason' => count($named) > 1 ? 'ambiguous' : 'none'];
    }

    /**
     * RISK-0186 (2026-09-17): bind ONE task to ONE of several sites the owner named, from the owner's own sentence.
     * The clause that ends at a site's mention belongs to that site ("the sourdough starters article for QA Signup
     * Bakery and the morning vinyasa article for QA Harbour Yoga"); a task whose title/topic/description shares
     * distinctive words with exactly one clause (strictly more than with any other) is bound there. Anything else is
     * null — the caller holds it and asks; it is never guessed and never falls back to the open site.
     *
     * @param  array $named   sites named in the message (from resolve()['named'])
     * @param  array $payload the task payload (title / topic / description / target_keyword / user_request)
     * @return array{site:?array, reason:string, scores:array}
     */
    public static function bindTask(string $message, array $named, array $payload): array
    {
        $t = ' ' . preg_replace('/\s+/', ' ', mb_strtolower(trim($message))) . ' ';
        $t = str_replace(["'", '’'], '', $t);
        // where does each named site appear? (earliest of its candidate spellings)
        $pos = [];
        foreach ($named as $s) {
            $name = str_replace(["'", '’'], '', mb_strtolower(trim((string) ($s['name'] ?? ''))));
            $cands = [$name];
            $words = preg_split('/\s+/', $name) ?: [];
            if (count($words) >= 3) { $two = $words[0] . ' ' . $words[1]; if (mb_strlen($two) >= 6 && mb_strlen($words[0]) >= 3) $cands[] = $two; }
            foreach (['subdomain', 'domain'] as $k) { $h = mb_strtolower(trim((string) ($s[$k] ?? ''))); if ($h === '') continue; $label = explode('.', $h)[0]; if (mb_strlen($label) >= 5) { $cands[] = str_replace('-', ' ', $label); $cands[] = $label; } }
            $best = null;
            foreach (array_unique($cands) as $c) {
                if ($c === '' || ! preg_match('/(^|[^a-z0-9])' . preg_quote($c, '/') . '([^a-z0-9]|$)/u', $t, $m, PREG_OFFSET_CAPTURE)) continue;
                $p = $m[0][1] + strlen($m[1][0]);
                if ($best === null || $p < $best[0]) $best = [$p, strlen($c)];
            }
            if ($best !== null) $pos[(int) $s['id']] = ['site' => $s, 'start' => $best[0], 'end' => $best[0] + $best[1]];
        }
        if (count($pos) < 2) return ['site' => null, 'reason' => count($pos) === 1 ? 'one_site_located' : 'sites_not_located', 'scores' => []];
        uasort($pos, fn ($a, $b) => $a['start'] <=> $b['start']);
        // the clause before each mention (and the tail after the last one) is that site's share of the order
        $clauses = []; $prevEnd = 0; $ids = array_keys($pos); $last = end($ids);
        foreach ($pos as $id => $p) { $clauses[$id] = substr($t, $prevEnd, max(0, $p['start'] - $prevEnd)); $prevEnd = $p['end']; }
        $clauses[$last] .= ' ' . substr($t, $prevEnd);
        $taskText = implode(' ', array_map(fn ($k) => (string) ($payload[$k] ?? ''), ['title', 'topic', 'description', 'target_keyword', 'keyword', 'subject']));
        $taskTokens = self::distinctiveTokens($taskText);
        if ($taskTokens === []) return ['site' => null, 'reason' => 'task_has_no_distinctive_words', 'scores' => []];
        $scores = [];
        foreach ($clauses as $id => $clause) { $scores[$id] = count(array_intersect($taskTokens, self::distinctiveTokens($clause))); }
        arsort($scores);
        $top = array_key_first($scores); $topScore = $scores[$top]; $second = array_slice(array_values($scores), 1, 1)[0] ?? 0;
        if ($topScore >= 1 && $topScore > $second) return ['site' => $pos[$top]['site'], 'reason' => 'clause_match', 'scores' => $scores];
        return ['site' => null, 'reason' => $topScore === 0 ? 'no_clause_matches' : 'clauses_tie', 'scores' => $scores];
    }

    /** words that tell one piece of content from another: ≥ 4 letters, not filler, lightly stemmed (plural s) */
    public static function distinctiveTokens(string $text): array
    {
        static $stop = ['article', 'articles', 'blog', 'blogs', 'post', 'posts', 'piece', 'pieces', 'content', 'draft', 'drafts', 'write', 'writes', 'written', 'create', 'publish', 'website', 'websites', 'site', 'sites',
            'please', 'ahead', 'both', 'each', 'with', 'from', 'that', 'this', 'then', 'them', 'they', 'have', 'will', 'should', 'would', 'about', 'into', 'onto', 'also', 'just', 'like', 'want', 'need', 'make', 'some', 'more',
            'your', 'their', 'what', 'when', 'where', 'which', 'guide', 'beginner', 'beginners', 'only', 'first', 'short', 'long', 'good', 'best', 'ready', 'now', 'here', 'there', 'over', 'under', 'again', 'other', 'another', 'every'];
        $words = preg_split('/[^a-z0-9]+/', mb_strtolower($text)) ?: [];
        $out = [];
        foreach ($words as $w) {
            if (mb_strlen($w) < 4 || in_array($w, $stop, true)) continue;
            $w = preg_replace('/(ies)$/', 'y', $w); $w = preg_replace('/(?<=[a-z]{3})s$/', '', $w);
            if (mb_strlen($w) >= 4 && ! in_array($w, $stop, true)) $out[$w] = true;
        }
        return array_keys($out);
    }

    /** The question Sarah asks about ONE piece she could not place among the sites the owner named. */
    public static function askWhichFor(string $title, array $named): string
    {
        $names = array_map(fn ($s) => (string) $s['name'], $named);
        $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' or ' . end($names) : (string) ($names[0] ?? 'your website');
        return "Before I write \"{$title}\": which website should it go on — {$list}? Tell me the name and I will start it.";
    }

    /** The question Sarah asks instead of writing for nobody. */
    public static function askWhich(array $sites, array $named = []): string
    {
        $names = array_map(fn ($s) => (string) $s['name'], count($named) > 1 ? $named : $sites);
        $list = count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' or ' . end($names) : (string) ($names[0] ?? 'your website');
        return "Before I write anything: which website should this go on? You have " . count($names) . " — " . $list . ". Tell me the name and I will start.";
    }
}
