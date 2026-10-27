<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\LoyaltyAccount;
use App\Models\LoyaltyLedger;
use App\Models\MenuCategory;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Models\SwitchImport;
use Juwri\Modules\Integrations\Services\Switching\SwitchError;
use Juwri\Modules\Integrations\Services\Switching\SwitchFile;
use Juwri\Modules\Integrations\Services\Switching\SwitchPlanner;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\FakeOdoo;
use Tests\TestCase;
use ZipArchive;

/**
 * P4-22 switching tools: menu, customers and loyalty balances from CSV / Excel (juwri template, Foodics-style and
 * Arabic headers) or the previous Odoo. Dry run writes nothing; the commit does exactly the preview, once; prices are
 * exact minor units; customers never get consent; points are credited once per customer and source; every lookup
 * and write stays in the importing store; the rows (personal data) are encrypted and cleared after the commit.
 */
class SwitchingImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function owner(): User
    {
        return User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner, string $currency = 'SAR'): Store
    {
        $store = Store::create(['user_id' => $owner->id, 'slug' => 'sw-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Switch',
            'sub_title' => 't', 'description' => 'd', 'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1', 'phone' => '1']);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);

        return $store;
    }

    private function upload(User $owner, Store $store, string $dataset, string $name, string $content, string $mode = 'add'): SwitchImport
    {
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->post(route('integrations.switch.preview'), ['dataset' => $dataset, 'mode' => $mode, 'source' => 'file',
                'file' => UploadedFile::fake()->createWithContent($name, $content)])
            ->assertRedirect()->assertSessionHasNoErrors();

        return SwitchImport::query()->latest('id')->firstOrFail();
    }

    private function commit(User $owner, Store $store, SwitchImport $import): void
    {
        $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])->post(route('integrations.switch.commit', $import->id))->assertRedirect();
    }

    public function test_a_foodics_style_menu_is_previewed_without_writing_then_imported_exactly_once(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $csv = "name,name_localized,description,sku,category_reference,price,is_active\n"
            ."Flat white,فلات وايت,Double shot,FW-01,Hot drinks,\"1,234.50\",1\n"
            ."Tea,شاي,,TEA-1,Hot drinks,٤٫٠٠,yes\n"
            ."Cake,كيك,,,Desserts,12.345,1\n"   // 3 decimals in SAR: refused, never rounded
            ."Tea again,,,TEA-1,Hot drinks,5,1\n"; // same SKU as row 3
        $import = $this->upload($alice, $store, 'menu', 'foodics-products.csv', $csv);

        $this->assertSame(0, Menu::query()->where('store_id', $store->id)->count(), 'the dry run wrote nothing');
        $this->assertEquals(['create' => 2, 'error' => 2, 'categories' => 1], array_intersect_key($import->plan['counts'], array_flip(['create', 'error', 'categories']))); // MySQL JSON reorders keys
        $this->assertSame(['4', '5'], array_column($import->plan['errors'], 'row'));

        $this->commit($alice, $store, $import);
        $fw = Menu::query()->where('store_id', $store->id)->where('external_sku', 'FW-01')->sole();
        $this->assertSame([123450, 'SAR', 'Double shot', '1'], [(int) $fw->price_minor, $fw->currency_code, $fw->getRawOriginal('description'), (string) $fw->active]);
        $this->assertSame('فلات وايت', $fw->translations->ar->title);
        $this->assertSame(400, (int) Menu::query()->where('store_id', $store->id)->where('external_sku', 'TEA-1')->sole()->price_minor);
        $this->assertSame(['Hot drinks'], MenuCategory::query()->where('store_id', $store->id)->pluck('name')->all());
        $import->refresh();
        $this->assertSame(['committed', null], [$import->status, $import->rows]);

        // the same import cannot be committed twice; the same file again is all "no change"
        $this->actingAs($alice)->withSession(['dashboard_store_id' => $store->id])->post(route('integrations.switch.commit', $import->id))->assertSessionHas('error');
        $again = $this->upload($alice, $store, 'menu', 'foodics-products.csv', $csv);
        $this->assertSame([0, 2], [$again->plan['counts']['create'], $again->plan['counts']['skip']]);

        // update mode changes the price of an existing item; add mode never does
        $upd = $this->upload($alice, $store, 'menu', 'p.csv', "name,sku,price\nTea,TEA-1,4.50\n", 'update');
        $this->assertSame(1, $upd->plan['counts']['update']);
        $this->commit($alice, $store, $upd);
        $this->assertSame(450, (int) Menu::query()->where('store_id', $store->id)->where('external_sku', 'TEA-1')->sole()->price_minor);
    }

    public function test_prices_parse_exactly_or_are_refused(): void
    {
        foreach (['14.50' => 1450, '14,50' => 1450, '1,234.50' => 123450, '1.234,50' => 123450, 'SAR 14.5' => 1450, '١٤٫٥٠' => 1450, '7' => 700, '0.10' => 10] as $in => $minor) {
            $this->assertSame($minor, SwitchPlanner::price($in, 'SAR'), $in);
        }
        $this->assertSame(12345, SwitchPlanner::price('12.345', 'KWD'));
        $this->assertSame(1200, SwitchPlanner::price('1,200', 'JPY'));
        foreach (['12.345', '-3', 'abc', '', '1.2.3'] as $bad) {
            try {
                SwitchPlanner::price($bad, 'SAR');
                $this->fail("accepted {$bad}");
            } catch (SwitchError) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_an_excel_file_is_read_with_shared_strings_and_float_cells(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $import = $this->upload($alice, $store, 'menu', 'menu.xlsx', $this->xlsx([['الاسم', 'السعر', 'الفئة'], ['Latte', 14.499999999999998, 'قهوة']]));
        $this->commit($alice, $store, $import);
        $this->assertSame(1450, (int) Menu::query()->where('store_id', $store->id)->sole()->price_minor);

        // an Excel file with a DOCTYPE (XXE) is refused
        $this->expectException(SwitchError::class);
        SwitchFile::read($this->xlsx([['name', 'price'], ['x', 1]], doctype: true), 'xlsx');
    }

    public function test_customers_are_deduplicated_never_opted_in_and_kept_to_the_store(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $other = $this->store($this->owner());
        Customer::create(['store_id' => $other->id, 'name' => 'Bob elsewhere', 'phone' => '+966501111111', 'email' => 'sara@example.com']);
        Customer::create(['store_id' => $store->id, 'name' => '', 'phone' => '+966502222222']);

        $csv = "name,dial_code,phone,email,birth_date\n"
            ."Sara,966,0501111111,Sara@Example.com,1990-05-14\n"        // new here (the other store's twin is not matched)
            ."Omar,,+966 50-222 2222,omar@example.com,\n"               // existing by phone: name + e-mail filled
            ."Sara dup,,+966501111111,,\n"                              // same as row 2
            ."Bad,,12,,\n";
        $import = $this->upload($alice, $store, 'customers', 'customers.csv', $csv);
        $this->assertSame([1, 1, 2], [$import->plan['counts']['create'], $import->plan['counts']['update'], $import->plan['counts']['error']]);

        $raw = (string) DB::table('switch_imports')->where('id', $import->id)->value('rows');
        $this->assertStringNotContainsString('0501111111', $raw, 'the import rows are encrypted at rest');

        $this->commit($alice, $store, $import);
        $sara = Customer::query()->where('store_id', $store->id)->where('email', 'sara@example.com')->sole();
        $this->assertSame(['+966501111111', false, false, 'import:csv'], [$sara->phone, (bool) $sara->marketing_consent, (bool) $sara->sms_consent, $sara->source]);
        $omar = Customer::query()->where('store_id', $store->id)->where('phone', '+966502222222')->sole();
        $this->assertSame(['Omar', 'omar@example.com'], [$omar->name, $omar->email]);
        $this->assertSame('Bob elsewhere', Customer::query()->where('store_id', $other->id)->sole()->name, "another store's customer is untouched");
    }

    public function test_loyalty_points_are_credited_once_to_this_store_s_customers_only(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $other = $this->store($this->owner());
        $c = Customer::create(['store_id' => $store->id, 'name' => 'Sara', 'phone' => '+966501111111']);
        $twin = Customer::create(['store_id' => $other->id, 'name' => 'Twin', 'phone' => '+966501111111']);

        $csv = "phone,points\n+966501111111,120\n+966509999999,50\n+966501111111x,1.5\n";
        $import = $this->upload($alice, $store, 'loyalty', 'points.csv', $csv);
        $this->assertSame([1, 2], [$import->plan['counts']['credit'], $import->plan['counts']['error']]);
        $this->commit($alice, $store, $import);

        $acct = LoyaltyAccount::query()->where('store_id', $store->id)->where('customer_id', $c->id)->sole();
        $this->assertSame(120, (int) $acct->balance);
        $this->assertSame(['adjustment', 120], [LoyaltyLedger::query()->where('loyalty_account_id', $acct->id)->sole()->type, (int) LoyaltyLedger::query()->where('loyalty_account_id', $acct->id)->sole()->delta]);
        $this->assertNull(LoyaltyAccount::query()->where('customer_id', $twin->id)->first(), "another store's customer got nothing");

        // the same source again: nothing added
        $again = $this->upload($alice, $store, 'loyalty', 'points.csv', "phone,points\n+966501111111,500\n");
        $this->assertSame([0, 1], [$again->plan['counts']['credit'], $again->plan['counts']['skip']]);
        $this->commit($alice, $store, $again);
        $this->assertSame(120, (int) $acct->fresh()->balance);
    }

    public function test_only_the_store_owner_sees_and_commits_an_import(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $import = $this->upload($alice, $store, 'menu', 'm.csv', "name,price\nTea,4\n");
        $bob = $this->owner();
        $bobStore = $this->store($bob);
        $this->app['auth']->forgetGuards();
        $this->actingAs($bob)->withSession(['dashboard_store_id' => $bobStore->id])->get(route('integrations.switch.show', $import->id))->assertNotFound();
        $this->actingAs($bob)->withSession(['dashboard_store_id' => $store->id])->post(route('integrations.switch.commit', $import->id))->assertNotFound();
        $this->assertSame(0, Menu::query()->count());

        $staff = StaffMember::create(['owner_id' => $alice->id, 'store_id' => $store->id, 'name' => 'S', 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1]);
        $this->app['auth']->forgetGuards();
        $res = $this->actingAs($staff, 'staff')->actingAs($alice, 'web')->withSession(['staff_session_id' => $staff->id, 'dashboard_store_id' => $store->id])
            ->post(route('integrations.switch.commit', $import->id));
        $this->assertContains($res->getStatusCode(), [302, 403]);
        $this->assertSame(0, Menu::query()->count(), 'a staff session imported');
    }

    public function test_old_previews_are_pruned_and_templates_download(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $old = $this->upload($alice, $store, 'customers', 'c.csv', "phone\n+966501111111\n");
        $this->travel(2)->days();
        $this->artisan('switch:prune-previews')->assertSuccessful();
        $this->assertNull(SwitchImport::query()->find($old->id));

        $res = $this->actingAs($alice)->withSession(['dashboard_store_id' => $store->id])->get(route('integrations.switch.template', 'menu'))->assertOk();
        $this->assertStringContainsString('category,name,name_ar,description,price,sku,active', (string) $res->getContent());
    }

    public function test_menu_customers_and_points_come_from_the_previous_odoo(): void
    {
        $odoo = FakeOdoo::install();
        $odoo->rows['product.template'] = [
            1 => ['id' => 1, 'name' => 'Espresso', 'list_price' => 9.5, 'default_code' => 'ESP', 'categ_id' => [3, 'All / Coffee'], 'description_sale' => false, 'active' => true, 'sale_ok' => true],
        ];
        $odoo->rows['res.partner'] = [
            5 => ['id' => 5, 'name' => 'Lina', 'phone' => '+966503333333', 'mobile' => false, 'email' => 'lina@example.com', 'customer_rank' => 2],
            6 => ['id' => 6, 'name' => 'Vendor', 'phone' => '+966504444444', 'mobile' => false, 'email' => false, 'customer_rank' => 0],
        ];
        $odoo->rows['loyalty.card'] = [9 => ['id' => 9, 'partner_id' => [5, 'Lina'], 'points' => 40.0]];
        $alice = $this->owner();
        $store = $this->store($alice);
        $form = ['source' => 'odoo', 'url' => FakeOdoo::URL, 'database' => FakeOdoo::DB, 'username' => FakeOdoo::USER, 'api_key' => FakeOdoo::KEY];
        foreach (['menu', 'customers', 'loyalty'] as $dataset) {
            $this->actingAs($alice)->withSession(['dashboard_store_id' => $store->id])->post(route('integrations.switch.preview'), $form + ['dataset' => $dataset])
                ->assertRedirect()->assertSessionHasNoErrors();
            $import = SwitchImport::query()->latest('id')->firstOrFail();
            $this->assertSame('odoo', $import->source);
            $this->commit($alice, $store, $import);
        }
        $this->assertSame([950, 'Coffee'], [(int) Menu::query()->where('store_id', $store->id)->sole()->price_minor, MenuCategory::query()->where('store_id', $store->id)->sole()->getRawOriginal('name')]);
        $this->assertSame(['Lina'], Customer::query()->where('store_id', $store->id)->pluck('name')->all(), 'only Odoo customers, not vendors');
        $this->assertSame(40, (int) LoyaltyAccount::query()->where('store_id', $store->id)->sole()->balance);
        $this->assertDatabaseMissing('credentials', ['store_id' => $store->id]); // the Odoo key was not kept
    }

    /** A minimal .xlsx: first sheet with a shared string table (text) and numeric cells. */
    private function xlsx(array $rows, bool $doctype = false): string
    {
        $strings = [];
        $sheetRows = '';
        foreach ($rows as $r => $cells) {
            $xmlCells = '';
            foreach (array_values($cells) as $c => $v) {
                $ref = chr(65 + $c).($r + 1);
                if (is_string($v)) {
                    $strings[] = htmlspecialchars($v, ENT_XML1);
                    $xmlCells .= '<c r="'.$ref.'" t="s"><v>'.(count($strings) - 1).'</v></c>';
                } else {
                    $xmlCells .= '<c r="'.$ref.'"><v>'.var_export($v, true).'</v></c>';
                }
            }
            $sheetRows .= '<row r="'.($r + 1).'">'.$xmlCells.'</row>';
        }
        $tmp = tempnam(sys_get_temp_dir(), 'xt');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0"?>'.($doctype ? '<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]>' : '')
            .'<workbook '.$ns.' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml" Type="x"/></Relationships>');
        $zip->addFromString('xl/sharedStrings.xml', '<?xml version="1.0"?><sst '.$ns.'>'.implode('', array_map(fn ($s) => '<si><t>'.$s.'</t></si>', $strings)).'</sst>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet '.$ns.'><sheetData>'.$sheetRows.'</sheetData></worksheet>');
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);

        return $bytes;
    }

    public function test_the_screens_walk_upload_check_import_and_show_row_errors_and_the_result(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $ctx = ['dashboard_store_id' => $store->id];
        $form = (string) $this->actingAs($alice)->withSession($ctx)->get(route('integrations.switch.index'))->assertOk()->getContent();
        $this->assertStringContainsString('data-switch-steps', $form);
        $this->assertStringContainsString('aria-describedby="switch-file-help"', $form);

        $import = $this->upload($alice, $store, 'menu', 'm.csv', "name,price\nTea,4\nBad,1.234\n");
        $page = (string) $this->actingAs($alice)->withSession($ctx)->get(route('integrations.switch.show', $import->id))->assertOk()->getContent();
        foreach (['data-switch-dryrun', 'data-switch-errors', 'data-switch-error-note', 'data-switch-commit', 'data-switch-wait'] as $marker) {
            $this->assertStringContainsString($marker, $page, $marker);
        }
        $this->assertStringContainsString('aria-current="step"', $page);

        $this->commit($alice, $store, $import);
        $done = (string) $this->actingAs($alice)->withSession($ctx)->get(route('integrations.switch.show', $import->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-switch-result', $done);
        $this->assertStringContainsString('data-switch-again', $done);
        $this->assertStringNotContainsString('data-switch-commit', $done, 'a finished import cannot be imported again');
    }

    public function test_every_template_style_downloads_and_round_trips_through_the_check_without_errors(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $ctx = ['dashboard_store_id' => $store->id];
        foreach (['juwri', 'foodics', 'arabic'] as $style) {
            $files = [];
            foreach (['menu', 'customers', 'loyalty'] as $dataset) {
                $res = $this->actingAs($alice)->withSession($ctx)->get(route('integrations.switch.template', ['dataset' => $dataset] + ($style === 'juwri' ? [] : ['style' => $style])))->assertOk();
                $files[$dataset] = (string) $res->getContent();
                $this->assertStringStartsWith("\xEF\xBB\xBF", $files[$dataset]);
            }
            // customers first (loyalty points need an existing customer), then the menu and the points
            $cust = $this->upload($alice, $store, 'customers', "c-$style.csv", $files['customers']);
            $this->assertSame([1, 0], [$cust->plan['counts']['create'] + $cust->plan['counts']['update'] + $cust->plan['counts']['skip'], $cust->plan['counts']['error']], "$style customers");
            $this->commit($alice, $store, $cust);
            foreach (['menu', 'loyalty'] as $dataset) {
                $imp = $this->upload($alice, $store, $dataset, "$dataset-$style.csv", $files[$dataset]);
                $this->assertSame(0, $imp->plan['counts']['error'], "$style $dataset template has no row errors");
                $this->assertSame(1, array_sum(array_intersect_key($imp->plan['counts'], array_flip(['create', 'credit', 'skip']))), "$style $dataset: one row understood");
            }
        }
        $this->actingAs($alice)->withSession($ctx)->get(route('integrations.switch.template', ['dataset' => 'menu', 'style' => 'bogus']))->assertNotFound();
    }

    public function test_row_errors_name_the_column_that_is_wrong(): void
    {
        $alice = $this->owner();
        $store = $this->store($alice);
        $menu = $this->upload($alice, $store, 'menu', 'm.csv', "name,price,sku\n,1,A\nTea,1.234,B\nCake,2,C\nCake again,2,C\n");
        $this->assertSame(['name', 'price', 'sku'], array_column($menu->plan['errors'], 'field'));
        $cust = $this->upload($alice, $store, 'customers', 'c.csv', "name,phone,email\nA,12,\nB,,not-an-email\nC,,\n");
        $this->assertSame(['phone', 'email', 'phone'], array_column($cust->plan['errors'], 'field'));
        $loy = $this->upload($alice, $store, 'loyalty', 'l.csv', "phone,points\n+966500000000,5\n");
        $this->assertSame(['phone'], array_column($loy->plan['errors'], 'field'));
        $page = (string) $this->actingAs($alice)->withSession(['dashboard_store_id' => $store->id])->get(route('integrations.switch.show', $menu->id))->assertOk()->getContent();
        $this->assertStringContainsString('data-field="price"', $page);
    }
}
