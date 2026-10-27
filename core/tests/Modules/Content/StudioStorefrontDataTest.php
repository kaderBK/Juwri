<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Models\Story;
use App\Storefront\StorefrontExtras;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Services\Rendering\LiquidEnvironmentFactory;
use Juwri\Modules\Storefront\Services\Rendering\LiquidRenderer;
use Juwri\Modules\Storefront\Services\Rendering\RenderClient;
use Juwri\Modules\Storefront\Storefront\DemoStorefront;
use Juwri\Modules\Storefront\Storefront\Drops\FlagsDrop;
use Juwri\Modules\Storefront\Storefront\Drops\ItemDrop;
use Juwri\Modules\Storefront\Storefront\Drops\OptionsDrop;
use Juwri\Modules\Storefront\Storefront\ThemeContext;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Theme Studio B0.6: Liquid themes get the storefront data the Blade themes
 * compute in their own @php blocks and layout partials — hours, stories,
 * offers, social links, announcement, feature switches, vertical item fields,
 * room service — using the same helpers and gates. Also fixes two OptionsDrop
 * defaults that disagreed with the Blade storefront.
 */
class StudioStorefrontDataTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'app/marketplace-themes/datatest';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path(self::BASE));
        parent::tearDown();
    }

    private function render(string $source, array $vars): string
    {
        $globals = app(ThemeContext::class)->build($vars);

        return new LiquidRenderer(new LiquidEnvironmentFactory, new RenderClient)->render($source, $globals, 'upload');
    }

    private function store(array $options = [], string $type = 'restaurant'): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => [
                'restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999,
                'category_limit' => 999, 'allow_ordering' => 1, 'table_qr' => 0,
                'allow_pro' => '0', 'allow_advanced' => '0', 'allow_on_free' => '0',
                'allow_on_try' => '0', 'allow_on_main' => '0', 'allow_viewonly' => '0',
                'allow_custom' => '0', 'allow_onlybio' => '0',
            ],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'S', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 Main St', 'phone' => '+966 50 123 4567', 'created_at' => now(), 'updated_at' => now(),
            'store_type' => $type,
        ]);
        foreach ($options as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store;
    }

    public function test_options_drop_defaults_match_the_blade_storefront(): void
    {
        $this->assertTrue(new OptionsDrop((object) [])->open_close_store(), 'unset switch = open (was: closed)');
        $this->assertTrue(new OptionsDrop((object) ['open_close_store' => '1'])->open_close_store());
        $this->assertFalse(new OptionsDrop((object) ['open_close_store' => '0'])->open_close_store());

        $this->assertTrue(new OptionsDrop((object) [])->allow_dietary_filters(), 'default on (was: off)');
        $this->assertFalse(new OptionsDrop((object) ['allow_dietary_filters' => '0'])->allow_dietary_filters());

        $this->assertTrue(new FlagsDrop(['room_service' => true])->room_service());
        $this->assertFalse(new FlagsDrop([])->room_service());
    }

    public function test_item_drop_exposes_offer_and_vertical_fields(): void
    {
        $item = new Menu(['name' => 'Serum', 'price' => 80]);
        $item->forceFill([
            'track_inventory' => true, 'stock_qty' => 3, 'low_stock_threshold' => 4,
            'plu' => null, 'external_sku' => 'SKU-9', 'pricing_unit' => 'kg',
            'turnaround_hours' => 48, 'expiry_date' => '2027-01-31', 'age_restricted' => true,
        ]);
        $item->setAttribute('original_price', 100);
        $item->setAttribute('schedule_label', 'Happy hour');
        $drop = new ItemDrop($item);

        $this->assertSame(100.0, $drop->original_price());
        $this->assertTrue($drop->on_offer());
        $this->assertSame(20, $drop->discount_percent());
        $this->assertSame('Happy hour', $drop->schedule_label());
        $this->assertTrue($drop->available_now());
        $this->assertSame('low', $drop->stock_status(), 'uses the item low-stock threshold');
        $this->assertSame(3, $drop->stock_qty());
        $this->assertSame('SKU-9', $drop->sku());
        $this->assertSame('kg', $drop->pricing_unit());
        $this->assertSame(48, $drop->turnaround_hours());
        $this->assertSame('2027-01-31', $drop->expiry_date());
        $this->assertTrue($drop->age_restricted());

        $plain = new ItemDrop(new Menu(['name' => 'Tea', 'price' => 5]));
        $this->assertNull($plain->original_price());
        $this->assertNull($plain->stock_status(), 'untracked stock');
        $this->assertSame('each', $plain->pricing_unit());
    }

    public function test_extras_follow_the_same_sources_and_gates_as_blade(): void
    {
        $store = $this->store([
            'store_announcement_active' => '1', 'store_announcement' => 'Closed Friday',
            'instagram_url' => 'https://instagram.com/demo', 'google_review_url' => 'https://g.page/r/x',
            'enable_reservations' => '1', 'allow_tips' => '1',
        ], 'pharmacy');
        Story::create(['store_id' => $store->id, 'media_url' => 'a.jpg', 'caption' => 'Live', 'cta_url' => '/x', 'active' => true, 'sort' => 1]);
        Story::create(['store_id' => $store->id, 'media_url' => 'b.jpg', 'caption' => 'Expired', 'active' => true, 'sort' => 2, 'expires_at' => now()->subDay()]);
        Story::create(['store_id' => $store->id, 'media_url' => 'c.jpg', 'caption' => 'Off', 'active' => false, 'sort' => 3]);

        $o = (object) store_options($store->id);
        $extras = StorefrontExtras::for($store, $o, ['on_table' => false]);

        $this->assertSame('Closed Friday', $extras['announcement']['text']);
        $this->assertSame(substr(hash('crc32b', 'Closed Friday'), 0, 8), $extras['announcement']['key']);
        $this->assertSame(['Live'], array_column($extras['stories'], 'caption'), 'only visible stories');
        $this->assertSame('Order now', $extras['stories'][0]['cta_label']);
        $this->assertSame('https://instagram.com/demo', $extras['social']['instagram_url']);
        $this->assertNotNull($extras['social']['whatsapp_url']);
        $this->assertTrue($extras['hours']['is_open'], 'no hours set = open');

        $f = $extras['features'];
        $this->assertTrue($f['reservations']);
        $this->assertTrue($f['tips']);
        $this->assertTrue($f['dietary_filters'], 'default on');
        $this->assertTrue($f['google_review']);
        $this->assertFalse($f['waitlist']);
        $this->assertFalse($f['call_waiter'], 'needs an on-table diner');
        $this->assertTrue($f['age_gate'], 'pharmacy capability');
        $this->assertTrue($f['expiry']);
        $this->assertFalse($f['fitment']);

        StoreOption::updateStoreOption($store->id, 'store_announcement_active', '0');
        StoreOption::updateStoreOption($store->id, 'open_close_store', '0');
        $this->assertNull(StorefrontExtras::announcement($store->fresh()));
        $hours = StorefrontExtras::hours($store->fresh());
        $this->assertFalse($hours['is_open']);
        $this->assertTrue($hours['manual_closed']);
        $this->assertSame('closed', $hours['state']);
    }

    public function test_liquid_templates_read_the_new_globals(): void
    {
        $vars = DemoStorefront::vars();
        $burger = $vars['total_menus'][9401];
        $burger->setAttribute('original_price', (float) $burger->price + 5);

        $out = $this->render(
            '{% if hours.is_open %}OPEN {{ hours.today }}{% endif %}|'.
            '{% for d in hours.week %}{{ d.day }}{% if d.closed %}-x{% endif %},{% endfor %}|'.
            '{{ announcement.text }}|{{ social.whatsapp_url }}|'.
            '{% if features.reservations %}RES{% endif %}{% if features.gift_cards %}GIFT{% endif %}|'.
            '{% for o in offers %}{{ o.name }}:{{ o.discount_percent }}%{% endfor %}|'.
            '{{ stories | size }}|{% if flags.room_service %}ROOM{% endif %}',
            $vars
        );

        $this->assertStringContainsString('OPEN 09:00–23:00', $out);
        $this->assertStringContainsString('mon-x,tue,', $out);
        $this->assertStringContainsString('Free delivery this weekend', $out);
        $this->assertStringContainsString('https://wa.me/100000000', $out);
        $this->assertStringContainsString('|RES|', $out, 'gift cards off in demo');
        $this->assertMatchesRegularExpression('/\|[^|]+:\d+%\|/', $out, 'offer rendered with discount');
        $this->assertStringEndsWith('|0|', $out);
    }

    public function test_public_storefront_passes_the_extras(): void
    {
        $dir = storage_path(self::BASE.'/1');
        File::ensureDirectoryExists($dir.'/templates');
        File::put($dir.'/templates/index.liquid',
            '<p data-probe>{% if options.open_close_store %}open{% endif %}|{{ announcement.text | escape }}|{% if features.reservations %}res{% endif %}|{{ hours.state }}</p>');

        $store = $this->store([
            'store_template' => 'mp:datatest@1',
            'store_announcement_active' => '1', 'store_announcement' => '<b>Hi</b>',
            'enable_reservations' => '1',
        ]);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertStringContainsString('<p data-probe>open|&lt;b&gt;Hi&lt;/b&gt;|res|', $html);
    }

    public function test_money_filter_and_runtime_share_the_fallback_sign(): void
    {
        $dir = storage_path(self::BASE.'/1');
        File::ensureDirectoryExists($dir.'/templates');
        File::put($dir.'/templates/index.liquid', '<p data-price>{{ 5 | money }}</p>{% commerce %}');

        $store = $this->store(['store_template' => 'mp:datatest@1']); // no currency_sign saved
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Item', 'price' => 5, 'active' => '1']);

        $html = $this->get(route('publicView', $store->slug))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<p data-price>[^<]*\$[^<]*5\.00[^<]*<\/p>/u', $html, 'server money has the sign');
        $this->assertStringContainsString('"sign":"$"', $html, 'runtime uses the same sign');
    }
}
