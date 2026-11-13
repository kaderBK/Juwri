<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core\Tenancy;

use Illuminate\Support\Facades\DB;
use Juwri\Core\Services\Tenancy\RowLevelSecurity;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Core\Services\Tenancy\TenantDatabaseSession;
use Juwri\Core\Services\Tenancy\TenantTables;
use Tests\TestCase;

/**
 * P1-04 slice 6: the RLS policy definitions are generated from TenantTables, and everything is a no-op off
 * PostgreSQL. (The behaviour on PostgreSQL itself: RowLevelSecurityTest, runner only.)
 */
class RowLevelSecurityDefinitionTest extends TestCase
{
    public function test_every_tenant_table_with_account_id_gets_a_policy(): void
    {
        $tables = RowLevelSecurity::tables();
        $expected = array_keys(array_filter(TenantTables::tables(), fn ($t) => $t['account']));
        $this->assertSame($expected, array_keys($tables));
        $this->assertArrayHasKey('stores', $tables);
        $this->assertArrayHasKey('orders', $tables);
        foreach ($tables as $table => $nullable) {
            $this->assertSame(! TenantTables::tables()[$table]['account_required'], $nullable, $table);
        }
    }

    public function test_statements_enable_without_force_and_check_reads_and_writes(): void
    {
        $required = RowLevelSecurity::tableStatements('orders', false);
        $this->assertSame('ALTER TABLE "orders" ENABLE ROW LEVEL SECURITY', $required[0]);
        $this->assertSame('ALTER TABLE "orders" NO FORCE ROW LEVEL SECURITY', $required[1]);
        $this->assertStringContainsString('FOR ALL USING (juwri_rls_bypass() OR account_id = juwri_rls_account()) WITH CHECK (juwri_rls_bypass() OR account_id = juwri_rls_account())', $required[3]);
        $this->assertStringNotContainsString('IS NULL', $required[3]);

        $nullable = RowLevelSecurity::tableStatements('activity_logs', true);
        $this->assertStringContainsString('USING (juwri_rls_bypass() OR account_id = juwri_rls_account() OR account_id IS NULL)', $nullable[3]);

        $functions = implode("\n", RowLevelSecurity::functionStatements());
        $this->assertStringContainsString("current_setting('app.tenant_bypass', true)", $functions);
        $this->assertStringContainsString("nullif(current_setting('app.account_id', true), '')::bigint", $functions);
    }

    public function test_off_postgresql_it_is_a_no_op(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->markTestSkipped('checks the non-PostgreSQL path');
        }
        $this->assertSame(0, RowLevelSecurity::sync(DB::connection()));
        $this->assertSame([], RowLevelSecurity::status(DB::connection()));
        $this->artisan('tenancy:rls', ['action' => 'status'])->expectsOutputToContain('Not PostgreSQL')->assertSuccessful();
    }

    public function test_the_wanted_session_state_follows_the_tenant_context(): void
    {
        $ctx = TenantContext::current();
        $this->assertSame('bypass', TenantDatabaseSession::wanted(), 'no context = platform path = explicit bypass');
        $ctx->actAs(42);
        $this->assertSame('account:42', TenantDatabaseSession::wanted());
        $this->assertSame('bypass', TenantContext::bypass(fn () => TenantDatabaseSession::wanted()));
        $ctx->actAs(null);
        $this->assertSame('account:', TenantDatabaseSession::wanted(), 'an owner without an account sees no account rows');
        $ctx->end();
        $this->assertSame('bypass', TenantDatabaseSession::wanted());
    }
}
