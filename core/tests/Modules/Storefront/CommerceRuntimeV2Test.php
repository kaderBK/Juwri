<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Juwri\Core\Models\Store;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuExtra;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Menu\Models\MenuVariantOption;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Juwri\Modules\Storefront\Storefront\MarketplaceTheme;
use Juwri\Modules\Storefront\Storefront\ThemePackageValidator;
use Tests\TestCase;

/**
 * Theme Studio B0.10: commerce runtime v2 — the additive config keys the v2
 * bundle reads (goods / pharmacy / services / car parts), per-theme bundle
 * selection via theme.json `"commerce_runtime"`, v1 config compatibility and
 * a syntax check of the shipped bundle.
 */
class CommerceRuntimeV2Test extends TestCase
{
    use RefreshDatabase;

    /**
     * The pre-B0.10 CommerceRuntime::config() output for legacyFixture()
     * (csrf removed), captured from main before v2 landed.
     */
    private const V1_GOLDEN = <<<'JSON'
{"storeId":5,"restaurantSlug":"pizza","currency":{"sign":"$","pos":"1","code":"USD","rates":[]},"charges":{"tax":10,"taxLabel":"VAT","delivery":3,"minOrder":5},"orderTypes":{"onTable":true,"takeaway":true,"delivery":true},"flags":{"allowOrder":true,"allowPayment":true,"allowCallWaiter":true,"allowCoupons":true,"allowStoreCredit":false,"allowTips":true,"allowScheduling":false,"forceTable":false,"defaultTable":null,"authMode":"guest","isLoggedIn":false,"customerName":null,"customerPhone":null},"storeCredit":{"balance":0,"formatted":"$0.00"},"endpoints":{"submitOrder":"https://localhost/5/send-order","coupon":"https://localhost/pizza/coupon","waiter":"https://localhost/pizza/call-waiter","itemEvent":"https://localhost/pizza/item-event","login":"https://localhost/5/customer/login","register":"https://localhost/5/customer/register","logout":"https://localhost/5/customer/logout","otpRequest":"https://localhost/5/customer/otp/request","otpVerify":"https://localhost/5/customer/otp/verify","orderLookup":"https://localhost/pizza/order-lookup","account":"https://localhost/5/customer/account","profile":"https://localhost/5/customer/profile","addressStore":"https://localhost/5/customer/addresses"},"items":{"1":{"id":1,"name":"Burger","desc":"","price":12,"img":"https://localhost/storage/menu/b.png","gallery":["https://localhost/storage/menu/b.png"],"rating":0,"popular":false,"isSoldOut":false,"variants":[{"id":1,"label":"Large","price":15}],"extras":[{"id":1,"title":"Cheese","price":1}],"groups":[]},"2":{"id":2,"name":"Shirt","desc":"","price":20,"img":"https://localhost/storage/menu/x.png","gallery":["https://localhost/storage/menu/x.png"],"rating":0,"popular":false,"isSoldOut":false,"variants":[{"id":7,"label":"Option","price":22}],"extras":[],"groups":[]}},"i18n":{"Add":"إضافة","Cart":"السلة","Call waiter":"نداء النادل","Extras":"الإضافات","Back":"رجوع","Sold out":"نفد من المخزون","You might also like":"قد يعجبك أيضاً","Dine-in":"تناول بالمطعم","Takeaway":"سفري","Delivery":"التسليم","Your name":"الاسم","Table":"الطاولة","Phone":"الهاتف","Delivery address":"عنوان التوصيل","Coupon":"كوبون","Apply":"تطبيق","Notes":"ملاحظات","Tip":"البقشيش","None":"بدون","Custom":"مخصص","Subtotal":"المجموع الفرعي","Discount":"خصم","Total":"الإجمالي","Place order":"تقديم الطلب","Clear cart":"إفراغ السلة","Select an order type.":"اختر نوع الطلب.","Please enter your name.":"يرجى إدخال اسمك.","Please enter a delivery address.":"يرجى إدخال عنوان التسليم.","Please sign in to order.":"يرجى تسجيل الدخول للطلب.","Something went wrong.":"حدث خطأ ما.","Network error. Please try again.":"خطأ في الشبكة. حاول مرة أخرى.","Order confirmed":"تم تأكيد الطلب","Your order has been placed successfully.":"تم إرسال طلبك بنجاح.","Track order":"تتبع الطلب","Back to menu":"العودة للقائمة","Tap the heart on any dish to save it here":"اضغط القلب على أي طبق لحفظه هنا","Browse menu":"تصفح القائمة","Account":"الحساب","Close":"إغلاق","Signed in as":"مسجّل الدخول باسم","Log out":"تسجيل الخروج","Log in":"الدخول","Register":"إنشاء حساب","SMS code":"رمز الرسالة","Email":"البريد الإلكتروني","Password":"الرمز السري","Name":"الاسم","Phone (optional)":"الهاتف (اختياري)","Confirm password":"تأكيد كلمة المرور","Create account":"إنشاء حساب","Code":"الرمز","Send code":"إرسال الرمز","Verify & log in":"تحقق وسجّل الدخول","Your orders":"طلباتك","Phone or email":"الهاتف أو البريد","Find":"بحث","No orders found.":"لا توجد طلبات.","Enter your phone number.":"أدخل رقم هاتفك.","Profile":"الملف الشخصي","Save profile":"حفظ الملف","Saved addresses":"العناوين المحفوظة","Default":"افتراضي","Remove":"إزالة","Label (Home, Work)":"التسمية (المنزل، العمل)","Address":"العنوان","City":"المدينة","Add address":"إضافة عنوان","Could not save.":"تعذّر الحفظ.","Could not save address.":"تعذّر حفظ العنوان.","No orders yet.":"لا توجد طلبات حتى الآن","Set as default":"تعيين كافتراضي","Minimum order is":"الحد الأدنى للطلب"}}
JSON;

