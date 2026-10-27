<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Tables;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09v characterisation of the owner tables actions
 * (User/Concerns/ManagesStoreTables: updateTable, deleteTable) and the
 * QR builder page + settings save (User/Concerns/BuildsQrCodes: qrBuilder,
 * qrBuilderSave): the table_qr feature gate, owner-only answers, the
 * store-scoped table lookup (a foreign id is refused, an unknown one 404s),
 * the branch a new table lands on, and which request fields become store
 * options.
 */
class OwnerTablesAndQrContractTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function owner(bool $tableQr = true): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => ['restaurant_limit' => 9, 'menu_limit' => 999] + ($tableQr ? ['table_qr' => '1'] : []),
        ]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'tq-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1', 'phone' => '1',
        ]);
    }

    private function flash(): array
    {
        return [session('app_alert_type'), session('app_alert_message')];
    }

    public function test_update_table_creates_and_renames(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $owner = $this->owner();
        $store = $this->store($owner);
        $main = Branch::where('store_id', $store->id)->orderBy('id')->firstOrFail();
        $foreignBranch = Branch::where('store_id', $this->store($this->owner())->id)->firstOrFail();

        $this->actingAs($this->owner(false))->post(route('stores.updateTable', $store->id), ['table_number' => 1])->assertNotFound();
        $this->actingAs($this->owner())->post(route('stores.updateTable', $store->id), ['table_number' => 1])->assertNotFound(); // BL-043: owner tenant scope
        $this->assertSame(0, StoreTable::count());

        $this->actingAs($owner)->from('/t')->post(route('stores.updateTable', $store->id), ['table_number' => 'x'])->assertRedirect('/t');
        $this->assertSame('error', session('app_alert_type'));
        $this->assertStringContainsString('table number field must be an integer', session('app_alert_message'));

        $this->actingAs($owner)->from('/t')->post(route('stores.updateTable', $store->id), ['table_number' => 4, 'branch_id' => $main->id])->assertRedirect('/t');
        $this->assertSame(['success', ___('Saved Successfully')], $this->flash());
        $t = StoreTable::sole();
        $this->assertSame([4, $store->id, $main->id], [(int) $t->table_no, (int) $t->store_id, (int) $t->branch_id]);
        $this->assertNotEmpty($t->key);

        $this->actingAs($owner)->post(route('stores.updateTable', $store->id), ['table_number' => 5, 'branch_id' => $foreignBranch->id]);
        $this->assertNull(StoreTable::where('table_no', 5)->value('branch_id'), 'a foreign branch is dropped');
        $this->actingAs($owner)->post(route('stores.updateTable', $store->id), ['table_number' => 6]);
        $this->assertNull(StoreTable::where('table_no', 6)->value('branch_id'));
        $keys = StoreTable::pluck('key');
        $this->assertSame($keys->count(), $keys->unique()->count());

        $this->actingAs($owner)->post(route('stores.updateTable', $store->id), ['id' => $t->id, 'table_number' => 9, 'branch_id' => 999]);
        $this->assertSame(['success', ___('Saved Successfully')], $this->flash());
        $t->refresh();
        $this->assertSame([9, $main->id], [(int) $t->table_no, (int) $t->branch_id], 'a rename keeps the branch');

        $foreign = StoreTable::create(['store_id' => $foreignBranch->store_id, 'table_no' => 1, 'key' => 'k1']);
        $this->actingAs($owner)->post(route('stores.updateTable', $store->id), ['id' => $foreign->id, 'table_number' => 7])->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertSame(1, (int) $foreign->fresh()->table_no);
        $this->actingAs($owner)->post(route('stores.updateTable', $store->id), ['id' => 999999, 'table_number' => 7])->assertNotFound();
    }

    public function test_delete_table(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $mine = StoreTable::create(['store_id' => $store->id, 'table_no' => 1, 'key' => 'a']);
        $foreign = StoreTable::create(['store_id' => $this->store($this->owner())->id, 'table_no' => 1, 'key' => 'b']);

        $this->actingAs($owner)->from('/t')->post(route('stores.deleteTable', $store->id), [])->assertRedirect('/t');
        $this->assertStringContainsString('id field is required', session('app_alert_message'));
        $this->actingAs($this->owner())->post(route('stores.deleteTable', $store->id), ['id' => $mine->id])->assertNotFound(); // BL-043: owner tenant scope
        $this->assertNotNull($mine->fresh());
        $this->actingAs($owner)->post(route('stores.deleteTable', $store->id), ['id' => $foreign->id])->assertNotFound(); // BL-043: the owner tenant scope hides the other account's row (404)
        $this->assertNotNull($foreign->fresh());
        $this->actingAs($owner)->post(route('stores.deleteTable', $store->id), ['id' => 999999])->assertNotFound();

        // No table_qr gate on delete (a downgraded owner can still clean up).
        $downgraded = $this->owner(false);
        $store->update(['user_id' => $downgraded->id]);
        $this->actingAs($downgraded)->post(route('stores.deleteTable', $store->id), ['id' => $mine->id]);
        $this->assertSame(['success', ___('Deleted Successfully')], $this->flash());
        $this->assertNull(StoreTable::find($mine->id));
    }

    public function test_qr_builder_page_flags_the_visit(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);

        $this->actingAs($this->owner())->get(route('stores.qrDesigner', $store->id))->assertNotFound();
        $this->assertEmpty(store_options($store->id, 'qr_builder_visited'));

        $res = $this->actingAs($owner)->get(route('stores.qrDesigner', $store->id))->assertOk()->assertViewIs('owner.qr.builder');
        $this->assertSame('1', (string) store_options($store->id, 'qr_builder_visited'));
        $this->assertTrue($res->viewData('store')->is($store));
        $this->assertSame('1', (string) $res->viewData('store_options')->qr_builder_visited);
    }

    public function test_qr_builder_save_stores_the_posted_fields_as_options(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $url = url('stores/'.$store->id.'/qr-designer');

        $this->actingAs($this->owner())->from('/q')->post($url, ['qr_fg_color' => '#000000'])->assertNotFound(); // BL-043: owner tenant scope
        $this->assertEmpty(store_options($store->id, 'qr_fg_color'));

        $this->actingAs($owner)->from('/q')->post($url, ['qr_source_label' => str_repeat('a', 65), 'qr_fg_color' => '#111111'])->assertRedirect('/q');
        $this->assertStringContainsString('qr source label field must not be greater than 64', session('app_alert_message'));
        $this->assertSame('#111111', session()->getOldInput('qr_fg_color'), 'input is kept');
        $this->assertEmpty(store_options($store->id, 'qr_fg_color'));

        $this->actingAs($owner)->from('/q')->post($url, [
            'qr_fg_color' => '#222222', 'qr_padding' => '3', 'qr_source_label' => '  Table Tent  Front!! ', 'submit' => 'Save',
        ])->assertRedirect('/q');
        $this->assertSame(['success', ___('Saved Successfully')], $this->flash());
        $this->assertSame(['#222222', '3', 'table-tent-front'], [
            store_options($store->id, 'qr_fg_color'), (string) store_options($store->id, 'qr_padding'), store_options($store->id, 'qr_source_label'),
        ]);
        $this->assertSame(0, count(array_intersect(['submit', '_token'], array_keys(StoreSettings::legacyMap((int) $store->id)))));

        // P6-67: keys outside the QR allowlist are ignored (no gateway/compliance mass-assign).
        $this->actingAs($owner)->from('/q')->post($url, [
            'qr_fg_color' => '#333333', 'restaurant_paylink_secret_key' => 'attacker', 'store_template' => 'evil', 'external_redirect_enabled' => '1',
        ])->assertRedirect('/q');
        $this->assertSame('#333333', store_options($store->id, 'qr_fg_color'));
        $this->assertEmpty(store_options($store->id, 'restaurant_paylink_secret_key'));
        $this->assertNotSame('evil', store_options($store->id, 'store_template'));
        $this->assertNotSame('1', (string) store_options($store->id, 'external_redirect_enabled'));

        // An uploaded logo is stored and its filename saved as qr_image.
        $this->actingAs($owner)->post($url, ['qr_image' => UploadedFile::fake()->image('logo.png', 40, 40)]);
        $this->assertSame(['success', ___('Saved Successfully')], $this->flash());
        $logo = (string) store_options($store->id, 'qr_image');
        $this->assertNotSame('', $logo);
        $this->tmpFiles[] = public_path('storage/store/logo/'.$logo);
        $this->actingAs($owner)->post($url, ['qr_image' => UploadedFile::fake()->create('x.pdf', 5, 'application/pdf')]);
        $this->assertSame('error', session('app_alert_type'));
        $this->assertSame($logo, (string) store_options($store->id, 'qr_image'));
    }
}
