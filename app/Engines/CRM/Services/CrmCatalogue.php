<?php

namespace App\Engines\CRM\Services;

use App\Core\Email888\TenantEmail;
use App\Models\Activity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * CRM-PACKS-4c: what a client is interested in, from the business's own catalogue (the listings, rooms, programs,
 * services and packages on its website). For property: buyer ↔ listing matches from the client's budget, bedrooms,
 * areas and whether they buy or rent. Sending a selection goes out white-label with each item's own page.
 */
class CrmCatalogue
{
    public const KINDS = [
        'property' => [['listing'], 'Listings'], 'stays' => [['room', 'package', 'event'], 'Rooms and packages'], 'enrolment' => [['program', 'session', 'plan'], 'Programs'],
        'appointments' => [['service', 'package', 'plan'], 'Services'], 'projects' => [['service', 'package', 'project'], 'Services'], 'guests' => [['menu', 'service', 'package'], 'Menu and services'],
        'audience' => [['plan', 'package', 'service'], 'Packages'], 'general' => [['service', 'package', 'plan', 'listing', 'room', 'program', 'menu', 'project', 'vehicle', 'event', 'session'], 'Catalogue'],
    ];

    private function websiteIds(int $ws, ?int $bizId): array
    {
        $q = DB::table('websites')->where('workspace_id', $ws)->whereNull('deleted_at');
        $ids = $bizId ? (clone $q)->where('business_id', $bizId)->pluck('id')->all() : [];
        return $ids ?: $q->pluck('id')->all();
    }

    public function items(int $ws, ?int $bizId, array $kinds): \Illuminate\Support\Collection
    {
        $sites = $this->websiteIds($ws, $bizId);
        if (! $sites) return collect();
        return DB::table('catalogue_items')->whereIn('website_id', $sites)->whereIn('kind', $kinds)->whereNull('deleted_at')
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', ['sold', 'closed', 'archived', 'hidden']))
            ->orderByDesc('featured')->orderBy('sort_order')->limit(200)->get();
    }

    public function url(object $it): string
    {
        $site = DB::table('websites')->where('id', $it->website_id)->first(['custom_domain', 'domain_verified', 'subdomain']);
        $host = $site ? ((! empty($site->custom_domain) && ! empty($site->domain_verified) ? $site->custom_domain : null) ?: $site->subdomain) : null;
        if (! $host) return '';   // white-label: an item without its own site address is sent without a link, never with the platform's
        $base = 'https://' . preg_replace('#^https?://#', '', rtrim((string) $host, '/'));
        $dirs = glob(storage_path('app/public/sites/' . (int) $it->website_id . '/*-' . preg_replace('/[^a-z0-9\-]/', '', (string) $it->slug)), GLOB_ONLYDIR) ?: [];
        return $base . ($dirs ? '/' . basename($dirs[0]) . '/' : '/');
    }

    public function photo(object $it): ?string
    {
        $p = (json_decode((string) $it->photos_json, true) ?: [])[0] ?? null;
        if (! is_string($p) || $p === '') return null;
        return preg_match('#^https?://#', $p) ? $p : rtrim((string) config('app.url'), '/') . '/' . ltrim($p, '/');
    }

    public function shape(object $it): array
    {
        $a = json_decode((string) $it->attrs_json, true) ?: [];
        $cur = (string) ($it->currency ?: '');
        $price = $it->price !== null ? (preg_match('/^[A-Z]{3}$/', $cur) ? app(CrmPayments::class)->money($cur, (float) $it->price) : $cur . number_format((float) $it->price, 0)) . ($it->price_period ? ' / ' . $it->price_period : '') : ($it->price_label ?: null);
        return ['id' => $it->id, 'kind' => $it->kind, 'title' => $it->title, 'status' => $it->status, 'price' => $price, 'price_value' => $it->price !== null ? (float) $it->price : null,
            'beds' => $a['beds'] ?? null, 'baths' => $a['baths'] ?? null, 'location' => $a['location'] ?? null, 'specs' => $a['specs_text'] ?? ($it->summary ?: null),
            'photo' => $this->photo($it), 'url' => $this->url($it)];
    }

