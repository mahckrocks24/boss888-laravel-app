<?php

namespace App\Engines\Infrastructure\Email\Customer;

use App\Engines\Infrastructure\Email\Models\EmailDomain;
use App\Engines\Infrastructure\Email\States\EmailVerificationState;

/**
 * INFRA888 · E4 — the simplified health a customer may see.
 *
 * ─── WHAT IS DELIBERATELY NOT HERE ──────────────────────────────────────────
 *
 * The estate's real health model is rich: custody, a five-rung confidence
 * ladder, a thirteen-kind drift taxonomy, alert eligibility, observation
 * provenance. All of it is correct and all of it is operator vocabulary.
 *
 * A customer needs to answer one question — "is my email working, and if not,
 * what do I do?" — and every extra dimension makes that answer harder to find
 * rather than more honest. So this exposes four named checks, an overall
 * status, and when we last looked. Nothing else.
 *
 * ─── AND THE ONE THING THAT WOULD BE DISHONEST ──────────────────────────────
 *
 * "Unknown" is a real answer and is never rendered as "healthy". A domain we
 * have not observed is reported as not-yet-checked, not as working. That
 * distinction is the whole reason the observation stack records confidence, and
 * dropping it at the last mile would undo it.
 */
final class CustomerHealth
{
    public const HEALTHY = 'healthy';
    public const ACTION_REQUIRED = 'action_required';
    public const PENDING_VERIFICATION = 'pending_verification';
    public const TEMPORARILY_UNAVAILABLE = 'temporarily_unavailable';

    /** @return array<int,string> */
    public static function statuses(): array
    {
        return [self::HEALTHY, self::ACTION_REQUIRED, self::PENDING_VERIFICATION, self::TEMPORARILY_UNAVAILABLE];
    }

    /**
     * The four checks, in the customer's language, with the standard technical
     * label alongside. Both are permitted: "Mail delivery (MX)" is more useful
     * to someone pasting records into a DNS panel than either half alone.
     *
     * @return array<string,array{label:string,technical:string,why:string}>
     */
    public static function checks(): array
    {
        return [
            'mx' => [
                'label'     => 'Mail delivery',
                'technical' => 'MX',
                'why'       => 'Tells the internet where to deliver mail sent to your domain.',
            ],
            'spf' => [
                'label'     => 'Sender authorization',
                'technical' => 'SPF',
                'why'       => 'Tells other mail services that we are allowed to send on your behalf.',
            ],
            'dkim' => [
                'label'     => 'Email signing',
                'technical' => 'DKIM',
                'why'       => 'Signs your mail so recipients can confirm it was not altered.',
            ],
            'dmarc' => [
                'label'     => 'Email protection',
                'technical' => 'DMARC',
                'why'       => 'Tells other mail services what to do with mail that fails the checks above.',
            ],
        ];
    }

    /** DMARC is advisory: mail flows without it, so its absence is not a failure. */
    public static function required(): array
    {
        return ['mx', 'spf', 'dkim'];
    }

    /**
     * Build the customer health payload for one domain.
     *
     * @param array<string,bool|null> $observed check key => pass/fail/unknown
     */
    public static function forDomain(EmailDomain $domain, array $observed = []): array
    {
        $verification = (string) $domain->verification_state;
        $checks = [];
        $failing = [];
        $unknown = [];

        foreach (self::checks() as $key => $meta) {
            // null means "we have not established this", which is NOT the same
            // as false and must never be rendered as a pass.
            $state = array_key_exists($key, $observed) ? $observed[$key] : null;

            if ($state === null && $verification === EmailVerificationState::VERIFIED) {
                // A verified domain has had its required records observed.
                $state = in_array($key, self::required(), true) ? true : null;
            }

            $checks[] = [
                'key'       => $key,
                'label'     => $meta['label'],
                'technical' => $meta['technical'],
                'why'       => $meta['why'],
                'required'  => in_array($key, self::required(), true),
                'status'    => $state === true ? 'ok' : ($state === false ? 'not_found' : 'not_checked'),
            ];

            if ($state === false && in_array($key, self::required(), true)) {
                $failing[] = $key;
            }

            if ($state === null) {
                $unknown[] = $key;
            }
        }

        $status = self::overall($domain, $failing, $unknown);

        return [
            'status'      => $status,
            'label'       => self::labels()[$status],
            'detail'      => self::details()[$status],
            'checks'      => $checks,
            'service'     => self::serviceAvailability($domain),
            'last_checked' => optional($domain->last_observed_at)->toIso8601String(),
            'warnings'    => self::warnings($domain, $failing, $unknown),
        ];
    }

