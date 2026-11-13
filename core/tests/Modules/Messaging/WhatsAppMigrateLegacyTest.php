<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Messaging\Console\Commands\WhatsAppMigrateLegacy;
use Tests\TestCase;

/**
 * WA-C4/D1: the legacy migration command.
 *
 * The owner re-imports legacy databases, so the properties that matter are:
 * idempotent, safe on an already-migrated database, and a dry run that writes
 * nothing at all.
 */
class WhatsAppMigrateLegacyTest extends TestCase
{
    use RefreshDatabase;

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1]);

        return Store::create([
            'user_id' => $owner->id,
            'slug' => 'r-'.Str::lower(Str::random(8)),
            'color' => '#111',
            'title' => 'WA Migrate Test',
            'sub_title' => 't',
            'description' => 't',
            'main_image' => 'default.png',
            'cover_image' => 'default.png',
            'address' => '1 St',
            'phone' => '123',
        ]);
    }

    private function raw(int $storeId, string $key): ?string
    {
        return StoreSettings::legacy($storeId, $key);
    }

    public function test_report_goes_to_the_given_path_and_never_into_rev(): void
    {
        $dir = sys_get_temp_dir().'/wa-report-'.Str::random(8);
        $path = $dir.'/nested/report.md';
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '1');

        $this->artisan('whatsapp:migrate-legacy', ['--dry-run' => true, '--report' => $path])->assertSuccessful();

        $this->assertFileExists($path);
        $report = (string) file_get_contents($path);
        $this->assertStringContainsString('whatsapp:migrate-legacy --dry-run', $report);
        $this->assertStringContainsString('### flag', $report);
        // a second run appends, never rewrites
        $this->artisan('whatsapp:migrate-legacy', ['--dry-run' => true, '--report' => $path])->assertSuccessful();
        $this->assertSame(2, substr_count((string) file_get_contents($path), '## '.now()->format('Y-m-d')));

        $source = (string) file_get_contents((new \ReflectionClass(WhatsAppMigrateLegacy::class))->getFileName());
        $this->assertStringNotContainsString("'../rev", $source);

        File::deleteDirectory($dir);
    }

    public function test_default_report_location_is_storage_app_and_unit_tests_do_not_write_it(): void
    {
        $default = storage_path('app/whatsapp-migration-report.md');
        File::delete($default);

        $this->artisan('whatsapp:migrate-legacy', ['--dry-run' => true])->assertSuccessful();

        $this->assertFileDoesNotExist($default);
    }

    public function test_dry_run_writes_absolutely_nothing(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '1');

        $this->artisan('whatsapp:migrate-legacy', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('1', $this->raw($store->id, 'quickorder_enable'), 'legacy row must survive a dry run');
        $this->assertNull($this->raw($store->id, 'whatsapp_ordering_enable'), 'dry run must not create the new key');
    }

    public function test_it_migrates_the_flag_and_drops_the_legacy_row(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '1');

        $this->artisan('whatsapp:migrate-legacy')->assertSuccessful();

        $this->assertSame('1', $this->raw($store->id, 'whatsapp_ordering_enable'));
        $this->assertNull($this->raw($store->id, 'quickorder_enable'), 'legacy row should be gone');
        $this->assertTrue(whatsapp_ordering_enabled($store->id));
    }

    public function test_a_disabled_store_stays_disabled(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '0');

        $this->artisan('whatsapp:migrate-legacy')->assertSuccessful();

        $this->assertSame('0', $this->raw($store->id, 'whatsapp_ordering_enable'));
        $this->assertFalse(whatsapp_ordering_enabled($store->id));
    }

    public function test_it_never_overwrites_a_value_the_owner_already_set(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '1');
        // Owner has since switched it off on the new settings page.
        StoreOption::updateStoreOption($store->id, 'whatsapp_ordering_enable', '0');

        $this->artisan('whatsapp:migrate-legacy')->assertSuccessful();

        $this->assertSame('0', $this->raw($store->id, 'whatsapp_ordering_enable'), 'the owner\'s choice wins');
    }

    public function test_running_it_twice_changes_nothing_the_second_time(): void
    {
        $store = $this->store();
        StoreOption::updateStoreOption($store->id, 'quickorder_enable', '1');

        $this->artisan('whatsapp:migrate-legacy')->assertSuccessful();
        $after = $this->raw($store->id, 'whatsapp_ordering_enable');

        // A legacy re-import runs this again months later; it must be a no-op.
        $this->artisan('whatsapp:migrate-legacy')->assertSuccessful();

        $this->assertSame($after, $this->raw($store->id, 'whatsapp_ordering_enable'));
        $this->assertNull($this->raw($store->id, 'quickorder_enable'));
    }

    public function test_it_survives_a_database_with_nothing_to_migrate(): void
    {
        $this->store();

        $this->artisan('whatsapp:migrate-legacy')->assertSuccessful();
    }

    public function test_an_unknown_step_fails_loudly(): void
    {
        $this->artisan('whatsapp:migrate-legacy', ['--only' => 'nope'])->assertFailed();
    }

    // --- WA-D2: legacy number -> default branch ---------------------------

    public function test_it_fills_an_empty_default_branch_from_the_legacy_option(): void
    {
        $store = $this->store();
        Branch::where('store_id', $store->id)->update(['whatsapp' => null]);
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '+44 7700 900201');

        $this->artisan('whatsapp:migrate-legacy', ['--only' => 'numbers'])->assertSuccessful();

        $this->assertSame('+44 7700 900201', Branch::where('store_id', $store->id)->value('whatsapp'));
    }

    public function test_it_never_overwrites_a_branch_number_that_is_already_set(): void
    {
        $store = $this->store();
        Branch::where('store_id', $store->id)->update(['whatsapp' => '+10000000000']);
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '+29999999999');

        $this->artisan('whatsapp:migrate-legacy', ['--only' => 'numbers'])->assertSuccessful();

        $this->assertSame('+10000000000', Branch::where('store_id', $store->id)->value('whatsapp'),
            'the branch number is authoritative and must survive');
    }

    public function test_a_store_with_no_branch_row_gets_a_default_one(): void
    {
        $store = $this->store();
        // The one owner-approved case where we may invent a branch.
        Branch::where('store_id', $store->id)->delete();
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '+44 7700 900201');

        $this->artisan('whatsapp:migrate-legacy', ['--only' => 'numbers'])->assertSuccessful();

        $branch = Branch::where('store_id', $store->id)->first();
        $this->assertNotNull($branch, 'a default branch should have been created');
        $this->assertSame('+44 7700 900201', $branch->whatsapp);
        $this->assertSame(0, (int) $branch->position);
    }

    public function test_the_numbers_dry_run_writes_nothing(): void
    {
        $store = $this->store();
        Branch::where('store_id', $store->id)->update(['whatsapp' => null]);
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '+44 7700 900201');

        $this->artisan('whatsapp:migrate-legacy', ['--only' => 'numbers', '--dry-run' => true])->assertSuccessful();

        $this->assertNull(Branch::where('store_id', $store->id)->value('whatsapp'));
    }

    public function test_running_numbers_twice_changes_nothing(): void
    {
        $store = $this->store();
        Branch::where('store_id', $store->id)->update(['whatsapp' => null]);
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '+44 7700 900201');

        $this->artisan('whatsapp:migrate-legacy', ['--only' => 'numbers'])->assertSuccessful();
        $after = Branch::where('store_id', $store->id)->value('whatsapp');
        $branchCount = Branch::where('store_id', $store->id)->count();

        $this->artisan('whatsapp:migrate-legacy', ['--only' => 'numbers'])->assertSuccessful();

        $this->assertSame($after, Branch::where('store_id', $store->id)->value('whatsapp'));
        $this->assertSame($branchCount, Branch::where('store_id', $store->id)->count(),
            're-running must not create a duplicate branch');
    }

    public function test_extra_numbers_are_never_placed_automatically(): void
    {
        $store = $this->store();
        Branch::where('store_id', $store->id)->update(['whatsapp' => null]);
        StoreOption::updateStoreOption($store->id, 'whatsapp_number', '+11111111111');
        StoreOption::updateStoreOption($store->id, 'whatsapp_number2', '+22222222222');
        StoreOption::updateStoreOption($store->id, 'whatsapp_number3', '+33333333333');

        $this->artisan('whatsapp:migrate-legacy', ['--only' => 'numbers'])->assertSuccessful();

        // Only #1 lands; the extras are reported for the owner, never invented into branches.
        $this->assertSame(1, Branch::where('store_id', $store->id)->count());
        $this->assertSame('+11111111111', Branch::where('store_id', $store->id)->value('whatsapp'));
    }

    public function test_an_option_row_whose_store_is_gone_is_skipped_not_fatal(): void
    {
        $store = $this->store();
        $id = $store->id;
        Branch::where('store_id', $id)->delete();
        StoreOption::updateStoreOption($id, 'whatsapp_number', '+44 7700 900201');
        Store::where('id', $id)->delete();

        // 52 such orphans exist in the real database — they must not crash the run
        // and must not resurrect a branch for a store that no longer exists.
        $this->artisan('whatsapp:migrate-legacy', ['--only' => 'numbers'])->assertSuccessful();

        $this->assertSame(0, Branch::where('store_id', $id)->count());
    }
}
