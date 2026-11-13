<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Models\Customer;
use App\Models\ImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Services\Import\CustomerImporter;
use Juwri\Modules\Integrations\Services\Import\GloriaFoodImporter;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

class GloriaFoodImportP2Test extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'category_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_customers_import_without_marketing_consent(): void
    {
        $r = $this->storeFixture();
        $csv = "Name,Phone,Email\nAlice,555-1,alice@x.com\nBob,555-2,\n";

        $batch = new CustomerImporter()->import($r->id, $csv);
        $this->assertSame(2, $batch->items_created);

        $alice = Customer::where('store_id', $r->id)->where('phone', '5551')->first();
        $this->assertNotNull($alice);
        $this->assertFalse((bool) $alice->marketing_consent);
        $this->assertFalse((bool) $alice->sms_consent);
        $this->assertSame('gloriafood_import', $alice->source);
    }

    public function test_customers_import_dedups_and_is_idempotent(): void
    {
        $r = $this->storeFixture();
        Customer::create(['store_id' => $r->id, 'name' => 'Existing', 'phone' => '555-1']);
        $csv = "Name,Phone\nAlice,555-1\nBob,555-2\n"; // 555-1 already exists

        $batch = new CustomerImporter()->import($r->id, $csv);
        $this->assertSame(1, $batch->items_created); // only Bob
        $this->assertSame(1, $batch->skipped);

        // Re-import same file → idempotent, and marked already_imported.
        $again = new CustomerImporter()->import($r->id, $csv);
        $this->assertSame($batch->id, $again->id);
        $this->assertTrue($again->already_imported);
        $this->assertSame(2, Customer::where('store_id', $r->id)->count());
    }

    public function test_reupload_after_customers_deleted_imports_again(): void
    {
        $r = $this->storeFixture();
        $csv = "Name,Phone\nAlice,555-1\nBob,555-2\n";

        $batch = new CustomerImporter()->import($r->id, $csv);
        $this->assertSame(2, $batch->items_created);

        // Delete the imported customers → the same file must import again (not dedupe).
        Customer::where('store_id', $r->id)->where('source', 'gloriafood_import')->delete();
        $this->assertSame(0, Customer::where('store_id', $r->id)->count());

        $again = new CustomerImporter()->import($r->id, $csv);
        $this->assertFalse($again->already_imported);
        $this->assertSame(2, $again->items_created);
        $this->assertSame(2, Customer::where('store_id', $r->id)->where('source', 'gloriafood_import')->count());

        // The earlier batch row was updated in place (no unique-key duplicate).
        $this->assertSame(1, ImportBatch::where('store_id', $r->id)->where('source', 'gloriafood')->where('kind', 'customers')->count());
    }

    public function test_customer_reupload_shows_already_imported_message(): void
    {
        config(['app.installed' => true]); // complete setup so the real route (auth, tenancy, setup gate) runs
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);
        $csv = "Name,Phone,Email\nAlice,555-1,alice@x.com\n";
        $file = UploadedFile::fake()->createWithContent('rows.csv', $csv);

        $this->actingAs($owner)->withSession(['dashboard_store_id' => $r->id])
            ->post(route('stores.import.gloriafood.customers'), ['file' => $file])
            ->assertRedirect();

        $response = $this->actingAs($owner)->withSession(['dashboard_store_id' => $r->id])
            ->post(route('stores.import.gloriafood.customers'), ['file' => UploadedFile::fake()->createWithContent('rows.csv', $csv)])
            ->assertRedirect();

        $response->assertSessionHas('app_alert_type', 'info');
        $response->assertSessionHas('app_alert_message', ___('This file was already imported. No changes were made.'));
    }

    public function test_widget_json_parses_and_imports_menu(): void
    {
        $r = $this->storeFixture();
        $json = [
            'categories' => [
                ['name' => 'Pizza', 'items' => [
                    ['name' => 'Margherita', 'price' => '8.00', 'description' => 'Classic'],
                    ['name' => 'Pepperoni', 'price' => 10],
                ]],
                ['name' => 'Sides', 'items' => [['name' => 'Fries', 'price' => 3]]],
            ],
        ];

        $importer = new GloriaFoodImporter;
        $rows = $importer->parseWidget($json);
        $this->assertCount(3, $rows);

        $batch = $importer->importFromWidget($r->id, $json);
        $this->assertSame(2, $batch->categories_created);
        $this->assertSame(3, $batch->items_created);
        $this->assertSame(3, Menu::where('store_id', $r->id)->count());

        // Idempotent re-import.
        $again = $importer->importFromWidget($r->id, $json);
        $this->assertSame($batch->id, $again->id);
    }
}
