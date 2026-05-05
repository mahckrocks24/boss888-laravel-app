<?php

namespace App\Core\DesignTokens;

use App\Models\DesignToken;

class DesignTokenService
{
    private array $defaults = [
        'colors' => [
            'bg' => '#0F1117',
            'surface1' => '#171A21',
            'surface2' => '#1E2230',
            'surface3' => '#252A3A',
            'primary' => '#6C5CE7',
            'accent' => '#00E5A8',
            'blue' => '#3B8BF5',
            'amber' => '#F59E0B',
            'red' => '#F87171',
            'purple' => '#A78BFA',
        ],
        'fonts' => [
            'heading' => 'Syne',
            'body' => 'DM Sans',
        ],
        'agent_colors' => [
            'sarah' => '#6C5CE7',
            'james' => '#3B8BF5',
            'priya' => '#A78BFA',
            'marcus' => '#F59E0B',
            'elena' => '#F87171',
            'alex' => '#00E5A8',
        ],
    ];

    public function getForWorkspace(int $workspaceId): array
    {
        $token = DesignToken::where('workspace_id', $workspaceId)->first();
        return $token ? $token->tokens_json : $this->defaults;
    }

    public function getDefaults(): array
    {
        return $this->defaults;
    }
}
