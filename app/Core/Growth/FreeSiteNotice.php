<?php

namespace App\Core\Growth;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * ADS-NOTICE-1: once a day, every Free website that is showing ads and whose owner has not been told yet gets one email
 * explaining how to remove them. Recorded on the website (settings_json.ads_notice_sent_at) so it is sent once.
 * Reserved test domains (example.com, *.test, *.invalid, *.localhost) are never mailed.
 */
final class FreeSiteNotice
{
    public function run(bool $force = false, ?int $onlyWebsite = null): int
    {
        if (! $force && ! Cache::add('ads-notice-run:' . now()->toDateString(), 1, now()->addHours(30))) return 0;
        $gate = app(\App\Engines\Ads\Services\AdGateService::class);
        $cheapest = DB::table('plans')->where('is_public', 1)->where('price', '>', 0)->orderBy('price')->value('price');
        $aiFrom = DB::table('plans')->where('is_public', 1)->where('price', '>', 0)->where('credit_limit', '>', 0)->orderBy('price')->value('price');
        $sent = 0;
        $q = DB::table('websites')->where('status', 'published')->whereNull('deleted_at')->whereNotNull('subdomain');
        if ($onlyWebsite) $q->where('id', $onlyWebsite);
        foreach ($q->get(['id', 'workspace_id', 'name', 'subdomain', 'custom_domain', 'domain_verified', 'settings_json']) as $w) {
            $set = json_decode((string) ($w->settings_json ?? '{}'), true) ?: [];
            if (! empty($set['ads_notice_sent_at'])) continue;
            if (! ($gate->evaluate((int) $w->id)['allowed'] ?? false)) continue;
            $u = DB::table('users')->where('id', DB::table('workspaces')->where('id', $w->workspace_id)->value('created_by'))->first(['name', 'email']);
            $email = strtolower(trim((string) ($u->email ?? '')));
            if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL) || preg_match('/@(example\.(com|org|net)|[^@]*\.(test|invalid|localhost|example))$/', $email)) continue;
            $host = (! empty($w->custom_domain) && $w->domain_verified) ? $w->custom_domain : $w->subdomain;
            try {
                \Illuminate\Support\Facades\Mail::to($email)->send(new Mail\RemoveAdsMail(trim(explode(' ', (string) ($u->name ?? ''))[0]) ?: 'there', (string) $w->name, 'https://' . $host . '/',
                    'https://levelupgrowth.io/pricing/?utm_source=ads_notice&utm_medium=email', '$' . (int) $cheapest, '$' . (int) $aiFrom));
                $set['ads_notice_sent_at'] = now()->toIso8601String();
                DB::table('websites')->where('id', $w->id)->update(['settings_json' => json_encode($set)]);
                $sent++;
            } catch (\Throwable $e) { Log::warning('[ADS-NOTICE-1] send failed', ['website' => $w->id, 'e' => $e->getMessage()]); }
        }
        if ($sent) Log::info('[ADS-NOTICE-1] sent', ['n' => $sent]);
        return $sent;
    }
}
