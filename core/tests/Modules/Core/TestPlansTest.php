<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Finder\Finder;
use Tests\Support\CommunityTestPlan;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * The Community test plan helper must work on every database without an edition package: the public mirror's MySQL /
 * PostgreSQL run failed when tests deleted from the edition's `plans` table (absent in Community) and wrote the stand-in
 * id 'free' into the integer `transactions.product_id` column (sqlite accepted both).
 */
class TestPlansTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_community_stand_in_has_no_row_and_no_integer_product_id(): void
    {
        $plan = new CommunityTestPlan(['name' => 'X']);

        $this->assertNull(TestPlans::productId($plan));

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });
        TestPlans::delete($plan);
        $this->assertSame(0, $queries, 'deleting the stand-in must not query any table');
    }

    public function test_a_persisted_model_plan_is_deleted_by_its_own_table_and_key(): void
    {
        $row = User::factory()->create();
        $other = User::factory()->create();

        $this->assertSame((int) $row->getKey(), TestPlans::productId($row));

        TestPlans::delete($row);

        $this->assertFalse(DB::table('users')->where('id', $row->getKey())->exists());
        $this->assertTrue(DB::table('users')->where('id', $other->getKey())->exists());
    }

    public function test_no_community_test_names_the_edition_plans_table(): void
    {
        $offenders = [];
        foreach (Finder::create()->files()->in(base_path('tests'))->name('*.php') as $file) {
            foreach (file($file->getRealPath()) ?: [] as $i => $line) {
                // A query on the table is fine only behind a hasTable('plans') check on the same line.
                if (preg_match('/(?:::|->)table\(\s*[\'"]plans[\'"]/', $line) === 1
                    && preg_match('/hasTable\(\s*[\'"]plans[\'"]/', $line) !== 1) {
                    $offenders[] = $file->getRelativePathname().':'.($i + 1);
                }
            }
        }

        $this->assertSame([], $offenders, 'use Tests\Support\TestPlans instead of the plans table');
    }
}
