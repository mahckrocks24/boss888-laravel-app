<?php

namespace App\Core\Engineer888\Support;

class StageVocabulary
{
    public const STATES = [
        'PENDING',
        'RUNNING',
        'PASSED',
        'FAILED',
        'BLOCKED',
        'SKIPPED',
        'AWAITING_APPROVAL'
    ];

    public static function uiState(string $stage, string $status): string
    {
        $status = strtoupper($status);
        if ($stage === 'REQUEST_APPROVAL' && $status === 'BLOCKED') {
            return 'AWAITING_APPROVAL';
        }

        switch ($status) {
            case 'OK':
                return 'PASSED';
            case 'BLOCKED':
                return 'BLOCKED';
            case 'FAILED':
                return 'FAILED';
            case 'SKIPPED':
                return 'SKIPPED';
            default:
                return $status;
        }
    }
}
