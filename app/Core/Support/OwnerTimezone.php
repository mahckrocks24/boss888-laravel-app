<?php

namespace App\Core\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * TZ-1 (Owner 2026-09-28: "Sarah always talks about UTC, she should automatically use the timezone of the user, like
 * Manila time, Dubai time or Eastern Standard time, or whatever is set up on the account").
 *
 * 134 of 141 workspaces carried the column default 'UTC' because only one onboarding path ever saved the owner's zone.
 * Now: the web app and the companion app send the device's IANA zone (X-LU-TZ) on every request, and while a workspace
 * still sits on UTC its owner's zone is adopted once. A zone the owner chose on purpose is never overwritten.
 * Where a workspace has no active owner device yet, the business location decides when it is unambiguous.
 */
final class OwnerTimezone
{
    public static function adopt(int $wsId, int $userId, string $tz): void
    {
        $tz = trim($tz);
        if ($wsId <= 0 || $userId <= 0 || $tz === '' || $tz === 'UTC' || $tz === 'Etc/UTC' || ! in_array($tz, \DateTimeZone::listIdentifiers(), true)) return;
        if (! Cache::add('tz-adopt:' . $wsId, 1, now()->addHours(6))) return;
        try {
            $ws = DB::table('workspaces')->where('id', $wsId)->first(['timezone', 'created_by']);
            if (! $ws || ! in_array((string) $ws->timezone, ['', 'UTC', 'Etc/UTC'], true)) return;
            $isOwner = (int) $ws->created_by === $userId
                || DB::table('workspace_users')->where('workspace_id', $wsId)->where('user_id', $userId)->where('role', 'owner')->exists();
            if (! $isOwner) return;
            DB::table('workspaces')->where('id', $wsId)->whereIn('timezone', ['', 'UTC', 'Etc/UTC'])->update(['timezone' => $tz, 'updated_at' => now()]);
            Log::info('[TZ-1] adopted the owner device zone', ['ws' => $wsId, 'tz' => $tz]);
        } catch (\Throwable $e) { Log::info('[TZ-1] adopt failed', ['ws' => $wsId, 'e' => $e->getMessage()]); }
    }

    /** The zone a business location clearly implies, or null. */
    public static function fromLocation(string $text): ?string
    {
        $t = ' ' . strtolower(preg_replace('/[^a-z ]+/i', ' ', $text)) . ' ';
        $countries = ['Asia/Dubai' => ['uae', 'united arab emirates', 'dubai', 'abu dhabi', 'sharjah', 'ajman'], 'Asia/Manila' => ['philippines', 'manila', 'cebu', 'davao', 'makati', 'quezon city', 'pasig', 'taguig'],
            'Europe/London' => ['united kingdom', 'uk', 'england', 'london', 'scotland', 'wales'], 'Asia/Singapore' => ['singapore'], 'Asia/Riyadh' => ['saudi', 'riyadh', 'jeddah'],
            'Asia/Qatar' => ['qatar', 'doha'], 'Asia/Kolkata' => ['india', 'mumbai', 'delhi', 'bangalore'], 'Asia/Hong_Kong' => ['hong kong'], 'Europe/Dublin' => ['ireland', 'dublin']];
        foreach ($countries as $tz => $words) foreach ($words as $w) if (str_contains($t, ' ' . $w . ' ')) return $tz;
        $us = ['America/New_York' => ['new jersey', 'new york', 'nj', 'ny', 'connecticut', 'massachusetts', 'boston', 'pennsylvania', 'philadelphia', 'florida', 'miami', 'georgia', 'atlanta', 'virginia', 'maryland', 'north carolina', 'ohio', 'michigan', 'washington dc'],
            'America/Chicago' => ['texas', 'austin', 'houston', 'dallas', 'chicago', 'illinois', 'minnesota', 'wisconsin', 'missouri', 'louisiana', 'tennessee', 'oklahoma'],
            'America/Denver' => ['colorado', 'denver', 'utah', 'new mexico'], 'America/Phoenix' => ['arizona', 'phoenix'],
            'America/Los_Angeles' => ['california', 'los angeles', 'san francisco', 'san diego', 'seattle', 'oregon', 'portland', 'nevada', 'las vegas'],
            'America/Vancouver' => ['vancouver', 'british columbia'], 'America/Toronto' => ['toronto', 'ontario', 'ottawa', 'montreal', 'quebec']];
        foreach ($us as $tz => $words) foreach ($words as $w) if (str_contains($t, ' ' . $w . ' ')) return $tz;
        return null;
    }

    /** How people say it: "Manila time", "Dubai time", "Eastern Time". */
    public static function label(string $tz): string
    {
        $named = ['America/New_York' => 'Eastern Time', 'America/Toronto' => 'Eastern Time', 'America/Chicago' => 'Central Time', 'America/Denver' => 'Mountain Time', 'America/Phoenix' => 'Arizona time',
            'America/Los_Angeles' => 'Pacific Time', 'America/Vancouver' => 'Pacific Time', 'Europe/London' => 'UK time', 'UTC' => 'UTC'];
        if (isset($named[$tz])) return $named[$tz];
        $city = str_replace('_', ' ', substr($tz, strrpos($tz, '/') !== false ? strrpos($tz, '/') + 1 : 0));
        return $city . ' time';
    }

    /** A timestamp (stored in UTC) as the owner reads it: "Sun 28 Sep, 9:40 AM Manila time". */
    public static function local(int $wsId, $ts, bool $withLabel = true): string
    {
        if (! $ts) return 'time unknown';
        $tz = (string) (DB::table('workspaces')->where('id', $wsId)->value('timezone') ?: 'UTC');
        try { new \DateTimeZone($tz); } catch (\Throwable $e) { $tz = 'UTC'; }
        try { $c = \Carbon\Carbon::parse($ts, 'UTC')->setTimezone($tz); return $c->format('D j M, g:i A') . ($withLabel ? ' ' . self::label($tz) : ''); } catch (\Throwable $e) { return (string) $ts; }
    }
}
