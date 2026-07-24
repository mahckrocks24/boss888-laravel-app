<?php

namespace App\Connectors\Infrastructure\Contracts;

use App\Connectors\Infrastructure\ProviderResult;

/**
 * Backup capability contract (Phase 2B-1).
 *
 * `CAPABILITY_BACKUP` was declared in Phase 1A with nothing behind it.
 *
 * TWO DELIBERATE DESIGN POSITIONS
 *
 * 1. `verifyRestorable()` exists as a first-class method. The Phase 0 audit
 *    found the platform's own backups had NEVER been restore-tested, and the
 *    PTAA runbook treats an untested backup as no backup. A provider that can
 *    only *claim* a backup exists is not offering a backup product; the
 *    contract makes verification askable.
 *
 * 2. Restore is separated into `restore()` and `restoreToNew()`. Restoring
 *    over live data and restoring to a fresh target are different risk
 *    operations, and collapsing them into one method with a flag invites the
 *    destructive one to be called by accident.
 */
interface BackupProviderConnector extends InfrastructureConnector
{
    /**
     * @param array{frequency:string,retention_days:int,include?:array,exclude?:array} $policy
     */
    public function configurePolicy(string $targetResourceId, array $policy, string $idempotencyKey): ProviderResult;

    public function createBackup(string $targetResourceId, array $options, string $idempotencyKey): ProviderResult;

    /**
     * @return ProviderResult data: ['backups'=>[['id'=>string,'created_at'=>string,
     *                               'size_bytes'=>int,'type'=>string,'verified'=>bool], ...]]
     */
    public function listBackups(string $targetResourceId, array $filter = []): ProviderResult;

    /**
     * Confirm a backup is actually restorable — not merely that a record of it
     * exists. Providers differ in how deeply they can answer this; a provider
     * that cannot verify at all must say so rather than imply success.
     */
    public function verifyRestorable(string $backupId): ProviderResult;

    /** DESTRUCTIVE — overwrites live data. Approval-gated upstream. */
    public function restore(string $backupId, string $targetResourceId, string $idempotencyKey): ProviderResult;

    /** Non-destructive: restores into a NEW target, leaving live data intact. */
    public function restoreToNew(string $backupId, array $targetSpec, string $idempotencyKey): ProviderResult;

    /** DESTRUCTIVE and usually irreversible. Approval-gated upstream. */
    public function deleteBackup(string $backupId, string $idempotencyKey): ProviderResult;
}
