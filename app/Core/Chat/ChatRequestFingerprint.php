<?php

namespace App\Core\Chat;

/**
 * P2-B — canonical fingerprint of a chat request (INV-03).
 *
 * Two requests carrying the same idempotency key must be provably the same
 * request, or the second is a conflict. The fingerprint is what makes "same"
 * decidable without storing the whole payload.
 *
 * Deliberately NOT hashed over the raw body: header order, whitespace and
 * client-added noise would make identical submissions look different and defeat
 * idempotency. Only the fields that change the ANSWER are included.
 */
final class ChatRequestFingerprint
{
    /**
     * @param array{
     *   workspace_id:int, surface:string, conversation_type?:?string,
     *   conversation_id?:?string, agent_id?:?string, content:string,
     *   attachment_ids?:array
     * } $parts
     */
    public static function compute(array $parts): string
    {
        $canonical = [
            'workspace_id'      => (int) ($parts['workspace_id'] ?? 0),
            'surface'           => (string) ($parts['surface'] ?? ''),
            'conversation_type' => (string) ($parts['conversation_type'] ?? ''),
            'conversation_id'   => (string) ($parts['conversation_id'] ?? ''),
            'agent_id'          => (string) ($parts['agent_id'] ?? ''),
            // Normalised so that trailing whitespace or a stray newline from the
            // composer does not read as a different question.
            'content'           => self::normaliseContent((string) ($parts['content'] ?? '')),
            'attachment_ids'    => self::normaliseIds($parts['attachment_ids'] ?? []),
        ];

        // ksort so key order can never change the hash.
        ksort($canonical);

        return hash('sha256', json_encode($canonical, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private static function normaliseContent(string $c): string
    {
        // Collapse runs of whitespace and trim. Case is PRESERVED: "delete it"
        // and "DELETE IT" may legitimately differ in intent, and treating them
        // as one request would be a silent behaviour change.
        return trim(preg_replace('/\s+/u', ' ', $c) ?? $c);
    }

    private static function normaliseIds(array $ids): array
    {
        $ids = array_values(array_filter(array_map('strval', $ids), fn ($v) => $v !== ''));
        sort($ids);   // order of attachment selection must not change the hash

        return $ids;
    }
}
