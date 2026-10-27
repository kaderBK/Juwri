<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** Platform-wide orders page for superadmin. */
class AdminOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png', 'currency_sign' => '$', 'currency_pos' => 1, 'site_admin_logo' => 'logo.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);
        view()->share([
            'admin_languages' => collect(),
            'notifications' => collect(),
            'totalUnreadNotifications' => 0,
            'totalUnapprovedComments' => 0,
        ]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    private function makeAdmin(): User
    {
        return User::factory()->create(['group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin']);
    }

    private function makeRestaurant(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T',
            'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);

        return Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234']);
    }

    /** Found by the P1-12 Arabic journey: the Type column printed the raw mode ("ship", "pickup_slot") for non-food stores. */
    public function test_the_type_column_names_vertical_fulfillment_modes_in_the_current_language(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeRestaurant();
        foreach (['ship', 'pickup_slot', 'appointment', 'in_store', 'takeaway'] as $type) {
            Order::forceCreate(['store_id' => $store->id, 'status' => 'pending', 'source' => 'qr', 'type' => $type, 'customer_name' => 'Type '.$type, 'created_at' => now()]);
        }

        $html = $this->actingAs($admin)->get(route('admin.orders.index'))->assertOk()->getContent();

        foreach (['<td data-label="Type">ship</td>', '<td data-label="Type">pickup_slot</td>', '<td data-label="Type">appointment</td>', '<td data-label="Type">in_store</td>'] as $raw) {
            $this->assertStringNotContainsString($raw, $html, 'raw mode value printed');
        }
        foreach (['Ship to address', 'Pickup', 'Appointment', 'In store'] as $label) {
            $this->assertStringContainsString('<td data-label="Type">'.$label.'</td>', $html);
        }

        app()->setLocale('ar');
        $ar = $this->actingAs($admin)->get(route('admin.orders.index'))->assertOk()->getContent();
        $this->assertStringContainsString('عنوان الشحن', $ar);
        $this->assertStringNotContainsString('>ship<', $ar);
    }

    /** Found by the P1-12 Arabic journey: the Source column printed the raw enum STAFF_PWA. */
    public function test_the_source_column_uses_the_translated_channel_label(): void
    {
        $admin = $this->makeAdmin();
        $store = $this->makeRestaurant();
        Order::forceCreate(['store_id' => $store->id, 'status' => 'pending', 'source' => 'staff_pwa', 'type' => 'takeaway', 'customer_name' => 'Staff order', 'created_at' => now()]);

        $en = $this->actingAs($admin)->get(route('admin.orders.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('STAFF_PWA', $en);

        app()->setLocale('ar');
        $ar = $this->actingAs($admin)->get(route('admin.orders.index'))->assertOk()->getContent();
        $this->assertStringNotContainsString('STAFF_PWA', $ar);
        $this->assertStringContainsString('تطبيق الموظفين', $ar);
    }

    public function test_admin_sees_orders_with_source_filter(): void
    {
        $admin = $this->makeAdmin();
        $qrRestaurant = $this->makeRestaurant();
        $posRestaurant = $this->makeRestaurant();

        Order::forceCreate(['store_id' => $qrRestaurant->id, 'status' => 'pending', 'source' => 'qr', 'type' => 'takeaway', 'customer_name' => 'QR Guy', 'created_at' => now()]);
        Order::forceCreate(['store_id' => $posRestaurant->id, 'status' => 'completed', 'source' => 'pos', 'type' => 'on-table', 'customer_name' => 'POS Guy', 'created_at' => now()]);

        $this->actingAs($admin)->get(route('admin.orders.index'))
            ->assertStatus(200)
            ->assertSee('Orders today')
            ->assertSee($qrRestaurant->title)
            ->assertSee($posRestaurant->title);

        $this->actingAs($admin)->get(route('admin.orders.index', ['source' => 'pos']))
            ->assertStatus(200)
            ->assertSee($posRestaurant->title)
            ->assertDontSee($qrRestaurant->title);
    }

    public function test_non_admin_redirected_from_orders(): void
    {
        $store = $this->makeRestaurant();
        $this->actingAs($store->user)->get(route('admin.orders.index'))->assertRedirect();
    }
}
