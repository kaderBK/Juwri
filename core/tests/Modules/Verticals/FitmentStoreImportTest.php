<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Verticals;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\VehicleFitment;
use App\Services\FeatureAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Verticals\Jobs\Fitment\ImportFitmentsJob;
use Juwri\Modules\Verticals\Models\FitmentImport;
use Juwri\Modules\Verticals\Services\Fitment\FitmentImporter;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P3-08b (D12): the store-wide fitment CSV import — preview before anything is written, bad rows reported and
 * skipped (never aborting the file), foreign store ids ignored, size / row limits, the queue for big files, and
 * every route scoped to the owner's own store.
 */
class FitmentStoreImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Store $store;

    private Menu $pads;

    private Menu $oil;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Storage::fake('local');
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->owner = $this->makeUser();
        $this->store = $this->makeStore($this->owner);
        $cat = MenuCategory::create(['store_id' => $this->store->id, 'name' => 'Parts']);
        $this->pads = $this->part($this->store, $cat, 'Brake pads', 'BRK-1001');
        $this->oil = $this->part($this->store, $cat, 'Oil 5W30', 'OIL-5W30');
    }

    private function makeUser(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5, 'menu_limit' => 999], 'status' => 1]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        EntitlementOverride::create(['user_id' => $user->id, 'feature_key' => 'multi_vertical', 'value' => '1', 'source_type' => 'admin']);
        FeatureAccess::flush();

        return $user;
    }

    private function makeStore(User $owner, string $type = 'carparts'): Store
    {
        $title = 'S'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'store_type' => $type, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function part(Store $store, MenuCategory $cat, string $name, string $sku): Menu
    {
        return Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => $name, 'price' => 10, 'active' => 1, 'external_sku' => $sku])->fresh();
    }

    private function upload(string $csv, ?User $as = null, ?Store $to = null, string $name = 'fitments.csv')
    {
        return $this->actingAs($as ?? $this->owner)->post(route('retail.fitments.import.upload', $to ?? $this->store), [
            'csv_file' => UploadedFile::fake()->createWithContent($name, $csv),
        ]);
    }

    private function preview(string $csv): FitmentImport
    {
        $this->upload($csv)->assertRedirect();

        return FitmentImport::query()->where('store_id', $this->store->id)->latest('id')->firstOrFail();
    }

    private function confirm(FitmentImport $import)
    {
        return $this->actingAs($this->owner)->post(route('retail.fitments.import.confirm', [$this->store, $import->public_id]));
    }

    public function test_a_preview_counts_and_samples_the_file_and_adds_nothing(): void
    {
        $import = $this->preview("sku,year,make,model,submodel,engine,vin,notes\nBRK-1001,2015-2018,Honda,Civic,EX,2.0L,,front\nOIL-5W30,2016,Toyota,Corolla,,1.8L,JTDKB20U,\n");

        $this->assertSame('previewed', $import->status);
        $this->assertSame(2, $import->total_rows);
        $this->assertSame(5, $import->valid_rows); // 4 years + 1
        $this->assertSame(0, $import->error_rows);
        $this->assertSame(2, count($import->sample));
        $this->assertSame(0, VehicleFitment::count(), 'a preview never writes a fitment');
        $this->actingAs($this->owner)->get(route('retail.fitments.import.show', [$this->store, $import->public_id]))
            ->assertOk()->assertSee('BRK-1001')->assertSee('Honda Civic');
    }

    public function test_confirming_adds_the_fitments_to_the_store_and_is_safe_to_repeat(): void
    {
        $import = $this->preview("sku,year,make,model\nBRK-1001,2015-2016,Honda,Civic\nOIL-5W30,2016,Toyota,Corolla\n");
        $this->confirm($import)->assertRedirect();
        $this->confirm($import)->assertRedirect(); // a second click adds nothing

        $import->refresh();
        $this->assertSame('done', $import->status);
        $this->assertSame(3, $import->imported_rows);
        $this->assertSame(3, VehicleFitment::where('store_id', $this->store->id)->count());
        $row = VehicleFitment::where('menu_id', $this->pads->id)->where('year', 2016)->firstOrFail();
        $this->assertSame((int) $this->store->account_id, (int) $row->account_id, 'rows carry the store\'s account');
        $this->assertNull($import->path);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'the upload is removed once imported');

        // the same file again: everything is already there
        $again = $this->preview("sku,year,make,model\nBRK-1001,2015-2016,Honda,Civic\n");
        $this->assertSame(0, $again->valid_rows);
        $this->assertSame(2, $again->duplicate_rows);
    }

    public function test_bad_rows_are_reported_and_the_rest_of_the_file_still_imports(): void
    {
        $csv = "sku,year,make,model,vin\n"
            ."BRK-1001,2016,Honda,Civic,\n"            // good
            ."NOPE-1,2016,Honda,Civic,\n"              // unknown product
            ."BRK-1001,abcd,Honda,Civic,\n"            // bad year
            ."BRK-1001,1850,Honda,Civic,\n"            // year out of range
            ."BRK-1001,2016,,Civic,\n"                 // no make
            ."BRK-1001,2016,Honda,Civic,IIII1234\n"    // bad VIN (I)
            ."BRK-1001,2000-2100,Honda,Civic,\n"       // range wider than 60 years
            ."OIL-5W30,2017,Toyota,Corolla,\n";        // good
        $import = $this->preview($csv);

        $this->assertSame(8, $import->total_rows);
        $this->assertSame(2, $import->valid_rows);
        $this->assertSame(6, $import->error_rows);
        $this->assertSame([3, 4, 5, 6, 7, 8], array_column($import->errors, 'row'));
        $this->assertStringContainsString('NOPE-1', $import->errors[0]['message']);

        $this->confirm($import)->assertRedirect();
        $this->assertSame(2, VehicleFitment::count());
        $this->actingAs($this->owner)->get(route('retail.fitments.import.errors', [$this->store, $import->public_id]))
            ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertSee('NOPE-1');
    }

    public function test_foreign_store_ids_in_the_file_are_ignored(): void
    {
        $other = $this->makeStore($this->makeUser());
        $otherCat = MenuCategory::create(['store_id' => $other->id, 'name' => 'X']);
        $foreign = $this->part($other, $otherCat, 'Foreign part', 'FOR-1');

        // store_id / account_id / menu_id point at the other store: never read
        $csv = "store_id,account_id,menu_id,sku,year,make,model\n{$other->id},{$other->account_id},{$foreign->id},BRK-1001,2016,Honda,Civic\n{$other->id},{$other->account_id},{$foreign->id},FOR-1,2016,Honda,Civic\n";
        $import = $this->preview($csv);

        $this->assertSame(['store_id', 'account_id', 'menu_id'], $import->meta['ignored']);
        $this->assertSame(1, $import->valid_rows, 'the other store\'s SKU is "not found" here');
        $this->assertSame(1, $import->error_rows);
        $this->confirm($import);
        $this->assertSame(1, VehicleFitment::where('store_id', $this->store->id)->where('menu_id', $this->pads->id)->count());
        $this->assertSame(0, VehicleFitment::where('store_id', $other->id)->count());
        $this->assertSame(0, VehicleFitment::where('menu_id', $foreign->id)->count());

        // the item public id of another store's product reads as not found too
        $byId = $this->preview("item_id,year,make,model\n{$foreign->public_id},2016,Honda,Civic\n{$this->oil->public_id},2016,Honda,Civic\n");
        $this->assertSame(1, $byId->valid_rows);
        $this->assertSame(1, $byId->error_rows);
    }

    public function test_a_variant_sku_finds_its_product_and_a_shared_sku_is_ambiguous(): void
    {
        MenuVariant::query()->insert(['account_id' => $this->store->account_id, 'store_id' => $this->store->id, 'menu_id' => $this->oil->id, 'sku' => 'OIL-1L', 'active' => true, 'position' => 1, 'price_minor' => 100]);
        MenuVariant::query()->insert(['account_id' => $this->store->account_id, 'store_id' => $this->store->id, 'menu_id' => $this->pads->id, 'sku' => 'SHARED', 'active' => true, 'position' => 1, 'price_minor' => 100]);
        MenuVariant::query()->insert(['account_id' => $this->store->account_id, 'store_id' => $this->store->id, 'menu_id' => $this->oil->id, 'sku' => 'SHARED', 'active' => true, 'position' => 2, 'price_minor' => 100]);

        $import = $this->preview("sku,year,make,model\noil-1l,2016,Toyota,Corolla\nSHARED,2016,Toyota,Corolla\n");

        $this->assertSame(1, $import->valid_rows, 'a variant SKU matches case-insensitively');
        $this->assertSame(1, $import->error_rows);
        $this->assertStringContainsString('more than one product', $import->errors[0]['message']);
    }

    public function test_a_file_that_cannot_be_read_at_all_is_refused_without_a_record(): void
    {
        foreach (['', "sku,year\nBRK-1001,2016\n", "year,make,model\n2016,Honda,Civic\n", "sku,year,make,model\n"] as $csv) {
            $this->upload($csv)->assertRedirect(route('retail.fitments.import', $this->store->id));
        }
        $this->assertSame(0, FitmentImport::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_size_and_row_limits_hold(): void
    {
        $big = UploadedFile::fake()->createWithContent('big.csv', "sku,year,make,model\n".str_repeat("BRK-1001,2016,Honda,Civic\n", 1)."\n")->size(FitmentImporter::MAX_BYTES / 1024 + 10);
        $this->actingAs($this->owner)->post(route('retail.fitments.import.upload', $this->store), ['csv_file' => $big])->assertSessionHasErrors('csv_file');

        $rows = "sku,year,make,model\n".str_repeat("BRK-1001,2016,Honda,Civic\n", FitmentImporter::MAX_ROWS + 1);
        $this->upload($rows)->assertRedirect(route('retail.fitments.import', $this->store->id));
        $this->assertSame(0, FitmentImport::count(), 'one row over the limit: nothing is kept');

        $wide = implode(',', array_map(fn ($i) => "c{$i}", range(1, FitmentImporter::MAX_COLUMNS + 1)))."\n1\n";
        $this->upload($wide)->assertRedirect(route('retail.fitments.import', $this->store->id));
        $this->assertSame(0, FitmentImport::count());
    }

    public function test_a_big_file_is_imported_by_the_queue(): void
    {
        Queue::fake();
        $rows = ["sku,year,make,model"];
        for ($i = 0; $i < FitmentImporter::INLINE_ROWS + 5; $i++) {
            $rows[] = 'BRK-1001,'.(1950 + intdiv($i, 20)).',Make'.($i % 20).',Model';
        }
        $import = $this->preview(implode("\n", $rows)."\n");
        $this->assertSame(FitmentImporter::INLINE_ROWS + 5, $import->valid_rows);

        $this->confirm($import)->assertRedirect();
        Queue::assertPushed(ImportFitmentsJob::class, fn (ImportFitmentsJob $j) => $j->importId === $import->id && $j->storeId === $this->store->id);
        $this->assertSame('queued', $import->refresh()->status);
        $this->assertSame(0, VehicleFitment::count(), 'nothing is written before the job runs');

        $this->confirm($import); // a second click while queued does not queue again
        Queue::assertPushed(ImportFitmentsJob::class, 1);

        (new ImportFitmentsJob($import->id, $this->store->id))->handle(app(FitmentImporter::class));
        $import->refresh();
        $this->assertSame('done', $import->status);
        $this->assertSame(FitmentImporter::INLINE_ROWS + 5, VehicleFitment::count());
        // a retry of the finished job does nothing
        (new ImportFitmentsJob($import->id, $this->store->id))->handle(app(FitmentImporter::class));
        $this->assertSame(FitmentImporter::INLINE_ROWS + 5, VehicleFitment::count());
    }

    public function test_the_job_never_runs_another_stores_import(): void
    {
        $import = $this->preview("sku,year,make,model\nBRK-1001,2016,Honda,Civic\n");
        $import->forceFill(['status' => 'queued'])->save();
        $other = $this->makeStore($this->makeUser());

        (new ImportFitmentsJob($import->id, $other->id))->handle(app(FitmentImporter::class));

        $this->assertSame('queued', $import->refresh()->status);
        $this->assertSame(0, VehicleFitment::count());
    }

    public function test_year_ranges_semicolons_bom_and_vin_prefixes_are_read(): void
    {
        $csv = "\xEF\xBB\xBFSKU;Year;Make;Model;Engine;VIN\nBRK-1001;2014 - 2015;Ford;Focus;1.6L;wf0 ab123\n";
        $import = $this->preview($csv);
        $this->assertSame(2, $import->valid_rows);
        $this->confirm($import);

        $row = VehicleFitment::where('year', 2015)->firstOrFail();
        $this->assertSame('Ford', $row->make);
        $this->assertSame('1.6L', $row->engine);
        $this->assertSame('WF0AB123', $row->vin_prefix);
    }

    public function test_the_error_report_defuses_spreadsheet_formulas(): void
    {
        $import = $this->preview("sku,year,make,model\n=cmd|' /C calc'!A0,2016,Honda,Civic\nBRK-1001,2016,Honda,Civic\n");
        $csv = app(FitmentImporter::class)->errorsCsv($import);

        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertStringNotContainsString("\n=cmd", $csv);
    }

    public function test_a_new_preview_replaces_an_unconfirmed_one(): void
    {
        $first = $this->preview("sku,year,make,model\nBRK-1001,2016,Honda,Civic\n");
        $second = $this->preview("sku,year,make,model\nOIL-5W30,2016,Honda,Civic\n");

        $this->assertNull(FitmentImport::find($first->id));
        $this->assertNotNull(FitmentImport::find($second->id));
        $this->assertCount(1, Storage::disk('local')->allFiles());
    }

    public function test_the_store_fitment_cap_is_enforced(): void
    {
        config(['verticals.fitment_store_cap' => 3]);
        VehicleFitment::create(['menu_id' => $this->oil->id, 'store_id' => $this->store->id, 'year' => 2000, 'make' => 'Old', 'model' => 'Car']);

        $import = $this->preview("sku,year,make,model\nBRK-1001,2016-2018,Honda,Civic\nOIL-5W30,2016,Toyota,Corolla\n");

        $this->assertSame(2, $import->valid_rows, 'room for two more');
        $this->assertSame(2, $import->error_rows, 'a row that does not fit entirely is reported');
        $this->assertStringContainsString('most fitments allowed', $import->errors[0]['message']);
        $this->confirm($import);
        $this->assertSame(3, VehicleFitment::where('store_id', $this->store->id)->count());
    }

    public function test_only_the_owner_of_a_fitment_store_reaches_the_import(): void
    {
        $import = $this->preview("sku,year,make,model\nBRK-1001,2016,Honda,Civic\n");
        $intruder = $this->makeUser();

        $this->actingAs($intruder)->get(route('retail.fitments.import', $this->store))->assertNotFound();
        $this->actingAs($intruder)->get(route('retail.fitments.import.template', $this->store))->assertNotFound();
        $this->upload("sku,year,make,model\nBRK-1001,2016,Honda,Civic\n", $intruder)->assertNotFound();
        $this->actingAs($intruder)->get(route('retail.fitments.import.show', [$this->store, $import->public_id]))->assertNotFound();
        $this->actingAs($intruder)->post(route('retail.fitments.import.confirm', [$this->store, $import->public_id]))->assertNotFound();
        $this->actingAs($intruder)->get(route('retail.fitments.import.errors', [$this->store, $import->public_id]))->assertNotFound();
        $this->actingAs($intruder)->delete(route('retail.fitments.import.destroy', [$this->store, $import->public_id]))->assertNotFound();
        $this->assertSame('previewed', $import->refresh()->status);
        $this->assertSame(0, VehicleFitment::count());
    }

    public function test_an_import_id_of_another_store_is_not_found_through_my_store(): void
    {
        $mine = $this->makeStore($this->owner);
        $import = $this->preview("sku,year,make,model\nBRK-1001,2016,Honda,Civic\n");

        // the same owner, another store of theirs: the import of the first store is not reachable through the second
        $this->actingAs($this->owner)->get(route('retail.fitments.import.show', [$mine, $import->public_id]))->assertNotFound();
        $this->actingAs($this->owner)->post(route('retail.fitments.import.confirm', [$mine, $import->public_id]))->assertNotFound();
        $this->assertSame('previewed', $import->refresh()->status);
    }

    public function test_a_store_without_the_fitment_capability_gets_404(): void
    {
        $shop = $this->makeStore($this->owner, 'retail');
        $this->actingAs($this->owner)->get(route('retail.fitments.import', $shop))->assertNotFound();
        $this->upload("sku,year,make,model\nA,2016,Honda,Civic\n", null, $shop)->assertNotFound();
    }

    public function test_the_screens_and_the_template_render(): void
    {
        $this->actingAs($this->owner)->get(route('retail.fitments.import', $this->store))->assertOk()->assertSee('Upload a fitment file');
        $this->actingAs($this->owner)->get(route('retail.fitments.index', $this->store))->assertOk()->assertSee(route('retail.fitments.import', $this->store->id));
        $tpl = $this->actingAs($this->owner)->get(route('retail.fitments.import.template', $this->store))->assertOk();
        $this->assertStringContainsString('sku,year,make,model', (string) $tpl->getContent());
        // the template itself imports cleanly once its SKUs exist
        $import = $this->preview(FitmentImporter::TEMPLATE);
        $this->assertSame(0, $import->error_rows);
    }

    public function test_discarding_a_preview_removes_it_and_its_file(): void
    {
        $import = $this->preview("sku,year,make,model\nBRK-1001,2016,Honda,Civic\n");
        $this->actingAs($this->owner)->delete(route('retail.fitments.import.destroy', [$this->store, $import->public_id]))->assertRedirect();

        $this->assertNull(FitmentImport::find($import->id));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
