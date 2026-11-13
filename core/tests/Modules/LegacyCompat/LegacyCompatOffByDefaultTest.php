<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\LegacyCompat;

use App\Http\Middleware\EnsureStaffDashboardPermission;
use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Hotel\RoomCharge;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\LegacyCompat\Http\Controllers\LegacyRedirectController;
use Juwri\Modules\LegacyCompat\Http\Middleware\LegacyStorefrontEntry;
use Juwri\Modules\LegacyCompat\Providers\LegacyCompatServiceProvider;
use Juwri\Modules\LegacyCompat\Support\LegacyCompatRoutes;
use Juwri\Modules\LegacyCompat\Support\LegacyRoomCharge;
use Juwri\Modules\LegacyCompat\Support\LegacyRoomTokenKeys;
use Juwri\Modules\LegacyCompat\Support\QrIdDecoder;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * J52b: the LegacyCompat module is OFF by default and then registers nothing, so a fresh install keeps J52a
 * (no aliases). Turning the switch on registers the module's route file (stub handlers for now).
 */
class LegacyCompatOffByDefaultTest extends TestCase
{
    use RefreshDatabase;

    public function test_switch_defaults_to_off(): void
    {
        $this->assertFalse(config('legacy_compat.enabled'));
        $this->assertFalse(LegacyCompatRoutes::enabled());

        // The shipped default itself is off, independent of the testing env.
        putenv('LEGACY_COMPAT_ENABLED');
        $this->assertFalse((require config_path('legacy_compat.php'))['enabled']);
    }

    /**
     * The env string must be parsed as a boolean: "false", "0", "" and "off" keep the switch off.
     *
     * @return array<string, array{string, bool}>
     */
    public static function envValues(): array
    {
        return [
            'false' => ['false', false],
            'zero' => ['0', false],
            'empty' => ['', false],
            'off' => ['off', false],
            'true' => ['true', true],
            'one' => ['1', true],
        ];
    }

