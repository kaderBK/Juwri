<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use Illuminate\Support\Facades\File;
use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Services\Tenancy\TenantTables;
use Juwri\Core\Services\Tenancy\TenantTablesGenerator;
use Tests\TestCase;

/**
 * P1-23 follow-up: the tenant-table lists are generated per source with the edition package layout — Community's
 * TenantTables.php from the Community migrations, each installed package's tenant_tables.php from its own — and the
 * committed lists must equal the generator's output (`php artisan tenancy:tables` regenerates them).
 */
class TenantTablesGeneratorTest extends TestCase
{
    private string $tmp;

    protected function tearDown(): void
    {
        if (isset($this->tmp)) {
            File::deleteDirectory($this->tmp);
        }
        parent::tearDown();
    }

    public function test_the_committed_lists_equal_the_generator_output(): void
    {
        $generator = new TenantTablesGenerator(base_path(), EditionPackages::tenantTablePackages());

        $this->assertSame([], $generator->stale(), 'tenant-table lists are stale: run php artisan tenancy:tables');
        $this->artisan('tenancy:tables', ['--check' => true])->expectsOutputToContain('current')->assertSuccessful();

        // Community lists only Community tables; a package's tables come from its own list.
        foreach (EditionPackages::tenantTables() as $package) {
            $this->assertSame([], array_intersect_key($package['tables'], TenantTables::TABLES));
            foreach ($package['tables'] as $table => $meta) {
                $this->assertSame($meta, TenantTables::get($table), "{$table}: merged");
            }
        }
    }

    public function test_a_new_migration_makes_the_list_stale_and_the_package_list_stays_in_the_package(): void
    {
        $core = $this->fixture();
        $generator = new TenantTablesGenerator($core, ['acme/edition' => $core.'/edition'], ['parents' => ['widget_parts' => [['widget_id', 'widgets']]], 'user_owned' => ['plans_paid']]);

        $files = $generator->files();
        $community = $files[$core.'/app/Core/Services/Tenancy/TenantTables.php'];
        $this->assertStringContainsString("        'widgets' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => false],\n", $community);
        $this->assertStringContainsString("        'widget_parts' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],\n", $community);
        $this->assertStringContainsString("        'plans_paid' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],\n", $community);
        $this->assertStringNotContainsString("'gadgets'", $community, 'package tables never land in the Community list');
        $this->assertStringNotContainsString("'retired'", $community, 'a table a drop migration removes is not listed');
        $this->assertStringContainsString("        'widget_parts' => [['widget_id', 'widgets']],\n", $community);

        $package = $files[$core.'/edition/tenant_tables.php'];
        $this->assertStringContainsString('acme/edition', $package);
        $this->assertStringContainsString("        'gadgets' => ['store' => true, 'account' => true, 'required' => true, 'account_required' => true],\n", $package);
        $this->assertStringContainsString("        'gadget_logs' => [['gadget_id', 'gadgets']],\n", $package, 'hand-kept parents survive');
        $data = eval('?>'.$package);
        $this->assertSame(['gadget_logs' => [['gadget_id', 'gadgets']]], $data['parents']);

        // Write, then nothing is stale; a new tenant migration makes the Community list stale.
        $this->assertCount(2, $generator->write());
        $this->assertSame([], $generator->stale());
        File::put($core.'/app/Core/Database/Migrations/2030_01_01_000000_create_bolts_table.php', $this->migration('bolts', "\$table->unsignedBigInteger('store_id');\n            \$table->unsignedBigInteger('account_id')->nullable();"));
        $this->assertSame([$core.'/app/Core/Services/Tenancy/TenantTables.php'], $generator->stale());
    }

