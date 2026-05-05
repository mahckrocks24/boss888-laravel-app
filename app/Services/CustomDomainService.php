<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class CustomDomainService
{
    /**
     * Connect a custom domain to a website.
     * Validates the domain format, stores it in the DB, and creates a Cloudflare CNAME record.
     *
     * @param  int    $websiteId
     * @param  string $customDomain
     * @return array
     */
    public function connect(int $websiteId, string $customDomain): array
    {
        // Validate domain format (no scheme, no path, no port)
        if (!preg_match('/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)+[a-zA-Z]{2,}$/', $customDomain)) {
            return [
                'success' => false,
                'error'   => 'Invalid domain format. Provide a bare domain such as shop.example.com.',
            ];
        }

        $website = DB::table('websites')->where('id', $websiteId)->first();

        if (!$website) {
            return ['success' => false, 'error' => 'Website not found.'];
        }

        $subdomain  = $website->subdomain ?? null;

        if (empty($subdomain)) {
            return ['success' => false, 'error' => 'Website does not have a subdomain configured.'];
        }

        // DB stores full subdomain (e.g. "mr-marketing.levelupgrowth.io") — use as-is
        $cfTarget  = str_contains($subdomain, '.levelupgrowth.io') ? $subdomain : "{$subdomain}.levelupgrowth.io";
        $zoneId    = config('services.cloudflare.zone_id',   env('CLOUDFLARE_ZONE_ID'));
        $apiToken  = config('services.cloudflare.api_token', env('CLOUDFLARE_API_TOKEN'));

        if (empty($zoneId) || empty($apiToken)) {
            return ['success' => false, 'error' => 'Cloudflare credentials are not configured.'];
        }

        $response = Http::withToken($apiToken)
            ->acceptJson()
            ->post("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", [
                'type'    => 'CNAME',
                'name'    => $customDomain,
                'content' => $cfTarget,
                'proxied' => true,
                'ttl'     => 1,
            ]);

        $body = $response->json();

        if (!$response->successful() || empty($body['success'])) {
            $cfError = $body['errors'][0]['message'] ?? 'Cloudflare API error.';
            return ['success' => false, 'error' => $cfError];
        }

        $dnsRecordId = $body['result']['id'] ?? null;

        // Persist to DB
        DB::table('websites')
            ->where('id', $websiteId)
            ->update([
                'custom_domain'       => $customDomain,
                'domain_verified'     => false,
                'domain_verified_at'  => null,
                'updated_at'          => now(),
            ]);

        return [
            'success'      => true,
            'dns_record_id' => $dnsRecordId,
            'instructions' => "Add a CNAME record for {$customDomain} pointing to {$cfTarget}. "
                            . "DNS propagation may take up to 24 hours. "
                            . "Call verify() once propagation is complete.",
        ];
    }

    /**
     * Verify that the custom domain's CNAME resolves to the expected target.
     * Marks domain_verified=true in the DB when confirmed.
     *
     * @param  int $websiteId
     * @return array
     */
    public function verify(int $websiteId): array
    {
        $website = DB::table('websites')->where('id', $websiteId)->first();

        if (!$website) {
            return ['success' => false, 'error' => 'Website not found.'];
        }

        $customDomain = $website->custom_domain ?? null;

        if (empty($customDomain)) {
            return ['success' => false, 'error' => 'No custom domain is connected to this website.'];
        }

        $subdomain = $website->subdomain ?? null;

        if (empty($subdomain)) {
            return ['success' => false, 'error' => 'Website does not have a subdomain configured.'];
        }

        $expectedTarget = str_contains($subdomain, '.levelupgrowth.io') ? $subdomain : "{$subdomain}.levelupgrowth.io";

        // PHP built-in DNS lookup — checks actual DNS, not cached system resolver
        $records = @dns_get_record($customDomain, DNS_CNAME);

        if ($records === false || empty($records)) {
            return [
                'success'  => false,
                'verified' => false,
                'error'    => "No CNAME record found for {$customDomain}. DNS may still be propagating.",
            ];
        }

        $matched = false;
        foreach ($records as $record) {
            $target = rtrim($record['target'] ?? '', '.');
            if (strtolower($target) === strtolower($expectedTarget)) {
                $matched = true;
                break;
            }
        }

        if (!$matched) {
            $actualTargets = array_column($records, 'target');
            return [
                'success'  => false,
                'verified' => false,
                'error'    => "CNAME for {$customDomain} does not point to {$expectedTarget}. "
                            . "Found: " . implode(', ', $actualTargets),
            ];
        }

        // Mark verified
        DB::table('websites')
            ->where('id', $websiteId)
            ->update([
                'domain_verified'    => true,
                'domain_verified_at' => now(),
                'updated_at'         => now(),
            ]);

        return [
            'success'            => true,
            'verified'           => true,
            'custom_domain'      => $customDomain,
            'domain_verified_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Disconnect a custom domain from a website.
     * Deletes the Cloudflare DNS record and clears the domain columns in the DB.
     *
     * @param  int $websiteId
     * @return array
     */
    public function disconnect(int $websiteId): array
    {
        $website = DB::table('websites')->where('id', $websiteId)->first();

        if (!$website) {
            return ['success' => false, 'error' => 'Website not found.'];
        }

        $customDomain = $website->custom_domain ?? null;

        if (empty($customDomain)) {
            return ['success' => false, 'error' => 'No custom domain is connected to this website.'];
        }

        $zoneId   = config('services.cloudflare.zone_id',   env('CLOUDFLARE_ZONE_ID'));
        $apiToken = config('services.cloudflare.api_token', env('CLOUDFLARE_API_TOKEN'));

        if (empty($zoneId) || empty($apiToken)) {
            return ['success' => false, 'error' => 'Cloudflare credentials are not configured.'];
        }

        // Look up the DNS record ID by name so we can delete it
        $listResponse = Http::withToken($apiToken)
            ->acceptJson()
            ->get("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records", [
                'type' => 'CNAME',
                'name' => $customDomain,
            ]);

        $listBody = $listResponse->json();

        if (!$listResponse->successful() || empty($listBody['success'])) {
            $cfError = $listBody['errors'][0]['message'] ?? 'Cloudflare API error when listing records.';
            return ['success' => false, 'error' => $cfError];
        }

        $records  = $listBody['result'] ?? [];
        $recordId = null;

        foreach ($records as $record) {
            if (strtolower($record['name'] ?? '') === strtolower($customDomain)) {
                $recordId = $record['id'];
                break;
            }
        }

        if ($recordId) {
            $deleteResponse = Http::withToken($apiToken)
                ->acceptJson()
                ->delete("https://api.cloudflare.com/client/v4/zones/{$zoneId}/dns_records/{$recordId}");

            $deleteBody = $deleteResponse->json();

            if (!$deleteResponse->successful() || empty($deleteBody['success'])) {
                $cfError = $deleteBody['errors'][0]['message'] ?? 'Cloudflare API error when deleting record.';
                return ['success' => false, 'error' => $cfError];
            }
        }

        // Clear domain columns regardless of whether a CF record was found
        DB::table('websites')
            ->where('id', $websiteId)
            ->update([
                'custom_domain'      => null,
                'domain_verified'    => false,
                'domain_verified_at' => null,
                'updated_at'         => now(),
            ]);

        return [
            'success'        => true,
            'custom_domain'  => $customDomain,
            'dns_record_deleted' => $recordId !== null,
        ];
    }
}
