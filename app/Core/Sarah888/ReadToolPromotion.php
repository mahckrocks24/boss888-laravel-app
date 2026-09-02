<?php

namespace App\Core\Sarah888;

/**
 * ReadToolPromotion — SF-05 (REPORT-0024 / RISK-0130, 2026-09-01).
 *
 * The Runtime's assistant answers "List the pages on Fable QA Cafe Two" by emitting a create_tasks entry
 * {engine: platform, action: list_pages} — or, as measured on 2026-09-02, {engine: "platform.list_pages",
 * action: "list pages"}. Laravel has no such TASK — it has a READ TOOL, `platform.list_pages`, approval auto,
 * executed synchronously through ToolSchemaService::executeToolCall(). TaskService refused the entry as
 * UNMAPPED_ACTION and the owner read "I'll fetch the list of pages now … I couldn't start this: that action
 * isn't available yet" — a promise and a refusal for something the platform can do.
 *
 * Two questions this class answers:
 *   toolIdFor()  — does a model-emitted (engine, action) name a registered read-only tool? Only the read families
 *                  are promoted (platform get/list/read/seo_health/search_performance, web fetch/search). Anything
 *                  that writes, spends credits on generation, or needs approval stays a task and keeps its gates.
 *   render()     — what the OWNER sees from the tool result. Tool `result` strings carry guidance written for the
 *                  model ("Page ids are internal: speak to the owner in WEBSITE NAMES only…"); on 2026-09-02 that
 *                  sentence reached a customer reply. Render data, never model instructions.
 */
final class ReadToolPromotion
{
    private const READ_FAMILY = '/^(platform\.(get_[a-z_]+|list_[a-z_]+|read_[a-z_]+|seo_health|search_performance)|web\.(fetch|search))$/';

    /** Model-directed guidance that must never reach the owner. Everything from the first match onward is dropped. */
    private const MODEL_GUIDANCE = '/\s*(Page ids are internal|Article ids are internal|speak to the owner in|never quote|do NOT tell the user|IMPORTANT:|Use this|Call platform\.)/i';

    /**
     * @param string[] $registeredToolIds  ToolSchemaService::getAllToolIds()
     */
    public static function toolIdFor(string $engine, string $action, array $registeredToolIds): ?string
    {
        $engine = self::normalise($engine);
        $action = self::normalise($action);
        if ($action === '') return null;

        // The action may already carry its engine ("platform.list_pages") — split it.
        if (str_contains($action, '.')) {
            [$e, $a] = explode('.', $action, 2);
            if ($a !== '') { $engine = $engine !== '' && $engine !== $e ? $engine : $e; $action = $a; }
        }
        // "platform.list_pages" arrives as engine "platform.list_pages" from some envelopes.
        if (str_contains($engine, '.')) {
            [$e, $a] = explode('.', $engine, 2);
            if ($a === $action || $action === '') { $engine = $e; $action = $a; }
        }

        $candidates = array_values(array_unique(array_filter([
            $engine !== '' ? "{$engine}.{$action}" : null,
            "platform.{$action}",
            "web.{$action}",
            str_contains($action, '.') ? $action : null,
        ])));

        $registered = array_flip(array_map('strtolower', $registeredToolIds));
        foreach ($candidates as $id) {
            if (isset($registered[$id]) && preg_match(self::READ_FAMILY, $id)) return $id;
        }
        return null;
    }

    /** True when an id is in the read-only families this class will promote. */
    public static function isReadTool(string $toolId): bool
    {
        return (bool) preg_match(self::READ_FAMILY, self::normalise($toolId));
    }

    /** "List Pages" / "list-pages" / " platform.list pages " → "list_pages" / "platform.list_pages". */
    public static function normalise(string $s): string
    {
        $s = strtolower(trim($s));
        $s = preg_replace('/[\s\-]+/', '_', $s) ?? $s;
        return preg_replace('/_+/', '_', $s) ?? $s;
    }

    /**
     * Owner-facing rendering of a tool result. Data first; the tool's summary string only after the
     * model-directed guidance has been cut; a CLARIFY comes through as its question; a failure as a plain limit.
     */
    public static function render(array $result): string
    {
        if (empty($result['success'])) {
            $code = (string) ($result['code'] ?? '');
            $err  = trim((string) ($result['error'] ?? ''));
            if ($code === 'CLARIFY_TARGET' && $err !== '') return $err;
            return "I tried to look that up but couldn't" . ($err !== '' ? ': ' . self::stripGuidance($err) : '') . '.';
        }

        $data = is_array($result['data'] ?? null) ? $result['data'] : [];
        $rows = null;
        foreach (['pages', 'articles', 'items', 'rows', 'results', 'websites', 'keywords', 'leads', 'tasks'] as $k) {
            if (isset($data[$k]) && is_array($data[$k])) { $rows = $data[$k]; break; }
        }
        if ($rows === null && array_is_list($data) && $data && is_array($data[0])) $rows = $data;

        if (is_array($rows) && $rows) {
            $lines = [];
            foreach (array_slice($rows, 0, 25) as $r) {
                if (!is_array($r)) continue;
                $label = (string) ($r['title'] ?? $r['name'] ?? $r['keyword'] ?? $r['url'] ?? $r['slug'] ?? '');
                if ($label === '') continue;
                $extra = [];
                if (!empty($r['website_name'])) $extra[] = (string) $r['website_name'];
                if (!empty($r['status']))       $extra[] = (string) $r['status'];
                $lines[] = '• ' . $label . ($extra ? ' (' . implode(', ', $extra) . ')' : '');
            }
            if ($lines) {
                $head = self::stripGuidance((string) ($result['result'] ?? ''));
                $head = $head !== '' ? $head : count($lines) . ' item' . (count($lines) === 1 ? '' : 's') . ':';
                return $head . "\n" . implode("\n", $lines) . (count($rows) > 25 ? "\n… and " . (count($rows) - 25) . ' more' : '');
            }
        }

        $text = self::stripGuidance((string) ($result['result'] ?? ''));
        if ($text !== '') return $text;
        if (isset($data['count'])) return (int) $data['count'] . ' found.';
        return 'Done — nothing to show for that.';
    }

    /**
     * Resolve the website the owner NAMED onto a read tool call (2026-09-02, run 5 turn 5): the Runtime's own
     * tool_calls path reached executeToolCall with no website_id and no context, so "List the pages on Fable QA
     * Cafe Two" was answered with a CLARIFY for a site the owner had just named. Workspace-scoped by construction:
     * websiteNamesMentioned() only sees this workspace's websites (ReadToolTenancyTest).
     *
     * @return array{0: array, 1: array}  [params, context]
     */
    public static function targetByName(\App\Core\Orchestration\ToolSchemaService $svc, int $wsId, string $ownerMessage, array $params, string $siteUrl = ''): array
    {
        $ctx = [];
        try {
            $named = $svc->websiteNamesMentioned($wsId, $ownerMessage);
            if (count($named) === 1) {
                $ctx['explicit_name'] = (string) $named[0]['name'];
                if (empty($params['website_id'])) $params['website_id'] = (int) $named[0]['id'];
            }
            if ($siteUrl !== '') $ctx['ui_site_url'] = $siteUrl;
        } catch (\Throwable) { $ctx = []; }
        return [$params, $ctx];
    }

    public static function stripGuidance(string $s): string
    {
        $s = trim($s);
        if ($s === '') return '';
        if (preg_match(self::MODEL_GUIDANCE, $s, $m, PREG_OFFSET_CAPTURE)) {
            $s = trim(substr($s, 0, $m[0][1]));
        }
        return $s;
    }
}
