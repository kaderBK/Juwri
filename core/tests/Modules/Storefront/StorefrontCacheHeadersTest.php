<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\StoreOption;
use App\Support\Csp\StorefrontPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\PlatformSettings;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * C4: a signed-in diner's storefront page must never be marked publicly
 * cacheable (the "is anonymous?" gate asked the default `web` guard, not the
 * `customer` guard). Anonymous pages keep the P5 ETag/304 but now carry
 * Vary: Cookie, and a superadmin switch can turn public caching off.
 */
class StorefrontCacheHeadersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // the public-cache machinery under report-only (enforced CSP makes it `private`: StorefrontWatiWidgetTest)
        config(['csp.enforce' => false, 'csp.storefront_enforce' => 'off', 'csp.policy' => '', 'csp.report_only_policy' => StorefrontPolicy::class]);
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
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

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
            EnsureStaffDashboardPermission::class,
        ]);
    }

    /** @return array{0: Store, 1: Customer} */
    private function storefront(string $template = 'crave'): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100, 'lifetime_price' => 1000,
            'settings' => ['restaurant_limit' => 5, 'scan_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => 1],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R'.Str::random(6);
        $store = Store::create(['user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::random(5), 'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1234', 'created_at' => now(), 'updated_at' => now()]);
        StoreOption::updateStoreOption($store->id, 'store_template', $template);
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains']);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Burger', 'price' => 12.00, 'active' => 1]);
        $customer = Customer::create(['name' => 'AdaSignedIn', 'store_id' => $store->id, 'phone' => UniquePhone::next('55-')]);

        return [$store, $customer];
    }

    /**
     * Sign a diner in on the `customer` guard WITHOUT `actingAs()`, which would
     * call shouldUse('customer') and flip the DEFAULT guard — masking this exact
     * bug (in production a signed-in diner leaves the default `web` guard, so the
     * old `auth()->check()` gate saw them as anonymous).
     */
    private function signInCustomer(Customer $customer): void
    {
        $this->app['auth']->guard('customer')->setUser($customer);
    }

    /** Array-valued query params must not 500 the anonymous storefront. */
    public function test_array_query_params_do_not_crash_the_storefront(): void
    {
        [$store] = $this->storefront();
        $slug = $store->slug;

        // ?qr-id[]=x → base64_decode(array) TypeError before the fix (unauth DoS).
        $this->get(route('publicView', $slug).'?qr-id[]=x')->assertOk();

        // A valid scan token + ?src[]=x → substr(array) TypeError on the scan-record path.
        // A recorded scan redirects to the clean URL (302), so assert only "no 500".
        $token = urlencode(app_xor_encrypt($slug, 'qr-scan'));
        $res = $this->get(route('publicView', $slug)."?qr-id={$token}&src[]=x");
        $this->assertLessThan(500, $res->status(), "array src[] must not 500 the scan path; got {$res->status()}");
    }

    public function test_signed_in_customer_page_is_never_publicly_cacheable(): void
    {
        [$store, $customer] = $this->storefront();
        $this->signInCustomer($customer);

        $res = $this->get(route('publicView', $store->slug));

        $res->assertOk();
        $this->assertStringNotContainsString('public', (string) $res->headers->get('Cache-Control'));
        $this->assertNull($res->headers->get('ETag'));
    }

    public function test_signed_in_customer_never_gets_a_304(): void
    {
        [$store, $customer] = $this->storefront();

        $etag = $this->get(route('publicView', $store->slug))->headers->get('ETag');
        $this->assertNotNull($etag, 'the anonymous page must carry an ETag');

        $this->signInCustomer($customer);
        $res = $this->get(route('publicView', $store->slug), ['If-None-Match' => $etag]);

        $res->assertOk(); // a 200, never a 304
        $res->assertSeeHtml($customer->name);
    }

    public function test_anonymous_page_still_caches_and_304s_and_varies_on_cookie(): void
    {
        [$store] = $this->storefront();

        $res = $this->get(route('publicView', $store->slug));
        $res->assertOk();
        $this->assertStringContainsString('public', (string) $res->headers->get('Cache-Control'));
        $etag = $res->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertStringContainsString('Cookie', (string) $res->headers->get('Vary'));

        $this->get(route('publicView', $store->slug), ['If-None-Match' => $etag])->assertStatus(304);
    }

    public function test_superadmin_switch_turns_public_caching_off(): void
    {
        [$store] = $this->storefront();

        PlatformSettings::set('storefront_public_cache', '0');
        config(['settings.storefront_public_cache' => '0']);
        $off = $this->get(route('publicView', $store->slug));
        $off->assertOk();
        $this->assertNull($off->headers->get('ETag'));
        $this->assertStringNotContainsString('public', (string) $off->headers->get('Cache-Control'));

        PlatformSettings::set('storefront_public_cache', '1');
        config(['settings.storefront_public_cache' => '1']);
        $on = $this->get(route('publicView', $store->slug));
        $this->assertNotNull($on->headers->get('ETag'), 'flipping the switch back restores the ETag');
    }

    /**
     * A conditional GET answered 304 must not carry a fresh CSP: the browser merges the 304's headers onto the page
     * it already holds, whose inline blocks carry the OLD nonce, so a new nonce in the policy refuses every one.
     */
    public function test_a_304_carries_no_csp_so_the_cached_body_keeps_its_matching_policy(): void
    {
        [$store] = $this->storefront();

        $first = $this->get('/'.$store->slug)->assertOk();
        $etag = $first->headers->get('ETag');
        $this->assertNotEmpty($etag, 'the anonymous storefront must send an ETag for this test to mean anything');
        $policy = (string) ($first->headers->get('Content-Security-Policy-Report-Only') ?: $first->headers->get('Content-Security-Policy'));
        $this->assertNotSame('', $policy, 'the 200 carries the nonce policy');
        preg_match('/nonce-([A-Za-z0-9+\/=]+)/', $policy, $m);
        $this->assertStringContainsString('nonce="'.$m[1].'"', $first->getContent(), 'the 200 body and its policy share one nonce');

        $second = $this->get('/'.$store->slug, ['If-None-Match' => $etag])->assertStatus(304);
        $this->assertNull($second->headers->get('Content-Security-Policy'));
        $this->assertNull($second->headers->get('Content-Security-Policy-Report-Only'));
    }
}
