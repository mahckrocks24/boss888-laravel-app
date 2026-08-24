<?php

namespace App\Connectors\Infrastructure\Namecheap;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Low-level Namecheap XML API transport.
 *
 * Responsibilities, and deliberately nothing else:
 *   - build the authenticated query
 *   - execute it against the environment-appropriate endpoint
 *   - parse the XML envelope
 *   - classify failures as transient or permanent
 *
 * SECRET HYGIENE
 * The API key is only ever placed into the outbound query string. It is never
 * logged, never returned, never included in an exception message, and never
 * written to an audit record. redactedContext() is the only structure that
 * leaves this class for logging purposes, and it is built from an allow-list.
 */
class NamecheapClient
{
    /** Namecheap error numbers that represent a transient condition worth retrying. */
    private const TRANSIENT_ERRORS = [
        '4022337',  // temporary system error
        '5050900',  // unhandled internal error
        '2011294',  // too many requests
    ];

    /** Error numbers that mean "the request never reached a billable state". Safe to treat as clean failure. */
    private const NON_BILLABLE_ERRORS = [
        '1010101',  // parameter missing
        '1010102',  // parameter invalid
        '1011102',  // APIKey invalid
        '1011150',  // IP not whitelisted
        '2019166',  // domain not found
        '2016166',  // domain not owned by this account
        // domains.getInfo returns this for a domain that is not in this
        // account. The message reads "Domain is invalid", which is misleading:
        // it means invalid *for this account*, not malformed.
        '2030166',
    ];

    public function __construct(
        private readonly string $environment,
        private readonly string $endpoint,
        private readonly string $apiUser,
        private readonly string $apiKey,
        private readonly string $username,
        private readonly string $clientIp,
        private readonly int $timeout = 30,
    ) {
    }

    public static function fromConfig(?string $environment = null): self
    {
        $env = $environment ?: (string) config('namecheap.environment', 'sandbox');
        $creds = (array) config("namecheap.credentials.{$env}", []);

        return new self(
            environment: $env,
            endpoint: (string) config("namecheap.endpoints.{$env}"),
            apiUser: (string) ($creds['api_user'] ?? ''),
            apiKey: (string) ($creds['api_key'] ?? ''),
            username: (string) ($creds['username'] ?? $creds['api_user'] ?? ''),
            clientIp: (string) config('namecheap.client_ip', ''),
            timeout: (int) config('namecheap.timeout_seconds', 30),
        );
    }

    public function environment(): string
    {
        return $this->environment;
    }

    /** True only when every credential field required to authenticate is present. */
    public function isConfigured(): bool
    {
        return $this->apiUser !== ''
            && $this->apiKey !== ''
            && $this->username !== ''
            && $this->clientIp !== '';
    }

    /**
     * Names of the credential fields that are missing. Returns field NAMES only --
     * never values, never partial values.
     */
    public function missingCredentials(): array
    {
        $missing = [];
        foreach ([
            'api_user'  => $this->apiUser,
            'api_key'   => $this->apiKey,
            'username'  => $this->username,
            'client_ip' => $this->clientIp,
        ] as $name => $value) {
            if ($value === '') {
                $missing[] = $name;
            }
        }

        return $missing;
    }

    /**
     * A non-reversible fingerprint of the configured key, so an operator can
     * confirm WHICH key is loaded without the key ever being displayed.
     */
    public function credentialFingerprint(): ?string
    {
        return $this->apiKey === '' ? null : substr(hash('sha256', $this->apiKey), 0, 16);
    }

