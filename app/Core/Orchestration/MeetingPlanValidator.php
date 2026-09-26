<?php

namespace App\Core\Orchestration;

use Illuminate\Support\Facades\DB;

/**
 * PLAN-CHECK-1 (2026-09-26, Owner: "i tried running meeting with the team for strategy but all the tasks failed").
 *
 * A meeting's synthesis is turned into tasks by an LLM. Until now nothing checked those tasks before the
 * customer approved them, so a plan could carry an action that is not built (seo/keyword_check), an article
 * named by a word instead of its number ("dinner-party"), or "Facebook, Instagram" as ONE platform — each
 * approved, each failed at run time. This class is the check between extraction and approval:
 *
 *   context()  — the facts the planner must choose from: runnable actions, this business's articles, platforms.
 *   validate() — every task is runnable as written, or it is reported with a reason in plain words.
 *
 * It never invents a target. It only normalises what is unambiguous (engine for a unique action, integer
 * strings, one task per named platform) and leaves every judgement to the planner's repair pass.
 */
class MeetingPlanValidator
{
    /** In the catalog, but their handlers return "not yet wired" (Orchestrator stub handlers). */
    public const NOT_WIRED = ['seo/keyword_check', 'seo/keyword_research', 'seo/keywords_suggest', 'seo/generate_links'];

    public const PLATFORMS = ['facebook', 'instagram', 'linkedin', 'tiktok', 'twitter'];

    /** Catalog aliases that run the same handler but are not registered capabilities. */
    public const ALIASES = ['social/create_post' => 'social/social_create_post'];

    private const PLATFORM_ALIASES = ['x' => 'twitter', 'x.com' => 'twitter', 'fb' => 'facebook', 'ig' => 'instagram', 'insta' => 'instagram', 'li' => 'linkedin'];

    /** Actions that produce an article whose id a later task can inherit through parent_task_id. */
    public const ARTICLE_PRODUCERS = ['write/create_article', 'write/write_article', 'seo/write_article'];

    public function catalog(): array
    {
        $all = \App\Core\TaskSystem\Orchestrator::dispatchableActions();
        foreach (self::NOT_WIRED as $k) unset($all[$k]);
        // PLAN-CHECK-1c: TaskService refuses an action with no capability row (UNMAPPED_ACTION) or a forbidden one;
        // such an entry can be planned but never created, so it is not offered.
        try {
            $cap = app(\App\Core\EngineKernel\CapabilityMapService::class);
            $auth = app(\App\Core\Sarah888\ActionAuthority::class);
            foreach (array_keys($all) as $k) {
                $act = explode('/', $k, 2)[1];
                if ($cap->resolve($act) === null || $auth->isForbiddenAction($act)) unset($all[$k]);
            }
        } catch (\Throwable $e) { /* registry unavailable: creation-time checks still apply */ }
        return $all;
    }

    public function context(int $wsId, ?int $businessId = null, int $articleLimit = 80): array
    {
        $q = DB::table('articles as a')->where('a.workspace_id', $wsId)->whereNull('a.deleted_at');
        if ($businessId) {
            $siteIds = DB::table('websites')->where('workspace_id', $wsId)->where('business_id', $businessId)->whereNull('deleted_at')->pluck('id')->all();
            if ($siteIds) $q->whereIn('a.website_id', $siteIds);
        }
        $articles = $q->orderByDesc('a.id')->limit($articleLimit)->get(['a.id', 'a.title', 'a.status'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'title' => (string) $r->title, 'status' => (string) $r->status])->all();
        $connected = DB::table('social_accounts')->where('workspace_id', $wsId)->where('status', 'connected')
            ->pluck('platform')->map(fn ($p) => strtolower((string) $p))->unique()->values()->all();

        // PLAN-CHECK-1b: only actions THIS workspace can run. The same gate the Orchestrator applies at run time
        // (PlanGatingService::check with the bare action), and Search Console actions only once it is connected.
        $catalog = $this->catalog(); $blocked = [];
        try {
            $gate = app(\App\Core\PlanGating\PlanGatingService::class);
            foreach (array_keys($catalog) as $k) {
                $c = $gate->check($wsId, explode('/', $k, 2)[1]);
                if (empty($c['allowed'])) $blocked[$k] = 'is not included in this workspace\'s plan (' . ($c['reason'] ?? 'plan limit') . ')';
            }
        } catch (\Throwable $e) { /* gate unavailable: run-time gating still applies */ }
        $gsc = false;
        try {
            $conn = app(\App\Engines\SEO\Services\GscClient::class)->getConnection($wsId);
            $gsc = $conn !== null && ! empty($conn->connected) && ! empty($conn->site_url);
        } catch (\Throwable $e) { $gsc = false; }
        if (! $gsc) foreach (array_keys($catalog) as $k) if (str_starts_with($k, 'seo/gsc_') && ! isset($blocked[$k])) $blocked[$k] = 'needs Google Search Console connected first';
        foreach (array_keys($blocked) as $k) unset($catalog[$k]);

        return ['ws' => $wsId, 'business_id' => $businessId, 'articles' => $articles, 'connected_platforms' => $connected,
            'gsc_connected' => $gsc, 'catalog' => $catalog, 'blocked' => $blocked];
    }

