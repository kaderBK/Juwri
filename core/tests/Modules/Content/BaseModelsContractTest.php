<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Content;

use App\Models\Coupon;
use App\Models\ImageMenu;
use App\Models\MenuCategory;
use App\Models\StoreTable;
use App\Models\StoreView;
use App\Models\Upgrade;
use App\Support\PlatformSecrets;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Country;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Notification;
use Juwri\Core\Models\PlatformSetting;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Core\Support\SecretMask;
use Juwri\Modules\Content\Models\Adsense;
use Juwri\Modules\Content\Models\Blog;
use Juwri\Modules\Content\Models\BlogCategory;
use Juwri\Modules\Content\Models\BlogComment;
use Juwri\Modules\Content\Models\Faq;
use Juwri\Modules\Content\Models\Page;
use Juwri\Modules\Content\Models\Testimonial;
use Juwri\Modules\Fiscal\Models\Tax;
use Juwri\Modules\Marketing\Models\Subscriber;
use Juwri\Modules\Menu\Models\Allergy;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Menu\Models\MenuVariant;
use Juwri\Modules\Ordering\Models\OrderItemExtra;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Tables\Models\WaiterCall;
use Tests\Support\DescribesModelContract;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * WI-09r characterisation of the models rewritten from behaviour: the
 * declared shape (table, key, timestamps, fillable/guarded, casts, sluggable
 * config) and every relation each model declares (type, related model, keys
 * and the SQL it builds) are pinned in a snapshot taken on the old code, plus
 * the behaviour of Option's settings store, Post's delete cascade and save
 * gates, ImageMenu's file cleanup and Allergy's storefront title.
 */
class BaseModelsContractTest extends TestCase
{
    use DescribesModelContract;
    use RefreshDatabase;

    private const SNAPSHOT = __DIR__.'/../../Fixtures/base-models-contract.json';

    /** @var list<class-string<Model>> */
    private const MODELS = [
        Store::class, Transaction::class,
        PlatformSetting::class, Blog::class, WaiterCall::class,
        Testimonial::class, Tax::class, Subscriber::class,
        Notification::class, BlogCategory::class,
        Adsense::class, StoreView::class, Upgrade::class,
        Page::class, PaymentGateway::class, OrderItemExtra::class,
        Language::class, ImageMenu::class, Coupon::class,
        BlogComment::class, Faq::class, Country::class,
        Allergy::class, StoreTable::class, MenuVariant::class,
    ];

    protected function tearDown(): void
    {
        unset($_COOKIE['Quick_user_lang_code']);
        parent::tearDown();
    }

    public function test_model_shapes_and_relations_match_the_snapshot(): void
    {
        $now = [];
        foreach (self::MODELS as $class) {
            $now[$class] = $this->describe($class);
        }
        if (getenv('WRITE_MODEL_SNAPSHOT')) {
            @mkdir(dirname(self::SNAPSHOT), 0777, true);
            file_put_contents(self::SNAPSHOT, json_encode($now, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
        $this->assertFileExists(self::SNAPSHOT);
        $this->assertSame(json_decode((string) file_get_contents(self::SNAPSHOT), true), json_decode(json_encode($now), true));
    }

    public function test_option_store_reads_writes_and_encrypts_secrets(): void
    {
        $this->assertFalse(PlatformSettings::get('wi09r_missing', false));

        Cache::put(PlatformSettings::CACHE_KEY, ['stale' => true]);
        $this->assertTrue(PlatformSettings::set('wi09r_plain', 'one'));
        $this->assertFalse(Cache::has(PlatformSettings::CACHE_KEY), 'a write busts the settings cache');
        $this->assertSame('one', PlatformSettings::get('wi09r_plain', false));
        PlatformSettings::set('wi09r_plain', 'two');
        $this->assertSame(1, PlatformSetting::where('name', 'wi09r_plain')->count(), 'one row per key');
        $this->assertSame('two', PlatformSettings::get('wi09r_plain', false));

        PlatformSettings::set('wi09r_list', ['a' => 1, 'b' => [2]]);
        $this->assertSame(['a' => 1, 'b' => [2]], json_decode((string) PlatformSettings::stored('wi09r_list'), true));
        $this->assertSame(['a' => 1, 'b' => [2]], PlatformSettings::get('wi09r_list'));

        $secret = PlatformSecrets::KEYS[0];
        $this->assertTrue(PlatformSettings::set($secret, 'sk_test_123'));
        $raw = PlatformSettings::stored($secret);
        $this->assertNotSame('sk_test_123', $raw, 'secrets are encrypted at rest');
        $this->assertSame('sk_test_123', PlatformSettings::get($secret, false));
        $this->assertTrue(PlatformSettings::set($secret, SecretMask::mask('sk_test_123')));
        $this->assertSame($raw, PlatformSettings::stored($secret), 'the echoed mask keeps the stored secret');
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 5], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1]);

        return Store::create([
            'user_id' => $owner->id, 'title' => 'Blue Cafe', 'color' => '#111', 'sub_title' => 'T',
            'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '555',
        ]);
    }