    private static function number(?string $s): ?float
    {
        $s = strtolower(str_replace([',', ' '], '', (string) $s));
        if (! preg_match('/(\d+(?:\.\d+)?)(k|m|million)?/', $s, $m)) return null;
        $v = (float) $m[1];
        return $v * (in_array($m[2] ?? '', ['m', 'million'], true) ? 1000000 : (($m[2] ?? '') === 'k' ? 1000 : 1));
    }

    /** For a property client: listings that fit what they told the business, best first, each with why. */
    public function matches(int $ws, object $lead, array $pack): array
    {
        if (($pack['key'] ?? '') !== 'property') return [];
        $f = (array) ((json_decode((string) $lead->metadata_json, true) ?: [])['fields'] ?? []);
        $budget = self::number($f['budget'] ?? null); $beds = self::number($f['bedrooms'] ?? null);
        $areas = array_filter(array_map('trim', preg_split('/[,;\/]| and /i', mb_strtolower((string) ($f['areas'] ?? '')))));
        $role = mb_strtolower((string) ($f['role'] ?? ''));
        if (! $budget && ! $beds && ! $areas) return [];
        $out = [];
        foreach ($this->items($ws, $lead->business_id ? (int) $lead->business_id : null, ['listing']) as $it) {
            $s = $this->shape($it); $score = 0; $why = [];
            $st = mb_strtolower((string) $it->status);
            if (str_contains($role, 'rent') && ! str_contains($role, 'let') && $st && ! str_contains($st, 'rent')) continue;
            if (str_contains($role, 'buy') && str_contains($st, 'rent')) continue;
            if ($budget && $s['price_value']) { if ($s['price_value'] > $budget * 1.1) continue; $score += 3; $why[] = 'within budget'; }
            if ($beds && $s['beds'] !== null) { if ((float) $s['beds'] < $beds) continue; $score += 2; $why[] = (int) $s['beds'] . ' bedrooms'; }
            $hay = mb_strtolower($it->title . ' ' . ($s['location'] ?? ''));
            foreach ($areas as $ar) if ($ar !== '' && str_contains($hay, $ar)) { $score += 3; $why[] = 'in ' . ucwords($ar); break; }
            if ($areas && ! array_filter($areas, fn ($ar) => $ar !== '' && str_contains($hay, $ar))) $score -= 1;
            $out[] = $s + ['score' => $score, 'why' => $why, 'in_area' => (bool) array_filter($areas, fn ($ar) => $ar !== '' && str_contains($hay, $ar))];
        }
        // in their areas first, and only, when there are any
        if ($areas && array_filter($out, fn ($x) => $x['in_area'])) $out = array_values(array_filter($out, fn ($x) => $x['in_area']));
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice(array_values(array_filter($out, fn ($x) => $x['score'] > 0)), 0, 6);
    }

