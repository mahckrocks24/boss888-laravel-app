<?php

namespace App\Core\Sarah888;

use Illuminate\Support\Facades\Log;

/**
 * MULTISITE-2 (Owner 2026-09-23, "go" on the fan-out): one message that says "for EACH website" / "on all my sites" /
 * "both of them" runs the planned work on every target site in one turn. The planner still writes ONE plan for the
 * message; this expands it deterministically — every site-scoped task (and everything that depends on one) is cloned
 * per target site with website_id pinned, titles carry the site's name, depends_on is re-mapped inside each site's
 * copy. Tasks that do not touch a site (a social post, a report) are kept once.
 *
 * Targets: the sites the owner NAMED when two or more are named with each/both ("both Bakery and Cafe Two"); otherwise
 * every website in the workspace. A message naming exactly one site never fans out ("all the pages on Fable QA Bakery").
 */
final class FanOut
{
    public const MAX_SITES = 12;

    /** site-scoped actions: their tasks are cloned per target site */
    public const SITE_ACTIONS = [
        'write_article', 'generate_meta', 'generate_image_mini', 'generate_image', 'generate_image_high', 'aeo_enrich',
        'link_suggestions', 'insert_link', 'publish_article', 'fix_orphans', 'fill_missing_images',
        'ai_builder_action', 'update_page', 'publish_builder_page', 'generate_page', 'add_page_from_template', 'ask_arthur',
        'seo_audit', 'technical_audit', 'keyword_research',
    ];

    /** @return array<int,array> the target sites (2..MAX_SITES), or [] when the message is not a fan-out */
    public static function targets(string $message, array $sites, array $named): array
    {
        $t = ' ' . mb_strtolower(preg_replace('/\s+/', ' ', $message)) . ' ';
        $each = preg_match('/\b(each|every|all|both)\b[^.!?]{0,30}\b(websites|sites)\b/u', $t)
            || preg_match('/\b(each|every)\s+(website|site)\b/u', $t)
            || preg_match('/\b(all|both)\s+of\s+(them|these|those|my\s+(websites|sites)|the\s+(websites|sites)|our\s+(websites|sites))\b/u', $t)
            || preg_match('/\b(websites|sites)\b[^.!?]{0,12}\b(each|every|all|both)\b/u', $t)
            || preg_match('/\b(across|on|for)\s+(all|both|every)\s+(my|our|the)?\s*(websites|sites)\b/u', $t);
        if (! $each) return [];
        if (count($named) === 1) return [];                 // one site named: that site, no fan-out
        $set = count($named) >= 2 ? $named : $sites;
        $set = array_values(array_filter($set, fn ($s) => (int) ($s['id'] ?? 0) > 0));
        if (count($set) < 2) return [];
        if (count($set) > self::MAX_SITES) {
            Log::warning('[Sarah888] MULTISITE-2 fan-out capped', ['sites' => count($set), 'cap' => self::MAX_SITES]);
            $set = array_slice($set, 0, self::MAX_SITES);
        }
        return $set;
    }

