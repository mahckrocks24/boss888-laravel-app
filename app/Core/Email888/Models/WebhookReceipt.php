<?php

namespace App\Core\Email888\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * EMAIL888 — one row per webhook attempt, accepted or refused.
 */
class WebhookReceipt extends Model
{
    protected $table = 'email_webhook_receipts';

    // ── authentication ────────────────────────────────────────────────
    public const AUTH_ACCEPTED       = 'accepted';
    public const AUTH_BAD_SECRET     = 'refused_bad_secret';
    public const AUTH_BAD_BASIC      = 'refused_bad_basic';
    public const AUTH_UNCONFIGURED   = 'refused_unconfigured';
    /** Refused by the ROUTER — the URL never carried a secret of the right shape. */
    public const AUTH_MALFORMED_PATH = 'refused_malformed_path';
    /** Refused by our own rate limiter, before authentication was ever attempted. */
    public const AUTH_RATE_LIMITED   = 'refused_rate_limited';
    /** EM-8: the URL named a provider this installation has no adapter for. */
    public const AUTH_UNKNOWN_PROVIDER = 'refused_unknown_provider';

    // ── validation ────────────────────────────────────────────────────
    public const VALID          = 'valid';
    public const INVALID_EMPTY  = 'empty_payload';
    public const INVALID_SHAPE  = 'malformed';

    // ── stage reached ─────────────────────────────────────────────────
    public const STAGE_AUTH     = 'authentication';
    public const STAGE_VALIDATE = 'validation';
    public const STAGE_PROCESS  = 'processing';
    public const STAGE_COMPLETE = 'complete';

    protected $fillable = [
        'received_at', 'provider', 'endpoint', 'stream', 'request_id',
        'correlation_id', 'provider_message_id', 'event_type',
        'authentication_result', 'validation_result',
        'processing_stage', 'processing_result',
        'payload_digest', 'processing_latency_ms', 'operator_visible_reason',
        'client_ip', 'http_status',
        'replay_of_receipt_id', 'replayed_by_user_id', 'replay_reason',
    ];

    protected $casts = ['received_at' => 'datetime'];

    /**
     * Every way an attempt can be turned away, each distinguishable from the
     * others. "Refused" as a single bucket is what made a credential change
     * indistinguishable from a port scan.
     *
     * @return list<string>
     */
    public static function refusalReasons(): array
    {
        return [
            self::AUTH_BAD_SECRET,
            self::AUTH_BAD_BASIC,
            self::AUTH_UNCONFIGURED,
            self::AUTH_MALFORMED_PATH,
            self::AUTH_RATE_LIMITED,
            self::AUTH_UNKNOWN_PROVIDER,
        ];
    }

    public function isRefusal(): bool
    {
        return $this->authentication_result !== self::AUTH_ACCEPTED;
    }

    /**
     * A refusal that names OUR configuration rather than the caller's. These
     * are the ones that mean delivery observability is broken right now, as
     * opposed to someone knocking on the door.
     */
    public function indictsOurConfiguration(): bool
    {
        return in_array($this->authentication_result, [
            self::AUTH_BAD_BASIC,      // the path secret was right — that is almost certainly Postmark
            self::AUTH_UNCONFIGURED,   // we have no secret set at all
            self::AUTH_RATE_LIMITED,   // our limiter, not their credentials
        ], true);
    }

    /**
     * Success is the conjunction of all four, never any one of them.
     * An HTTP 200 with processing_result 'error' is not a success.
     */
    public function wasFullyProcessed(): bool
    {
        return $this->authentication_result === self::AUTH_ACCEPTED
            && $this->validation_result === self::VALID
            && $this->processing_stage === self::STAGE_COMPLETE
            && in_array($this->processing_result, ['applied', 'duplicate', 'out_of_order', 'ignored'], true);
    }

