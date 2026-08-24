<?php

namespace Tests\Feature\Engineer888;

use PHPUnit\Framework\TestCase;
use App\Core\Engineer888\Recovery\RecoveryActionHelper;
use App\Core\Engineer888\Recovery\RecoveryCandidate;

class RecoveryActionHelperTest extends TestCase {
    public function testGetDescription() {
        $this->assertEquals('Remove the created file.', RecoveryActionHelper::getDescription(RecoveryCandidate::REMOVE_CREATED_FILE));
        $this->assertEquals('Restore the updated file.', RecoveryActionHelper::getDescription(RecoveryCandidate::RESTORE_UPDATED_FILE));
        $this->assertEquals('Restore the deleted file.', RecoveryActionHelper::getDescription(RecoveryCandidate::RESTORE_DELETED_FILE));
        $this->assertEquals('No action will be taken.', RecoveryActionHelper::getDescription(RecoveryCandidate::NO_ACTION));
        $this->assertEquals('Manual intervention is required.', RecoveryActionHelper::getDescription(RecoveryCandidate::MANUAL_ONLY));
        $this->assertEquals('Unknown action.', RecoveryActionHelper::getDescription('UNKNOWN_ACTION'));
    }

    public function testIsExecutable() {
        $this->assertTrue(RecoveryActionHelper::isExecutable(RecoveryCandidate::REMOVE_CREATED_FILE));
        $this->assertTrue(RecoveryActionHelper::isExecutable(RecoveryCandidate::RESTORE_UPDATED_FILE));
        $this->assertTrue(RecoveryActionHelper::isExecutable(RecoveryCandidate::RESTORE_DELETED_FILE));
        $this->assertFalse(RecoveryActionHelper::isExecutable(RecoveryCandidate::NO_ACTION));
        $this->assertFalse(RecoveryActionHelper::isExecutable(RecoveryCandidate::MANUAL_ONLY));
    }
}
