<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\Menu;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoCredentials;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoOwnerPlan;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoWipe;
use Tests\TestCase;

/**
 * J62-1: the owner demo command — local only, idempotent, and it never prints a secret.
 */
class DemoSeedCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $credentialsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->credentialsPath = storage_path('app/demo-credentials-test-'.Str::random(12).'.md');
        config(['demo.credentials_path' => $this->credentialsPath]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->credentialsPath)) {
            unlink($this->credentialsPath);
        }

        parent::tearDown();
    }

    public function test_it_refuses_in_production_and_writes_nothing(): void
    {
        $this->app['env'] = 'production';

        try {
            $exit = Artisan::call('juwri:demo-seed');
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(1, $exit, 'the command refuses in production');
        $this->assertSame(0, Store::count(), 'no store was created');
        $this->assertSame(0, User::count(), 'no login was created');
        $this->assertFileDoesNotExist($this->credentialsPath, 'no credentials file was written');
    }

    public function test_it_refuses_in_an_environment_that_is_not_local(): void
    {
        $this->app['env'] = 'staging';

        try {
            $exit = Artisan::call('juwri:demo-seed');
        } finally {
            $this->app['env'] = 'testing';
        }

        $this->assertSame(1, $exit);
        $this->assertSame(0, Store::count());
        $this->assertFileDoesNotExist($this->credentialsPath);
    }

    public function test_running_it_twice_never_duplicates_a_row(): void
    {
        $this->assertSame(0, Artisan::call('juwri:demo-seed'));
        $first = $this->counts();

        $this->assertSame(0, Artisan::call('juwri:demo-seed'));
        $second = $this->counts();

        $this->assertSame($first, $second, 'a second run changes no count');
        $this->assertGreaterThan(0, $first['stores'], 'the demo seeded a store');
        $this->assertGreaterThan(0, $first['items'], 'the demo seeded a catalogue');
        $this->assertGreaterThan(0, $first['orders'], 'the demo seeded an order');
        $this->assertGreaterThan(0, $first['coupons'], 'the demo seeded a coupon');

        // The demo's own logins land with the accounts seeder; until then there is none of them,
        // but there is never more than one.
        $owner = User::where('email', DemoOwnerPlan::OWNER_EMAIL)->count();
        $this->assertLessThanOrEqual(1, $owner, 'the demo owner login is never duplicated');
        if (class_exists('Juwri\\Modules\\Storefront\\Database\\Seeders\\DemoAccountsSeeder')) {
            $this->assertSame(1, $owner, 'the demo owner login exists once');
        }
    }

    public function test_it_writes_the_credentials_file_and_never_prints_a_secret(): void
    {
        $this->assertSame(0, Artisan::call('juwri:demo-seed'));

        $this->assertFileExists($this->credentialsPath);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->credentialsPath)), -4), 'the file is readable by its owner only');

        $contents = (string) file_get_contents($this->credentialsPath);
        $credentials = app(DemoCredentials::class);

        foreach (DemoOwnerPlan::userEmails() as $email) {
            $this->assertStringContainsString($email, $contents, 'the file names the '.$email.' login');
        }
        $this->assertStringContainsString(DemoOwnerPlan::RESELLER_EMAIL, $contents);

        $secrets = [
            $credentials->password('owner'),
            $credentials->password('admin'),
            $credentials->password('staff'),
            $credentials->password('reseller'),
        ];
        foreach ($secrets as $secret) {
            $this->assertGreaterThanOrEqual(20, strlen($secret), 'a password is at least 20 characters');
            $this->assertStringContainsString(str_replace('|', '\\|', $secret), $contents, 'the file carries the password');
        }
        $this->assertCount(4, array_unique($secrets), 'every password is its own');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $credentials->pin('cashier'), 'the cashier PIN is six digits');

        $output = Artisan::output();
        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString($secret, $output, 'a secret never reaches the console');
        }
        $this->assertStringNotContainsString($credentials->pin('cashier'), $output);
        $this->assertStringContainsString($this->credentialsPath, $output, 'the console names the credentials file');
    }

    public function test_fresh_removes_a_demo_store_and_keeps_a_foreign_one(): void
    {
        $foreign = $this->makeStore('foreign-store', 'foreign-owner@demo.test');
        $demo = $this->makeStore('demo-branches', 'demo-branches-owner@demo.test');
        $this->makeRows((int) $foreign->id);
        $this->makeRows((int) $demo->id);

        $result = app(DemoWipe::class)->run();

        $this->assertContains((int) $demo->id, $result['stores'], 'the demo store was erased');
        $this->assertNull(Store::where('slug', 'demo-branches')->first(), 'the demo store is gone');
        $this->assertSame(0, Menu::where('store_id', $demo->id)->count(), 'its menu rows are gone');
        $this->assertSame(0, MenuCategory::where('store_id', $demo->id)->count());
        $this->assertSame(0, Order::where('store_id', $demo->id)->count(), 'its orders are gone');
        $this->assertSame(0, Customer::where('store_id', $demo->id)->count(), 'its customers are gone');
        $this->assertSame(0, StoreTable::where('store_id', $demo->id)->count(), 'its tables are gone');

        $this->assertNotNull(Store::where('slug', 'foreign-store')->first(), 'the foreign store survives');
        $this->assertSame(1, Menu::where('store_id', $foreign->id)->count(), 'its menu rows survive');
        $this->assertSame(1, Order::where('store_id', $foreign->id)->count());
        $this->assertSame(1, Customer::where('store_id', $foreign->id)->count());
        $this->assertSame(1, StoreTable::where('store_id', $foreign->id)->count());
        $this->assertNotNull(User::where('email', 'foreign-owner@demo.test')->first(), 'the foreign login survives');
    }

    public function test_the_command_runs_with_fresh_and_leaves_a_foreign_store_alone(): void
    {
        $foreign = $this->makeStore('foreign-store', 'foreign-owner@demo.test');
        $this->makeRows((int) $foreign->id);

        $this->assertSame(0, Artisan::call('juwri:demo-seed', ['--fresh' => true]));

        $this->assertNotNull(Store::where('slug', 'foreign-store')->first(), 'the foreign store survives');
        $this->assertSame(1, Menu::where('store_id', $foreign->id)->count(), 'its menu rows survive');
        $this->assertNotNull(User::where('email', 'foreign-owner@demo.test')->first());
    }

    /**
     * The rows the demo must not duplicate.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        return [
            'stores' => Store::count(),
            'users' => User::count(),
            'items' => Menu::count(),
            'categories' => MenuCategory::count(),
            'orders' => Order::count(),
            'customers' => Customer::count(),
            'tables' => StoreTable::count(),
            'coupons' => Coupon::count(),
        ];
    }

    /** A store of its own, with an owner of its own. */
    private function makeStore(string $slug, string $email): Store
    {
        $owner = User::updateOrCreate(
            ['email' => $email],
            [
                'username' => Str::slug($email, '_'),
                'name' => 'Owner of '.$slug,
                'password' => Hash::make('demo-password'),
                'user_type' => 'user',
                'status' => 1,
                'country_code' => 'SA',
                'country' => 'Saudi Arabia',
                'email_verified_at' => now(),
            ]
        );

        return Store::updateOrCreate(
            ['slug' => $slug],
            [
                'user_id' => $owner->id,
                'title' => ucfirst($slug),
                'address' => 'Riyadh',
                'phone' => '966500000000',
                'catalog_visible' => 1,
            ]
        );
    }

    /** One of everything the wipe has to take with a store. */
    private function makeRows(int $storeId): void
    {
        $category = MenuCategory::updateOrCreate(
            ['store_id' => $storeId, 'name' => 'Demo category'],
            ['position' => 1, 'active' => '1']
        );

        Menu::updateOrCreate(
            ['store_id' => $storeId, 'name' => 'Demo item'],
            ['category_id' => $category->id, 'price' => 10, 'active' => '1', 'position' => 1]
        );

        Customer::updateOrCreate(
            ['store_id' => $storeId, 'phone' => '96650000'.substr((string) $storeId, -4)],
            ['name' => 'Demo customer']
        );

        StoreTable::updateOrCreate(
            ['store_id' => $storeId, 'table_no' => 1],
            ['label' => 'T1', 'seats' => 2, 'status' => 'free']
        );

        Order::updateOrCreate(
            ['store_id' => $storeId, 'track_token' => 'demo-store-'.$storeId],
            ['type' => 'takeaway', 'status' => 'new', 'customer_name' => 'Demo customer']
        );
    }
}
