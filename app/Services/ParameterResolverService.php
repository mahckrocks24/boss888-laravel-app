<?php

namespace App\Services;

use App\Core\Memory\WorkspaceMemoryService;
use App\Core\EngineKernel\CapabilityMapService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Arr;

class ParameterResolverService
{
    private int $maxRetries = 2;

    public function __construct(
        private WorkspaceMemoryService $memory,
        private CapabilityMapService $capabilityMap,
    ) {}

    /**
     * Resolve and validate parameters for an action.
     *
     * Merge order (later wins):
     *   defaults → workspace memory → workspace context → user input
     *
     * @return array ['resolved' => bool, 'params' => [], 'missing' => [], 'errors' => []]
     */
    public function resolve(int $workspaceId, string $action, array $userInput): array
    {
        $rules = $this->capabilityMap->getValidationRules($action);

        // If no validation rules, pass through
        if (empty($rules)) {
            return [
                'resolved' => true,
                'params' => $userInput,
                'missing' => [],
                'errors' => [],
            ];
        }

        // Build merged params
        $defaults = $this->getDefaults($action);
        $memoryContext = $this->getMemoryContext($workspaceId, $action);
        $workspaceContext = $this->getWorkspaceContext($workspaceId);

        $merged = array_merge($defaults, $memoryContext, $workspaceContext, $userInput);

        // Retry loop
        $attempt = 0;
        $lastErrors = [];

        while ($attempt < $this->maxRetries) {
            $attempt++;

            $validator = Validator::make($merged, $rules);

            if ($validator->passes()) {
                return [
                    'resolved' => true,
                    'params' => $validator->validated(),
                    'missing' => [],
                    'errors' => [],
                ];
            }

            $lastErrors = $validator->errors()->toArray();

            // Extract missing required fields
            $missing = $this->extractMissing($rules, $merged, $lastErrors);

            // Try to auto-fill missing from deeper memory / context
            $autoFilled = $this->autoFill($workspaceId, $missing);

            if (empty($autoFilled)) {
                break; // Nothing new to fill — stop retrying
            }

            $merged = array_merge($merged, $autoFilled);
        }

        // Final check
        $validator = Validator::make($merged, $rules);

        if ($validator->passes()) {
            return [
                'resolved' => true,
                'params' => $validator->validated(),
                'missing' => [],
                'errors' => [],
            ];
        }

        $missing = $this->extractMissing($rules, $merged, $validator->errors()->toArray());

        return [
            'resolved' => false,
            'params' => $merged,
            'missing' => $missing,
            'errors' => $validator->errors()->toArray(),
        ];
    }

    // ── Private Helpers ──────────────────────────────────────────────────

    private function getDefaults(string $action): array
    {
        $defaults = [
            'create_post' => ['status' => 'draft'],
            'update_post' => [],
            'update_seo' => [],
            'get_pages' => ['per_page' => 20, 'page' => 1, 'status' => 'any'],
            'generate_image' => ['aspect_ratio' => '1:1'],
            'generate_video' => ['duration' => 5],
            'send_email' => ['html' => true],
            'send_campaign' => ['html' => true, 'batch_size' => 50],
            'create_post' => ['status' => 'draft'],
        ];

        return $defaults[$action] ?? [];
    }

    private function getMemoryContext(int $workspaceId, string $action): array
    {
        $context = [];

        // Pull relevant memory keys
        $brandVoice = $this->memory->get($workspaceId, 'brand_voice');
        if ($brandVoice) {
            $context['brand_voice'] = $brandVoice;
        }

        $defaultPlatform = $this->memory->get($workspaceId, 'default_social_platform');
        if ($defaultPlatform && in_array($action, ['social_create_post', 'social_publish_post'])) {
            $context['platform'] = $defaultPlatform['value'] ?? $defaultPlatform;
        }

        return $context;
    }

    private function getWorkspaceContext(int $workspaceId): array
    {
        $workspace = \App\Models\Workspace::find($workspaceId);
        if (! $workspace) {
            return [];
        }

        $settings = $workspace->settings_json ?? [];

        return Arr::only($settings, [
            'default_language', 'timezone', 'currency',
        ]);
    }

    private function extractMissing(array $rules, array $params, array $errors): array
    {
        $missing = [];

        foreach ($rules as $field => $rule) {
            $ruleStr = is_array($rule) ? implode('|', $rule) : $rule;
            $isRequired = str_contains($ruleStr, 'required');

            if ($isRequired && (! isset($params[$field]) || $params[$field] === '' || $params[$field] === null)) {
                $missing[] = $field;
            }
        }

        // Also add fields that failed validation
        foreach (array_keys($errors) as $field) {
            if (! in_array($field, $missing)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function autoFill(int $workspaceId, array $missingFields): array
    {
        $filled = [];

        foreach ($missingFields as $field) {
            // Try to resolve from workspace memory
            $memValue = $this->memory->get($workspaceId, "default_{$field}");
            if ($memValue !== null) {
                $filled[$field] = is_array($memValue) ? ($memValue['value'] ?? $memValue) : $memValue;
            }
        }

        return $filled;
    }
}
