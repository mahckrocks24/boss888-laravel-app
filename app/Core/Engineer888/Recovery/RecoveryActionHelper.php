<?php

namespace App\Core\Engineer888\Recovery;

class RecoveryActionHelper {
    private const DESCRIPTIONS = [
        RecoveryCandidate::REMOVE_CREATED_FILE => 'Remove the created file.',
        RecoveryCandidate::RESTORE_UPDATED_FILE => 'Restore the updated file.',
        RecoveryCandidate::RESTORE_DELETED_FILE => 'Restore the deleted file.',
        RecoveryCandidate::NO_ACTION => 'No action will be taken.',
        RecoveryCandidate::MANUAL_ONLY => 'Manual intervention is required.'
    ];

    public static function getDescription(string $action): string {
        return self::DESCRIPTIONS[$action] ?? 'Unknown action.';
    }

    public static function isExecutable(string $action): bool {
        return in_array($action, RecoveryCandidate::EXECUTABLE, true);
    }
}