    /**
     * The stages this attempt actually reached, in order, each present only
     * because there is evidence for it. Nothing is interpolated to make the
     * journey look complete — a refused attempt genuinely has one stage.
     *
     * @return list<array{stage:string,outcome:string,detail:string}>
     */
    public function stages(): array
    {
        $out = [[
            'stage'   => 'received',
            'outcome' => 'observed',
            'detail'  => 'The request reached this application and was given a request id.',
        ]];

        $out[] = [
            'stage'   => 'authentication',
            'outcome' => $this->authentication_result === self::AUTH_ACCEPTED ? 'passed' : 'refused',
            'detail'  => match ($this->authentication_result) {
                self::AUTH_ACCEPTED       => 'Path secret matched; HTTP Basic credentials matched.',
                self::AUTH_BAD_SECRET     => 'The path secret did not match.',
                self::AUTH_BAD_BASIC      => 'The path secret matched but HTTP Basic did not — the caller knows the URL.',
                self::AUTH_UNCONFIGURED   => 'No webhook secret is configured, so everything is refused.',
                self::AUTH_MALFORMED_PATH => 'The URL did not carry a secret of the expected shape; refused by the router.',
                self::AUTH_RATE_LIMITED   => 'Refused by the rate limiter before authentication ran.',
                self::AUTH_UNKNOWN_PROVIDER => 'No adapter is registered for the provider named in the URL.',
                default                   => 'Refused.',
            },
        ];

        if ($this->validation_result !== null) {
            $out[] = [
                'stage'   => 'validation',
                'outcome' => $this->validation_result === self::VALID ? 'passed' : 'refused',
                'detail'  => match ($this->validation_result) {
                    self::VALID         => 'The body parsed as one or more provider events.',
                    self::INVALID_EMPTY => 'The body was empty or was not JSON.',
                    self::INVALID_SHAPE => 'The body parsed but contained no recognisable event.',
                    default             => 'Validation outcome recorded.',
                },
            ];
        }

        if ($this->processing_result !== null) {
            $out[] = [
                'stage'   => 'processing',
                'outcome' => $this->processing_result === 'error' ? 'failed' : 'completed',
                'detail'  => $this->operator_visible_reason
                    ?? 'Outcome: ' . $this->processing_result,
            ];
        }

        if ($this->wasFullyProcessed()) {
            $out[] = [
                'stage'   => 'ledger',
                'outcome' => 'settled',
                'detail'  => match ($this->processing_result) {
                    'applied'      => 'The delivery ledger was updated from this event.',
                    'duplicate'    => 'Already held; the ledger was correctly left unchanged.',
                    'out_of_order' => 'A later state was already recorded; the ledger was correctly left unchanged.',
                    default        => 'No ledger change was required for this record type.',
                },
            ];
        }

        return $out;
    }

    public function toAdminArray(): array
    {
        return [
            'id'                      => $this->id,
            'received_at'             => optional($this->received_at)->toIso8601String(),
            'provider'                => $this->provider,
            'endpoint'                => $this->endpoint,
            'stream'                  => $this->stream,
            'request_id'              => $this->request_id,
            'correlation_id'          => $this->correlation_id,
            'provider_message_id'     => $this->provider_message_id,
            'event_type'              => $this->event_type,
            'authentication_result'   => $this->authentication_result,
            'validation_result'       => $this->validation_result,
            'processing_stage'        => $this->processing_stage,
            'processing_result'       => $this->processing_result,
            'payload_digest'          => $this->payload_digest,
            'processing_latency_ms'   => $this->processing_latency_ms,
            'operator_visible_reason' => $this->operator_visible_reason,
            'http_status'             => $this->http_status,
            'fully_processed'         => $this->wasFullyProcessed(),
            'is_refusal'              => $this->isRefusal(),
            'indicts_our_config'      => $this->indictsOurConfiguration(),
            'replay_of_receipt_id'    => $this->replay_of_receipt_id,
            'replay_reason'           => $this->replay_reason,
        ];
    }
}