    public function test_post_save_gates_default_branch_and_delete_cascade(): void
    {
        $store = $this->store();
        $this->assertSame('blue-cafe', $store->slug);
        $this->assertSame(1, Branch::where('store_id', $store->id)->count(), 'a new store gets its Main branch');
        $this->assertSame('Blue Cafe — Main', Branch::where('store_id', $store->id)->value('name'));

        $store->head_code = '<script>x</script>';
        $store->save();
        $this->assertNull($store->fresh()->head_code, 'custom code is dropped unless enabled');

        $reserved = Store::create([
            'user_id' => $store->user_id, 'title' => 'AR', 'color' => '#111', 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
        $this->assertSame('ar-menu', $reserved->slug);

        $top = MenuCategory::create(['store_id' => $store->id, 'name' => 'Top']);
        $sub = MenuCategory::create(['store_id' => $store->id, 'name' => 'Sub', 'parent' => $top->id]);
        $item = Menu::create(['store_id' => $store->id, 'category_id' => $sub->id, 'name' => 'Tea', 'price' => 1, 'active' => 1]);
        $img = ImageMenu::create(['store_id' => $store->id, 'name' => 'Card', 'image' => 'default.png', 'position' => 0, 'active' => 1]);
        $keep = MenuCategory::create(['store_id' => $reserved->id, 'name' => 'Other']);

        $store->delete();
        $this->assertNull(MenuCategory::find($top->id));
        $this->assertNull(MenuCategory::find($sub->id), 'subcategories go too');
        $this->assertNull(Menu::find($item->id));
        $this->assertNull(ImageMenu::find($img->id));
        $this->assertNotNull(MenuCategory::find($keep->id));
    }

    public function test_post_delete_removes_uploaded_images_but_never_the_default(): void
    {
        $store = $this->store();
        $dirs = ['cover' => public_path('storage/store/cover'), 'logo' => public_path('storage/store/logo')];
        foreach ($dirs as $dir) {
            @mkdir($dir, 0777, true);
        }
        $names = [];
        foreach (['cover_image' => 'cover', 'main_image' => 'logo', 'bio_cover_image' => 'cover', 'bio_main_image' => 'logo'] as $col => $dir) {
            $names[$col] = 'wi09r-'.Str::random(8).'.png';
            file_put_contents($dirs[$dir].'/'.$names[$col], 'x');
            $store->{$col} = $names[$col];
        }
        $store->save();
        $default = $dirs['cover'].'/default.png';
        $hadDefault = file_exists($default);

        $store->delete();
        foreach (['cover_image' => 'cover', 'main_image' => 'logo', 'bio_cover_image' => 'cover', 'bio_main_image' => 'logo'] as $col => $dir) {
            $this->assertFileDoesNotExist($dirs[$dir].'/'.$names[$col], $col);
        }
        $this->assertSame($hadDefault, file_exists($default));
    }

    public function test_image_menu_delete_removes_its_image_and_variant(): void
    {
        $store = $this->store();
        $dir = public_path('storage/menu');
        @mkdir($dir, 0777, true);
        $name = 'wi09r-'.Str::random(8);
        file_put_contents("$dir/$name.jpg", 'x');
        file_put_contents("$dir/$name-w900.webp", 'x');
        $img = ImageMenu::create(['store_id' => $store->id, 'name' => 'Card', 'image' => "$name.jpg", 'position' => 0, 'active' => 1]);

        $img->delete();
        $this->assertFileDoesNotExist("$dir/$name.jpg");
        $this->assertFileDoesNotExist("$dir/$name-w900.webp");
    }

    public function test_allergy_title_follows_the_storefront_language(): void
    {
        $a = Allergy::create(['title' => 'Nuts', 'translations' => ['ar' => ['title' => 'مكسرات'], 'fr' => ['title' => '']], 'position' => 0, 'active' => '1']);
        $a = $a->fresh();
        $this->assertSame('Nuts', $a->title);
        $_COOKIE['Quick_user_lang_code'] = 'ar';
        $this->assertSame('مكسرات', $a->title);
        $_COOKIE['Quick_user_lang_code'] = 'fr';
        $this->assertSame('Nuts', $a->title, 'an empty translation falls back');
        $_COOKIE['Quick_user_lang_code'] = 'de';
        $this->assertSame('Nuts', $a->title);
        $this->assertSame('Nuts', $a->getRawOriginal('title'));
    }
}
