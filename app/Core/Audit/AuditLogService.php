<?php

namespace App\Core\Audit;

use App\Models\AuditLog;

class AuditLogService
{
    public function log(
        ?int $workspaceId,
        ?int $userId,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $metadata = null,
    ): AuditLog {
        return AuditLog::create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata_json' => $metadata,
        ]);
    }
}
