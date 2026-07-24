<?php

namespace App\Engines\Infrastructure\Services;

use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Writes append-only provider control-plane events (Phase 2B-9).
 *
 * SANITIZATION IS STRICTER HERE THAN IN InfraEventRecorder
 * -------------------------------------------------------
 * That recorder redacts by KEY NAME. Sufficient for tenant events, insufficient
 * here, because provider responses embed credentials in places a key-name filter
 * cannot see:
 *
 *   - a value that IS a token but sits under an innocuous key ('value', 'data')
 *   - an Authorization header inside a nested request echo
 *   - a URL with an api_key in the query string
 *
 * So sanitization runs THREE passes: key-name matching, value-shape heuristics
 * (long high-entropy strings, bearer prefixes, PEM blocks, JWTs), and URL query
 * stripping. Over-redaction is the correct failure mode: a lost diagnostic string
 * costs an operator five minutes, a leaked provider token costs customer
 * infrastructure.
 */
class ProviderEventRecorder
{
    private const REDACT_KEYS = [
        'token', 'api_token', 'api_key', 'apikey', 'secret', 'password', 'passwd',
        'authorization', 'auth', 'credential', 'credentials', 'private_key',
        'privatekey', 'client_secret', 'access_token', 'refresh_token', 'bearer',
        'signature', 'session', 'cookie', 'x-auth', 'x-api', 'passphrase',
        'certificate_key', 'dkim_private', 'secret_encrypted', 'secret_fingerprint',
    ];

    /** Keys whose values are known-safe even though they match above. */
    private const ALLOW_KEYS = [
        'credential_key', 'credential_id', 'credential_state', 'has_credential',
        'credential_count', 'authorization_result', 'auth_ok',
    ];

    private const MAX_STRING = 512;
    private const MAX_DEPTH  = 8;

    public function record(
        string $eventType,
        ?InfraProvider $provider = null,
        ?string $capability = null,
        ?string $environment = null,
        string $severity = InfraProviderEvent::SEVERITY_INFO,
        ?string $fromState = null,
        ?string $toState = null,
        ?string $summary = null,
        array $metadata = [],
        ?int $actorUserId = null,
        string $actorType = InfraProviderEvent::ACTOR_SYSTEM,
        ?string $actorLabel = null,
        ?string $correlationId = null,
        ?int $operationId = null,
        ?int $credentialId = null
    ): ?InfraProviderEvent {
        try {
            return InfraProviderEvent::create([
                'event_uid'      => (string) Str::uuid(),
                'provider_id'    => $provider?->id,
                'provider_key'   => $provider?->provider_key,
                'capability'     => $capability,
                'environment'    => $environment,
                'event_type'     => $eventType,
                'severity'       => $severity,
                'actor_type'     => $actorType,
                'actor_user_id'  => $actorUserId,
                'actor_label'    => $actorLabel,
                'from_state'     => $fromState,
                'to_state'       => $toState,
                'correlation_id' => $correlationId ?: (string) Str::uuid(),
                'operation_id'   => $operationId,
                'credential_id'  => $credentialId,
                'summary'        => $summary,
                'metadata_json'  => $this->sanitize($metadata),
                'created_at'     => now(),
            ]);
        } catch (Throwable $e) {
            // Auditing must never break the operation it records — but a failure
            // to audit a control-plane change is itself serious, so it is logged
            // at error level rather than swallowed.
            Log::error('INFRA888: failed to record provider event', [
                'event_type' => $eventType,
                'provider'   => $provider?->provider_key,
                'error'      => $e->getMessage(),
            ]);

            return null;
        }
    }

    public function recordDenial(
        string $attemptedAction,
        ?InfraProvider $provider,
        string $reason,
        ?int $actorUserId = null,
        array $metadata = []
    ): ?InfraProviderEvent {
        return $this->record(
            eventType: InfraProviderEvent::PERMISSION_DENIED,
            provider: $provider,
            severity: InfraProviderEvent::SEVERITY_WARNING,
            summary: "Denied '{$attemptedAction}': {$reason}",
            metadata: $metadata + ['attempted_action' => $attemptedAction],
            actorUserId: $actorUserId,
            actorType: $actorUserId
                ? InfraProviderEvent::ACTOR_USER
                : InfraProviderEvent::ACTOR_SYSTEM,
        );
    }

    /** Three-pass sanitization. Public so tests can assert it directly. */
    public function sanitize(array $payload, int $depth = 0): array
    {
        if ($depth > self::MAX_DEPTH) {
            return ['_truncated' => 'max depth exceeded'];
        }

        $clean = [];

        foreach ($payload as $key => $value) {
            $lower = strtolower((string) $key);

            if (!in_array($lower, self::ALLOW_KEYS, true) && $this->keyLooksSecret($lower)) {
                $clean[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value, $depth + 1);
                continue;
            }

            if (is_string($value)) {
                $clean[$key] = $this->sanitizeString($value);
                continue;
            }

            if (is_object($value)) {
                // Never serialize an object blindly — it may be a model carrying
                // secret attributes, or hold a connection handle.
                $clean[$key] = '[object:' . get_class($value) . ']';
                continue;
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    private function keyLooksSecret(string $lower): bool
    {
        foreach (self::REDACT_KEYS as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pass 2 + 3: value-shape heuristics and URL query stripping.
     *
     * These catch the case a key-name filter cannot: a token stored under a
     * blameless key such as 'value' or 'result'.
     */
    private function sanitizeString(string $value): string
    {
        $trimmed = trim($value);

        // PEM private key material.
        if (str_contains($trimmed, '-----BEGIN')) {
            return '[redacted:pem]';
        }

        // Authorization header values.
        if (preg_match('/^(bearer|basic|token)\s+\S+/i', $trimmed)) {
            return '[redacted:auth-header]';
        }

        // JWT.
        if (preg_match('/^ey[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]+$/', $trimmed)) {
            return '[redacted:jwt]';
        }

        // Strip credentials from URLs rather than dropping the whole URL — the
        // host and path are genuinely useful for diagnosis.
        if (preg_match('#^https?://#i', $trimmed)) {
            return $this->stripUrlSecrets($trimmed);
        }

        // High-entropy opaque string: long, no whitespace, mixed alphabet. This
        // is the shape of most API tokens. Deliberately conservative — 32 chars
        // with mixed case AND digits is unusual for prose.
        if (strlen($trimmed) >= 32
            && !preg_match('/\s/', $trimmed)
            && preg_match('/[A-Za-z]/', $trimmed)
            && preg_match('/[0-9]/', $trimmed)
            && preg_match('/^[A-Za-z0-9_\-\.~+\/=]+$/', $trimmed)) {
            return '[redacted:high-entropy]';
        }

        if (strlen($value) > self::MAX_STRING) {
            return substr($value, 0, self::MAX_STRING) . '…[truncated]';
        }

        return $value;
    }

    private function stripUrlSecrets(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return '[redacted:url]';
        }

        $rebuilt = ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . ($parts['path'] ?? '');

        if (!empty($parts['query'])) {
            parse_str($parts['query'], $q);
            foreach ($q as $k => $v) {
                $q[$k] = $this->keyLooksSecret(strtolower((string) $k)) ? '[redacted]' : $v;
            }
            $rebuilt .= '?' . http_build_query($q);
        }

        return $rebuilt;
    }
}