    /** The planner-facing text for the context: numbered articles and platforms. */
    public function promptBlock(array $ctx): string
    {
        $lines = [];
        foreach ($ctx['articles'] as $a) $lines[] = "  #{$a['id']}  {$a['title']}" . ($a['status'] !== 'published' ? "  ({$a['status']})" : '');
        return "THIS BUSINESS'S ARTICLES (article_id is the number after #; use ONLY these numbers):\n"
            . ($lines ? implode("\n", $lines) : '  (none yet)') . "\n\n"
            . "SOCIAL PLATFORMS (exactly one per task): " . implode(', ', self::PLATFORMS)
            . ($ctx['connected_platforms'] ? ' — connected now: ' . implode(', ', $ctx['connected_platforms']) : ' — none connected yet') . "\n"
            . 'GOOGLE SEARCH CONSOLE: ' . (! empty($ctx['gsc_connected']) ? 'connected' : 'NOT connected — do not plan Search Console tasks') . "\n\n";
    }

    /**
     * @return array{tasks: array, issues: array<int, array{index:int, action:string, problem:string, hard:bool}>}
     *   tasks  — every task that is runnable as written (platform fan-out applied, `after` re-indexed)
     *   issues — hard: the task cannot run and is not in `tasks`; soft: it runs, but the plan does less than it says
     */
    public function validate(array $tasks, array $ctx): array
    {
        $catalog = $ctx['catalog'];
        $articleIds = array_flip(array_map(fn ($a) => $a['id'], $ctx['articles']));
        $byAction = [];
        foreach (array_keys($catalog) as $k) { [$e, $a] = explode('/', $k, 2); $byAction[$a][] = $e; }

        $issues = []; $kept = []; $map = [];   // $map: input index => first output index
        foreach (array_values($tasks) as $i => $t) {
            if (! is_array($t) || empty($t['action'])) { $issues[] = $this->issue($i, '?', 'has no action', true); continue; }
            $engine = strtolower(trim((string) ($t['engine'] ?? ''))); $action = strtolower(trim((string) $t['action']));
            $key = "{$engine}/{$action}";
            if (isset(self::ALIASES[$key])) { $key = self::ALIASES[$key]; [$engine, $action] = explode('/', $key, 2); }
            if (in_array($key, self::NOT_WIRED, true)) { $issues[] = $this->issue($i, $key, 'is not built yet, so it cannot run', true); continue; }
            if (isset($ctx['blocked'][$key])) { $issues[] = $this->issue($i, $key, $ctx['blocked'][$key], true); continue; }
            if (! isset($catalog[$key])) {
                $engines = array_values(array_diff($byAction[$action] ?? [], []));
                if (count($engines) === 1) { $engine = $engines[0]; $key = "{$engine}/{$action}"; }
                else { $issues[] = $this->issue($i, $key, 'is not an action the team can run', true); continue; }
            }
            $params = (isset($t['params']) && is_array($t['params'])) ? $t['params'] : [];
            foreach ($params as $pk => $pv) {
                if (! is_string($pk) || preg_match('/^(no[ _]?params|none|n\/a)$/i', $pk)) unset($params[$pk]);
                elseif (is_string($pv) && trim($pv) === '') unset($params[$pk]);
            }
            $hasParent = isset($t['after']) && is_numeric($t['after']) && (int) $t['after'] >= 0 && (int) $t['after'] < $i
                && in_array($this->keyOf($tasks[(int) $t['after']] ?? []), self::ARTICLE_PRODUCERS, true);

            // article_id: a number from this business's articles, or inherited from an earlier article-producing task.
            if (array_key_exists('article_id', $params)) {
                $raw = $params['article_id'];
                if (is_string($raw) && preg_match('/^#?(\d+)$/', trim($raw), $m)) $raw = (int) $m[1];
                if (! is_int($raw) || ! isset($articleIds[$raw])) {
                    if ($hasParent) { unset($params['article_id']); }
                    else { $issues[] = $this->issue($i, $key, 'names article ' . mb_substr((string) json_encode($params['article_id'], JSON_UNESCAPED_UNICODE), 0, 60) . ', which is not one of this business\'s articles', true); continue; }
                } else { $params['article_id'] = $raw; }
            }
            // other *_id params must at least be numbers.
            foreach ($params as $pk => $pv) {
                if ($pk !== 'article_id' && preg_match('/_id$/', (string) $pk)) {
                    if (is_string($pv) && preg_match('/^\d+$/', trim($pv))) $params[$pk] = (int) $pv;
                    elseif (! is_int($params[$pk])) { $issues[] = $this->issue($i, $key, "gives {$pk} as " . mb_substr((string) json_encode($pv, JSON_UNESCAPED_UNICODE), 0, 40) . ', which is not an id', true); continue 2; }
                }
            }
            // required params from the catalog hint ("a (required)", "a or b (required)").
            $missing = [];
            foreach ($this->requiredGroups((string) ($catalog[$key]['params_hint'] ?? '')) as $group) {
                $ok = false;
                foreach ($group as $name) { if (isset($params[$name]) && $params[$name] !== '' && $params[$name] !== []) { $ok = true; break; } }
                if (! $ok && ! ($hasParent && in_array('article_id', $group, true))) $missing[] = implode(' or ', $group);
            }
            if ($missing) { $issues[] = $this->issue($i, $key, 'is missing ' . implode(', ', $missing), true); continue; }

            // platforms: one task per named platform.
            $platforms = [null];
            if ($engine === 'social' && array_key_exists('platform', $params)) {
                $platforms = $this->platforms((string) (is_array($params['platform']) ? implode(',', $params['platform']) : $params['platform']));
                if (! $platforms) { $issues[] = $this->issue($i, $key, 'names platform ' . mb_substr((string) json_encode($params['platform'], JSON_UNESCAPED_UNICODE), 0, 40) . ', which is not one the team posts to', true); continue; }
            }
            $map[$i] = count($kept);
            foreach ($platforms as $p) {
                $out = $t; $out['engine'] = $engine; $out['action'] = $action; $out['params'] = $params;
                if ($p !== null) $out['params']['platform'] = $p;
                $out['_after_in'] = $hasParent ? (int) $t['after'] : null;
                unset($out['after']);
                $kept[] = $out;
            }
        }
        // re-index `after` onto the output list; a parent that was dropped drops its children.
        $final = []; $finalMap = [];
        foreach ($kept as $o => $t) {
            $in = $t['_after_in']; unset($t['_after_in']);
            if ($in !== null) {
                if (! isset($map[$in])) { $issues[] = $this->issue($o, $this->keyOf($t), 'depends on a task that could not be planned', true); continue; }
                $t['after'] = $finalMap[$map[$in]] ?? null;
                if ($t['after'] === null) { $issues[] = $this->issue($o, $this->keyOf($t), 'depends on a task that could not be planned', true); continue; }
            }
            $finalMap[$o] = count($final);
            $final[] = $t;
        }
        // soft: the description promises N items of a kind, the plan holds fewer tasks of that kind.
        $counts = [];
        foreach ($final as $t) { $counts[$this->keyOf($t)] = ($counts[$this->keyOf($t)] ?? 0) + 1; }
        foreach (array_values($tasks) as $i => $t) {
            if (! is_array($t)) continue;
            $k = $this->keyOf($t);
            if (! preg_match('/\b(\d{1,2})\b\s+(?:new\s+|more\s+|best[- ]performing\s+|[a-z\/-]+\s+){0,3}(articles?|posts?|blogs?|pieces?)\b/i', (string) ($t['description'] ?? ''), $m)) continue;
            $n = (int) $m[1];
            if ($n > 1 && ($counts[$k] ?? 0) > 0 && ($counts[$k] ?? 0) < $n) {
                $issues[] = $this->issue($i, $k, "says {$n} " . strtolower($m[2]) . " but the plan has " . $counts[$k] . " task" . ($counts[$k] === 1 ? '' : 's') . ' for it', false);
            }
        }
        return ['tasks' => $final, 'issues' => $issues];
    }