    /**
     * Execute a Namecheap command.
     *
     * @return array{ok:bool,command:string,data:?\SimpleXMLElement,errors:array,error_code:?string,
     *                error_summary:?string,retry:string,http_status:?int,duration_ms:int}
     */
    public function call(string $command, array $params = [], ?int $timeoutOverride = null): array
    {
        if (! $this->isConfigured()) {
            return $this->failure(
                $command,
                'CREDENTIALS_MISSING',
                'Namecheap credentials are not configured: missing ' . implode(', ', $this->missingCredentials()),
                'permanent'
            );
        }

        $query = array_merge([
            'ApiUser'   => $this->apiUser,
            'ApiKey'    => $this->apiKey,
            'UserName'  => $this->username,
            'ClientIp'  => $this->clientIp,
            'Command'   => $command,
        ], $params);

        $started = microtime(true);

        try {
            $response = Http::timeout($timeoutOverride ?? $this->timeout)
                ->asForm()
                ->post($this->endpoint, $query);
        } catch (\Throwable $e) {
            // The exception message can contain the full request URL, which would
            // contain the API key. Never propagate it.
            return $this->failure(
                $command,
                'TRANSPORT_ERROR',
                'HTTP transport to Namecheap failed (' . class_basename($e) . ')',
                'transient',
                (int) round((microtime(true) - $started) * 1000)
            );
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        if (! $response->successful()) {
            return $this->failure(
                $command,
                'HTTP_' . $response->status(),
                'Namecheap returned HTTP ' . $response->status(),
                $response->serverError() ? 'transient' : 'permanent',
                $durationMs,
                $response->status()
            );
        }

        return $this->parse($command, $response->body(), $durationMs, $response->status());
    }

    /**
     * Parse the Namecheap XML envelope.
     *
     * Namecheap returns HTTP 200 even for application errors, so the envelope's
     * Status attribute -- not the HTTP status -- is the authority.
     */
    private function parse(string $command, string $body, int $durationMs, int $httpStatus): array
    {
        $previous = libxml_use_internal_errors(true);

        try {
            $xml = simplexml_load_string($body);
        } catch (\Throwable) {
            $xml = false;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if ($xml === false) {
            return $this->failure(
                $command,
                'MALFORMED_XML',
                'Namecheap response was not parseable XML',
                'transient',
                $durationMs,
                $httpStatus
            );
        }

        // Strip the default namespace so child access works without registering it.
        $status = (string) ($xml->attributes()['Status'] ?? '');

        if (strcasecmp($status, 'OK') !== 0) {
            $errors = [];
            $firstCode = null;

            foreach ($xml->Errors->Error ?? [] as $error) {
                $number = (string) ($error->attributes()['Number'] ?? '');
                $errors[] = ['number' => $number, 'message' => trim((string) $error)];
                $firstCode ??= $number;
            }

            if ($errors === []) {
                $errors[] = ['number' => 'UNKNOWN', 'message' => 'Namecheap reported ERROR with no error detail'];
            }

            return [
                'ok'            => false,
                'command'       => $command,
                'data'          => null,
                // Namecheap emits a CommandResponse even on Status="ERROR", and it
                // can carry the definitive answer (e.g. IsOwner). Callers need it
                // to tell "not yours" apart from "the call failed".
                'xml'           => $xml,
                'errors'        => $errors,
                'error_code'    => $firstCode ?: 'UNKNOWN',
                'error_summary' => $errors[0]['message'],
                'retry'         => in_array($firstCode, self::TRANSIENT_ERRORS, true) ? 'transient' : 'permanent',
                'billable_risk' => ! in_array($firstCode, self::NON_BILLABLE_ERRORS, true),
                'http_status'   => $httpStatus,
                'duration_ms'   => $durationMs,
            ];
        }

        return [
            'ok'            => true,
            'command'       => $command,
            'data'          => $xml->CommandResponse ?? $xml,
            'errors'        => [],
            'error_code'    => null,
            'error_summary' => null,
            'retry'         => 'none',
            'billable_risk' => false,
            'http_status'   => $httpStatus,
            'duration_ms'   => $durationMs,
        ];
    }

    private function failure(
        string $command,
        string $code,
        string $summary,
        string $retry,
        int $durationMs = 0,
        ?int $httpStatus = null
    ): array {
        return [
            'ok'            => false,
            'command'       => $command,
            'data'          => null,
            'xml'           => null,
            'errors'        => [['number' => $code, 'message' => $summary]],
            'error_code'    => $code,
            'error_summary' => $summary,
            'retry'         => $retry,
            // A transport failure or timeout on a billable command may have
            // reached Namecheap. The caller must reconcile, never blind-retry.
            'billable_risk' => in_array($code, ['TRANSPORT_ERROR', 'MALFORMED_XML'], true)
                || str_starts_with($code, 'HTTP_5'),
            'http_status'   => $httpStatus,
            'duration_ms'   => $durationMs,
        ];
    }

    /** Allow-listed context safe to write to logs and audit records. */
    public function redactedContext(array $result): array
    {
        return [
            'provider'    => 'namecheap',
            'environment' => $this->environment,
            'command'     => $result['command'] ?? null,
            'ok'          => $result['ok'] ?? null,
            'error_code'  => $result['error_code'] ?? null,
            'retry'       => $result['retry'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'duration_ms' => $result['duration_ms'] ?? null,
            'key_fp'      => $this->credentialFingerprint(),
        ];
    }
}
