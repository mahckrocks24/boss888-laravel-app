<?php

namespace App\Support;

/**
 * Wave 36a — Single source of truth for agent-name scrubbing.
 *
 * In the WP plugin / connector context, ALL 21 agents are presented
 * to the user under a single identity: "AI SEO Assistant". This
 * utility removes any leaked agent name from text fields in responses.
 */
class AgentNameScrub
{
    // The 21 canonical agent names (matches the agents table).
    // Order matters: longer names should come first so "Sarah Smith" gets
    // hit before "Sarah" if there were any compound matches.
    private const AGENT_NAMES = [
        'Sarah', 'James', 'Alex', 'Diana', 'Ryan', 'Sofia', 'Priya', 'Leo',
        'Maya', 'Chris', 'Nora', 'Marcus', 'Zara', 'Tyler', 'Zoe', 'Jordan',
        'Elena', 'Kai', 'Vera', 'Max', 'Aria',
    ];

    /**
     * Replace all 21 agent names in the text with "AI SEO Assistant".
     * Case-insensitive, word-boundary matched.
     */
    public static function scrub(string $text): string
    {
        if ($text === '') {
            return $text;
        }
        foreach (self::AGENT_NAMES as $name) {
            $text = preg_replace(
                '/\b' . preg_quote($name, '/') . '\b/i',
                'AI SEO Assistant',
                $text
            );
        }
        // Collapse runs of "AI SEO Assistant, AI SEO Assistant, and AI SEO Assistant"
        // → "AI SEO Assistant".
        $text = preg_replace(
            '/AI SEO Assistant(?:\s*,?\s*(?:and\s+)?AI SEO Assistant)+/i',
            'AI SEO Assistant',
            $text
        );
        return $text;
    }

    /**
     * Recursively scrub a response array. Only string values under the
     * given keys are touched (default: common text fields).
     */
    public static function scrubArray(array $data, ?array $textKeys = null): array
    {
        $textKeys ??= [
            'reply', 'response', 'message', 'description', 'title',
            'sarah_context', 'progress_message', 'content', 'text',
            'agent_name', 'reasoning', 'notes',
        ];

        foreach ($data as $k => $v) {
            if (is_string($v) && in_array($k, $textKeys, true)) {
                $data[$k] = self::scrub($v);
            } elseif (is_array($v)) {
                $data[$k] = self::scrubArray($v, $textKeys);
            }
        }
        return $data;
    }
}