    /** Plain words for the customer: what was left out and why. */
    public function describe(array $issues): string
    {
        $lines = [];
        foreach ($issues as $x) $lines[] = '- ' . $this->label($x['action']) . ' ' . $x['problem'] . ($x['hard'] ? ' (left out)' : '');
        return implode("\n", $lines);
    }

    private function label(string $key): string
    {
        $a = str_contains($key, '/') ? explode('/', $key, 2)[1] : $key;
        return ucfirst(str_replace('_', ' ', $a));
    }

    private function requiredGroups(string $hint): array
    {
        $groups = [];
        foreach (preg_split('/[,;]/', $hint) as $seg) {
            if (! preg_match('/^(.*?)\(required\)/i', $seg, $m)) continue;
            $names = array_values(array_filter(array_map(fn ($s) => trim(preg_replace('/[^a-z0-9_ ]/i', '', $s)), preg_split('/\bor\b/i', $m[1]))));
            $names = array_values(array_filter($names, fn ($n) => preg_match('/^[a-z][a-z0-9_]*$/i', $n)));
            if ($names) $groups[] = $names;
        }
        return $groups;
    }

    private function platforms(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\s*(?:,|\/|&|\+|\band\b|\bor\b)\s*/i', strtolower(trim($raw))) as $p) {
            $p = trim($p); if ($p === '') continue;
            $p = self::PLATFORM_ALIASES[$p] ?? $p;
            if (! in_array($p, self::PLATFORMS, true)) return [];
            $out[$p] = true;
        }
        return array_keys($out);
    }

    private function keyOf($t): string
    {
        return is_array($t) ? strtolower(trim((string) ($t['engine'] ?? ''))) . '/' . strtolower(trim((string) ($t['action'] ?? ''))) : '';
    }

    private function issue(int $i, string $action, string $problem, bool $hard): array
    {
        return ['index' => $i, 'action' => $action, 'problem' => $problem, 'hard' => $hard];
    }
}
