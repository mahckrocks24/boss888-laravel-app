<?php

namespace App\Engines\CRM\Services;

use Illuminate\Support\Facades\DB;

/**
 * CRM-DATA-1 (Clients revamp Phase 1): who a client is, which business they belong to, and where they came from.
 *
 *  - business:  explicit business_id > the website they used > the social account/page > the workspace's only
 *               business > its default business. Every answer is recorded in leads.business_source so an
 *               owner (and Sarah) can see which ones were a guess ('default').
 *  - person:    one row per real human per workspace, matched on email or phone. The same person dealing with two
 *               of an owner's businesses has two client profiles (leads) and one person.
 *  - channel:   ten plain channel names instead of the 21 source spellings found in the audit.
 */
class ClientIdentity
{
    public const CHANNELS = ['website', 'booking', 'chatbot', 'facebook', 'instagram', 'messenger', 'store', 'import', 'manual', 'other'];

    public static function channelFor(?string $source): string
    {
        $s = strtolower(trim((string) $source));
        if ($s === '') return 'manual';
        return match (true) {
            in_array($s, ['website_form', 'contact_form', 'website', 'form', 'web', 'landing_page', 'kabayan_jobs', 'jobs_form'], true) => 'website',
            str_starts_with($s, 'levelupgrowth_') || str_starts_with($s, 'markraymundo_') || $s === 'rfp' => 'website', // own-site forms, waitlist, intake
            str_contains($s, 'booking') || $s === 'appointment' => 'booking',
            str_contains($s, 'chatbot') || in_array($s, ['chat', 'chat inquiry', 'conversation'], true) => 'chatbot',
            $s === 'facebook_messenger' || $s === 'messenger' => 'messenger',
            str_starts_with($s, 'instagram') => 'instagram',
            str_starts_with($s, 'facebook') => 'facebook',
            in_array($s, ['order', 'store_order', 'store', 'shop'], true) => 'store',
            $s === 'import' || $s === 'csv' => 'import',
            in_array($s, ['manual', 'referral', 'phone', 'walk_in', 'email', 'social', 'api', 'sarah', 'agent'], true) => 'manual',
            default => 'other',
        };
    }

    public static function phoneKey(?string $phone): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $phone);
        return strlen($d) >= 7 ? substr($d, -10) : null;
    }

    public static function emailKey(?string $email): ?string
    {
        $e = strtolower(trim((string) $email));
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : null;
    }

    /** @return array{0: ?int, 1: ?string} [business_id, how it was decided] */
    public static function resolveBusiness(int $wsId, array $data): array
    {
        $meta = is_array($data['metadata'] ?? null) ? $data['metadata'] : (is_array($data['metadata_json'] ?? null) ? $data['metadata_json'] : []);
        $valid = fn ($id) => $id && DB::table('businesses')->where('workspace_id', $wsId)->where('id', (int) $id)->whereNull('deleted_at')->exists() ? (int) $id : null;

        if ($b = $valid($data['business_id'] ?? null)) return [$b, 'explicit'];
        foreach ([$data['website_id'] ?? null, $meta['website_id'] ?? null] as $w) {
            if ($w && ($b = $valid(DB::table('websites')->where('workspace_id', $wsId)->where('id', (int) $w)->value('business_id')))) return [$b, 'website'];
        }
        if ($b = $valid($meta['business_id'] ?? null)) return [$b, 'social'];
        $all = DB::table('businesses')->where('workspace_id', $wsId)->whereNull('deleted_at')->orderByDesc('is_default')->orderBy('id')->pluck('id');
        if ($all->count() === 1) return [(int) $all->first(), 'only'];
        if ($all->count() > 1) return [(int) $all->first(), 'default'];
        return [null, null];
    }

    /** Find or create the person; any new email/phone is added to their record. */
    public static function personFor(int $wsId, ?string $name, ?string $email, ?string $phone): ?int
    {
        $ek = self::emailKey($email); $pk = self::phoneKey($phone);
        if (! $ek && ! $pk) {
            // no way to match a human without contact details: still one person, never merged
            return (int) DB::table('people')->insertGetId(['workspace_id' => $wsId, 'name' => $name ?: null, 'created_at' => now(), 'updated_at' => now()]);
        }
        $p = null;
        if ($ek) $p = DB::table('people')->where('workspace_id', $wsId)->where('email', $ek)->first();
        if (! $p && $ek) $p = DB::table('people')->where('workspace_id', $wsId)->whereRaw('JSON_CONTAINS(emails_json, ?)', [json_encode($ek)])->first();
        if (! $p && $pk) $p = DB::table('people')->where('workspace_id', $wsId)->where('phone_key', $pk)->first();
        if (! $p && $pk) $p = DB::table('people')->where('workspace_id', $wsId)->whereRaw('JSON_CONTAINS(phones_json, ?)', [json_encode($pk)])->first();
        if (! $p) {
            return (int) DB::table('people')->insertGetId(['workspace_id' => $wsId, 'name' => $name ?: null, 'email' => $ek, 'phone_key' => $pk, 'phone' => $phone ?: null,
                'emails_json' => json_encode($ek ? [$ek] : []), 'phones_json' => json_encode($pk ? [$pk] : []), 'created_at' => now(), 'updated_at' => now()]);
        }
        $emails = json_decode((string) $p->emails_json, true) ?: []; $phones = json_decode((string) $p->phones_json, true) ?: [];
        $upd = [];
        if ($ek && ! in_array($ek, $emails, true)) { $emails[] = $ek; $upd['emails_json'] = json_encode($emails); }
        if ($pk && ! in_array($pk, $phones, true)) { $phones[] = $pk; $upd['phones_json'] = json_encode($phones); }
        if (! $p->email && $ek) $upd['email'] = $ek;
        if (! $p->phone_key && $pk) { $upd['phone_key'] = $pk; $upd['phone'] = $phone; }
        if (! $p->name && $name) $upd['name'] = $name;
        if ($upd) DB::table('people')->where('id', $p->id)->update($upd + ['updated_at' => now()]);
        return (int) $p->id;
    }

    /** The existing client profile for this human in this business (dedupe on capture), or null. */
    public static function existingProfile(int $wsId, ?int $businessId, ?string $email, ?string $phone): ?object
    {
        $ek = self::emailKey($email); $pk = self::phoneKey($phone);
        if (! $ek && ! $pk) return null;
        $q = DB::table('leads')->where('workspace_id', $wsId)->whereNull('deleted_at');
        $businessId ? $q->where('business_id', $businessId) : $q->whereNull('business_id');
        $q->where(function ($w) use ($ek, $pk) {
            if ($ek) $w->orWhereRaw('LOWER(email) = ?', [$ek]);
            if ($pk) $w->orWhereRaw("RIGHT(REGEXP_REPLACE(COALESCE(phone,''), '[^0-9]', ''), 10) = ?", [$pk]);
        });
        return $q->orderBy('id')->first();
    }
}
