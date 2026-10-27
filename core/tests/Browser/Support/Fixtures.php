<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Browser\Support;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;

/** Records the browser tests build in-process (the Playwright browser talks to this same PHP process). */
final class Fixtures
{
    public const PASSWORD = 'Browser-Test-1234';

    /** Why browser tests cannot run here (null = they can): Playwright's npm package and a Chromium build are needed. */
    public static function unavailable(): ?string
    {
        if (! is_file(base_path('node_modules/.bin/playwright'))) {
            return 'Playwright is not installed (npm ci in core/).';
        }
        $home = getenv('PLAYWRIGHT_BROWSERS_PATH') ?: ((getenv('HOME') ?: '').'/.cache/ms-playwright');
        if (glob($home.'/chromium*') === [] || glob($home.'/chromium*') === false) {
            return 'Playwright Chromium is not installed (npx playwright install chromium).';
        }

        return null;
    }

    public static function owner(array $attributes = [], array $planSettings = []): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => array_merge(['restaurant_limit' => 999, 'allow_ordering' => '1', 'menu_limit' => 999, 'scan_limit' => 999], $planSettings),
        ]);

        return User::factory()->create(array_merge([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'password' => bcrypt(self::PASSWORD),
        ], $attributes));
    }

    public static function store(User $owner, string $title = 'Browser Bistro', string $template = 'crave'): Store
    {
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'bb-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => $title, 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
        // Default logo images the themes request; the in-process test server has no static files, so a missing one is a 404 exception.
        foreach (['storage/store/logo/default.png', 'storage/logo/classic-theme_logo.png'] as $asset) {
            if (! is_file(public_path($asset))) {
                File::ensureDirectoryExists(dirname(public_path($asset)));
                file_put_contents(public_path($asset), base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), LOCK_EX);
            }
        }
        foreach (['store_template' => $template, 'restaurant_takeaway_order' => '1', 'restaurant_online_payment' => '1'] as $key => $value) {
            StoreOption::updateStoreOption($store->id, $key, $value);
        }

        return $store;
    }

    /** Test-only stub gateway: armed in the testing env, with its catalogue row (the runtime guard of PaymentMethods\\StubController). */
    public static function armStubGateway(): void
    {
        // The payment page shows the gateway's logo; the in-process test server has no static files, so a missing one is a 404 exception.
        $logo = public_path('storage/payment_gateways/stub.png');
        if (! is_file($logo)) {
            File::ensureDirectoryExists(dirname($logo));
            file_put_contents($logo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), LOCK_EX);
        }
        config(['app.e2e_stubs' => true, 'settings.admin_allow_online_payment' => 1]);
        DB::table('payment_gateways')->updateOrInsert(
            ['payment_folder' => 'stub'],
            ['payment_install' => '1', 'payment_title' => 'E2E Stub Gateway']
        );
    }

    public static function item(Store $store, string $name = 'Browser Burger', float $price = 10.00, ?string $image = null): Menu
    {
        $category = MenuCategory::firstOrCreate(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1']);

        $attributes = [
            'store_id' => $store->id, 'category_id' => $category->id, 'name' => $name,
            'price' => $price, 'active' => '1',
        ];
        if ($image !== null) {
            $attributes['image'] = $image;
        }

        return Menu::create($attributes);
    }

    /**
     * A real image file on the public media path, for menu items.
     *
     * `items.image` defaults to `storage/menu/default.png`, which has no file on disk in a browser-test
     * checkout. Opening the storefront item sheet then requests that missing path; the in-process test
     * server has no static file to answer with, so the request falls through to the router and Laravel
     * throws a NotFoundHttpException, failing the journey. Returning a filename that exists on disk keeps
     * the item's image request servable (see how the theme builds the URL: media_url('storage/menu/', …)).
     */
    public static function menuImage(string $filename = 'browser-menu-item.png'): string
    {
        $path = public_path('storage/menu/'.$filename);
        if (! is_file($path)) {
            File::ensureDirectoryExists(dirname($path));
            // 1×1 transparent PNG. LOCK_EX so parallel browser workers cannot interleave writes.
            file_put_contents($path, base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
            ), LOCK_EX);
        }

        return $filename;
    }
}
