<?php

namespace App\Connectors\Contracts;

interface ConnectorInterface
{
    /**
     * Execute an action through this connector.
     *
     * @param  string  $action  The action to perform (e.g. 'create_post', 'generate_image')
     * @param  array   $params  Validated parameters for the action
     * @return array   Normalized result: ['success' => bool, 'data' => [], 'message' => string]
     */
    public function execute(string $action, array $params): array;

    /**
     * Return validation rules for the given action.
     *
     * @param  string  $action
     * @return array   Laravel validation rules
     */
    public function validationRules(string $action): array;

    /**
     * Validate parameters against the action's rules.
     *
     * @param  string  $action
     * @param  array   $params
     * @return array   Validated (cleaned) params
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function validate(string $action, array $params): array;

    /**
     * Ping the external service to confirm connectivity.
     */
    public function healthCheck(): bool;

    /**
     * Return the list of actions this connector supports.
     *
     * @return string[]
     */
    public function supportedActions(): array;

    /**
     * Verify execution result is genuinely successful.
     * Connector "success" is NOT trusted until verified.
     *
     * @return array ['verified' => bool, 'message' => string, 'data' => []]
     */
    public function verifyResult(string $action, array $params, array $result): array;
}