    private static function overall(EmailDomain $domain, array $failing, array $unknown): string
    {
        if ($domain->health_state === 'degraded') {
            return self::TEMPORARILY_UNAVAILABLE;
        }

        if ($failing !== []) {
            return self::ACTION_REQUIRED;
        }

        if ($domain->verification_state !== EmailVerificationState::VERIFIED) {
            return self::PENDING_VERIFICATION;
        }

        // Verified, nothing failing — but if we have never actually looked,
        // say so rather than claiming health we have not measured.
        if ($domain->last_observed_at === null) {
            return self::PENDING_VERIFICATION;
        }

        return self::HEALTHY;
    }

    /**
     * Provider availability, renamed. The customer's provider is LevelUp
     * Growth; "Business Email service" is the only name they see for it.
     */
    private static function serviceAvailability(EmailDomain $domain): array
    {
        $available = $domain->health_state !== 'degraded';

        return [
            'label'     => 'Business Email service',
            'available' => $available,
            'detail'    => $available
                ? 'The Business Email service is available.'
                : 'The Business Email service is temporarily unavailable. Your settings are unchanged.',
        ];
    }

    /** @return array<int,array{severity:string,message:string,action:string|null}> */
    private static function warnings(EmailDomain $domain, array $failing, array $unknown): array
    {
        $out = [];
        $labels = self::checks();

        foreach ($failing as $key) {
            $out[] = [
                'severity' => 'high',
                'message'  => $labels[$key]['label'] . ' is not active for this domain.',
                'action'   => 'Add the ' . $labels[$key]['technical'] . ' record shown in Domain Setup.',
            ];
        }

        if ($domain->verification_state === EmailVerificationState::DRIFTED) {
            $out[] = [
                'severity' => 'high',
                'message'  => 'Your DNS records have changed since we last verified them.',
                'action'   => 'Check the records in Domain Setup and run a DNS check.',
            ];
        }

        if (in_array('dmarc', $unknown, true) || ($domain->settings_json['dmarc_monitoring_only'] ?? false)) {
            $out[] = [
                'severity' => 'low',
                'message'  => 'Your email protection policy is monitoring only.',
                'action'   => 'This is a safe default. Tighten it once you are confident all your mail passes.',
            ];
        }

        if ($domain->lifecycle_state === 'provisioning_failed' || $domain->lifecycle_state === 'reconciling') {
            $out[] = [
                'severity' => 'medium',
                // No internal state name, no failure code, no provider.
                'message'  => 'We are reviewing a setup issue on this domain.',
                'action'   => null,
            ];
        }

        return $out;
    }

    /** @return array<string,string> */
    public static function labels(): array
    {
        return [
            self::HEALTHY                 => 'Healthy',
            self::ACTION_REQUIRED         => 'Action required',
            self::PENDING_VERIFICATION    => 'Pending verification',
            self::TEMPORARILY_UNAVAILABLE => 'Temporarily unavailable',
        ];
    }

    /** @return array<string,string> */
    public static function details(): array
    {
        return [
            self::HEALTHY                 => 'Your email is set up correctly.',
            self::ACTION_REQUIRED         => 'Something needs your attention before mail will work correctly.',
            self::PENDING_VERIFICATION    => 'We have not yet confirmed your DNS records.',
            self::TEMPORARILY_UNAVAILABLE => 'We could not check this just now. Your settings are unchanged.',
        ];
    }
}
