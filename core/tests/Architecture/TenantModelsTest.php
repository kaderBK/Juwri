<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Architecture;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Concerns\BelongsToAccount;
use Juwri\Core\Services\Tenancy\TenantTables;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\Support\TenantSchema;
use Tests\TestCase;

/**
 * P1-04 slice 5 (J25-6, J29-1): tenant safety is structural.
 *
 *  1. TenantTables (generated from the migrations) matches the live schema: every table with a store_id or
 *     account_id column is listed with the right flags, and nothing else is.
 *  2. Every Eloquent model on a tenant table uses BelongsToAccount (account fill + tenant scope). A new model
 *     with store_id/account_id that forgets it fails here.
 */
class TenantModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_table_list_matches_the_schema(): void
    {
        $live = TenantSchema::live();
        $listed = TenantTables::tables();
        ksort($listed);

        // Community list (Community migrations) + the installed edition packages' lists (their own migrations), merged:
        // exactly the live schema, with or without a package.
        $this->assertSame($live, $listed, 'TenantTables is stale: run php artisan tenancy:tables');
        foreach (TenantTables::tables() as $table => $meta) {
            $this->assertTrue($meta['account'], "{$table}: every tenant table carries account_id (J29-1)");
        }
    }

    public function test_every_tenant_model_uses_belongs_to_account(): void
    {
        $missing = [];
        $seen = 0;
        foreach ($this->models() as $class) {
            $table = (new ReflectionClass($class))->newInstanceWithoutConstructor()->getTable();
            if (TenantTables::get($table) === null) {
                continue;
            }
            $seen++;
            if (! in_array(BelongsToAccount::class, class_uses_recursive($class), true)) {
                $missing[] = "{$class} ({$table})";
            }
        }

        $this->assertGreaterThan(150, $seen, 'model discovery found too few tenant models');
        $this->assertSame([], $missing, "tenant models without BelongsToAccount:\n".implode("\n", $missing));
    }

    /** @return list<class-string<Model>> */
    private function models(): array
    {
        $out = [];
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path()));
        foreach ($it as $file) {
            if (! str_ends_with($file->getFilename(), '.php')) {
                continue;
            }
            $src = (string) file_get_contents($file->getPathname());
            if (! str_contains($src, 'extends') || ! preg_match('/^namespace\s+([^;]+);/m', $src, $ns)
                || ! preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $src, $cl)) {
                continue;
            }
            $class = $ns[1].'\\'.$cl[1];
            if (! class_exists($class)) {
                continue;
            }
            $r = new ReflectionClass($class);
            if (! $r->isAbstract() && $r->isSubclassOf(Model::class)) {
                $out[] = $class;
            }
        }

        return $out;
    }
}
