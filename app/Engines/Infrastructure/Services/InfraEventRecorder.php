<?php

namespace App\Engines\Infrastructure\Services;

use App\Engines\Infrastructure\Models\InfraEvent;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Writes append-only infrastructure events (control C10 of the 0.1-F design).
 *
 * Two behaviours the platform audit path does not have (Phase 0 audit §7):
 *   1. Records VALUES, not just array_keys($params) — infrastructure forensics
 *      require knowing WHICH resource was touched, not merely that one was.
 *   2. Records DENIALS and FAILURES. The platform returns early on PLAN_GATED /
 *      NO_CREDITS / NOT_FOUND before its audit write, so repeated unauthorized
 *      attempts leave no trail at all.
 *
 * Every payload passes through redaction. Provider secrets must never reach this
 * table, and the redaction list is deliberately broad.
 */
class InfraEventRecorder
{
    private const REDACT_KEYS = [
        'token', 'api_token', 'api_key', 'apikey', 'secret', 'password', 'passwd',
        'authorization', 'auth', 'credential', 'credentials', 'private_key',
        'client_secret', 'access_token', 'refresh_token', 'bearer', 'signature',
        'auth_code', 'authcode', 'dkim_private',
    ];

    public function record(
        int $workspaceId,
        string $event,
        string $ownerType,
        ?int $ownerId = null,
        string $severity = InfraEvent::SEVERITY_INFO,
        ?string $fromState = null,
        ?string $toState = null,
        ?string $summary = null,
        array $context = [],
        ?int $actorUserId = null,
        string $source = 'system',
        ?int $operationId = null,
        ?string $provider = null,
        ?string $providerResourceId = null
    ): ?InfraEvent {
        try {
            return InfraEvent::create([
                'workspace_id'         => $workspaceId,
                'owner_type'           => $ownerType,
                'owner_id'             => $ownerId,
                'event'                => $event,
                'severity'             => $severity,
                'from_state'           => $fromState,
                'to_state'             => $toState,
                'operation_id'         => $operationId,
                'actor_user_id'        => $actorUserId,
                'source'               => $source,
                'provider'             => $provider,
                'provider_resource_id' => $providerResourceId,
                'summary'              => $summary,
                'context_json'         => $this->redact($context),
                'created_at'           => now(),
            ]);
        } catch (Throwable $e) {
            // Audit must never break the operation it is recording, but a failure
            // to audit is itself notable — surface it server-side.
            Log::error('INFRA888: failed to record infra event', [
                'event'        => $event,
                'workspace_id' => $workspaceId,
                'error'        => $e->getMessage(),
            ]);

            return null;
        }
    }

    /** Convenience for the most common case. */
    public function recordStateChange(
        int $workspaceId,
        string $ownerType,
        int $ownerId,
        string $from,
        string $to,
        ?int $actorUserId = null,
        string $source = 'system',
        array $context = [],
        ?int $operationId = null
    ): ?InfraEvent {
        return $this->record(
            workspaceId: $workspaceId,
            event: 'state_changed',
            ownerType: $ownerType,
            ownerId: $ownerId,
            severity: InfraEvent::SEVERITY_INFO,
            fromState: $from,
            toState: $to,
            summary: "State changed from {$from} to {$to}.",
            context: $context,
            actorUserId: $actorUserId,
            source: $source,
            operationId: $operationId,
        );
    }

    public function recordDenial(
        int $workspaceId,
        string $ownerType,
        ?int $ownerId,
        string $reason,
        ?int $actorUserId = null,
        array $context = []
    ): ?InfraEvent {
        return $this->record(
            workspaceId: $workspaceId,
            event: 'permission_denied',
            ownerType: $ownerType,
            ownerId: $ownerId,
            severity: InfraEvent::SEVERITY_WARNING,
            summary: $reason,
            context: $context,
            actorUserId: $actorUserId,
            source: 'manual',
        );
    }

    /** Recursive, case-insensitive, partial-match redaction. */
    public function redact(array $payload): array
    {
        $clean = [];

        foreach ($payload as $key => $value) {
            $lower = strtolower((string) $key);
            $isSecret = false;

            foreach (self::REDACT_KEYS as $needle) {
                if (str_contains($lower, $needle)) {
                    $isSecret = true;
                    break;
                }
            }

            if ($isSecret) {
                $clean[$key] = '[redacted]';
                continue;
            }

            $clean[$key] = is_array($value) ? $this->redact($value) : $value;
        }

        return $clean;
    }
}