    /** D-P1-21-15: a later migration that adds the tenant keys to an existing table (Schema::table in up()). */
    public function test_an_alter_migration_adding_the_tenant_keys_is_listed(): void
    {
        $core = $this->fixture();
        File::put($core.'/app/Modules/Things/Database/Migrations/2020_01_06_000000_create_gizmos_table.php', $this->migration('gizmos', "\$table->unsignedBigInteger('user_id');"));
        $alter = "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::table('%s', function (Blueprint \$table) {\n            %s\n        });\n    }\n\n    public function down(): void\n    {\n        Schema::table('%s', function (Blueprint \$table) {\n            \$table->dropColumn('account_id');\n        });\n    }\n};\n";
        File::put($core.'/app/Modules/Things/Database/Migrations/2020_01_07_000000_add_account_id_to_gizmos.php', sprintf($alter, 'gizmos', "\$table->unsignedBigInteger('account_id')->nullable();\n            \$table->index(['account_id'], 'gizmos_account_id_index');", 'gizmos'));
        // Keys and foreign keys on an existing column are not a new column (widget_parts.store_id stays nullable).
        File::put($core.'/app/Modules/Things/Database/Migrations/2020_01_08_000000_add_widget_parts_keys.php', sprintf($alter, 'widget_parts', "\$table->foreign('store_id')->references('id')->on('stores');\n            \$table->index('account_id');", 'widget_parts'));

        $community = (new TenantTablesGenerator($core, [], ['parents' => [], 'user_owned' => ['gizmos']]))->files()[$core.'/app/Core/Services/Tenancy/TenantTables.php'];
        $this->assertStringContainsString("        'gizmos' => ['store' => false, 'account' => true, 'required' => false, 'account_required' => false],\n", $community);
        $this->assertStringContainsString("        'widget_parts' => ['store' => true, 'account' => true, 'required' => false, 'account_required' => false],\n", $community);
        $this->assertStringContainsString("        'gizmos',\n", $community, 'the user-owned decision is checked against the altered columns');
    }

    public function test_decisions_are_checked_against_the_schema(): void
    {
        $core = $this->fixture();

        try {
            (new TenantTablesGenerator($core, [], ['parents' => ['widget_parts' => [['nope_id', 'widgets']]], 'user_owned' => []]))->files();
            $this->fail('a parent fk that does not exist must fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('widget_parts.nope_id missing', $e->getMessage());
        }

        // A Community decision may not name a package table (the mirror has no package).
        $this->expectExceptionMessage('gadgets');
        (new TenantTablesGenerator($core, ['acme/edition' => $core.'/edition'], ['parents' => [], 'user_owned' => ['gadgets']]))->files();
    }

    private function fixture(): string
    {
        $this->tmp = sys_get_temp_dir().'/tt-gen-'.bin2hex(random_bytes(4));
        $core = $this->tmp;
        File::ensureDirectoryExists($core.'/app/Core/Database/Migrations');
        File::ensureDirectoryExists($core.'/app/Core/Services/Tenancy');
        File::ensureDirectoryExists($core.'/app/Modules/Things/Database/Migrations');
        File::ensureDirectoryExists($core.'/edition/Modules/Gadgets/Database/Migrations');
        File::put($core.'/app/Core/Database/Migrations/2020_01_01_000000_create_widgets_table.php', $this->migration('widgets', "\$table->unsignedBigInteger('store_id');\n            \$table->unsignedBigInteger('account_id')->nullable();"));
        File::put($core.'/app/Modules/Things/Database/Migrations/2020_01_02_000000_create_widget_parts_table.php', $this->migration('widget_parts', "\$table->unsignedBigInteger('widget_id');\n            \$table->unsignedBigInteger('store_id')->nullable();\n            \$table->unsignedBigInteger('account_id')->nullable();"));
        File::put($core.'/app/Modules/Things/Database/Migrations/2020_01_03_000000_create_plans_paid_table.php', $this->migration('plans_paid', "\$table->unsignedBigInteger('user_id');\n            \$table->unsignedBigInteger('account_id')->nullable();"));
        File::put($core.'/app/Modules/Things/Database/Migrations/2020_01_04_000000_create_retired_table.php', $this->migration('retired', "\$table->unsignedBigInteger('account_id');"));
        File::put($core.'/app/Modules/Things/Database/Migrations/2020_01_05_000000_drop_retired_table.php', "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::dropIfExists('retired');\n    }\n\n    public function down(): void\n    {\n    }\n};\n");
        File::put($core.'/edition/Modules/Gadgets/Database/Migrations/2020_02_01_000000_create_gadgets_table.php', $this->migration('gadgets', "\$table->unsignedBigInteger('store_id');\n            \$table->unsignedBigInteger('account_id');"));
        File::put($core.'/edition/Modules/Gadgets/Database/Migrations/2020_02_02_000000_create_gadget_logs_table.php', $this->migration('gadget_logs', "\$table->unsignedBigInteger('gadget_id');\n            \$table->unsignedBigInteger('store_id')->nullable();\n            \$table->unsignedBigInteger('account_id')->nullable();"));
        File::put($core.'/edition/tenant_tables.php', "<?php\n\nreturn ['tables' => [], 'parents' => ['gadget_logs' => [['gadget_id', 'gadgets']]], 'user_owned' => []];\n");

        return $core;
    }

    private function migration(string $table, string $columns): string
    {
        return "<?php\nreturn new class {\n    public function up(): void\n    {\n        Schema::create('{$table}', function (Blueprint \$table) {\n            \$table->id();\n            {$columns}\n        });\n    }\n};\n";
    }
}
