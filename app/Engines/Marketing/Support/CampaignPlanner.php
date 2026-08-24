<?php

namespace App\Engines\Marketing\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * EM-7 PHASE 12 — normalises either campaign shape into one dispatch plan.
 *
 * PURE AND DETERMINISTIC. It reads campaign, lead and template rows; it sends
 * nothing, queues nothing, and touches no provider. That is what makes the
 * equivalence proof possible: the same campaign row must produce the same plan
 * whether it is about to be sent inline or handed to a worker, and a plan can be
 * compared without anybody receiving an email.
 *
 * THE LEADS LOOKUP IS BATCHED HERE ON PURPOSE.
 * The synchronous path ran one `leads` query PER RECIPIENT inside the send loop.
 * At a thousand recipients that is a thousand queries interleaved with a
 * thousand provider calls, inside one HTTP request. Resolving them in a single
 * query is not an optimisation detail — it is most of the reason the request
 * path can stop scaling with audience size.
 */
class CampaignPlanner
{
    public function plan(int $campaignId, ?int $workspaceId = null): CampaignDispatchPlan
    {
        $q = DB::table('campaigns')->where('id', $campaignId);
        if ($workspaceId !== null) {
            $q->where('workspace_id', $workspaceId);
        }

        $campaign = $q->first();

        if (! $campaign) {
            throw new RuntimeException('Campaign not found');
        }

        $wsId = (int) ($workspaceId ?? $campaign->workspace_id);
        $raw  = json_decode($campaign->recipients_json ?? '[]', true);
        $raw  = is_array($raw) ? $raw : [];

        // Historical behaviour, preserved exactly: an empty recipient list falls
        // back to every lead in the workspace that has an address.
        if ($raw === []) {
            $raw = DB::table('leads')->where('workspace_id', $wsId)
                ->whereNotNull('email')->where('email', '!=', '')
                ->pluck('email')->toArray();
        }

        if ($raw === []) {
            throw new RuntimeException('No recipients');
        }

        $templateId = $campaign->template_id !== null ? (int) $campaign->template_id : null;
        $recipients = $this->normalise($raw);

        // Only the body_html shape can be rendered ahead of time; see
        // CampaignRecipient for why the templated shape cannot.
        if ($templateId === null) {
            $recipients = $this->render($recipients, (string) ($campaign->body_html ?? ''), $wsId);
        }

        return new CampaignDispatchPlan(
            campaignId:  (int) $campaign->id,
            workspaceId: $wsId,
            subject:     (string) ($campaign->subject ?? ''),
            templateId:  $templateId,
            recipients:  $recipients,
        );
    }

    /**
     * Accepts BOTH historical shapes — a flat list of address strings, or a list
     * of objects — and produces one list. Entries carrying no address at all are
     * kept with an empty address rather than dropped: the send loop refuses them
     * by name, and silently shrinking an audience is how a campaign reports
     * success over fewer people than the operator chose.
     *
     * @param  list<mixed> $raw
     * @return list<CampaignRecipient>
     */
    private function normalise(array $raw): array
    {
        $out = [];

        foreach ($raw as $entry) {
            if (is_string($entry)) {
                $out[] = new CampaignRecipient(address: trim($entry));
                continue;
            }

            if (! is_array($entry)) {
                $out[] = new CampaignRecipient(address: '');
                continue;
            }

            $out[] = new CampaignRecipient(
                address:        trim((string) ($entry['email'] ?? '')),
                name:           (string) ($entry['name'] ?? ''),
                company:        (string) ($entry['company'] ?? ''),
                variables:      is_array($entry['variables'] ?? null) ? $entry['variables'] : [],
                subjectVariant: isset($entry['variant']) ? (string) $entry['variant'] : null,
            );
        }

        return $out;
    }

    /**
     * Merge-tag substitution for the body_html shape, with the whole audience's
     * leads resolved in ONE query instead of one per recipient.
     *
     * @param  list<CampaignRecipient> $recipients
     * @return list<CampaignRecipient>
     */
    private function render(array $recipients, string $bodyHtml, int $wsId): array
    {
        $addresses = array_values(array_filter(array_map(
            fn (CampaignRecipient $r) => $r->address,
            $recipients,
        )));

        $leads = $addresses === []
            ? collect()
            : DB::table('leads')->where('workspace_id', $wsId)
                ->whereIn('email', $addresses)->get()->keyBy('email');

        $out = [];

        foreach ($recipients as $r) {
            $lead = $leads[$r->address] ?? null;

            $name    = $r->name    !== '' ? $r->name    : (string) ($lead->name    ?? 'there');
            $company = $r->company !== '' ? $r->company : (string) ($lead->company ?? '');

            $html = strtr($bodyHtml, [
                '{{name}}'        => $name,
                '{{email}}'       => $r->address,
                '{{company}}'     => $company,
                '{{first_name}}'  => explode(' ', $name)[0] ?? '',
                '{{unsubscribe}}' => '<a href="#">Unsubscribe</a>',
            ]);

            $out[] = new CampaignRecipient(
                address:        $r->address,
                name:           $name,
                company:        $company,
                variables:      $r->variables,
                html:           $html,
                subjectVariant: $r->subjectVariant,
            );
        }

        return $out;
    }
}
