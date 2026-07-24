<?php

namespace App\Core\LaunchScope;

use Illuminate\Support\Facades\Log;

/**
 * The unforgeable publishing credential.
 *
 * ORIGINAL PURPOSE (W5, 2026-07-22)
 * ---------------------------------
 * LaunchScopePolicy::isArticleShareContext() used to return true for ANY caller
 * that supplied mode='article_share', a truthy auto_share, or source='blog_share'.
 * Those are claims, not credentials: anyone reaching the kernel could make them,
 * unlocking the whole social surface with arbitrary content and no article. This
 * class replaced the claim with an HMAC over the immutable context.
 *
 * EXTENDED (scope change 2026-07-22) — Content Publisher
 * -----------------------------------------------------
 * Manual publishing is now a retained launch capability, so the token carries an
 * `intent` and binds a different claim set per intent:
 *
 *   INTENT_ARTICLE_SHARE   ws + article + platform + account + canonical_url + idem
 *   INTENT_MANUAL_PUBLISH  ws + publisher_post + platform + account + idem
 *                          + approval_method + approved_by
 *
 * THE APPROVAL BOUNDARY LIVES HERE. A manual-publish token CANNOT be minted
 * without an approval_method drawn from EXPLICIT_APPROVALS and a real approver
 * id. Uploading media, generating a caption, or editing a variation never
 * produces one, so those actions can never reach a provider — not by bug, not by
 * prompt injection, not by a stray autonomous code path. Autonomous publishing is
 * unrepresentable rather than merely forbidden.
 *
 * The class name is retained deliberately: ~all W5 wiring, tests and docs refer
 * to it, and the article-share behaviour is unchanged.
 */
final class ArticleShareToken
{
    public const TTL_SECONDS = 86400; // 24h

    public const INTENT_ARTICLE_SHARE  = 'article_share';
    public const INTENT_MANUAL_PUBLISH = 'manual_publish';

    private const VERSION = 'v2';

    /**
     * The ONLY approvals that authorise publishing. Each is an unambiguous,
     * deliberate user act. There is no 'auto', no 'agent', no 'scheduled_by_ai'.
     */
    public const EXPLICIT_APPROVALS = [
        'publish_now',        // user said "publish this now" / pressed Publish
        'schedule',           // user said "schedule this" / pressed Schedule
        'ui_publish_button',
        'ui_schedule_button',
        'approval_click',     // user approved from the approvals queue
    ];

    /** Claims covered by the signature, per intent. Order is fixed. */
    private const BOUND = [
        self::INTENT_ARTICLE_SHARE => [
            'workspace_id', 'article_id', 'platform', 'social_account_id',
            'canonical_url', 'idempotency_key',
        ],
        // Bound to the EXACT approved artefact. Changing the caption, the media,
        // the target account, the platform or the schedule changes one of these
        // hashes, so the previously minted token no longer verifies and the
        // approval is void. A token minted for Facebook cannot authorise
        // Instagram; one minted for Page A cannot authorise Page B; one minted
        // for image v1 cannot authorise a replaced image.
        self::INTENT_MANUAL_PUBLISH => [
            'workspace_id', 'publisher_post_id', 'platform', 'social_account_id',
            'idempotency_key', 'approval_method', 'approved_by',
            'caption_hash', 'media_hash', 'schedule_key',
        ],
    ];

    public static function isExplicitApproval(?string $method): bool
    {
        return $method !== null && in_array($method, self::EXPLICIT_APPROVALS, true);
    }