    /** Email a selection of items to the client, as the business. */
    public function send(int $ws, object $lead, array $itemIds, string $note, ?int $userId): array
    {
        $to = ClientIdentity::emailKey($lead->email);
        if (! $to) return ['success' => false, 'error' => 'This client has no email address.'];
        $sites = $this->websiteIds($ws, $lead->business_id ? (int) $lead->business_id : null);
        $items = DB::table('catalogue_items')->whereIn('id', array_map('intval', $itemIds))->whereIn('website_id', $sites ?: [0])->whereNull('deleted_at')->limit(8)->get();
        if ($items->isEmpty()) return ['success' => false, 'error' => 'Pick at least one item.'];
        $tid = TenantEmail::identity($ws, $lead->business_id ? (int) $lead->business_id : null);
        $first = trim(explode(' ', (string) $lead->name)[0]) ?: 'there';
        $cards = '';
        foreach ($items as $it) {
            $s = $this->shape($it);
            $a = fn (string $inner, string $style = '') => $s['url'] !== '' ? '<a href="' . e($s['url']) . '" style="' . $style . '">' . $inner . '</a>' : '<span style="' . $style . '">' . $inner . '</span>';
            $cards .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 16px;border:1px solid #E5E7EB;border-radius:12px;border-collapse:separate;overflow:hidden">'
                . ($s['photo'] ? '<tr><td style="line-height:0">' . $a('<img src="' . e($s['photo']) . '" width="528" alt="" style="display:block;width:100%;height:auto;border:0">') . '</td></tr>' : '')
                . '<tr><td style="padding:14px 16px;font:15px/1.5 Arial,Helvetica,sans-serif;color:#1F2937">' . $a(e($s['title']), 'font-weight:700;color:#111827;text-decoration:none;font-size:16px')
                . ($s['price'] ? '<div style="font-weight:700;color:#111827;margin-top:4px">' . e($s['price']) . '</div>' : '')
                . ($s['location'] ? '<div style="color:#6B7280;font-size:13px;margin-top:2px">' . e($s['location']) . '</div>' : '')
                . ($s['specs'] ? '<div style="color:#374151;font-size:13px;margin-top:6px">' . e(mb_substr($s['specs'], 0, 180)) . '</div>' : '')
                . ($s['url'] !== '' ? '<div style="margin-top:10px"><a href="' . e($s['url']) . '" style="color:#111827;font-weight:600">See the details &rarr;</a></div>' : '') . '</td></tr></table>';
        }
        $body = '<p style="margin:0 0 14px">Hi ' . e($first) . ',</p><p style="margin:0 0 18px">' . ($note !== '' ? nl2br(e($note)) : 'Here are a few we think you will like, picked from what you told us.') . '</p>' . $cards
            . '<p style="margin:6px 0 0">Reply to this email if you would like to see any of them.</p>';
        $subject = count($items) === 1 ? $items[0]->title : count($items) . ' picked for you';
        try {
            $html = TenantEmail::layout($tid, '', $body, null, null, 'Picked for you by ' . $tid['name'], ['hero' => false, 'signoff' => 'The ' . $tid['name'] . ' team']);
            $plain = "Hi {$first},\n\n" . implode("\n", $items->map(fn ($it) => '- ' . $it->title . ($this->url($it) !== '' ? ': ' . $this->url($it) : ''))->all()) . "\n\n" . $tid['name'];
            Mail::html($html, function ($m) use ($plain, $ws, $lead, $tid, $to, $subject) {
                $m->text($plain);
                $h = $m->getSymfonyMessage()->getHeaders();
                $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_PURPOSE, 'tenant_transactional');
                $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_WORKSPACE, (string) $ws);
                if ($lead->business_id) $h->addTextHeader(\App\Core\Email888\OutboundPolicy::HDR_BUSINESS, (string) $lead->business_id);
                $m->from($tid['address'], $tid['name'])->to($to, $lead->name ?: null)->subject($subject);
                if (! empty($tid['reply_to'])) $m->replyTo($tid['reply_to'], $tid['name']);
            });
        } catch (\Throwable $e) {
            try { app(\App\Core\Email888\DeliveryLedger::class)->markLastRecordedFailed($e); } catch (\Throwable $x) {}
            return ['success' => false, 'error' => 'The email could not be sent.'];
        }
        Activity::create(['workspace_id' => $ws, 'activitable_type' => 'Lead', 'activitable_id' => (int) $lead->id, 'type' => 'email', 'completed' => 1, 'performed_by' => $userId,
            'subject' => 'Sent ' . $items->count() . ' to look at: ' . mb_substr($items->pluck('title')->implode(', '), 0, 200), 'metadata_json' => ['items' => $items->pluck('id')->all()]]);
        DB::table('leads')->where('id', $lead->id)->update(['last_contacted_at' => now()]);
        if ($lead->status === 'new') {   // sending them something is contact
            $pack = CrmPacks::forBusiness($lead->business_id ? (int) $lead->business_id : null);
            DB::table('leads')->where('id', $lead->id)->update(['status' => 'contacted', 'stage' => collect($pack['stages'])->firstWhere('status', 'contacted')['key'] ?? null]);
        }
        return ['success' => true, 'sent' => $items->count()];
    }
}
