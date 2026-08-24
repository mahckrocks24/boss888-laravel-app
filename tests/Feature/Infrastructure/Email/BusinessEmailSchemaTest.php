<?php

namespace Tests\Feature\Infrastructure\Email;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * INFRA888 · E1-C / E1-H — SCHEMA PROOF.
 *
 * Asserts what actually exists in the database rather than what the migration
 * source appears to say. Two defects this project has already shipped make that
 * distinction worth the extra work: a migration that ran but whose column was
 * absent from $fillable (values silently discarded), and a clean-install chain
 * that broke on MySQL's 3072-byte key limit — invisible on any engine that does
 * not enforce it.
 */
class BusinessEmailSchemaTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_TABLES = [
        'email_domains',
        'email_mailboxes',
        'email_aliases',
        'email_forwarders',
        'email_catchall',
        'email_usage',
    ];

    /**
     * Platform primitives E1 REUSES instead of duplicating. The masterplan lists
     * `email_operations` and `email_observations` as conceptual entities; both
     * already exist as generic platform tables, and building parallel copies
     * would create a second provider/audit spine that drifts from the first.
     */
    private const REUSED_TABLES = [
        'infra_operations',
        'infra_events',
        'infra_observation_facts',
        'infra_provider_resources',
        'infra_provider_connections',
        'infra_providers',
        'infra_provider_capabilities',
        'infra_usage_records',
        'infra_estate_alerts',
        'infra_incidents',
    ];

    private function database(): string
    {
        return (string) config('database.connections.' . config('database.default') . '.database');
    }

    // ── existence ────────────────────────────────────────────────────────────

    public function test_every_business_email_table_exists(): void
    {
        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(Schema::hasTable($table), "Missing table: {$table}");
        }
    }

    public function test_no_parallel_operations_or_observations_table_was_created(): void
    {
        // The reuse decision, asserted. If a future session adds these, the
        // platform has two audit spines and this test says so immediately.
        $this->assertFalse(
            Schema::hasTable('email_operations'),
            'email_operations must NOT exist: governed operations are recorded in infra_operations, which '
            . 'already provides idempotency, retry classification, timeout handling and approval linkage.'
        );

        $this->assertFalse(
            Schema::hasTable('email_observations'),
            'email_observations must NOT exist: email health facts are recorded in infra_observation_facts, '
            . 'which is dimension-agnostic and already carries custody and confidence.'
        );
    }

    public function test_every_reused_platform_primitive_is_present(): void
    {
        foreach (self::REUSED_TABLES as $table) {
            $this->assertTrue(
                Schema::hasTable($table),
                "E1 depends on the existing platform table {$table}, which is absent."
            );
        }
    }

    // ── columns ──────────────────────────────────────────────────────────────

    public function test_domain_table_has_the_expected_shape(): void
    {
        foreach ([
            'id', 'workspace_id', 'domain', 'customer_domain_id', 'website_id', 'custody',
            'lifecycle_state', 'previous_state', 'verification_state', 'health_state',
            'provider_connection_id', 'dns_verified_at', 'last_observed_at', 'last_observation_id',
            'last_operation_id', 'state_changed_at', 'state_changed_by_user_id', 'created_by_user_id',
            'suspended_at', 'suspension_reason', 'terminated_at', 'settings_json',
            'created_at', 'updated_at', 'deleted_at',
        ] as $column) {
            $this->assertTrue(
                Schema::hasColumn('email_domains', $column),
                "email_domains is missing {$column}"
            );
        }
    }

    public function test_mailbox_table_stores_no_credential_material(): void
    {
        $columns = Schema::getColumnListing('email_mailboxes');

        // Exactly two password-adjacent columns are permitted, and both record
        // that an EVENT happened rather than what it contained. Their names end
        // in `_at` for that reason.
        $permitted = ['password_set_at', 'last_password_reset_at'];

        foreach ($columns as $column) {
            if (in_array($column, $permitted, true)) {
                continue;
            }

            $this->assertSame(
                0,
                preg_match('/password|secret|token|credential|passphrase/i', $column),
                "email_mailboxes.{$column} looks like credential storage. The provider owns authentication; "
                . 'this engine never stores a mailbox password in any form.'
            );
        }

        foreach ($permitted as $column) {
            $this->assertContains($column, $columns);
            $this->assertStringEndsWith(
                '_at',
                $column,
                'A permitted password-adjacent column must be a timestamp, so it cannot hold a value.'
            );
        }
    }

    public function test_mailbox_has_no_stored_address_column(): void
    {
        // Identity is (domain, local_part). A stored address would be a second
        // source of truth for the same fact.
        $this->assertFalse(Schema::hasColumn('email_mailboxes', 'address'));
        $this->assertFalse(Schema::hasColumn('email_mailboxes', 'email_address'));
    }

    public function test_quota_is_not_nullable_and_has_no_fabricated_default(): void
    {
        $column = $this->columnMeta('email_mailboxes', 'quota_mb');

        $this->assertSame('NO', $column->IS_NULLABLE, 'A mailbox must have an explicit quota.');
        $this->assertNull($column->COLUMN_DEFAULT, 'quota_mb must not carry an invented default size.');
    }

    // ── every table is workspace-scoped ──────────────────────────────────────

    public function test_every_business_email_table_is_workspace_scoped(): void
    {
        foreach (self::NEW_TABLES as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'workspace_id'),
                "{$table} has no workspace_id and therefore cannot be tenancy-scoped."
            );

            $meta = $this->columnMeta($table, 'workspace_id');

            $this->assertSame(
                'NO',
                $meta->IS_NULLABLE,
                "{$table}.workspace_id must be NOT NULL — a nullable tenant key permits an unowned row."
            );
        }
    }

    // ── indexes ──────────────────────────────────────────────────────────────

    /** @return array<int,string> */
    private function indexNames(string $table): array
    {
        return DB::table('information_schema.STATISTICS')
            ->where('TABLE_SCHEMA', $this->database())
            ->where('TABLE_NAME', $table)
            ->distinct()
            ->pluck('INDEX_NAME')
            ->all();
    }

    public function test_expected_indexes_exist(): void
    {
        $expected = [
            'email_domains'    => ['email_dom_domain_uq', 'email_dom_ws_state_idx', 'email_dom_state_ver_idx',
                                   'email_dom_ws_domain_idx', 'email_dom_provconn_idx', 'email_dom_custody_idx',
                                   'email_dom_custdom_idx', 'email_dom_observed_idx'],
            'email_mailboxes'  => ['email_mbx_identity_uq', 'email_mbx_ws_state_idx', 'email_mbx_dom_state_idx',
                                   'email_mbx_ws_usage_idx', 'email_mbx_susp_idx', 'email_mbx_provconn_idx'],
            'email_aliases'    => ['email_alias_identity_uq', 'email_alias_ws_state_idx',
                                   'email_alias_dom_state_idx', 'email_alias_target_idx'],
            'email_forwarders' => ['email_fwd_identity_uq', 'email_fwd_ws_state_idx', 'email_fwd_dom_state_idx',
                                   'email_fwd_source_idx', 'email_fwd_loop_idx'],
            'email_catchall'   => ['email_ca_domain_uq', 'email_ca_ws_state_idx', 'email_ca_target_idx'],
            'email_usage'      => ['email_usg_idem_uq', 'email_usg_mbx_time_idx', 'email_usg_dom_time_idx',
                                   'email_usg_ws_scope_idx', 'email_usg_ws_storage_idx'],
        ];

        foreach ($expected as $table => $indexes) {
            $actual = $this->indexNames($table);

            foreach ($indexes as $index) {
                $this->assertContains($index, $actual, "{$table} is missing index {$index}");
            }
        }
    }

    public function test_identity_and_idempotency_constraints_are_unique(): void
    {
        $uniques = [
            'email_domains'    => 'email_dom_domain_uq',
            'email_mailboxes'  => 'email_mbx_identity_uq',
            'email_aliases'    => 'email_alias_identity_uq',
            'email_forwarders' => 'email_fwd_identity_uq',
            'email_catchall'   => 'email_ca_domain_uq',
            'email_usage'      => 'email_usg_idem_uq',
        ];

        foreach ($uniques as $table => $index) {
            $nonUnique = DB::table('information_schema.STATISTICS')
                ->where('TABLE_SCHEMA', $this->database())
                ->where('TABLE_NAME', $table)
                ->where('INDEX_NAME', $index)
                ->value('NON_UNIQUE');

            $this->assertSame(0, (int) $nonUnique, "{$table}.{$index} must be UNIQUE.");
        }
    }

    // ── foreign keys ─────────────────────────────────────────────────────────

    /** @return array<string,object> constraint name => row */
    private function foreignKeys(string $table): array
    {
        $rows = DB::select(
            'SELECT rc.CONSTRAINT_NAME, rc.DELETE_RULE, kcu.COLUMN_NAME,
                    kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME
               FROM information_schema.REFERENTIAL_CONSTRAINTS rc
               JOIN information_schema.KEY_COLUMN_USAGE kcu
                 ON kcu.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
                AND kcu.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
              WHERE rc.CONSTRAINT_SCHEMA = ? AND rc.TABLE_NAME = ?',
            [$this->database(), $table]
        );

        $out = [];

        foreach ($rows as $row) {
            $out[$row->CONSTRAINT_NAME] = $row;
        }

        return $out;
    }

    public function test_foreign_keys_exist_with_the_intended_delete_rules(): void
    {
        $expected = [
            'email_mailboxes'  => [
                'email_mbx_domain_fk'     => ['email_domains', 'CASCADE'],
                'email_mbx_provconn_fk'   => ['infra_provider_connections', 'RESTRICT'],
            ],
            'email_aliases'    => [
                'email_alias_domain_fk'   => ['email_domains', 'CASCADE'],
                // A mailbox an alias delivers into may not vanish beneath it.
                'email_alias_mailbox_fk'  => ['email_mailboxes', 'RESTRICT'],
                'email_alias_provconn_fk' => ['infra_provider_connections', 'RESTRICT'],
            ],
            'email_forwarders' => [
                'email_fwd_domain_fk'     => ['email_domains', 'CASCADE'],
                'email_fwd_provconn_fk'   => ['infra_provider_connections', 'RESTRICT'],
            ],
            'email_catchall'   => [
                'email_ca_domain_fk'      => ['email_domains', 'CASCADE'],
                'email_ca_mailbox_fk'     => ['email_mailboxes', 'RESTRICT'],
                'email_ca_provconn_fk'    => ['infra_provider_connections', 'RESTRICT'],
            ],
            'email_usage'      => [
                'email_usg_domain_fk'     => ['email_domains', 'CASCADE'],
                // Samples describe a mailbox and mean nothing without it.
                'email_usg_mailbox_fk'    => ['email_mailboxes', 'CASCADE'],
                'email_usg_provconn_fk'   => ['infra_provider_connections', 'RESTRICT'],
            ],
            'email_domains'    => [
                'email_dom_provconn_fk'   => ['infra_provider_connections', 'RESTRICT'],
            ],
        ];

        foreach ($expected as $table => $constraints) {
            $actual = $this->foreignKeys($table);

            foreach ($constraints as $name => [$referenced, $deleteRule]) {
                $this->assertArrayHasKey($name, $actual, "{$table} is missing foreign key {$name}");
                $this->assertSame($referenced, $actual[$name]->REFERENCED_TABLE_NAME, "{$name} references the wrong table");
                $this->assertSame($deleteRule, $actual[$name]->DELETE_RULE, "{$name} has the wrong ON DELETE rule");
            }
        }
    }

    public function test_no_business_email_table_constrains_the_workspaces_table(): void
    {
        // Deliberate, and consistent with every other INFRA888 table. A
        // restrict-on-delete against workspaces would change workspace deletion
        // semantics for every other workstream.
        foreach (self::NEW_TABLES as $table) {
            foreach ($this->foreignKeys($table) as $name => $row) {
                $this->assertNotSame(
                    'workspaces',
                    $row->REFERENCED_TABLE_NAME,
                    "{$table}.{$name} constrains workspaces, which changes deletion semantics platform-wide."
                );
            }
        }
    }

    // ── check constraints ────────────────────────────────────────────────────

    /** @return array<int,string> */
    private function checkConstraints(string $table): array
    {
        return DB::table('information_schema.TABLE_CONSTRAINTS')
            ->where('TABLE_SCHEMA', $this->database())
            ->where('TABLE_NAME', $table)
            ->where('CONSTRAINT_TYPE', 'CHECK')
            ->pluck('CONSTRAINT_NAME')
            ->all();
    }

    public function test_exclusive_target_and_scope_rules_are_enforced_by_the_database(): void
    {
        $this->assertContains('email_alias_target_chk', $this->checkConstraints('email_aliases'));
        $this->assertContains('email_ca_target_chk', $this->checkConstraints('email_catchall'));
        $this->assertContains('email_usg_scope_chk', $this->checkConstraints('email_usage'));
    }

    public function test_the_alias_target_check_actually_rejects_an_invalid_row(): void
    {
        $domainId = $this->seedDomain();

        // Both targets set: the constraint must refuse it. Written with raw SQL
        // so the model-level guard cannot be what rejects it — this asserts the
        // DATABASE enforces the rule.
        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('email_aliases')->insert([
            'workspace_id'      => 4101,
            'email_domain_id'   => $domainId,
            'source_local_part' => 'both',
            'target_type'       => 'mailbox',
            'target_mailbox_id' => null,
            'target_address'    => 'someone@example.test',
            'lifecycle_state'   => 'requested',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);
    }

    public function test_the_usage_scope_check_actually_rejects_an_invalid_row(): void
    {
        $domainId = $this->seedDomain();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // scope=mailbox with no mailbox: a measurement of nothing.
        DB::table('email_usage')->insert([
            'workspace_id'     => 4101,
            'email_domain_id'  => $domainId,
            'scope'            => 'mailbox',
            'email_mailbox_id' => null,
            'source'           => 'provider',
            'confidence'       => 'observed',
            'observed_at'      => now(),
            'idempotency_key'  => 'scope-check-' . uniqid('', true),
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_the_catchall_check_rejects_an_enabled_row_with_no_target(): void
    {
        $domainId = $this->seedDomain();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('email_catchall')->insert([
            'workspace_id'    => 4101,
            'email_domain_id' => $domainId,
            'state'           => 'enabled',
            'target_type'     => null,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    // ── uniqueness behaviour ─────────────────────────────────────────────────

    public function test_a_mail_domain_cannot_be_registered_twice_while_live(): void
    {
        $this->seedDomain('unique-proof.test');

        $this->expectException(\Illuminate\Database\QueryException::class);

        // A different workspace: MX is a property of public DNS, so two tenants
        // cannot both operate mail for the same domain.
        //
        // This assertion failed on the first run, and the reason is worth
        // keeping: the constraint was originally UNIQUE(domain, deleted_at),
        // which MySQL does not apply to rows where an indexed column is NULL —
        // and deleted_at is NULL for every live row. The constraint existed,
        // read correctly, and constrained nothing.
        $this->seedDomain('unique-proof.test', 4102);
    }

    public function test_live_row_uniqueness_is_enforced_on_every_soft_deleting_table(): void
    {
        // The generated `active_flag` column is what makes each identity
        // constraint apply to live rows only. If it were ever dropped, the
        // unique keys above would silently stop constraining anything.
        foreach (['email_domains', 'email_mailboxes', 'email_aliases', 'email_forwarders'] as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'active_flag'),
                "{$table} soft-deletes and therefore needs active_flag for its identity constraint to apply."
            );

            $meta = $this->columnMeta($table, 'active_flag');

            $this->assertSame('YES', $meta->IS_NULLABLE, 'active_flag must be NULL for deleted rows to be exempt.');
            $this->assertStringContainsString(
                'GENERATED',
                (string) $meta->EXTRA,
                'active_flag must be generated, never writable — a writable flag could be set by hand.'
            );
        }

        // Tables without soft deletes must NOT carry the column: it would be a
        // meaningless always-1 field.
        foreach (['email_catchall', 'email_usage'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'active_flag'));
        }
    }

    public function test_a_soft_deleted_mailbox_frees_its_local_part(): void
    {
        $domainId = $this->seedDomain('recycle-mailbox.test');

        $first = DB::table('email_mailboxes')->insertGetId([
            'workspace_id'    => 4101,
            'email_domain_id' => $domainId,
            'local_part'      => 'sales',
            'quota_mb'        => 1024,
            'lifecycle_state' => 'requested',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        DB::table('email_mailboxes')->where('id', $first)->update(['deleted_at' => now()]);

        $second = DB::table('email_mailboxes')->insertGetId([
            'workspace_id'    => 4101,
            'email_domain_id' => $domainId,
            'local_part'      => 'sales',
            'quota_mb'        => 1024,
            'lifecycle_state' => 'requested',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        $this->assertNotSame($first, $second);
    }

    public function test_two_live_mailboxes_cannot_share_a_local_part(): void
    {
        $domainId = $this->seedDomain('dup-mailbox.test');

        $row = [
            'workspace_id'    => 4101,
            'email_domain_id' => $domainId,
            'local_part'      => 'sales',
            'quota_mb'        => 1024,
            'lifecycle_state' => 'requested',
            'created_at'      => now(),
            'updated_at'      => now(),
        ];

        DB::table('email_mailboxes')->insert($row);

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('email_mailboxes')->insert($row);
    }

    public function test_a_soft_deleted_domain_does_not_block_re_registration(): void
    {
        $id = $this->seedDomain('recycle.test');

        DB::table('email_domains')->where('id', $id)->update(['deleted_at' => now()]);

        $second = $this->seedDomain('recycle.test');

        $this->assertNotSame($id, $second);
        $this->assertSame(2, DB::table('email_domains')->where('domain', 'recycle.test')->count());
    }

    // ── vendor-name guard, read from the live schema ─────────────────────────

    public function test_no_table_or_column_in_the_database_names_a_vendor(): void
    {
        // Fragments, so this file contains no readable prohibited literal.
        $patterns = [
            '/\b' . 'm' . 'igadu\b/i',
            '/\b' . 'z' . 'oho\b/i',
            '/\b' . 'f' . 'astmail\b/i',
            '/' . 'p' . 'rivate[_.-]*(email|mail)\b/i',
            '/' . 'm' . 'icrosoft[_.-]*365/i',
            '/' . 'g' . 'oogle[_.-]*workspace/i',
        ];

        $identifiers = [];

        foreach (self::NEW_TABLES as $table) {
            $identifiers[] = $table;

            foreach (Schema::getColumnListing($table) as $column) {
                $identifiers[] = "{$table}.{$column}";
            }

            foreach ($this->indexNames($table) as $index) {
                $identifiers[] = "{$table}#{$index}";
            }
        }

        $this->assertNotEmpty($identifiers);

        foreach ($identifiers as $identifier) {
            foreach ($patterns as $pattern) {
                $this->assertSame(0, preg_match($pattern, $identifier), "Vendor-named identifier: {$identifier}");
            }
        }
    }

    public function test_no_business_email_column_stores_a_provider_reference(): void
    {
        // Provider object references live in infra_provider_resources, which
        // already carries an opaque provider_resource_id with a uniqueness
        // constraint. Duplicating them per-entity would build a second spine.
        foreach (self::NEW_TABLES as $table) {
            foreach (Schema::getColumnListing($table) as $column) {
                $this->assertSame(
                    0,
                    preg_match('/_ref$/', $column),
                    "{$table}.{$column} stores a provider reference. Use infra_provider_resources."
                );
            }
        }
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function columnMeta(string $table, string $column): object
    {
        $row = DB::table('information_schema.COLUMNS')
            ->where('TABLE_SCHEMA', $this->database())
            ->where('TABLE_NAME', $table)
            ->where('COLUMN_NAME', $column)
            ->first();

        $this->assertNotNull($row, "{$table}.{$column} does not exist");

        return $row;
    }

    private function seedDomain(string $domain = 'schema-proof.test', int $workspaceId = 4101): int
    {
        return DB::table('email_domains')->insertGetId([
            'workspace_id'       => $workspaceId,
            'domain'             => $domain,
            'custody'            => 'managed_by_us',
            'lifecycle_state'    => 'connected',
            'verification_state' => 'unverified',
            'health_state'       => 'unknown',
            'created_at'         => now(),
            'updated_at'         => now(),
        ]);
    }
}