    /**
     * Mint a token for a fully-validated context.
     *
     * @param array  $ctx    must contain every bound claim for the intent
     * @param string $intent one of the INTENT_* constants
     */
    public static function mint(array $ctx, string $intent = self::INTENT_ARTICLE_SHARE): string
    {
        $bound = self::BOUND[$intent] ?? null;
        if ($bound === null) {
            throw new \InvalidArgumentException("ArticleShareToken: unknown intent '{$intent}'");
        }

        // The approval boundary, enforced at the point of credential creation.
        if ($intent === self::INTENT_MANUAL_PUBLISH) {
            if (!self::isExplicitApproval($ctx['approval_method'] ?? null)) {
                throw new \InvalidArgumentException(
                    'ArticleShareToken: manual publishing requires an explicit user approval; '
                    . 'got ' . var_export($ctx['approval_method'] ?? null, true)
                );
            }
            if (empty($ctx['approved_by'])) {
                throw new \InvalidArgumentException('ArticleShareToken: manual publishing requires an approver id');
            }
        }

        $claims = [];
        foreach ($bound as $k) {
            if (!array_key_exists($k, $ctx) || $ctx[$k] === null || $ctx[$k] === '') {
                throw new \InvalidArgumentException("ArticleShareToken: missing bound claim '{$k}'");
            }
            $claims[$k] = (string) $ctx[$k];
        }
        $claims['int'] = $intent;
        $claims['iat'] = time();
        $claims['exp'] = time() + self::TTL_SECONDS;
        $claims['ver'] = self::VERSION;

        $body = self::b64(json_encode($claims, JSON_UNESCAPED_SLASHES));
        return $body . '.' . self::b64(hash_hmac('sha256', $body, self::key(), true));
    }

    /**
     * Verify a token AND confirm it describes the call actually being made.
     * A valid signature is not enough — every bound claim must match the live
     * call, so a genuine token cannot be replayed against a different post,
     * workspace, platform or account.
     */
    public static function verify(string $token, array $params, array $context = []): bool
    {
        if ($token === '' || substr_count($token, '.') !== 1) return false;

        [$body, $sig] = explode('.', $token, 2);
        if (!hash_equals(self::b64(hash_hmac('sha256', $body, self::key(), true)), $sig)) {
            self::deny('bad_signature', []);
            return false;
        }

        $claims = json_decode(self::unb64($body) ?: '', true);
        if (!is_array($claims))                          { self::deny('bad_body', []);      return false; }
        if (($claims['ver'] ?? null) !== self::VERSION)   { self::deny('bad_version', $claims); return false; }
        if ((int) ($claims['exp'] ?? 0) < time())         { self::deny('expired', $claims);  return false; }

        $intent = (string) ($claims['int'] ?? '');
        $bound  = self::BOUND[$intent] ?? null;
        if ($bound === null) { self::deny('unknown_intent', $claims); return false; }

        // Re-assert the approval boundary at verification, not just at mint.
        if ($intent === self::INTENT_MANUAL_PUBLISH
            && !self::isExplicitApproval($claims['approval_method'] ?? null)) {
            self::deny('non_explicit_approval', $claims);
            return false;
        }

        foreach ($bound as $k) {
            $live = $params[$k] ?? $context[$k] ?? null;
            if ($live === null || $live === '') { self::deny("missing_live_{$k}", $claims); return false; }
            if (!hash_equals((string) $claims[$k], (string) $live)) {
                self::deny("mismatch_{$k}", $claims);
                return false;
            }
        }

        return true;
    }

    /** Decode without verifying — audit/debug display only. NEVER for authz. */
    public static function peek(string $token): ?array
    {
        if ($token === '' || !str_contains($token, '.')) return null;
        $c = json_decode(self::unb64(explode('.', $token, 2)[0]) ?: '', true);
        return is_array($c) ? $c : null;
    }

    private static function key(): string
    {
        $k = (string) config('app.key');
        if ($k === '') throw new \RuntimeException('ArticleShareToken: APP_KEY is not set');
        return $k;
    }

    private static function deny(string $why, array $claims): void
    {
        Log::info('[LaunchScope] publish token rejected', [
            'reason'   => $why,
            'intent'   => $claims['int'] ?? null,
            'ws'       => $claims['workspace_id'] ?? null,
            'platform' => $claims['platform'] ?? null,
            'article'  => $claims['article_id'] ?? null,
            'post'     => $claims['publisher_post_id'] ?? null,
        ]);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return base64_decode(strtr($s, '-_', '+/')) ?: '';
    }
}
