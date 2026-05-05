<?php

namespace App\Connectors;

use App\Connectors\Contracts\ConnectorInterface;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

abstract class BaseConnector implements ConnectorInterface
{
    public function validate(string $action, array $params): array
    {
        if (! in_array($action, $this->supportedActions())) {
            throw new \InvalidArgumentException("Unsupported action: {$action}");
        }

        $rules = $this->validationRules($action);

        if (empty($rules)) {
            return $params;
        }

        $validator = Validator::make($params, $rules);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        return $validator->validated();
    }

    protected function success(array $data = [], string $message = 'OK'): array
    {
        return [
            'success' => true,
            'data' => $data,
            'message' => $message,
        ];
    }

    protected function failure(string $message, array $data = []): array
    {
        return [
            'success' => false,
            'data' => $data,
            'message' => $message,
        ];
    }

    /**
     * Default verification — override in specific connectors.
     */
    public function verifyResult(string $action, array $params, array $result): array
    {
        if (! ($result['success'] ?? false)) {
            return ['verified' => false, 'message' => 'Execution reported failure', 'data' => []];
        }

        return ['verified' => true, 'message' => 'Result verified (default)', 'data' => $result['data'] ?? []];
    }
}
