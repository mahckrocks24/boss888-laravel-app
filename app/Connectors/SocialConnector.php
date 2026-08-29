<?php

namespace App\Connectors;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * SocialConnector — platform publishing + OAuth.
 *
 * Phase 2G Session 1: Added Facebook + Instagram OAuth flow and fixed the
 * fatal `publish()` method mismatch. Session 2 will add real publishing
 * via the Graph API using the tokens stored here.
 *
 * OAuth flow:
 *   1. User clicks "Connect Facebook" → getAuthUrl('facebook', $wsId)
 *      → redirect to Facebook login with correct scopes
 *   2. Facebook redirects back to callback URL → handleCallback($code, $wsId)
 *      → exchange code for User Access Token
 *      → exchange for long-lived token
 *      → fetch connected Pages + Instagram Business Accounts
 *      → store Page Access Tokens in social_accounts.credentials_json
 *   3. Instagram is auto-discovered from connected Facebook Pages
 *      (Instagram Business API requires a linked Facebook Page).
 */
class SocialConnector extends BaseConnector
{
    private const GRAPH_API_VERSION = 'v19.0';
    private const GRAPH_BASE_URL   = 'https://graph.facebook.com/' . self::GRAPH_API_VERSION;

    /* b16-linkedin */
    private const LI_AUTHORIZE_URL = 'https://www.linkedin.com/oauth/v2/authorization';
    private const LI_TOKEN_URL     = 'https://www.linkedin.com/oauth/v2/accessToken';
    private const LI_USERINFO_URL  = 'https://api.linkedin.com/v2/userinfo';

    /* b17-twitter */
    private const TW_AUTHORIZE_URL = 'https://twitter.com/i/oauth2/authorize';
    private const TW_TOKEN_URL     = 'https://api.twitter.com/2/oauth2/token';
    private const TW_USERINFO_URL  = 'https://api.twitter.com/2/users/me';

    private string $baseUrl;
    private string $apiKey;
    private bool   $mockMode;

    // Facebook OAuth
    private string $fbAppId;
    private string $fbAppSecret;
    private string $fbRedirectUri;

    // /* b16-linkedin */ LinkedIn OAuth
    private string $liClientId;
    private string $liClientSecret;
    private string $liRedirectUri;

    // /* b17-twitter */ Twitter/X OAuth
    private string $twClientId;
    private string $twClientSecret;
    private string $twRedirectUri;

    public function __construct()
    {
        $this->baseUrl      = rtrim(config('connectors.social.base_url', ''), '/');
        $this->apiKey       = config('connectors.social.api_key', '');
        $this->mockMode     = (bool) config('connectors.social.mock_mode', true);

        $this->fbAppId      = (string) env('FACEBOOK_APP_ID', '');
        $this->fbAppSecret  = (string) env('FACEBOOK_APP_SECRET', '');
        $this->fbRedirectUri = (string) env('FACEBOOK_REDIRECT_URI', '');

        /* b16-linkedin */
        $this->liClientId     = (string) env('LINKEDIN_CLIENT_ID', '');
        $this->liClientSecret = (string) env('LINKEDIN_CLIENT_SECRET', '');
        $this->liRedirectUri  = (string) env('LINKEDIN_REDIRECT_URI', '');

        /* b17-twitter */
        $this->twClientId     = (string) env('TWITTER_CLIENT_ID', '');
        $this->twClientSecret = (string) env('TWITTER_CLIENT_SECRET', '');
        $this->twRedirectUri  = (string) env('TWITTER_REDIRECT_URI', '');
    }

    public function supportedActions(): array
    {
        return ['create_post', 'publish_post'];
    }

