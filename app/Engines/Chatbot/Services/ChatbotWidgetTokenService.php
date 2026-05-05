<?php

namespace App\Engines\Chatbot\Services;

use Illuminate\Support\Facades\DB;

/**
 * CHATBOT888 — Public widget token mint / verify / domain check.
 *
 * Public widget tokens are SEPARATE from the lgs_* private API keys.
 * They live in chatbot_widget_tokens, are bound to (workspace_id +
 * site_connection_id|website_id), and have a domain allowlist enforced
 * via Origin header on every public POST.
 *
 * Token shape: cwt_<48-char-hex>. Stored hashed (sha256). Plain returned
 * once on creation; never recoverable from DB.
 */
class ChatbotWidgetTokenService
{
    /**
     * Mint a new token. Returns ['plain' => 'cwt_...', 'id' => int, 'prefix' => 'cwt_xxxx'].
     */
    public function mint(
        int $workspaceId,
        ?int $siteConnectionId,
        ?int $websiteId,
        array $allowedDomains,
        ?string $label = null
    ): array {
        $plain  = 'cwt_' . bin2hex(random_bytes(24));
        $hash   = hash('sha256', $plain);
        $prefix = substr($plain, 0, 12);

        // Normalise domains: strip protocol, trailing slash, port. Lowercase.
        $allowedDomains = array_values(array_filter(array_map(
            fn($d) => $this->normaliseDomain($d),
            (array) $allowedDomains
        )));

        $id = DB::table('chatbot_widget_tokens')->insertGetId([
            'workspace_id'         => $workspaceId,
            'site_connection_id'   => $siteConnectionId,
            'website_id'           => $websiteId,
            'token_hash'           => $hash,
            'token_prefix'         => $prefix,
            'label'                => $label ?: 'Widget token',
            'allowed_domains_json' => json_encode($allowedDomains),
            'status'               => 'active',
            'created_at'           => now(), 'updated_at' => now(),
        ]);

        // For Laravel-built websites, persist the plain token onto
        // websites.settings_json so BuilderRenderer can inject it on each
        // published page. The token is PUBLIC by design — it's the cwt_*
        // value that ships in the <script> tag and identifies the workspace.
        // Domain allowlist + revocation are the actual security boundary.
        if ($websiteId) {
            $row = DB::table('websites')->where('id', $websiteId)->first();
            if ($row) {
                $settings = is_string($row->settings_json) ? json_decode($row->settings_json, true) : ($row->settings_json ?? []);
                $settings = is_array($settings) ? $settings : [];
                $settings['chatbot_widget_token'] = $plain;
                $settings['chatbot_widget_token_id'] = $id;
                DB::table('websites')->where('id', $websiteId)
                    ->update(['settings_json' => json_encode($settings)]);
            }
        }

        return ['plain' => $plain, 'id' => $id, 'prefix' => $prefix];
    }

    /**
     * Verify a presented plaintext token. Returns the row (with workspace_id)
     * on success, or null if invalid / revoked / disabled.
     *
     * Updates last_used_at as a side effect.
     */
    public function verify(string $plain): ?object
    {
        if (! str_starts_with($plain, 'cwt_') || strlen($plain) !== 52) {
            return null;
        }
        $hash = hash('sha256', $plain);
        $row = DB::table('chatbot_widget_tokens')
            ->where('token_hash', $hash)
            ->first();
        if (! $row || $row->status !== 'active' || $row->revoked_at) {
            return null;
        }
        DB::table('chatbot_widget_tokens')
            ->where('id', $row->id)
            ->update(['last_used_at' => now()]);
        return $row;
    }

    /**
     * Check Origin header against the token's allowed_domains_json. Returns
     * true if the origin matches any allowed entry exactly (host comparison).
     * If allowed_domains is empty → reject (fail-closed, no wildcard).
     */
    public function originAllowed(object $tokenRow, ?string $originHeader): bool
    {
        if (empty($originHeader)) return false;
        $originHost = $this->normaliseDomain($originHeader);
        if ($originHost === null) return false;
        $allowed = json_decode($tokenRow->allowed_domains_json ?: '[]', true) ?: [];
        if (empty($allowed)) return false;
        return in_array($originHost, $allowed, true);
    }

    public function revoke(int $tokenId): void
    {
        DB::table('chatbot_widget_tokens')->where('id', $tokenId)
            ->update(['revoked_at' => now(), 'status' => 'disabled', 'updated_at' => now()]);
    }

    /**
     * Strip protocol, port, path, trailing slash. Lowercase. Returns null
     * if the input looks malformed.
     */
    private function normaliseDomain(string $input): ?string
    {
        $input = trim($input);
        if ($input === '') return null;
        // If no protocol, prepend so parse_url works.
        $hasProto = preg_match('#^https?://#i', $input);
        $url = $hasProto ? $input : 'https://' . $input;
        $parts = parse_url($url);
        $host = $parts['host'] ?? null;
        if (! $host) return null;
        return strtolower($host);
    }
}