    /** @return array{tasks:array, expanded:bool, reason:string} */
    public static function expand(array $tasks, array $targets): array
    {
        $n = count($tasks);
        $tasks = array_values($tasks);
        $fan = [];
        for ($i = 1; $i <= $n; $i++) {
            $t = $tasks[$i - 1];
            if (! is_array($t)) continue;
            if (in_array(strtolower((string) ($t['action'] ?? '')), self::SITE_ACTIONS, true)) $fan[$i] = true;
        }
        // everything that depends on a site-scoped task is part of its chain
        $changed = true;
        while ($changed) {
            $changed = false;
            for ($i = 1; $i <= $n; $i++) {
                if (isset($fan[$i]) || ! is_array($tasks[$i - 1])) continue;
                foreach ((array) ($tasks[$i - 1]['depends_on'] ?? []) as $d) {
                    if (isset($fan[(int) $d])) { $fan[$i] = true; $changed = true; break; }
                }
            }
        }
        if ($fan === []) return ['tasks' => $tasks, 'expanded' => false, 'reason' => 'no_site_scoped_tasks'];

        // the planner may already have written one root per site ("… for Bakery", "… for Cafe Two") — then there is nothing to multiply
        $rootsNaming = [];
        foreach ($fan as $i => $_) {
            $t = $tasks[$i - 1];
            if (! empty($t['depends_on'])) continue;
            $text = mb_strtolower(implode(' ', [(string) ($t['description'] ?? ''), (string) ($t['title'] ?? ''), (string) (($t['params']['title'] ?? '')), (string) (($t['params']['topic'] ?? ''))]));
            foreach ($targets as $s) {
                $name = mb_strtolower(trim((string) ($s['name'] ?? '')));
                if ($name !== '' && mb_strlen($name) >= 5 && mb_strpos($text, $name) !== false) { $rootsNaming[(int) $s['id']] = true; break; }
            }
        }
        if (count($rootsNaming) >= 2) return ['tasks' => $tasks, 'expanded' => false, 'reason' => 'planner_already_per_site'];

        $out = []; $map = [];
        // tasks that touch no site are kept once, first (a chain may depend on them)
        for ($i = 1; $i <= $n; $i++) {
            if (isset($fan[$i]) || ! is_array($tasks[$i - 1])) continue;
            $t = $tasks[$i - 1];
            $deps = [];
            foreach ((array) ($t['depends_on'] ?? []) as $d) { if (isset($map['0:' . (int) $d])) $deps[] = $map['0:' . (int) $d]; }
            $t['depends_on'] = $deps;
            $out[] = $t; $map['0:' . $i] = count($out);
        }
        foreach ($targets as $s) {
            $sid = (int) $s['id']; $sname = (string) ($s['name'] ?? '');
            for ($i = 1; $i <= $n; $i++) {
                if (! isset($fan[$i])) continue;
                $t = $tasks[$i - 1];
                $t['params'] = is_array($t['params'] ?? null) ? $t['params'] : [];
                $t['params']['website_id'] = $sid;
                unset($t['params']['page_id'], $t['params']['article_id']);   // ids from the plan belong to no particular site
                $t['fanout'] = ['website_id' => $sid, 'site' => $sname];
                foreach (['description', 'title'] as $k) {
                    if (! empty($t[$k]) && $sname !== '' && mb_stripos((string) $t[$k], $sname) === false) $t[$k] = (string) $t[$k] . ' — ' . $sname;
                }
                foreach (['title', 'topic'] as $k) {
                    if (! empty($t['params'][$k]) && $sname !== '' && mb_stripos((string) $t['params'][$k], $sname) === false) $t['params'][$k] = (string) $t['params'][$k] . ' — ' . $sname;
                }
                $deps = [];
                foreach ((array) ($t['depends_on'] ?? []) as $d) {
                    $key = isset($fan[(int) $d]) ? $sid . ':' . (int) $d : '0:' . (int) $d;
                    if (isset($map[$key])) $deps[] = $map[$key];
                }
                $t['depends_on'] = $deps;
                $out[] = $t; $map[$sid . ':' . $i] = count($out);
            }
        }
        Log::info('[Sarah888] MULTISITE-2 fan-out expanded', ['sites' => array_map(fn ($s) => (int) $s['id'], $targets), 'planned' => $n, 'expanded' => count($out)]);
        return ['tasks' => $out, 'expanded' => true, 'reason' => 'expanded'];
    }

    /** "Fable QA Bakery, Fable QA Cafe Two and Fable WP QA" */
    public static function names(array $targets): string
    {
        $names = array_values(array_filter(array_map(fn ($s) => (string) ($s['name'] ?? ''), $targets)));
        return count($names) > 1 ? implode(', ', array_slice($names, 0, -1)) . ' and ' . end($names) : (string) ($names[0] ?? '');
    }
}
