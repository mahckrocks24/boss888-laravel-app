<?php

namespace Tests\Feature\Infrastructure;

use App\Engines\Infrastructure\Models\InfraCostEntry;
use App\Engines\Infrastructure\Models\InfraEntitlementDefinition;
use App\Engines\Infrastructure\Models\InfraEvent;
use App\Engines\Infrastructure\Models\InfraHostedSite;
use App\Engines\Infrastructure\Models\InfraHostingAccount;
use App\Engines\Infrastructure\Models\InfraOperation;
use App\Engines\Infrastructure\Models\InfraPlan;
use App\Engines\Infrastructure\Models\InfraPlanEntitlement;
use App\Engines\Infrastructure\Models\InfraProduct;
use App\Engines\Infrastructure\Models\InfraProviderConnection;
use App\Engines\Infrastructure\Models\InfraProvider;
use App\Engines\Infrastructure\Models\InfraProviderCapability;
use App\Engines\Infrastructure\Models\InfraProviderCredential;
use App\Engines\Infrastructure\Models\InfraProviderCertification;
use App\Engines\Infrastructure\Models\InfraCertificationCheck;
use App\Engines\Infrastructure\Models\InfraCertificationWaiver;
use App\Engines\Infrastructure\Models\AdminAppointment;
use App\Engines\Infrastructure\Models\InfraGovernanceState;
use App\Engines\Infrastructure\Models\InfraMonitorCheck;
use App\Engines\Infrastructure\Models\InfraMonitorResult;
use App\Engines\Infrastructure\Models\InfraMonitorIncident;
use App\Engines\Infrastructure\Models\InfraAsset;
use App\Engines\Infrastructure\Models\InfraAssetRelationship;
use App\Engines\Infrastructure\Models\InfraIncident;
use App\Engines\Infrastructure\Models\InfraIncidentTransition;
use App\Engines\Infrastructure\Models\InfraMonitorDaily;
use App\Engines\Infrastructure\Models\InfraProviderEvent;
use App\Engines\Infrastructure\Models\InfraProviderHealth;
use App\Engines\Infrastructure\Models\InfraProviderResource;
use App\Engines\Infrastructure\Models\InfraRenewal;
use App\Engines\Infrastructure\Models\InfraSubscription;
use App\Engines\Infrastructure\Models\InfraUsageRecord;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Guards against SILENT MASS-ASSIGNMENT DROPS.
 *
 * WHY THIS EXISTS
 * ---------------
 * This defect class has now bitten this project three times:
 *
 *   1. `Approval` gained requested_by / capability_key / approval_policy_json via
 *      migration, but not in $fillable. Laravel discarded every value without
 *      error, and separation-of-duties failed closed on every approval.
 *   2. `InfraUsageRecord` gained behavior / warning_threshold_pct / warning_sent
 *      in Phase 2A-1 with the same omission — usage behaviour saved as NULL.
 *   3. (caught here before shipping)
 *
 * The migration runs, the column exists, the code writes it, and the value
 * vanishes. Nothing errors. It only surfaces when something downstream depends
 * on the value.
 *
 * This test walks every INFRA888 model and asserts that each real table column
 * is either mass-assignable or DELIBERATELY excluded below.
 */
class MassAssignmentDriftTest extends TestCase
{
    /**
     * Columns never expected in $fillable, with the reason.
     *
     * `id` / timestamps are framework-managed. `deleted_at` is soft-delete
     * machinery. `created_at` on InfraEvent is set explicitly because that table
     * is append-only with no updated_at.
     */
    private const ALWAYS_EXCLUDED = ['id', 'created_at', 'updated_at', 'deleted_at'];

    /** @return array<class-string,array<int,string>> model => deliberately excluded columns */
    private function models(): array
    {
        return [
            InfraProduct::class               => [],
            InfraPlan::class                  => [],
            InfraSubscription::class          => [],
            InfraHostingAccount::class        => [],
            InfraHostedSite::class            => [],
            InfraOperation::class             => [],
            InfraEvent::class                 => [],
            InfraProviderConnection::class    => [],

            // Phase 2B provider control plane.
            InfraProvider::class              => [],
            InfraProviderCapability::class    => [],
            InfraProviderHealth::class        => [],
            InfraProviderEvent::class         => [],
            // secret_encrypted IS fillable (it must be writable on create);
            // it is protected by $hidden + the encrypted cast + four
            // serialization overrides, not by guarding mass assignment.
            InfraProviderCredential::class    => [],

            // Phase 2B-CERT certification framework.
            InfraProviderCertification::class => [],
            InfraCertificationCheck::class    => [],
            InfraCertificationWaiver::class   => [],

            // Phase 2B-R1 admin governance.
            AdminAppointment::class           => [],

            // Phase 2B-R3 governance state.
            InfraGovernanceState::class       => [],

            // Phase 3A monitoring.
            InfraMonitorCheck::class          => [],
            InfraMonitorResult::class         => [],
            InfraMonitorIncident::class       => [],

            // Phase 3B canonical intelligence.
            InfraAsset::class                 => [],
            InfraAssetRelationship::class     => [],
            InfraIncident::class              => [],
            InfraIncidentTransition::class    => [],
            InfraMonitorDaily::class          => [],
            InfraProviderResource::class      => [],
            InfraUsageRecord::class           => [],
            InfraCostEntry::class             => [],
            InfraEntitlementDefinition::class => [],
            InfraPlanEntitlement::class       => [],
            InfraRenewal::class               => [],
        ];
    }

    public function test_every_infra_model_can_mass_assign_every_column(): void
    {
        $problems = [];

        foreach ($this->models() as $modelClass => $deliberatelyExcluded) {
            /** @var \Illuminate\Database\Eloquent\Model $model */
            $model = new $modelClass();
            $table = $model->getTable();

            if (!Schema::hasTable($table)) {
                $problems[] = "{$modelClass}: table `{$table}` does not exist";
                continue;
            }

            $columns  = Schema::getColumnListing($table);
            $fillable = $model->getFillable();

            $missing = array_diff(
                $columns,
                $fillable,
                self::ALWAYS_EXCLUDED,
                $deliberatelyExcluded
            );

            if ($missing !== []) {
                $problems[] = sprintf(
                    "%s (%s): columns exist but are NOT mass-assignable and not declared as excluded -> %s",
                    class_basename($modelClass),
                    $table,
                    implode(', ', $missing)
                );
            }
        }

        $this->assertSame(
            [],
            $problems,
            "Silent mass-assignment drop risk detected:\n  " . implode("\n  ", $problems)
        );
    }

    public function test_no_model_declares_a_fillable_column_that_does_not_exist(): void
    {
        // The inverse drift: a column removed or renamed while $fillable kept the
        // old name. Harmless at runtime, but it hides real intent.
        $problems = [];

        foreach (array_keys($this->models()) as $modelClass) {
            /** @var \Illuminate\Database\Eloquent\Model $model */
            $model = new $modelClass();
            $table = $model->getTable();

            if (!Schema::hasTable($table)) {
                continue;
            }

            $columns = Schema::getColumnListing($table);
            $phantom = array_diff($model->getFillable(), $columns);

            if ($phantom !== []) {
                $problems[] = class_basename($modelClass) . " ({$table}): " . implode(', ', $phantom);
            }
        }

        $this->assertSame([], $problems, "Fillable references non-existent columns:\n  " . implode("\n  ", $problems));
    }
}