    #[DataProvider('envValues')]
    public function test_env_value_is_parsed_as_a_boolean(string $value, bool $expected): void
    {
        $name = 'LEGACY_COMPAT_ENABLED';
        $hadEnv = array_key_exists($name, $_ENV);
        $hadServer = array_key_exists($name, $_SERVER);
        $oldEnv = $_ENV[$name] ?? null;
        $oldServer = $_SERVER[$name] ?? null;
        $oldPutenv = getenv($name);

        try {
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name.'='.$value);

            $this->assertSame($expected, (require config_path('legacy_compat.php'))['enabled']);
        } finally {
            if ($hadEnv) {
                $_ENV[$name] = $oldEnv;
            } else {
                unset($_ENV[$name]);
            }
            if ($hadServer) {
                $_SERVER[$name] = $oldServer;
            } else {
                unset($_SERVER[$name]);
            }
            putenv($oldPutenv === false ? $name : $name.'='.$oldPutenv);
        }
    }

    public function test_no_legacy_route_is_registered_when_off(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            $this->assertFalse($this->isLegacyRoute($route), 'legacy route registered while off: '.$route->uri());
        }
    }

    public function test_register_adds_nothing_when_off(): void
    {
        $before = count(Route::getRoutes()->getRoutes());
        LegacyCompatRoutes::register();
        $this->assertSame($before, count(Route::getRoutes()->getRoutes()));
    }

    public function test_legacy_paths_are_not_served_by_the_module_when_off(): void
    {
        foreach (LegacyCompatRoutes::PROBE_PATHS as $path) {
            try {
                $route = Route::getRoutes()->match(Request::create($path, 'GET'));
                $this->assertFalse($this->isLegacyRoute($route), $path.' resolved to a legacy route while off');
            } catch (HttpException) {
                // No route at all: fine (404/405).
            }
        }
    }

    public function test_register_adds_the_legacy_routes_when_on(): void
    {
        config(['legacy_compat.enabled' => true]);
        LegacyCompatRoutes::register();
        Route::getRoutes()->refreshNameLookups();

        $route = Route::getRoutes()->getByName(LegacyCompatRoutes::NAME_PREFIX.'store_page');
        $this->assertNotNull($route, 'store_page not registered when on');
        $this->assertTrue($this->isLegacyRoute($route));
        $this->assertTrue($route->isFallback, 'a legacy route overlapping core paths must be a fallback route');

        // B2: no stub without a real handler (the wallet JWT stubs used to shadow the working core routes).
        foreach (['wallet.loyalty_jwt', 'wallet.gift_card_jwt'] as $name) {
            $this->assertNull(Route::getRoutes()->getByName(LegacyCompatRoutes::NAME_PREFIX.$name), $name);
        }
    }

    /** B2: with the module ON, every core path sharing a legacy route's shape still resolves to the same core route. */
    public function test_core_paths_keep_their_core_route_when_on(): void
    {
        $before = [];
        foreach (LegacyCompatRoutes::CORE_SHARED_PATHS as $path) {
            $before[$path] = $this->matchedAction($path);
        }

        config(['legacy_compat.enabled' => true]);
        LegacyCompatRoutes::register();
        Route::getRoutes()->refreshNameLookups();

        foreach (LegacyCompatRoutes::CORE_SHARED_PATHS as $path) {
            $after = $this->matchedAction($path);
            $this->assertSame($before[$path], $after, $path.' changed route when the module was switched on');
            $this->assertStringNotContainsString('Modules\\LegacyCompat\\', (string) $after, $path);
        }
    }

    /** S3: the legacy controller answers 404 while the switch is off, even when a (cached) route still points at it. */
    public function test_the_legacy_controller_404s_when_off(): void
    {
        $this->expectException(NotFoundHttpException::class);
        app(LegacyRedirectController::class)->storePage(Request::create('/x/y'), 'any-store');
    }

    public function test_the_store_page_form_301s_to_the_slug_with_the_query_kept_when_on(): void
    {
        config(['legacy_compat.enabled' => true]);
        $store = $this->makeStore();

        $response = app(LegacyRedirectController::class)->storePage(Request::create('/x/y?utm_source=print&item=4'), $store->slug, 'Old Title');
        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame(url('/'.$store->slug).'?utm_source=print&item=4', $response->headers->get('Location'));
        $this->assertStringContainsString('max-age=86400', (string) $response->headers->get('Cache-Control'));

        $byId = app(LegacyRedirectController::class)->storePage(Request::create('/x/y'), (string) $store->id);
        $this->assertSame(url('/'.$store->id), $byId->headers->get('Location'));

        $this->expectException(NotFoundHttpException::class);
        app(LegacyRedirectController::class)->storePage(Request::create('/x/y'), 'no-such-store-'.Str::random(5));
    }

    private function matchedAction(string $path): ?string
    {
        try {
            return Route::getRoutes()->match(Request::create($path, 'GET'))->getActionName();
        } catch (HttpException) {
            return null;
        }
    }

    private function makeStore(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(4), 'description' => 'T', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'settings' => ['scan_limit' => 999, 'table_qr' => '1'], 'status' => 1,
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'store-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'Store',
            'sub_title' => 'T', 'description' => 'T', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
    }

    /** Off: nothing is wired: no storefront middleware, no RoomCharge decorator, no active flag. */
    public function test_nothing_is_wired_when_off(): void
    {
        $this->assertFalse($this->app->bound(LegacyCompatServiceProvider::ACTIVE_FLAG));
        $this->assertNotContains(LegacyStorefrontEntry::class, $this->app->make(Router::class)->getMiddlewareGroups()['web'] ?? []);
        $this->assertNotInstanceOf(LegacyRoomCharge::class, app(RoomCharge::class));
    }

    /**
     * Off: over HTTP no legacy path reaches the module (its stubs answer 501) or renders a page. Until the J52 rename
     * batch removes them, core itself still owns some of these paths (e.g. the customer wallet group answers a 302 to
     * login), so the exact fresh-install status is asserted only where core has no route: 404.
     */
    public function test_legacy_paths_are_not_answered_when_off(): void
    {
        $this->bootStorefront();

        foreach (LegacyCompatRoutes::PROBE_PATHS as $path) {
            $response = $this->get($path);
            $status = $response->getStatusCode();
            $this->assertNotSame(501, $status, $path.' reached a module stub while off');
            $this->assertNotSame(200, $status, $path.' rendered while off');
            if ($status !== 404) {
                $this->assertSame(302, $status, $path.' answered '.$status);
                $this->assertStringEndsWith('/login', (string) $response->headers->get('Location'), $path);
            }
        }
    }

    private function bootStorefront(): void
    {
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->shareLayoutGlobals();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class, EnsureStaffDashboardPermission::class]);
    }

    /** Off: an old printed qr-id (old passphrase) is not decoded: served direct, no scan, even with passphrases set. */
    public function test_an_old_qr_code_is_not_decoded_when_off(): void
    {
        $this->bootStorefront();
        config(['legacy_compat.qr_passphrases' => ['synthetic-passphrase-a']]);

        $store = $this->makeStore();

        $this->get('/'.$store->slug.'?qr-id='.urlencode(QrIdDecoder::encode($store->slug, 'synthetic-passphrase-a')))->assertOk();
        $this->assertSame(0, $store->views()->count());
    }

    /** Off: a room token minted under an old key is refused even when old keys are configured. */
    public function test_an_old_key_room_token_is_refused_when_off(): void
    {
        $oldKey = 'base64:c3ludGhldGljLW9sZC1rZXk=';
        config(['legacy_compat.room_token_legacy_keys' => [Crypt::encryptString($oldKey)]]);
        $room = (new Store)->tables()->getRelated()->newInstance();
        $room->forceFill(['id' => 11, 'store_id' => 5]);

        $this->assertFalse(app(RoomCharge::class)->verifyToken($room, LegacyRoomTokenKeys::sign(5, 11, $oldKey)));
    }

    private function isLegacyRoute(RoutingRoute $route): bool
    {
        return str_starts_with((string) $route->getName(), LegacyCompatRoutes::NAME_PREFIX)
            || str_contains($route->getActionName(), 'Modules\\LegacyCompat\\');
    }
}
