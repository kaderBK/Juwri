<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Contracts\Tenancy\StoreDataPolicy;
use Juwri\Core\Services\Tenancy\StoreDataEngine;
use Juwri\Core\Services\Tenancy\StoreDataRegistry;
use Tests\Support\EditionFolders;
use Tests\TestCase;

/**
 * P1-21 (J29-8): the store-data registry is complete and safe, read against the REAL schema.
 *
 *  1. Every table with a store_id or account_id column is registered, and nothing else is.
 *  2. Each table is declared by the module whose migration creates it.
 *  3. Policies are valid per operation; account-level tables (no store_id) are never moved, reset or copied.
 *  4. A copied table handles every reference column (remapped onto copied tables, cleared, or kept for global
 *     rows) and every unique key (store-scoped, remapped or fresh) — a new column fails here until declared.
 *  5. Business data, secrets, domains and integrations are never copied; every owner connection is reset.
 */
class StoreDataRegistryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array<string, array{nullable: bool}>> */
    private function tenantSchema(): array
    {
        $out = [];
        foreach (Schema::getTables() as $t) {
            $cols = [];
            foreach (Schema::getColumns($t['name']) as $c) {
                $cols[$c['name']] = ['nullable' => (bool) $c['nullable']];
            }
            if (isset($cols['store_id']) || isset($cols['account_id'])) {
                $out[$t['name']] = $cols;
            }
        }
        ksort($out);

        return $out;
    }

    public function test_every_store_or_account_table_is_registered(): void
    {
        $live = array_keys($this->tenantSchema());
        $registered = array_keys(array_filter(StoreDataRegistry::tables(), fn ($t) => $t->kind === 'tenant'));

        $this->assertGreaterThan(150, count($live));
        $this->assertSame([], array_values(array_diff($live, $registered)), 'tables with store_id/account_id missing from the store-data registry (declare them in the owning module\'s *StoreData)');
        $this->assertSame([], array_values(array_diff($registered, $live)), 'registered tables that have no store_id/account_id (or do not exist)');
    }

    /**
     * P1-21 part 2: a table without store_id/account_id that names a user (owner columns) or references a tenant row by
     * foreign key is declared too — `owned` (the account owner's user-level rows: exported/deleted/restored with the
     * account), `child` (rows of a parent in scope) or `platform` (actors, partners: never touched).
     */
    public function test_user_keyed_and_tenant_referencing_tables_are_classified(): void
    {
        $tenant = array_keys($this->tenantSchema());
        $declared = StoreDataRegistry::tables();
        $missing = [];
        foreach (Schema::getTables() as $t) {
            $name = $t['name'];
            if (in_array($name, $tenant, true) || $name === 'accounts') {
                continue;
            }
            $cols = array_column(Schema::getColumns($name), 'name');
            $userRef = array_intersect($cols, ['user_id', 'owner_id', 'owner_user_id', 'tokenable_id', 'new_user_id', 'developer_user_id']);
            $fkRef = array_filter(Schema::getForeignKeys($name), fn ($fk) => in_array($fk['foreign_table'], [...$tenant, 'users'], true));
            if (($userRef !== [] || $fkRef !== []) && ! isset($declared[$name])) {
                $missing[] = $name.' ('.implode(', ', [...$userRef, ...array_map(fn ($fk) => implode(',', $fk['columns']).'->'.$fk['foreign_table'], $fkRef)]).')';
            }
        }
        foreach ($declared as $name => $table) {
            if ($table->kind !== 'tenant' && in_array($name, $tenant, true)) {
                $missing[] = "{$name}: has store_id/account_id, must be a tenant table";
            }
            if (in_array($table->kind, ['owned', 'child'], true) && ! Schema::hasColumn($name, (string) $table->scopeColumn)) {
                $missing[] = "{$name}: scope column {$table->scopeColumn} missing";
            }
            if ($table->kind === 'child' && ($declared[$table->parentTable] ?? null) === null) {
                $missing[] = "{$name}: parent {$table->parentTable} not registered";
            }
        }

        $this->assertSame([], $missing, 'declare these in the owning module\'s *StoreData');
    }

    /** P1-21 part 2: a secret never reaches an export bundle — every secret-looking column is declared (or the table is not exported). */
    public function test_secret_columns_are_declared_for_export(): void
    {
        $secretNames = ['password', 'pending_password', 'pin', 'pin_hash', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'auth_token', 'password_enc', 'secret', 'secrets', 'previous_secret', 'pem', 'credentials'];
        $bearer = ['staff_tokens', 'customer_tokens', 'storefront_tokens', 'signage_screens', 'drivers', 'api_tokens', 'personal_access_tokens'];
        $leaks = [];
        foreach (StoreDataRegistry::tables() as $name => $table) {
            if (! $table->exported || $table->kind === 'platform') {
                continue;
            }
            foreach (array_column(Schema::getColumns($name), 'name') as $column) {
                $secret = in_array($column, $secretNames, true) || (in_array($name, $bearer, true) && $column === 'token');
                if ($secret && ! array_key_exists($column, $table->secret)) {
                    $leaks[] = "{$name}.{$column}";
                }
            }
            foreach (array_keys($table->secret) as $column) {
                if (! Schema::hasColumn($name, $column)) {
                    $leaks[] = "{$name}.{$column}: declared secret, not a column";
                }
            }
        }

        $this->assertSame([], $leaks, 'secret columns that would be exported');
        foreach (['credentials', 'fiscal_certificates', 'staff_tokens', 'customer_tokens', 'api_tokens', 'oauth_access_tokens', 'personal_access_tokens'] as $table) {
            $this->assertFalse(StoreDataRegistry::get($table)?->exported ?? true, "{$table} must never be exported");
        }
    }

    public function test_each_table_is_declared_by_the_module_that_creates_it(): void
    {
        $owner = $this->tableModules();
        $wrong = [];
        foreach (StoreDataRegistry::tables() as $name => $table) {
            if (($owner[$name] ?? null) !== $table->module) {
                $wrong[] = "{$name}: declared by {$table->module}, created by ".($owner[$name] ?? '?');
            }
        }

        $this->assertSame([], $wrong);
    }

    public function test_policies_are_valid_and_account_level_tables_stay_put(): void
    {
        $schema = $this->tenantSchema();
        $bad = [];
        foreach (StoreDataRegistry::tables() as $name => $table) {
            $table->validate();
            if (! isset($schema[$name]['store_id']) && $name !== 'stores' // the store row itself (keyed by id)
                && ($table->transfer !== StoreDataPolicy::StayWithOldAccount || $table->duplicate !== StoreDataPolicy::Exclude || $table->repoint !== [])) {
                $bad[] = "{$name}: no store_id — must be stay-with-old-account / exclude";
            }
            foreach (array_keys($table->files) as $column) {
                if (! isset($schema[$name][$column])) {
                    $bad[] = "{$name}: file column {$column} does not exist";
                }
            }
            foreach ($table->repoint as $column) {
                if (! isset($schema[$name][$column])) {
                    $bad[] = "{$name}: repoint column {$column} does not exist";
                }
            }
        }

        $this->assertSame([], $bad);
        $this->assertSame(['message_costs', 'users', 'whatsapp_usage'], array_values(array_filter(
            array_keys(StoreDataRegistry::where(StoreDataPolicy::StayWithOldAccount)),
            fn (string $t) => isset($schema[$t]['store_id']) || $t === 'users',
        )), 'store rows that stay with the previous account (decision P1-21)');
    }

    public function test_copied_tables_handle_every_reference_and_unique_key(): void
    {
        $schema = $this->tenantSchema();
        $copied = StoreDataRegistry::where(StoreDataPolicy::Copy);
        $problems = [];

        foreach ($copied as $name => $table) {
            if ($table->duplicate !== StoreDataPolicy::Copy || $name === 'stores') {
                continue; // the store row itself is StoreCloneService's
            }
            $cols = $schema[$name];
            $handled = [...array_keys($table->remap), ...$table->clear, ...array_keys($table->values), ...array_keys($table->fresh), ...$table->keep];
            foreach (array_keys($cols) as $column) {
                $isRef = $column === 'parent' || (str_ends_with($column, '_id') && ! in_array($column, ['store_id', 'account_id', 'public_id'], true));
                if ($isRef && ! in_array($column, $handled, true)) {
                    $problems[] = "{$name}.{$column}: reference not remapped, cleared or kept";
                }
            }
            foreach ([...array_keys($table->remap), ...$table->clear, ...array_keys($table->values), ...array_keys($table->fresh), ...$table->keep] as $column) {
                if (! isset($cols[$column])) {
                    $problems[] = "{$name}.{$column}: declared but not a column";
                }
            }
            foreach (array_keys($table->remap) as $column) {
                [$target] = $table->remapTarget($column) ?? [''];
                if (! isset($copied[$target]) || $copied[$target]->duplicate !== StoreDataPolicy::Copy) {
                    $problems[] = "{$name}.{$column}: remapped onto {$target}, which is not copied";
                }
            }
            foreach (Schema::getIndexes($name) as $index) {
                if (! $index['unique'] || $index['primary']) {
                    continue;
                }
                $safe = array_intersect($index['columns'], ['store_id', ...array_keys($table->remap), ...array_keys($table->fresh)]);
                if ($safe === []) {
                    $problems[] = "{$name}: unique (".implode(', ', $index['columns']).') would collide — make a column fresh';
                }
            }
        }

        $this->assertSame([], $problems);
        $this->assertNotEmpty((new StoreDataEngine)->copyOrder(), 'copy order resolves (no cycles)');
    }

    public function test_business_data_secrets_domains_and_integrations_are_never_copied(): void
    {
        foreach ([
            'orders', 'order_items', 'carts', 'customers', 'customer_addresses', 'loyalty_accounts', 'gift_cards',
            'reservations', 'staff_members', 'drivers', 'activity_logs', 'message_costs', 'transaction',
            'credentials', 'store_domains', 'storefront_tokens', 'aggregator_connections', 'pos_bridge_connections',
            'pos_item_mappings', 'phone_lines', 'fiscal_certificates', 'whatsapp_routes', 'push_subscriptions',
            'paylink_onboardings', 'store_backups', 'store_imports', 'users',
        ] as $table) {
            $this->assertSame(StoreDataPolicy::Exclude, StoreDataRegistry::get($table)?->duplicate, "{$table} must never be copied");
        }

        $reset = array_keys(StoreDataRegistry::where(StoreDataPolicy::Reset));
        foreach ([
            'stores', 'credentials', 'store_payment_gateways', 'paylink_onboardings', 'store_domains', 'storefront_tokens',
            'aggregator_connections', 'pos_bridge_connections', 'phone_lines', 'fiscal_certificates', 'whatsapp_routes',
            'push_subscriptions',
        ] as $table) {
            $this->assertContains($table, $reset, "{$table}: the previous owner's connection must be reset on transfer");
        }

        // Owner settings come from the modules that own them.
        foreach (['restaurant_stripe_install', 'restaurant_stripe_secret_key', 'restaurant_2checkout_install', 'meta_pixel_id', 'meta_capi_token', 'print_bridge_token', 'integration_ga_id'] as $name) {
            $this->assertTrue(StoreDataRegistry::isOwnerSetting($name), $name);
        }
        foreach (['restaurant_stripe_title', 'currency_code', 'store_template', 'restaurant_bnpl_enabled', 'restaurant_online_payment'] as $name) {
            $this->assertFalse(StoreDataRegistry::isOwnerSetting($name), $name);
        }
    }

    /** @return array<string, string> table => module whose migration creates it */
    private function tableModules(): array
    {
        $root = base_path();
        $dirs = ['Core' => [$root.'/app/Core/Database/Migrations', $root.'/database/migrations']];
        foreach (glob($root.'/app/Modules/*/Database/Migrations') ?: [] as $dir) {
            $dirs[basename(dirname($dir, 2))][] = $dir;
        }
        foreach (EditionFolders::glob('Database/Migrations') as $dir) {
            $dirs[basename(dirname($dir, 2))][] = $dir;
        }
        $out = [];
        foreach ($dirs as $module => $list) {
            foreach ($list as $dir) {
                foreach (glob($dir.'/*.php') ?: [] as $file) {
                    preg_match_all("/Schema::create\\('([a-z0-9_]+)'/", (string) file_get_contents($file), $m);
                    foreach ($m[1] as $table) {
                        $out[$table] = $module;
                    }
                }
            }
        }

        return $out;
    }
}
