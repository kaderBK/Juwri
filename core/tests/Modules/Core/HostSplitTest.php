<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Core;

use App\Http\Controllers\User\StoreController;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Http\Hosts\HostMap;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Models\StoreDomain;
use Juwri\Modules\Storefront\Services\StoreAddressResolver;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P1-20 slice 3 (J28): host-separated origins behind HOST_MODE=split — per-origin host-only cookies, the
 * admin session never reaching app/storefront hosts, sessions bound to their origin, superadmin refused on
 * `app.`, storefront/marketing hosts never serving dashboard routes, single mode unchanged.
 */
class HostSplitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        config(['session.driver' => 'file']); // the array driver never sends a cookie
        $this->app->instance(StoreController::class, new class extends StoreController
        {
            public function publicView($slug)
            {
                return response('STORE:'.$slug);
            }
        });
    }

    private function split(): void
    {
        config([
            'hosts.mode' => 'split',
            'hosts.app_url' => 'https://app.juwri.test',
            'hosts.admin_url' => 'https://admin.juwri.test',
            'hosts.storefront_url' => 'https://juwri-store.test',
            'hosts.marketing_url' => 'https://juwri.test',
        ]);
    }

    /** A new request in the same test application: no cached guard user, no in-memory session attributes. */
    private function fresh(): void
    {
        Auth::forgetGuards();
        app('session')->forgetDrivers();
    }

    private function user(array $attrs = []): User
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(6), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]), 'status' => 1,
        ]);

        return User::factory()->create(array_merge([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'u'.Str::lower(Str::random(6)), 'password' => Hash::make('secret123'),
        ], $attrs));
    }

    private function sessionCookie(TestResponse $res, string $name): ?Cookie
    {
        foreach ($res->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $name) {
                return $cookie;
            }
        }

        return null;
    }

    public function test_single_mode_is_the_default_and_keeps_the_one_session_cookie(): void
    {
        $this->assertSame('single', config('hosts.mode'));
        $this->assertFalse(HostMap::split());
        $this->assertNotEmpty(HostMap::warnings(), 'single-host installs get the admin health warning');

        $res = $this->get('https://localhost/login')->assertOk();
        $this->assertNotNull($this->sessionCookie($res, (string) config('session.cookie')));
    }

    public function test_each_origin_gets_its_own_host_only_secure_cookie(): void
    {
        $this->split();
        $this->assertSame([], HostMap::warnings());

        foreach (['https://admin.juwri.test/login' => '__Host-site_admin', 'https://app.juwri.test/login' => '__Host-site_app', 'https://juwri.test/login' => '__Host-site_marketing'] as $url => $name) {
            $this->fresh();
            $res = $this->get($url);
            $cookie = $this->sessionCookie($res, $name);
            $this->assertNotNull($cookie, "{$url} sets {$name}");
            $this->assertNull($cookie->getDomain(), 'host-only: never shared with sibling hosts');
            $this->assertTrue($cookie->isSecure());
            $this->assertSame('/', $cookie->getPath());
            foreach (['__Host-site_admin', '__Host-site_app', '__Host-site_store', '__Host-site_marketing'] as $other) {
                if ($other !== $name) {
                    $this->assertNull($this->sessionCookie($res, $other), "{$url} never sets {$other}");
                }
            }
        }
    }

    public function test_admin_session_is_not_accepted_on_the_app_or_storefront_host(): void
    {
        $this->split();
        $admin = $this->user(['user_type' => 'admin']);

        $login = $this->post('https://admin.juwri.test/login', ['username' => $admin->username, 'password' => 'secret123']);
        $login->assertRedirect();
        $cookie = $this->sessionCookie($login, '__Host-site_admin');
        $this->assertNotNull($cookie);
        $this->assertAuthenticatedAs($admin);

        // The admin cookie presented to the app host: a different cookie name there → no session.
        $this->fresh();
        $this->call('GET', 'https://app.juwri.test/dashboard', [], ['__Host-site_admin' => $cookie->getValue()])
            ->assertRedirect();
        $this->assertGuest();

        // The admin session id replayed under the app cookie name: bound to the admin origin → invalidated.
        $this->fresh();
        $this->call('GET', 'https://app.juwri.test/dashboard', [], ['__Host-site_app' => $cookie->getValue()])
            ->assertRedirect();
        $this->assertGuest();

        // …and under the storefront cookie name on the storefront host.
        $this->fresh();
        $this->call('GET', 'https://juwri-store.test/dashboard', [], ['__Host-site_store' => $cookie->getValue()])
            ->assertNotFound();
        $this->assertGuest();
    }

    public function test_app_host_refuses_superadmin_logins_and_admin_host_refuses_everyone_else(): void
    {
        $this->split();
        $admin = $this->user(['user_type' => 'admin']);
        $owner = $this->user(['user_type' => 'user']);

        $this->post('https://app.juwri.test/login', ['username' => $admin->username, 'password' => 'secret123'])
            ->assertRedirect('https://admin.juwri.test/login');
        $this->assertGuest();

        $this->fresh();
        $this->post('https://admin.juwri.test/login', ['username' => $owner->username, 'password' => 'secret123'])
            ->assertRedirect('https://app.juwri.test/login');
        $this->assertGuest();

        $this->fresh();
        $this->get('https://app.juwri.test/'.ltrim(admin_url(), '/').'/dashboard')->assertNotFound();
        $this->fresh();
        $this->get('https://admin.juwri.test/signup')->assertNotFound();
    }

    public function test_storefront_and_store_hosts_never_serve_dashboard_routes(): void
    {
        $this->split();
        $owner = $this->user(['user_type' => 'user']);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'split-deli', 'color' => '#111', 'title' => 'R', 'sub_title' => 'x',
            'description' => 'x', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1', 'phone' => '1',
        ]);
        StoreDomain::create(['store_id' => $store->id, 'type' => 'custom', 'host' => 'menu.split.test', 'verified_at' => now(), 'verification_token' => 't']);

        foreach (['https://juwri-store.test', 'https://menu.split.test'] as $base) {
            foreach (['/login', '/dashboard', '/signup', '/'.ltrim(admin_url(), '/').'/dashboard'] as $path) {
                $this->fresh();
                $this->assertSame(404, $this->get($base.$path)->getStatusCode(), $base.$path);
            }
        }
        $this->fresh();
        $this->get('https://menu.split.test/')->assertOk()->assertSee('STORE:split-deli', false);

        // The path form lives on the storefront host; elsewhere it 301s there (anonymous visitors).
        $this->fresh();
        $this->get('https://juwri.test/split-deli')->assertStatus(301)->assertHeader('Location', 'https://menu.split.test');
        StoreDomain::query()->update(['is_primary' => false]);
        app(StoreAddressResolver::class)->flush();
        $this->fresh();
        $this->get('https://app.juwri.test/split-deli')->assertStatus(301)->assertHeader('Location', 'https://juwri-store.test/split-deli');
        $this->fresh();
        $this->get('https://juwri-store.test/split-deli')->assertOk();
    }

    public function test_marketing_host_sends_dashboard_links_to_the_app_host(): void
    {
        $this->split();
        $this->get('https://juwri.test/dashboard?x=1')->assertRedirect('https://app.juwri.test/dashboard?x=1');
        $this->fresh();
        $this->post('https://juwri.test/dashboard/layout')->assertNotFound();
    }

    public function test_admin_host_ip_allowlist(): void
    {
        $this->split();
        config(['hosts.admin.ip_allowlist' => ['10.0.0.0/8']]);
        $this->get('https://admin.juwri.test/login')->assertForbidden();
        $this->fresh();
        $this->withServerVariables(['REMOTE_ADDR' => '10.1.2.3'])->get('https://admin.juwri.test/login')->assertStatus(200);
    }
}