    /** @var array<int,string> */
    private array $tmpDirs = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpDirs as $dir) {
            File::deleteDirectory($dir);
        }
        parent::tearDown();
    }

    private function storePost(?string $storeType, string $slug = 'shop'): Store
    {
        $store = new Store(['slug' => $slug]);
        $store->id = 5;
        $store->store_type = $storeType;

        return $store;
    }

    /** @return array<string,mixed> */
    private function vars(Store $store, array $menus): array
    {
        return [
            'store' => $store,
            'postOptions' => (object) [
                'currency_sign' => '$', 'currency_pos' => '1',
                'restaurant_taxx_charge' => 10, 'restaurant_tax_label' => 'VAT',
                'restaurant_delivery_charge' => 3, 'min_order_value' => 5,
                'allow_coupons' => 1, 'allow_tips' => 1, 'allow_call_waiter' => 1,
                'customer_auth_mode' => 'guest',
            ],
            'total_menus' => $menus,
            'flags' => ['allow_order' => true, 'on_table' => true, 'takeaway' => true, 'delivery' => true, 'payment' => true, 'scheduling' => false],
            'customer' => null,
            'table' => null,
        ];
    }

    private function menu(int $id, string $name, float $price, array $attrs = []): Menu
    {
        $m = new Menu(['price' => $price]);
        $m->id = $id;
        $m->name = $name;
        $m->image = 'x.png';
        $m->is_sold_out = false;
        foreach ($attrs as $k => $v) {
            $m->{$k} = $v;
        }

        return $m;
    }

    private function variant(int $id, float $price, array $options, ?int $stock = null, ?string $sku = null): MenuVariant
    {
        $v = new MenuVariant(['price' => $price, 'options' => $options, 'stock_qty' => $stock, 'sku' => $sku]);
        $v->id = $id;

        return $v;
    }

    /** The CommerceRuntimeTest fixture (legacy {dimId: idx} variant) + a Lane A2 matrix item. */
    private function legacyFixture(): array
    {
        $item = $this->menu(1, 'Burger', 12);
        $item->image = 'b.png';
        $dimension = tap(new MenuVariantOption(['title' => 'Size', 'options' => ['Small', 'Large']]), fn ($g) => $g->id = 40);
        $item->setRelation('variantOptions', collect([$dimension]));
        $item->setRelation('variants', collect([$this->variant(1, 15, [40 => 1])]));
        $item->setRelation('extras', collect([tap(new MenuExtra(['title' => 'Cheese', 'price' => 1]), fn ($e) => $e->id = 1)]));

        $shirt = $this->menu(2, 'Shirt', 20);
        $shirt->setRelation('variants', collect([$this->variant(7, 22, ['Size' => 'M', 'Color' => 'Red'])]));

        $store = new Store(['slug' => 'pizza']);
        $store->id = 5;

        return $this->vars($store, [1 => $item, 2 => $shirt]);
    }

    /** Keep only the keys that exist in $shape (recursively) — the v1 projection of a v2 config. */
    private function project(array $value, array $shape): array
    {
        $out = [];
        foreach ($shape as $k => $v) {
            if (! array_key_exists($k, $value)) {
                continue;
            }
            $out[$k] = is_array($v) && is_array($value[$k]) ? $this->project($value[$k], $v) : $value[$k];
        }

        return $out;
    }

    private function theme(array $manifest): MarketplaceTheme
    {
        $dir = sys_get_temp_dir().'/mb-crv2-'.uniqid();
        File::ensureDirectoryExists($dir);
        File::put($dir.'/theme.json', json_encode($manifest));
        $this->tmpDirs[] = $dir;

        return new MarketplaceTheme('t', '1', $dir);
    }

    // ------------------------------------------------------------ v1 compatibility

    public function test_existing_v1_config_keys_are_byte_identical(): void
    {
        // Golden = the pre-B0.10 CommerceRuntime::config() for this fixture
        // (csrf dropped — per-session). v2 may only ADD keys.
        $golden = json_decode(self::V1_GOLDEN, true);
        $cfg = (new CommerceRuntime)->config($this->legacyFixture());
        unset($cfg['csrf']);

        $projected = $this->project($cfg, $golden);

        $this->assertSame(self::V1_GOLDEN, json_encode($projected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        // Top-level order: v1 keys first, in their original order.
        $this->assertSame(array_keys($golden), array_slice(array_keys($cfg), 0, count($golden)));
        $this->assertSame(array_keys($golden['items'][1]), array_slice(array_keys($cfg['items'][1]), 0, count($golden['items'][1])));
    }

    // ------------------------------------------------------------ goods

    public function test_goods_store_exposes_variant_matrix_and_stock(): void
    {
        $shirt = $this->menu(10, 'Tee', 20, ['track_inventory' => true, 'stock_qty' => 9, 'low_stock_threshold' => 3, 'plu' => 'TEE-1']);
        $shirt->setRelation('variants', collect([
            $this->variant(101, 20, ['Size' => 'S', 'Color' => 'Red'], 4, 'TEE-S-R'),
            $this->variant(102, 22, ['Size' => 'M', 'Color' => 'Red'], 0),
            $this->variant(103, 22, ['Size' => 'M', 'Color' => 'Blue'], null),
        ]));
        $untracked = $this->menu(11, 'Mug', 5, ['track_inventory' => false, 'stock_qty' => 3]);

        $cfg = (new CommerceRuntime)->config($this->vars($this->storePost('clothing'), [10 => $shirt, 11 => $untracked]));

        $this->assertSame('clothing', $cfg['store']['type']);
        $this->assertTrue($cfg['store']['capabilities']['variant_goods']);
        $this->assertFalse($cfg['store']['capabilities']['fitment']);
        $this->assertFalse($cfg['store']['ageGate']['enabled']);

        $item = $cfg['items'][10];
        $this->assertSame([
            ['name' => 'Size', 'values' => ['S', 'M']],
            ['name' => 'Color', 'values' => ['Red', 'Blue']],
        ], $item['dimensions']);
        $this->assertSame(9, $item['stock']);
        $this->assertSame(3, $item['lowStock']);
        $this->assertSame('TEE-1', $item['sku']);

        $this->assertSame(['Size' => 'S', 'Color' => 'Red'], (array) $item['variants'][0]['dims']);
        $this->assertSame(4, $item['variants'][0]['stock']);
        $this->assertSame('TEE-S-R', $item['variants'][0]['sku']);
        $this->assertSame(0, $item['variants'][1]['stock']);
        $this->assertNull($item['variants'][2]['stock']); // untracked variant
        $this->assertSame('S · Red', $item['variants'][0]['displayLabel']);
        $this->assertSame('Option', $item['variants'][0]['label']); // v1 label untouched

        // track_inventory off → stock not exposed even though a count exists.
        $this->assertNull($cfg['items'][11]['stock']);
        $this->assertSame([], $cfg['items'][11]['dimensions']);

        // Endpoints: the slot feed always; fitment feeds only for fitment stores.
        $this->assertSame(route('store.scheduleSlots', 5), $cfg['endpoints']['scheduleSlots']);
        $this->assertArrayNotHasKey('fitmentOptions', $cfg['endpoints']);
    }

    public function test_legacy_variant_dimensions_resolve_through_variant_options(): void
    {
        $cfg = (new CommerceRuntime)->config($this->legacyFixture());

        $this->assertSame([['name' => 'Size', 'values' => ['Large']]], $cfg['items'][1]['dimensions']);
        $this->assertSame(['Size' => 'Large'], (array) $cfg['items'][1]['variants'][0]['dims']);
        $this->assertSame('Large', $cfg['items'][1]['variants'][0]['displayLabel']);
        // A food store (no store_type) → restaurant, no vertical capabilities.
        $this->assertSame('restaurant', $cfg['store']['type']);
        $this->assertSame([], array_filter($cfg['store']['capabilities']));
    }

    // ------------------------------------------------------------ pharmacy

    public function test_pharmacy_store_exposes_age_gate_and_restricted_items(): void
    {
        $syrup = $this->menu(20, 'Cough syrup', 8, ['age_restricted' => true]);
        $plaster = $this->menu(21, 'Plasters', 3);

        $cfg = (new CommerceRuntime)->config($this->vars($this->storePost('pharmacy'), [20 => $syrup, 21 => $plaster]));

        $this->assertTrue($cfg['store']['capabilities']['age_gate']);
        $this->assertTrue($cfg['store']['ageGate']['enabled']);
        $this->assertTrue($cfg['store']['ageGate']['entry']); // PharmacyComplianceService default: on
        $this->assertNotSame('', $cfg['store']['ageGate']['disclaimer']);
        $this->assertTrue($cfg['items'][20]['ageRestricted']);
        $this->assertFalse($cfg['items'][21]['ageRestricted']);
    }

    // ------------------------------------------------------------ services

    public function test_service_store_exposes_booking_fields(): void
    {
        $wash = $this->menu(30, 'Wash & fold', 4, ['pricing_unit' => 'kg', 'turnaround_hours' => 24]);
        $press = $this->menu(31, 'Press shirt', 2);

        $cfg = (new CommerceRuntime)->config($this->vars($this->storePost('laundry'), [30 => $wash, 31 => $press]));

        $this->assertTrue($cfg['store']['capabilities']['service']);
        $this->assertSame('kg', $cfg['items'][30]['pricingUnit']);
        $this->assertSame(24, $cfg['items'][30]['turnaroundHours']);
        $this->assertSame('each', $cfg['items'][31]['pricingUnit']);
        $this->assertNull($cfg['items'][31]['turnaroundHours']);
        $this->assertSame(route('store.scheduleSlots', 5), $cfg['endpoints']['scheduleSlots']);
        $this->assertArrayHasKey('Please choose a time.', $cfg['i18n']);
    }

    // ------------------------------------------------------------ car parts

    public function test_carparts_store_exposes_fitment_endpoints(): void
    {
        $pad = $this->menu(40, 'Brake pad', 30);

        $cfg = (new CommerceRuntime)->config($this->vars($this->storePost('carparts', 'parts-co'), [40 => $pad]));

        $this->assertTrue($cfg['store']['capabilities']['fitment']);
        $this->assertSame(route('storefront.fitment.options', 'parts-co'), $cfg['endpoints']['fitmentOptions']);
        $this->assertSame(route('storefront.fitment.oemSearch', 'parts-co'), $cfg['endpoints']['fitmentOem']);
    }

    // ------------------------------------------------------------ version selection

    public function test_registers_without_theme_keep_the_platform_default(): void
    {
        config(['platform.commerce.runtime_version' => 'v1']);
        $reg = (new CommerceRuntime)->registers($this->legacyFixture());

        $this->assertStringContainsString('assets/commerce-runtime/v1/commerce.js', $reg['commerce_runtime_url']);
    }

    public function test_theme_without_pin_uses_the_default_bundle(): void
    {
        config(['platform.commerce.runtime_version' => 'v1']);
        $theme = $this->theme(['name' => 'T', 'slug' => 't', 'version' => '1']);

        $this->assertSame('v1', $theme->commerceRuntimeVersion());
        $reg = (new CommerceRuntime)->registers($this->legacyFixture(), $theme);
        $this->assertStringContainsString('assets/commerce-runtime/v1/commerce.js', $reg['commerce_runtime_url']);
    }

    public function test_theme_can_opt_into_v2(): void
    {
        config(['platform.commerce.runtime_version' => 'v1']);
        $theme = $this->theme(['name' => 'T', 'slug' => 't', 'version' => '1', 'commerce_runtime' => 'v2']);

        $this->assertSame('v2', $theme->commerceRuntimeVersion());
        $reg = (new CommerceRuntime)->registers($this->legacyFixture(), $theme);
        $this->assertStringContainsString('assets/commerce-runtime/v2/commerce.js', $reg['commerce_runtime_url']);
        $this->assertMatchesRegularExpression('/commerce\.js\?v=\d+$/', $reg['commerce_runtime_url']); // bundle exists → cache-bust
    }

    public function test_junk_pin_falls_back_to_the_default(): void
    {
        config(['platform.commerce.runtime_version' => 'v1']);

        foreach (['v9', '../v1', 'V2', 2, ['v2'], null] as $junk) {
            $theme = $this->theme(['name' => 'T', 'slug' => 't', 'version' => '1', 'commerce_runtime' => $junk]);
            $this->assertSame('v1', $theme->commerceRuntimeVersion(), 'junk pin: '.json_encode($junk));
        }
    }

    public function test_shipped_liquid_themes_stay_on_v1(): void
    {
        config(['platform.commerce.runtime_version' => 'v1']);
        foreach (['crave', 'bazaar', 'starter'] as $slug) {
            $dir = resource_path('liquid-themes/'.$slug.'/1');
            if (! is_dir($dir)) {
                continue;
            }
            $this->assertSame('v1', new MarketplaceTheme($slug, '1', $dir)->commerceRuntimeVersion(), $slug);
        }
    }

    // ------------------------------------------------------------ package validation

    public function test_validator_accepts_allowed_pins_and_rejects_others(): void
    {
        $base = ['name' => 'T', 'slug' => 't', 'version' => '1'];

        $this->assertSame([], ThemePackageValidator::manifestIssues(json_encode($base)));
        $this->assertSame([], ThemePackageValidator::manifestIssues(json_encode($base + ['commerce_runtime' => 'v1'])));
        $this->assertSame([], ThemePackageValidator::manifestIssues(json_encode($base + ['commerce_runtime' => 'v2'])));

        foreach (['v3', '', 2, null, ['v2']] as $bad) {
            $issues = ThemePackageValidator::manifestIssues(json_encode($base + ['commerce_runtime' => $bad]));
            $this->assertCount(1, $issues, 'bad pin: '.json_encode($bad));
            $this->assertStringContainsString('commerce_runtime', $issues[0]);
        }
    }

    // ------------------------------------------------------------ bundle

    public function test_v2_bundle_exists_parses_and_keeps_v1_hooks(): void
    {
        $v1 = (string) file_get_contents(base_path('../assets/commerce-runtime/v1/commerce.js'));
        $path = base_path('../assets/commerce-runtime/v2/commerce.js');
        $this->assertFileExists($path);
        $v2 = (string) file_get_contents($path);

        // Every data-mb-* hook v1 understands is still understood by v2.
        preg_match_all('/data-mb-[a-z0-9-]+/', $v1, $m);
        foreach (array_unique($m[0]) as $hook) {
            $this->assertStringContainsString($hook, $v2, "v2 dropped the {$hook} hook");
        }
        // CSP: no inline handlers / eval in the bundle's markup.
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=\\\\?["\']/', $v2);
        $this->assertStringNotContainsString('eval(', $v2);

        $node = trim((string) shell_exec('command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('node not installed — syntax check skipped');
        }
        exec(escapeshellarg($node).' --check '.escapeshellarg($path).' 2>&1', $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));
    }
}
