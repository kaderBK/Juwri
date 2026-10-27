<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform\Admin;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Policies\Admin\PanelAbilities;
use Tests\Feature\Admin\InteractsWithAdminPanel;
use Tests\TestCase;

/**
 * WI-08 characterisation: the superadmin restaurants (stores) list — the
 * older DataTables answer and the bulk delete. The server-rendered list,
 * filters, redirects, custom code and verified badge have their own tests
 * (AdminPostsPlansPageTest, ExternalRedirectAdminTest, CustomCodeGateTest).
 */
class StoresAdminContractTest extends TestCase
{
    use InteractsWithAdminPanel;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootAdminPanel();
    }

    private function store(array $overrides = [], ?User $owner = null): Store
    {
        return Store::factory()->create(array_merge(['user_id' => ($owner ?? $this->plainUser())->id], $overrides));
    }

    public function test_stores_admin_only(): void
    {
        $store = $this->store();
        $this->assertAdminOnly('get', route('admin.stores.index'));
        $this->assertAdminOnly('post', route('admin.stores.delete'), ['ids' => [$store->id]]);
        $this->assertNotNull($store->fresh());
    }

    public function test_stores_data_table(): void
    {
        $admin = $this->admin();
        $owner = User::factory()->create(['name' => 'Olive Owner', 'username' => 'olive', 'user_type' => 'user', 'status' => 1]);
        $cafe = $this->store(['title' => 'Blue Cafe', 'sub_title' => 'Coffee', 'address' => 'Harbour St'], $owner);
        $grill = $this->store(['title' => 'Red Grill', 'sub_title' => 'Steaks', 'address' => 'Hill Rd']);

        $rows = $this->actingAs($admin)->dataTable(route('admin.stores.index'), 0, 'asc')->assertOk()
            ->assertJson(['draw' => 3, 'recordsTotal' => 2])->json('data');
        $this->assertCount(2, $rows);
        $this->assertStringContainsString((string) $cafe->id, $rows[0][0]);
        $this->assertStringContainsString('Blue Cafe', $rows[0][1]);
        $this->assertStringContainsString(route('publicView', $cafe->slug), $rows[0][1]);
        $this->assertStringContainsString('Olive Owner', $rows[0][2]);
        $this->assertStringContainsString('@olive', $rows[0][2]);
        $this->assertStringContainsString(route('admin.users.edit', $owner->id), $rows[0][2]);
        $this->assertStringContainsString(route('publicView', $cafe->slug), $rows[0][4]);
        $this->assertStringContainsString('quick-check', $rows[0][5]);
        $this->assertSame($cafe->id, $rows[0]['DT_RowId']);

        $this->assertSame($grill->id, $this->actingAs($admin)->dataTable(route('admin.stores.index'), 1, 'desc')->json('data.0.DT_RowId'));
        foreach (['Grill', 'Steaks', 'Hill'] as $term) {
            $this->assertSame([$grill->id], array_column($this->actingAs($admin)->dataTable(route('admin.stores.index'), 0, 'asc', $term)->json('data'), 'DT_RowId'), $term);
        }
    }

    public function test_stores_bulk_delete(): void
    {
        $admin = $this->admin();
        $a = $this->store();
        $b = $this->store();
        $keep = $this->store();

        $this->actingAs($admin)->post(route('admin.stores.delete'), ['ids' => [$a->id, $b->id]])
            ->assertOk()->assertJson(['success' => true]);
        $this->assertSame([$keep->id], Store::pluck('id')->all());
    }

    public function test_rewrite_escapes_cells_and_gates_the_list(): void
    {
        $admin = $this->admin();
        $this->store(['title' => '<img src=x onerror=alert(1)>']);
        $cell = $this->actingAs($admin)->dataTable(route('admin.stores.index'))->json('data.0.1');
        $this->assertStringNotContainsString('<img src=x', $cell);

        $this->assertTrue($admin->can(PanelAbilities::STORES));
        $this->assertFalse($this->plainUser()->can(PanelAbilities::STORES));
    }
}
