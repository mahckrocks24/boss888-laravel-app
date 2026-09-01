<?php

namespace App\Core\Sarah888;

/**
 * ReadToolPromotion — SF-05 (REPORT-0024 / RISK-0130, 2026-09-01).
 *
 * The Runtime's assistant answers "List the pages on Fable QA Cafe Two" by emitting a create_tasks entry
 * {engine: platform, action: list_pages}. Laravel has no such TASK — it has a READ TOOL, `platform.list_pages`,
 * approval auto, executed synchronously through ToolSchemaService::executeToolCall(). TaskService refused the
 * entry as UNMAPPED_ACTION and the owner read "I'll fetch the list of pages now … I couldn't start this: that
 * action isn't available yet" — a promise and a refusal for something the platform can do.
 *
 * This class answers one question: does a model-emitted (engine, action) name a registered read-only tool?
 * Only the read families are promoted (platform get_… list_… read_… seo_health search_performance, and web fetch/search).
 * Anything that writes, spends credits on generation, or needs approval stays a task and keeps its gates.
 */
final class ReadToolPromotion
{
    private const READ_FAMILY = '/^(platform\.(get_[a-z_]+|list_[a-z_]+|read_[a-z_]+|seo_health|search_performance)|web\.(fetch|search))$/';

    /**
     * @param string[] $registeredToolIds  ToolSchemaService::getAllToolIds()
     */
    public static function toolIdFor(string $engine, string $action, array $registeredToolIds): ?string
    {
        $engine = strtolower(trim($engine));
        $action = strtolower(trim($action));
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
        return (bool) preg_match(self::READ_FAMILY, strtolower(trim($toolId)));
    }
}