    public function validationRules(string $action): array
    {
        return match ($action) {
            'create_post' => [
                'platform' => 'required|in:facebook,instagram,twitter,linkedin',
                'content' => 'required|string|max:5000',
                'media_urls' => 'nullable|array',
                'media_urls.*' => 'url',
                'scheduled_at' => 'nullable|date|after:now',
                'hashtags' => 'nullable|array',
            ],
            // RISK-0099 (2026-08-29): the Orchestrator validates a TASK payload against these
            // connector rules (CapabilityMapService::getValidationRules → ParameterResolverService).
            // The platform's publish task carries post_id (EngineExecutionService dispatches
            // SocialService::publishPost(post_id)), never draft_id/platform — so every approved
            // social publish died with "Missing required parameters: draft_id, platform"
            // (task 31822). Accept the platform's contract; draft_id stays for direct connector use.
            'publish_post' => [
                'post_id'  => 'required_without:draft_id|integer',
                'draft_id' => 'required_without:post_id|string',
                'platform' => 'nullable|in:facebook,instagram,twitter,linkedin',
            ],
            default => [],
        };
    }

    public function execute(string $action, array $params): array
    {
        $validated = $this->validate($action, $params);

        try {
            if ($this->mockMode) {
                return $this->executeMock($action, $validated);
            }

            return match ($action) {
                'create_post' => $this->createPost($validated),
                'publish_post' => $this->publishPost($validated),
                default => $this->failure("Unknown action: {$action}"),
            };
        } catch (\Throwable $e) {
            Log::error("SocialConnector::{$action} failed", ['error' => $e->getMessage()]);
            return $this->failure("Social action failed: {$e->getMessage()}");
        }
    }

    /**
     * Phase 2G Session 1 fix — `SocialService::publishPost()` calls
     * `$this->connector->publish(platform, params)`. This method was missing
     * entirely, causing a fatal `Call to undefined method` error with mock
     * mode OFF. Delegates to `execute('publish_post', ...)` with the
     * correct parameter shape.
     *
     * Session 2 will replace this with a direct Graph API call per platform.
     */
    public function publish(string $platform, array $params): array
    {
        $result = $this->execute('publish_post', [
            'draft_id' => (string) ($params['account_id'] ?? $params['post_id'] ?? 'unknown'),
            'platform' => $platform,
        ]);

        // Map the execute() response shape to what SocialService::publishPost() expects
        $data = $result['data'] ?? [];
        return [
            'success'     => $result['success'] ?? false,
            'external_id' => $data['post_id'] ?? null,
            'url'         => $data['url'] ?? null,
            'mock'        => $data['mock'] ?? false,
            'error'       => $result['message'] ?? null,
        ];
    }

