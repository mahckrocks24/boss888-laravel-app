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

    private string $baseUrl;
    private string $apiKey;
    private bool   $mockMode;

    // Facebook OAuth
    private string $fbAppId;
    private string $fbAppSecret;
    private string $fbRedirectUri;

    public function __construct()
    {
        $this->baseUrl      = rtrim(config('connectors.social.base_url', ''), '/');
        $this->apiKey       = config('connectors.social.api_key', '');
        $this->mockMode     = (bool) config('connectors.social.mock_mode', true);

        $this->fbAppId      = (string) env('FACEBOOK_APP_ID', '');
        $this->fbAppSecret  = (string) env('FACEBOOK_APP_SECRET', '');
        $this->fbRedirectUri = (string) env('FACEBOOK_REDIRECT_URI', '');
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
            'publish_post' => [
                'draft_id' => 'required|string',
                'platform' => 'required|in:facebook,instagram,twitter,linkedin',
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
        if ($platform !== 'facebook' && $platform !== 'instagram') {
            throw new \InvalidArgumentException("OAuth for {$platform} is not yet supported. Use facebook or instagram.");
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
