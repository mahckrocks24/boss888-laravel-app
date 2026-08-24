<?php

namespace App\Core\Sarah888;

/**
 * SARAH888 — TYPED TOOL INTENT (Runtime → Laravel).
 *
 * The architecture ruling of 2026-08-13: Runtime selects and sequences; Laravel
 * executes, because Laravel owns the credentials. This is the thing that crosses
 * that boundary.
 *
 * WHY TYPED RATHER THAN PROSE.
 * Runtime previously answered a capability request with "I will perform a web
 * search... Please hold on for a moment" and returned nothing, and `/ai/run
 * task=serp_analysis` returned success:true carrying a model-written essay with
 * zero URLs and zero ranking positions. Prose cannot be executed and cannot be
 * audited. An intent either carries what Laravel needs to run the real connector
 * or it is rejected — there is no third outcome where something looks done.
 *
 * An intent is a REQUEST. It confers no authority. Governance decides.
 */
final class ToolIntent
{
    public function __construct(
        public readonly string $capabilityId,
        public readonly int $workspaceId,
        public readonly array $parameters = [],
        public readonly ?string $objective = null,
        public readonly ?string $conversationId = null,
        public readonly ?string $executionId = null,
        public readonly ?string $entityType = null,
        public readonly ?string $entityId = null,
        public readonly ?string $requestedBy = null,
        /**
         * The owner's own words for this turn.
         *
         * Carried so `ParameterProvenance` can tell a parameter the owner STATED from one
         * the model carried over from an earlier turn. Without it, "did they say this?"
         * is unanswerable and a paid call gets made on a guess — measured 2026-08-14, twice
         * in four runs.
         */
        public readonly ?string $ownerMessage = null,
    ) {}

    /**
     * Build from whatever the runtime emitted. Unknown keys are dropped rather than
     * passed through: the runtime's chat envelope is known to drop unknown keys of
     * its own, and a parameter that survives only on one side of the boundary is a
     * parameter that will be missing exactly once, in production.
     *
     * @return array{intent: self|null, error: string|null}
     */
    public static function fromRuntime(array $raw, int $wsId, array $turn = []): array
    {
        $id = trim((string) ($raw['capability_id'] ?? $raw['capability'] ?? $raw['tool'] ?? ''));
        if ($id === '') return ['intent' => null, 'error' => 'no capability_id in runtime intent'];

        $params = $raw['parameters'] ?? $raw['params'] ?? [];
        if (!is_array($params)) return ['intent' => null, 'error' => 'parameters must be an object'];

        return ['intent' => new self(
            capabilityId:   $id,
            workspaceId:    $wsId,
            parameters:     $params,
            objective:      isset($raw['reason']) ? (string) $raw['reason']
                            : (isset($raw['objective']) ? (string) $raw['objective'] : null),
            conversationId: $turn['conversation_id'] ?? null,
            executionId:    $turn['execution_id'] ?? null,
            entityType:     isset($raw['entity_type']) ? (string) $raw['entity_type'] : null,
            entityId:       isset($raw['entity_id']) ? (string) $raw['entity_id'] : null,
            requestedBy:    'runtime',
            ownerMessage:   isset($turn['owner_message']) ? (string) $turn['owner_message'] : null,
        ), 'error' => null];
    }

    public function toArray(): array
    {
        return [
            'capability_id'   => $this->capabilityId,
            'workspace_id'    => $this->workspaceId,
            'parameters'      => $this->parameters,
            'objective'       => $this->objective,
            'conversation_id' => $this->conversationId,
            'execution_id'    => $this->executionId,
            'entity_type'     => $this->entityType,
            'entity_id'       => $this->entityId,
            'requested_by'    => $this->requestedBy,
        ];
    }
}