    public function healthCheck(): bool
    {
        if ($this->mockMode) return true;

        try {
            $response = $this->client()->get('/health');
            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    // ═══════════════════════════════════════════════════════════
    // OAUTH — Facebook + Instagram
    // ═══════════════════════════════════════════════════════════

    /* b16-linkedin-config-check */
    /**
     * Is LinkedIn OAuth configured? (all 3 env vars present)
     */
    public function isLinkedInOAuthConfigured(): bool
    {
        return $this->liClientId !== '' && $this->liClientSecret !== '' && $this->liRedirectUri !== '';
    }

    /* b17-twitter-config-check */
    /**
     * Is Twitter/X OAuth configured? (all 3 env vars present)
     */
    public function isTwitterOAuthConfigured(): bool
    {
        return $this->twClientId !== '' && $this->twClientSecret !== '' && $this->twRedirectUri !== '';
    }

    /**
     * Is Facebook OAuth configured? (all 3 env vars present)
     */
    public function isFacebookOAuthConfigured(): bool
    {
        return $this->fbAppId !== '' && $this->fbAppSecret !== '' && $this->fbRedirectUri !== '';
    }

    /**
     * Generate the Facebook OAuth authorization URL.
     *
     * Scopes requested:
     *   - pages_manage_posts     — publish to Pages
     *   - pages_read_engagement  — read Page insights
     *   - instagram_basic        — read Instagram Business Account info
     *   - instagram_content_publish — publish to Instagram
     *
     * The `state` parameter encodes workspace_id + a CSRF nonce (stored in
     * cache for 10 minutes) so the callback can verify the request is legit
     * and route the tokens to the correct workspace.
     */
    public function getAuthUrl(string $platform, int $workspaceId): string
    {
        /* b16-linkedin-authurl */
        if ($platform === 'linkedin') {
            return $this->buildLinkedInAuthUrl($workspaceId);
        }

        /* b17-twitter-authurl */
        // Twitter OAuth 2.0 with PKCE — different flow shape (verifier/challenge).
        if ($platform === 'twitter') {
            return $this->buildTwitterAuthUrl($workspaceId);
        }

        if ($platform !== 'facebook' && $platform !== 'instagram') {
            throw new \InvalidArgumentException("OAuth for {$platform} is not yet supported. Use facebook, instagram, linkedin, or twitter.");
        }

        if (! $this->isFacebookOAuthConfigured()) {
            throw new \RuntimeException('Facebook OAuth not configured. Set FACEBOOK_APP_ID, FACEBOOK_APP_SECRET, FACEBOOK_REDIRECT_URI in .env.');
        }

        $nonce = Str::random(32);
        $state = "{$workspaceId}_{$nonce}";

        // Store the nonce in cache for CSRF verification on callback
        cache()->put("social_oauth_state:{$state}", [
            'workspace_id' => $workspaceId,
            'platform'     => $platform,
            'nonce'        => $nonce,
            'created_at'   => now()->toISOString(),
        ], now()->addMinutes(10));

        // v5.5.4 — narrowed to scopes that auto-approve in Development mode.
        // Broader publishing scopes (pages_manage_posts, instagram_content_publish)
        // require the Facebook app to pass App Review before they can be granted.
        // Once connect works end-to-end we broaden these and submit for review.
        $scopes = implode(',', [
            'public_profile',
            'pages_show_list',
        ]);

        return 'https://www.facebook.com/' . self::GRAPH_API_VERSION . '/dialog/oauth?'
            . http_build_query([
                'client_id'    => $this->fbAppId,
                'redirect_uri' => $this->fbRedirectUri,
                'scope'        => $scopes,
                'state'        => $state,
                'response_type' => 'code',
            ]);
    }

    /**
     * Handle the Facebook OAuth callback.
     *
     * Flow:
     *   1. Verify state (CSRF nonce from cache)
     *   2. Exchange authorization code for short-lived User Access Token
     *   3. Exchange short-lived → long-lived User Access Token (~60 days)
     *   4. Fetch connected Pages (each has its own Page Access Token)
     *   5. For each Page, check for a linked Instagram Business Account
     *   6. Store all accounts via storeAccountTokens()
     *
     * Returns: ['success' => bool, 'accounts' => [...], 'error' => ?string]
     */
    public function handleCallback(string $code, string $state, int $workspaceId): array
    {
        // ── Step 1: Verify state ──────────────────────────────────────
        $cached = cache()->pull("social_oauth_state:{$state}");
        if (! $cached || ($cached['workspace_id'] ?? null) !== $workspaceId) {
            return ['success' => false, 'error' => 'Invalid or expired OAuth state. Try connecting again.'];
        }

        // ── Step 2: Exchange code for short-lived User Access Token ───
        $tokenResp = Http::get(self::GRAPH_BASE_URL . '/oauth/access_token', [
            'client_id'     => $this->fbAppId,
            'client_secret' => $this->fbAppSecret,
            'redirect_uri'  => $this->fbRedirectUri,
            'code'          => $code,
        ]);

        if ($tokenResp->failed()) {
            $err = $tokenResp->json('error.message') ?? $tokenResp->body();
            Log::error('SocialConnector: Facebook code exchange failed', ['error' => $err]);
            return ['success' => false, 'error' => "Facebook code exchange failed: {$err}"];
        }

        $shortLivedToken = $tokenResp->json('access_token');
        if (! $shortLivedToken) {
            return ['success' => false, 'error' => 'No access_token in Facebook response'];
        }

        // ── Step 3: Exchange for long-lived token (~60 days) ──────────
        $longLivedResp = Http::get(self::GRAPH_BASE_URL . '/oauth/access_token', [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => $this->fbAppId,
            'client_secret'     => $this->fbAppSecret,
            'fb_exchange_token' => $shortLivedToken,
        ]);

        $longLivedToken = $longLivedResp->json('access_token') ?? $shortLivedToken;
        $expiresIn      = $longLivedResp->json('expires_in');  // seconds

        // ── Step 4: Fetch connected Pages ─────────────────────────────
        $pagesResp = Http::get(self::GRAPH_BASE_URL . '/me/accounts', [
            'access_token' => $longLivedToken,
            'fields'       => 'id,name,access_token,category,picture',
        ]);

        if ($pagesResp->failed()) {
            $err = $pagesResp->json('error.message') ?? $pagesResp->body();
            return ['success' => false, 'error' => "Failed to fetch Pages: {$err}"];
        }

        $pages = $pagesResp->json('data') ?? [];
        if (empty($pages)) {
            return ['success' => false, 'error' => 'No Facebook Pages found. You need at least one Page to publish.'];
        }

        // ── Step 5: For each Page, check for linked Instagram Business Account
        $accounts = [];
        foreach ($pages as $page) {
            $pageId    = $page['id'];
            $pageName  = $page['name'];
            $pageToken = $page['access_token'];  // Page Access Tokens from /me/accounts are already long-lived

            // Store the Facebook Page account
            $accounts[] = [
                'platform'       => 'facebook',
                'account_id'     => $pageId,
                'account_name'   => $pageName,
                'access_token'   => $pageToken,
                'token_expires'  => $expiresIn ? now()->addSeconds($expiresIn)->toISOString() : null,
                'picture_url'    => $page['picture']['data']['url'] ?? null,
                'category'       => $page['category'] ?? null,
            ];

            // Check for linked Instagram Business Account
            try {
                $igResp = Http::get(self::GRAPH_BASE_URL . "/{$pageId}", [
                    'access_token' => $pageToken,
                    'fields'       => 'instagram_business_account{id,name,username,profile_picture_url,followers_count}',
                ]);

                $igAccount = $igResp->json('instagram_business_account');
                if ($igAccount && !empty($igAccount['id'])) {
                    $accounts[] = [
                        'platform'       => 'instagram',
                        'account_id'     => $igAccount['id'],
                        'account_name'   => $igAccount['username'] ?? $igAccount['name'] ?? "IG:{$pageName}",
                        'access_token'   => $pageToken,  // Instagram uses the Page Access Token
                        'token_expires'  => $expiresIn ? now()->addSeconds($expiresIn)->toISOString() : null,
                        'picture_url'    => $igAccount['profile_picture_url'] ?? null,
                        'followers'      => $igAccount['followers_count'] ?? null,
                        'linked_page_id' => $pageId,
                    ];
                }
            } catch (\Throwable $e) {
                Log::warning('SocialConnector: Could not check Instagram for page', [
                    'page_id' => $pageId, 'error' => $e->getMessage(),
                ]);
            }
        }

        // ── Step 6: Persist ───────────────────────────────────────────
        $stored = $this->storeAccountTokens($accounts, $workspaceId);

        Log::info('SocialConnector: OAuth callback completed', [
            'workspace_id'    => $workspaceId,
            'accounts_found'  => count($accounts),
            'accounts_stored' => $stored,
        ]);

        return [
            'success'  => true,
            'accounts' => $accounts,
            'stored'   => $stored,
        ];
    }

    /**
     * Persist discovered accounts to social_accounts table.
     * Upserts by workspace_id + platform + account_id.
     */
    public function storeAccountTokens(array $accounts, int $workspaceId): int
    {
        $stored = 0;

        foreach ($accounts as $acct) {
            $existing = DB::table('social_accounts')
                ->where('workspace_id', $workspaceId)
                ->where('platform', $acct['platform'])
                ->where('account_id', $acct['account_id'])
                ->first();

            $credentials = [
                'access_token'  => $acct['access_token'],
                'token_expires' => $acct['token_expires'] ?? null,
                'linked_page_id' => $acct['linked_page_id'] ?? null,
            ];

            $stats = array_filter([
                'followers' => $acct['followers'] ?? null,
                'picture_url' => $acct['picture_url'] ?? null,
                'category' => $acct['category'] ?? null,
            ]);

            if ($existing) {
                DB::table('social_accounts')->where('id', $existing->id)->update([
                    'account_name'     => $acct['account_name'],
                    'credentials_json' => json_encode($credentials),
                    'stats_json'       => json_encode($stats),
                    'status'           => 'connected',
                    'updated_at'       => now(),
                ]);
            } else {
                DB::table('social_accounts')->insert([
                    'workspace_id'     => $workspaceId,
                    'platform'         => $acct['platform'],
                    'account_id'       => $acct['account_id'],
                    'account_name'     => $acct['account_name'],
                    'credentials_json' => json_encode($credentials),
                    'stats_json'       => json_encode($stats),
                    'status'           => 'connected',
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
            $stored++;
        }

        return $stored;
    }

    // ═══════════════════════════════════════════════════════════
    // LINKEDIN OAUTH (Batch 16)
    // ═══════════════════════════════════════════════════════════

    /* b16-linkedin-methods */

    /**
     * Build LinkedIn OAuth authorization URL. Uses OpenID Connect scopes
     * (openid, profile, email) plus w_member_social for posting on the
     * user's behalf. w_member_social is part of LinkedIn's "Share on
     * LinkedIn" product which is auto-approved when added to a developer
     * app — no review needed.
     */
    public function buildLinkedInAuthUrl(int $workspaceId): string
    {
        if (! $this->isLinkedInOAuthConfigured()) {
            throw new \RuntimeException('LinkedIn OAuth not configured. Set LINKEDIN_CLIENT_ID, LINKEDIN_CLIENT_SECRET, LINKEDIN_REDIRECT_URI in .env.');
        }

        $nonce = \Illuminate\Support\Str::random(32);
        $state = "{$workspaceId}_{$nonce}";

        cache()->put("social_oauth_state:{$state}", [
            'workspace_id' => $workspaceId,
            'platform'     => 'linkedin',
            'nonce'        => $nonce,
            'created_at'   => now()->toISOString(),
        ], now()->addMinutes(10));

        $scopes = implode(' ', [
            'openid',           // OpenID Connect — required for /userinfo
            'profile',          // Basic profile (name, picture, locale)
            'email',            // Verified email
            'w_member_social',  // Post on member's behalf — the publishing scope
        ]);

        return self::LI_AUTHORIZE_URL . '?' . http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->liClientId,
            'redirect_uri'  => $this->liRedirectUri,
            'scope'         => $scopes,
            'state'         => $state,
        ]);
    }

    /**
     * Handle the LinkedIn OAuth callback. Mirrors handleCallback() shape:
     *   1. Verify state (CSRF nonce from cache)
     *   2. Exchange code for access_token (returns expires_in ~60 days)
     *   3. Fetch /v2/userinfo for member ID + name + email + picture
     *   4. Store as a single LinkedIn account
     *
     * Returns: ['success' => bool, 'accounts' => [...], 'error' => ?string]
     */
    public function handleLinkedInCallback(string $code, string $state, int $workspaceId): array
    {
        // ── Step 1: Verify state ──────────────────────────────────────
        $cached = cache()->pull("social_oauth_state:{$state}");
        if (! $cached || ($cached['workspace_id'] ?? null) !== $workspaceId || ($cached['platform'] ?? null) !== 'linkedin') {
            return ['success' => false, 'error' => 'Invalid or expired OAuth state. Try connecting again.'];
        }

        // ── Step 2: Exchange code for access_token ────────────────────
        $tokenResp = \Illuminate\Support\Facades\Http::asForm()->post(self::LI_TOKEN_URL, [
            'grant_type'    => 'authorization_code',
            'code'          => $code,
            'redirect_uri'  => $this->liRedirectUri,
            'client_id'     => $this->liClientId,
            'client_secret' => $this->liClientSecret,
        ]);

        if ($tokenResp->failed()) {
            $err = $tokenResp->json('error_description') ?? $tokenResp->json('error') ?? $tokenResp->body();
            \Illuminate\Support\Facades\Log::error('SocialConnector: LinkedIn code exchange failed', ['error' => $err]);
            return ['success' => false, 'error' => "LinkedIn code exchange failed: {$err}"];
        }

        $accessToken = $tokenResp->json('access_token');
        $expiresIn   = (int) ($tokenResp->json('expires_in') ?? 0);
        if (! $accessToken) {
            return ['success' => false, 'error' => 'No access_token in LinkedIn response'];
        }

        // ── Step 3: Fetch userinfo ────────────────────────────────────
        $userResp = \Illuminate\Support\Facades\Http::withToken($accessToken)->get(self::LI_USERINFO_URL);
        if ($userResp->failed()) {
            $err = $userResp->json('message') ?? $userResp->body();
            return ['success' => false, 'error' => "Failed to fetch LinkedIn userinfo: {$err}"];
        }

        $user = $userResp->json();
        $memberId = $user['sub'] ?? null;
        if (! $memberId) {
            return ['success' => false, 'error' => 'LinkedIn userinfo missing member ID (sub)'];
        }

        $accountName = $user['name'] ?? trim(($user['given_name'] ?? '') . ' ' . ($user['family_name'] ?? '')) ?: 'LinkedIn user';

        // ── Step 4: Persist ───────────────────────────────────────────
        $accounts = [[
            'platform'      => 'linkedin',
            'account_id'    => $memberId,
            'account_name'  => $accountName,
            'access_token'  => $accessToken,
            'token_expires' => $expiresIn > 0 ? now()->addSeconds($expiresIn)->toISOString() : null,
            'picture_url'   => $user['picture'] ?? null,
            'email'         => $user['email'] ?? null,
        ]];
        $stored = $this->storeAccountTokens($accounts, $workspaceId);

        \Illuminate\Support\Facades\Log::info('SocialConnector: LinkedIn OAuth callback completed', [
            'workspace_id'    => $workspaceId,
            'member_id'       => $memberId,
            'accounts_stored' => $stored,
        ]);

        return [
            'success'  => true,
            'accounts' => $accounts,
            'stored'   => $stored,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // TWITTER/X OAUTH 2.0 with PKCE (Batch 17)
    // ═══════════════════════════════════════════════════════════

    /* b17-twitter-methods */

    /**
     * Build Twitter OAuth 2.0 authorization URL with PKCE.
     *
     * PKCE flow:
     *   1. Generate random code_verifier (43-128 chars, [A-Za-z0-9-._~])
     *   2. Compute code_challenge = base64url(sha256(code_verifier))
     *   3. Send code_challenge + code_challenge_method=S256 with auth URL
     *   4. Cache code_verifier alongside state for callback verification
     *   5. On callback: send code_verifier to token endpoint; Twitter verifies
     *      sha256(verifier) === stored challenge.
     *
     * Scopes:
     *   - tweet.read     — read tweets (required to fetch own tweets)
     *   - tweet.write    — post tweets on member's behalf
     *   - users.read     — fetch authenticated user info (/users/me)
     *   - offline.access — receive a refresh_token alongside access_token
     */
    public function buildTwitterAuthUrl(int $workspaceId): string
    {
        if (! $this->isTwitterOAuthConfigured()) {
            throw new \RuntimeException('Twitter OAuth not configured. Set TWITTER_CLIENT_ID, TWITTER_CLIENT_SECRET, TWITTER_REDIRECT_URI in .env.');
        }

        // PKCE verifier: 64-char random URL-safe string
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        // PKCE challenge: base64url(sha256(verifier))
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        $nonce = \Illuminate\Support\Str::random(32);
        $state = "{$workspaceId}_{$nonce}";

        cache()->put("social_oauth_state:{$state}", [
            'workspace_id'  => $workspaceId,
            'platform'      => 'twitter',
            'nonce'         => $nonce,
            'code_verifier' => $verifier,
            'created_at'    => now()->toISOString(),
        ], now()->addMinutes(10));

        $scopes = implode(' ', [
            'tweet.read',
            'tweet.write',
            'users.read',
            'offline.access',
        ]);

        return self::TW_AUTHORIZE_URL . '?' . http_build_query([
            'response_type'         => 'code',
            'client_id'             => $this->twClientId,
            'redirect_uri'          => $this->twRedirectUri,
            'scope'                 => $scopes,
            'state'                 => $state,
            'code_challenge'        => $challenge,
            'code_challenge_method' => 'S256',
        ]);
    }

    /**
     * Handle Twitter OAuth 2.0 callback. PKCE verifier is retrieved from
     * cache and sent during token exchange.
     */
    public function handleTwitterCallback(string $code, string $state, int $workspaceId): array
    {
        // ── Step 1: Verify state + retrieve code_verifier ─────────────
        $cached = cache()->pull("social_oauth_state:{$state}");
        if (! $cached
            || ($cached['workspace_id'] ?? null) !== $workspaceId
            || ($cached['platform'] ?? null) !== 'twitter'
            || empty($cached['code_verifier'])
        ) {
            return ['success' => false, 'error' => 'Invalid or expired OAuth state. Try connecting again.'];
        }
        $verifier = $cached['code_verifier'];

        // ── Step 2: Exchange code for access_token (with PKCE verifier)
        // Twitter requires HTTP Basic auth (client_id:client_secret) for
        // Confidential clients, even though PKCE is in use.
        $tokenResp = \Illuminate\Support\Facades\Http::asForm()
            ->withBasicAuth($this->twClientId, $this->twClientSecret)
            ->post(self::TW_TOKEN_URL, [
                'grant_type'    => 'authorization_code',
                'code'          => $code,
                'redirect_uri'  => $this->twRedirectUri,
                'code_verifier' => $verifier,
                'client_id'     => $this->twClientId,
            ]);

        if ($tokenResp->failed()) {
            $err = $tokenResp->json('error_description')
                ?? $tokenResp->json('error')
                ?? $tokenResp->body();
            \Illuminate\Support\Facades\Log::error('SocialConnector: Twitter code exchange failed', ['error' => $err]);
            return ['success' => false, 'error' => "Twitter code exchange failed: {$err}"];
        }

        $accessToken  = $tokenResp->json('access_token');
        $refreshToken = $tokenResp->json('refresh_token');
        $expiresIn    = (int) ($tokenResp->json('expires_in') ?? 0);
        if (! $accessToken) {
            return ['success' => false, 'error' => 'No access_token in Twitter response'];
        }

        // ── Step 3: Fetch authenticated user info ─────────────────────
        $userResp = \Illuminate\Support\Facades\Http::withToken($accessToken)
            ->get(self::TW_USERINFO_URL, [
                'user.fields' => 'id,name,username,profile_image_url',
            ]);
        if ($userResp->failed()) {
            $err = $userResp->json('detail') ?? $userResp->body();
            return ['success' => false, 'error' => "Failed to fetch Twitter user: {$err}"];
        }

        $userData = $userResp->json('data') ?? [];
        $userId   = $userData['id'] ?? null;
        if (! $userId) {
            return ['success' => false, 'error' => 'Twitter user response missing id'];
        }

        // ── Step 4: Persist ───────────────────────────────────────────
        $accounts = [[
            'platform'      => 'twitter',
            'account_id'    => (string) $userId,
            'account_name'  => $userData['username'] ?? $userData['name'] ?? 'Twitter user',
            'access_token'  => $accessToken,
            'refresh_token' => $refreshToken,
            'token_expires' => $expiresIn > 0 ? now()->addSeconds($expiresIn)->toISOString() : null,
            'picture_url'   => $userData['profile_image_url'] ?? null,
        ]];
        $stored = $this->storeAccountTokens($accounts, $workspaceId);

        \Illuminate\Support\Facades\Log::info('SocialConnector: Twitter OAuth callback completed', [
            'workspace_id'    => $workspaceId,
            'twitter_user_id' => $userId,
            'accounts_stored' => $stored,
        ]);

        return [
            'success'  => true,
            'accounts' => $accounts,
            'stored'   => $stored,
        ];
    }

    // ═══════════════════════════════════════════════════════════
    // VERIFICATION
    // ═══════════════════════════════════════════════════════════

    public function verifyResult(string $action, array $params, array $result): array
    {
        if (! ($result['success'] ?? false)) {
            return ['verified' => false, 'message' => 'Execution reported failure', 'data' => []];
        }

        $data = $result['data'] ?? [];

        if (! empty($data['mock'])) {
            return ['verified' => true, 'message' => 'Mock result — flagged as mock_result=true', 'data' => array_merge($data, ['mock_result' => true])];
        }

        if ($action === 'publish_post') {
            if (empty($data['post_id']) && empty($data['url'])) {
                return ['verified' => false, 'message' => 'No post_id or URL in publish response', 'data' => $data];
            }
        }

        if ($action === 'create_post') {
            if (empty($data['draft_id'])) {
                return ['verified' => false, 'message' => 'No draft_id in create response', 'data' => $data];
            }
        }

        return ['verified' => true, 'message' => 'Social result verified', 'data' => $data];
    }

    // ═══════════════════════════════════════════════════════════
    // MOCK IMPLEMENTATION
    // ═══════════════════════════════════════════════════════════

    private function executeMock(string $action, array $params): array
    {
        $draftId = 'draft_' . Str::random(12);
        $postId = 'post_' . Str::random(12);

        return match ($action) {
            'create_post' => $this->success([
                'draft_id' => $draftId,
                'platform' => $params['platform'],
                'content_preview' => Str::limit($params['content'], 100),
                'scheduled_at' => $params['scheduled_at'] ?? null,
                'mock' => true,
            ], "[MOCK] Social post draft created on {$params['platform']}"),

            'publish_post' => $this->success([
                'post_id' => $postId,
                'platform' => $params['platform'],
                'published_at' => now()->toIso8601String(),
                'url' => "https://{$params['platform']}.com/p/{$postId}",
                'mock' => true,
            ], "[MOCK] Post published on {$params['platform']}"),

            default => $this->failure("Unknown mock action: {$action}"),
        };
    }

    // ═══════════════════════════════════════════════════════════
    // LIVE IMPLEMENTATION (phantom relay — Session 2 replaces with Graph API)
    // ═══════════════════════════════════════════════════════════

    private function createPost(array $params): array
    {
        $response = $this->client()->post('/api/social/posts', $params);

        if ($response->failed()) {
            return $this->failure('Failed to create social post: ' . $response->body());
        }

        $data = $response->json();
        return $this->success([
            'draft_id' => $data['id'] ?? null,
            'platform' => $params['platform'],
            'status' => $data['status'] ?? 'draft',
        ], 'Social post created');
    }

    private function publishPost(array $params): array
    {
        $response = $this->client()->post("/api/social/posts/{$params['draft_id']}/publish", [
            'platform' => $params['platform'],
        ]);

        if ($response->failed()) {
            return $this->failure('Failed to publish post: ' . $response->body());
        }

        $data = $response->json();
        return $this->success([
            'post_id' => $data['id'] ?? $params['draft_id'],
            'url' => $data['url'] ?? null,
            'published_at' => $data['published_at'] ?? now()->toIso8601String(),
        ], 'Post published');
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders([
                'X-API-Key' => $this->apiKey,
                'Accept' => 'application/json',
            ])
            ->timeout(15)
            ->retry(2, 500);
    }
}
