<?php

namespace App\Engines\Social\Services;

use App\Core\Publisher\ConnectionHealth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SOCIAL-LEADS-3 (RFC-0016 P3). Facebook Lead Ads: a 'leadgen' webhook change carries only the leadgen id; the answers
 * are read from Graph (needs leads_retrieval) and become a CRM lead with source facebook_lead_ad. Sarah tells the Owner.
 * The id is unique, so a retried delivery never makes a second lead.
 */
class LeadAdService
{
    private const GRAPH = 'https://graph.facebook.com/v19.0';

    /** @return array<int,array{acct:object,leadgen_id:string,form_id:?string,ad_id:?string}> events to process after the response */
    public function events(array $payload): array
    {
        if (($payload['object'] ?? '') !== 'page') return [];
        $out = [];
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            $pageId = (string) ($entry['id'] ?? '');
            foreach ((array) ($entry['changes'] ?? []) as $ch) {
                if (($ch['field'] ?? '') !== 'leadgen') continue;
                $v = (array) ($ch['value'] ?? []);
                $pid = (string) ($v['page_id'] ?? $pageId);
                $acct = DB::table('social_accounts')->where('platform', 'facebook')->where('status', 'connected')
                    ->where(function ($q) use ($pid) { $q->where('linked_page_id', $pid)->orWhere('account_id', $pid); })->first();
                if ($acct && ! empty($v['leadgen_id'])) $out[] = ['acct_id' => (int) $acct->id, 'leadgen_id' => (string) $v['leadgen_id'], 'form_id' => $v['form_id'] ?? null, 'ad_id' => $v['ad_id'] ?? null];
            }
        }
        return $out;
    }

    /** Read the submission and make the lead. $fields lets a test hand in field_data without calling Graph. */
    public function process(array $ev, ?array $fields = null): ?int
    {
        $acct = DB::table('social_accounts')->where('id', $ev['acct_id'])->first();
        if (! $acct) return null;
        $dupe = DB::table('leads')->where('workspace_id', $acct->workspace_id)->whereNull('deleted_at')->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata_json, '$.leadgen_id')) = ?", [$ev['leadgen_id']])->value('id');
        if ($dupe) return (int) $dupe;
        if ($fields === null) {
            $c = ConnectionHealth::readCredentials($acct);
            $tok = (string) ($c['page_access_token'] ?? $c['access_token'] ?? '');
            $j = Http::timeout(15)->get(self::GRAPH . '/' . $ev['leadgen_id'], ['fields' => 'created_time,field_data,form_id,ad_id,campaign_name,ad_name', 'access_token' => $tok])->json() ?: [];
            if (isset($j['error'])) { Log::warning('[SOCIAL-LEADS-3] lead ad not readable', ['leadgen' => $ev['leadgen_id'], 'error' => mb_substr((string) ($j['error']['message'] ?? ''), 0, 200)]); return null; }
            $fields = [];
            foreach ((array) ($j['field_data'] ?? []) as $f) $fields[(string) ($f['name'] ?? '')] = (string) (($f['values'] ?? [])[0] ?? '');
            $ev['campaign'] = $j['campaign_name'] ?? null; $ev['ad'] = $j['ad_name'] ?? null;
        }
        $name = trim(($fields['full_name'] ?? '') ?: trim(($fields['first_name'] ?? '') . ' ' . ($fields['last_name'] ?? '')));
        $lead = app(\App\Engines\CRM\Services\CrmService::class)->createLead((int) $acct->workspace_id, [
            'name' => $name ?: 'Lead Ad contact', 'email' => ($fields['email'] ?? null) ?: null, 'phone' => ($fields['phone_number'] ?? $fields['phone'] ?? null) ?: null,
            'city' => ($fields['city'] ?? null) ?: null, 'source' => 'facebook_lead_ad',
            'metadata' => ['channel' => 'facebook_lead_ad', 'leadgen_id' => $ev['leadgen_id'], 'form_id' => $ev['form_id'] ?? null, 'ad_id' => $ev['ad_id'] ?? null,
                'campaign' => $ev['campaign'] ?? null, 'ad' => $ev['ad'] ?? null, 'answers' => $fields, 'business_id' => $acct->business_id, 'stage_note' => 'new - social'],
        ]);
        $who = $name ?: 'Someone';
        $answers = array_diff_key($fields, array_flip(['full_name', 'first_name', 'last_name', 'email', 'phone_number', 'phone']));
        $msg = "{$who} just filled in your Facebook lead form" . (! empty($ev['campaign']) ? " (\"{$ev['campaign']}\")" : '') . ' — they are in your CRM now'
            . (! empty($fields['phone_number']) || ! empty($fields['email']) ? ' with their contact details' : '') . ($answers ? '. They told you: ' . implode('; ', array_map(fn ($k, $v) => str_replace('_', ' ', $k) . ': ' . $v, array_keys($answers), $answers)) : '') . '. Worth calling them today while they are warm.';
        try {
            $rt = app(\App\Connectors\RuntimeClient::class);
            if ($rt->isConfigured()) {
                $r = $rt->chatJson('You are Sarah. In 2-3 sentences, from the FACTS only, tell the business owner a new lead came in from their Facebook lead form, who, what they said, that they are in the CRM, and the next step (contact them quickly). No emojis, no invented facts. Return ONLY JSON {"message":"..."}.',
                    'FACTS: ' . json_encode(['name' => $who, 'answers' => $answers, 'has_phone' => ! empty($fields['phone_number']), 'has_email' => ! empty($fields['email']), 'campaign' => $ev['campaign'] ?? null], JSON_UNESCAPED_UNICODE), ['task' => 'leadad_announce', 'workspace_id' => (string) $acct->workspace_id], 250);
                $m = trim((string) (($r['success'] ?? false) ? ($r['parsed']['message'] ?? '') : ''));
                if ($m !== '') $msg = mb_substr($m, 0, 1000);
            }
        } catch (\Throwable $e) {}
        app(\App\Core\Agents\AgentMessageService::class)->postAsAgent((int) $acct->workspace_id, 'sarah', $msg, ['kind' => 'lead_ad', 'lead_id' => (int) $lead->id]);
        $owner = DB::table('workspace_users')->where('workspace_id', $acct->workspace_id)->where('role', 'owner')->value('user_id');
        if ($owner) {
            try { app(\App\Core\Notifications\NotificationService::class)->dispatch(type: \App\Core\Notifications\NotificationTypes::AGENT_TASK_REQUIRES_APPROVAL, userId: (int) $owner,
                title: "New lead: {$who}", workspaceId: (int) $acct->workspace_id, body: 'From your Facebook lead form — in your CRM now.', severity: 'info', actionUrl: '/app/crm'); } catch (\Throwable $e) {}
        }
        return (int) $lead->id;
    }
}
