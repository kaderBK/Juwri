<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\MenuCategory;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use App\Services\OrderApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pos\Models\PrinterProfile;
use Juwri\Modules\Pos\Models\PrintJob;
use Juwri\Modules\Pos\Services\Printing\BridgeUrl;
use Juwri\Modules\Pos\Services\Printing\PrintSettings;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-298: the print-bridge URL is judged in one place (BridgeUrl), never stored
 * with CSP tokens / metadata addresses, and server-side delivery refuses
 * owner-typed internal targets while trusting the configured bridge and vetted
 * public https hosts.
 */
class PrintBridgeUrlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);

        config(['settings.admin_allow_print_routing' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    protected function tearDown(): void
    {
        SsrfGuard::$resolver = null;

        parent::tearDown();
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P '.Str::random(5), 'description' => 'T',
            'monthly_price' => 1, 'annual_price' => 1, 'lifetime_price' => 1,
            'settings' => ['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1', 'pos_enabled' => '1'],
            'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $title = 'R '.Str::random(5);

        return Store::create([
            'user_id' => $owner->id, 'slug' => Str::slug($title).'-'.Str::lower(Str::random(5)),
            'color' => '#111', 'title' => $title, 'sub_title' => 'T', 'description' => 'T',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function item(Store $r, float $price = 12, ?int $stationId = null): Menu
    {
        $cat = MenuCategory::create(['store_id' => $r->id, 'name' => 'Mains', 'active' => 1]);

        return Menu::create([
            'store_id' => $r->id, 'category_id' => $cat->id, 'name' => 'Dish '.Str::random(4),
            'price' => $price, 'active' => 1, 'station_id' => $stationId,
        ]);
    }

    private function profile(Store $r, array $attrs = []): PrinterProfile
    {
        return PrinterProfile::create(array_merge([
            'store_id' => $r->id, 'name' => 'Front', 'transport' => 'webserial',
            'width_mm' => 80, 'events' => ['new_order'], 'station_ids' => [],
            'auto_print' => true, 'active' => true, 'copies' => 1,
        ], $attrs));
    }

    private function placeOrder(Store $r): void
    {
        OrderApiService::create($r->user_id, $r->id, [
            'type' => 'takeaway', 'items' => [['item_id' => $this->item($r)->id, 'quantity' => 1]],
        ]);
    }

    // ── normalize: rejects ───────────────────────────────────────────────────

    public static function rejectedUrls(): array
    {
        return [
            'metadata ip' => ['http://169.254.169.254'],
            'metadata path' => ['http://169.254.169.254/latest/meta-data'],
            'csp semicolon' => ['https://x;script-src *'],
            'csp inline' => ["http://x.test; script-src 'unsafe-inline'"],
            'ftp scheme' => ['ftp://192.168.1.5'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher scheme' => ['gopher://127.0.0.1:25'],
            'userinfo' => ['http://user:pw@127.0.0.1:8543'],
            'path' => ['http://127.0.0.1:8543/print'],
            'query' => ['http://127.0.0.1:8543?x=1'],
            'unspecified' => ['http://0.0.0.0:8543'],
            'link-local ipv6' => ['http://[fe80::1]:8543'],
            'multicast' => ['http://224.0.0.1'],
            'decimal ip' => ['http://2852039166'],
            'octal ip' => ['http://0251.0376.0251.0376'],
            'javascript scheme' => ['javascript:alert(1)'],
            'wildcard host' => ['http://*'],
            'port out of range' => ['http://127.0.0.1:99999'],
            'empty' => [''],
        ];
    }

    #[DataProvider('rejectedUrls')]
    public function test_normalize_rejects_unsafe_bridge_origins(string $url): void
    {
        $this->assertNull(BridgeUrl::normalize($url));
    }

    // ── normalize: accepts and canonicalises ─────────────────────────────────

    public static function acceptedUrls(): array
    {
        return [
            'loopback' => ['http://127.0.0.1:8543', 'http://127.0.0.1:8543'],
            'uppercase trailing slash' => ['HTTP://127.0.0.1:8543/', 'http://127.0.0.1:8543'],
            'lan' => ['http://192.168.1.50:9100', 'http://192.168.1.50:9100'],
            'private' => ['http://10.0.0.9:8543', 'http://10.0.0.9:8543'],
            'hostname case' => ['https://Bridge.Example/', 'https://bridge.example'],
            'ipv6 loopback' => ['http://[::1]:8543', 'http://[::1]:8543'],
            'mdns host' => ['http://till.local:8543', 'http://till.local:8543'],
        ];
    }

    #[DataProvider('acceptedUrls')]
    public function test_normalize_accepts_and_canonicalises_bridge_origins(string $url, string $expected): void
    {
        $this->assertSame($expected, BridgeUrl::normalize($url));
    }

    // ── owner CRUD validation ────────────────────────────────────────────────

    public static function unsafeBridgeUrls(): array
    {
        return [
            'metadata' => ['http://169.254.169.254'],
            'csp token' => ['https://x;script-src *'],
            'ftp' => ['ftp://x'],
        ];
    }

    #[DataProvider('unsafeBridgeUrls')]
    public function test_owner_cannot_store_an_unsafe_bridge_url(string $url): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        $this->actingAs($owner)
            ->postJson(route('stores.printing.store', ['store_id' => $r->id]), [
                'name' => 'Bad bridge', 'transport' => 'bridge', 'bridge_url' => $url,
                'width_mm' => 80, 'copies' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bridge_url');

        $this->assertSame(0, PrinterProfile::count());
    }

    #[DataProvider('unsafeBridgeUrls')]
    public function test_owner_cannot_update_a_profile_to_an_unsafe_bridge_url(string $url): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);
        $profile = $this->profile($r, [
            'transport' => 'bridge',
            'target' => 'tcp://192.168.1.50:9100',
            'bridge_url' => PrinterProfile::DEFAULT_BRIDGE_URL,
        ]);

        $this->actingAs($owner)
            ->postJson(route('stores.printing.update', ['id' => $profile->id, 'store_id' => $r->id]), [
                'name' => 'Bad bridge', 'transport' => 'bridge', 'bridge_url' => $url,
                'width_mm' => 80, 'copies' => 1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('bridge_url');

        $this->assertSame(PrinterProfile::DEFAULT_BRIDGE_URL, $profile->fresh()->bridge_url);
    }

    public function test_owner_bridge_url_is_canonicalised_on_store(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        $this->actingAs($owner)
            ->postJson(route('stores.printing.store', ['store_id' => $r->id]), [
                'name' => 'LAN bridge', 'transport' => 'bridge',
                'bridge_url' => 'http://192.168.1.50:9100/',
                'width_mm' => 80, 'copies' => 1,
            ])
            ->assertRedirect();

        $profile = PrinterProfile::where('store_id', $r->id)->firstOrFail();
        $this->assertSame('http://192.168.1.50:9100', $profile->bridge_url);
    }

    public function test_owner_empty_bridge_url_stores_the_loopback_default(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        $this->actingAs($owner)
            ->postJson(route('stores.printing.store', ['store_id' => $r->id]), [
                'name' => 'Default bridge', 'transport' => 'bridge', 'bridge_url' => '',
                'width_mm' => 80, 'copies' => 1,
            ])
            ->assertRedirect();

        $profile = PrinterProfile::where('store_id', $r->id)->firstOrFail();
        $this->assertSame(PrinterProfile::DEFAULT_BRIDGE_URL, $profile->bridge_url);
    }

    // ── server-side delivery ─────────────────────────────────────────────────

    private function enableServerBridge(string $bridgeUrl = PrinterProfile::DEFAULT_BRIDGE_URL): void
    {
        config(['printing.server_bridge' => true, 'printing.bridge_url' => $bridgeUrl]);
        Http::fake(['*' => Http::response(['ok' => true, 'jobId' => 'x'])]);
    }

    private function bridgeProfile(Store $r, string $bridgeUrl): PrinterProfile
    {
        PrintSettings::rotatePairingToken((int) $r->id);

        return $this->profile($r, [
            'transport' => 'bridge',
            'target' => 'tcp://192.168.1.50:9100',
            'bridge_url' => $bridgeUrl,
        ]);
    }

    public function test_server_delivery_refuses_an_owner_typed_private_bridge(): void
    {
        $this->enableServerBridge();
        $r = $this->storeFixture();
        $this->bridgeProfile($r, 'http://10.0.0.5:8543');

        $this->placeOrder($r);

        $this->assertSame('failed', PrintJob::first()->status);
        Http::assertNothingSent();
    }

    public function test_server_delivery_falls_back_to_the_default_bridge_for_a_metadata_address(): void
    {
        $this->enableServerBridge();
        $r = $this->storeFixture();
        $this->bridgeProfile($r, 'http://169.254.169.254');

        $this->placeOrder($r);

        $this->assertSame('done', PrintJob::first()->status);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), PrinterProfile::DEFAULT_BRIDGE_URL));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254'));
    }

    public function test_server_delivery_uses_the_default_loopback_bridge(): void
    {
        $this->enableServerBridge();
        $r = $this->storeFixture();
        $this->bridgeProfile($r, PrinterProfile::DEFAULT_BRIDGE_URL);

        $this->placeOrder($r);

        $this->assertSame('done', PrintJob::first()->status);
    }

    public function test_server_delivery_allows_a_public_https_bridge(): void
    {
        $this->enableServerBridge();
        SsrfGuard::$resolver = fn (string $host) => ['93.184.216.34'];

        $r = $this->storeFixture();
        $this->bridgeProfile($r, 'https://bridge.example');

        $this->placeOrder($r);

        $this->assertSame('done', PrintJob::first()->status);
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://bridge.example'));
    }

    public function test_server_delivery_refuses_a_public_hostname_that_resolves_private(): void
    {
        $this->enableServerBridge();
        SsrfGuard::$resolver = fn (string $host) => ['10.0.0.1'];

        $r = $this->storeFixture();
        $this->bridgeProfile($r, 'https://bridge.example');

        $this->placeOrder($r);

        $this->assertSame('failed', PrintJob::first()->status);
        Http::assertNothingSent();
    }

    // ── CSP / legacy rows ────────────────────────────────────────────────────

    public function test_a_legacy_injected_bridge_url_falls_back_to_the_loopback_default(): void
    {
        $r = $this->storeFixture();
        $profile = $this->profile($r, [
            'transport' => 'bridge',
            'bridge_url' => 'http://x.test; script-src *',
        ]);

        $this->assertSame(PrinterProfile::DEFAULT_BRIDGE_URL, $profile->bridgeBase());
    }
}
