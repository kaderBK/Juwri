<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Http\Middleware\TrackOwnerScreenView;
use Juwri\Core\Http\Middleware\TurboFormResponses;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\OwnerApp;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner app server items S5 (Hotwire Native bridge components) and S7 (Turbo-
 * compatible form responses) — docs/dev/owner-app-bridge.md.
 */
class OwnerAppBridgeTest extends TestCase
{
    use RefreshDatabase;

    private const APP_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Mobile/15E148 JuwriApp/1.0.0 (ios) Hotwire Native iOS; Turbo Native iOS; bridge-components: [form menu button]';

    private const WEB_UA = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    /** What Turbo 8 sends with a form submission. */
    private const TURBO = [
        'X-Turbo-Request-Id' => '6f1c4f36-2d4e-4d59-9a44-0c1b1f8f7c11',
        'Accept' => 'text/vnd.turbo-stream.html, text/html, application/xhtml+xml',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class, TrackOwnerScreenView::class]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        // Probe routes behind the real `web` group (TurboFormResponses included).
        Route::middleware('web')->group(function () {
            Route::post('__turbo-probe/redirect', fn () => redirect('/__turbo-probe/done'));
            Route::post('__turbo-probe/json-ok', fn () => response()->json(['success' => true, 'message' => 'Imported 3 items.']));
            Route::post('__turbo-probe/json-fail', fn () => response()->json(['success' => false, 'message' => 'Invalid CSV file.']));
            Route::post('__turbo-probe/json-422', fn () => response()->json(['message' => 'The given data was invalid.', 'errors' => ['name' => ['The name field is required.']]], 422));
            Route::post('__turbo-probe/json-403', fn () => response()->json(['message' => 'Unauthorized'], 403));
            Route::post('__turbo-probe/page', fn () => response('<!DOCTYPE html><html><body><p>Form with errors</p></body></html>'));
            Route::post('__turbo-probe/download', fn () => response()->streamDownload(fn () => print ('a,b'), 'x.csv', ['Content-Type' => 'text/csv']));
            Route::get('__turbo-probe/page', fn () => response('<!DOCTYPE html><html><body><p>A page</p></body></html>'));
        });
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)), 'email' => Str::lower(Str::random(8)).'@owner.test',
        ]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R '.Str::random(4), 'sub_title' => 't', 'description' => 't',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'store_type' => 'restaurant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function dashboard(User $owner, string $ua): string
    {
        return (string) $this->actingAs($owner)->withHeaders(['User-Agent' => $ua])->get(route('dashboard'))->assertOk()->getContent();
    }

    // ── S5: bridge components ────────────────────────────────────────────────

    public function test_bridge_assets_load_only_inside_the_app(): void
    {
        $owner = $this->owner();
        $this->store($owner);

        $web = $this->dashboard($owner, self::WEB_UA);
        $this->assertStringNotContainsString('assets/owner-app/bridge.js', $web);
        $this->assertStringNotContainsString('assets/owner-app/bridge.css', $web);

        $app = $this->dashboard($owner, self::APP_UA);
        $this->assertStringContainsString('data-owner-app="1"', $app);
        $this->assertStringContainsString('assets/owner-app/bridge.css', $app);
        $this->assertSame(1, substr_count($app, 'assets/owner-app/bridge.js'), 'the module is included once');
        // CSP (nonce-based, script-src 'self'): a same-origin module with a nonce, loaded once across Turbo visits.
        $this->assertMatchesRegularExpression(
            '#<script type="module" nonce="[^"]+" src="[^"]*assets/owner-app/bridge\.js\?v=[^"]+" data-turbo-eval="false"></script>#',
            $app
        );
    }

    public function test_vendored_libraries_are_pinned_and_import_each_other_locally(): void
    {
        $dir = public_path('assets/owner-app');
        $stimulus = File::get($dir.'/vendor/stimulus-3.2.2.js');
        $bridgeLib = File::get($dir.'/vendor/hotwire-native-bridge-1.2.2.js');
        $boot = File::get($dir.'/bridge.js');

        $this->assertStringContainsString('export { Application,', $stimulus);
        $this->assertStringContainsString('Hotwire Native Bridge 1.2.2', $bridgeLib);
        // No bundler / import map on the dashboard: every import is a relative file.
        foreach ([$bridgeLib, $boot] as $source) {
            preg_match_all('/^\s*import\s[^;]*?from\s+[\'"]([^\'"]+)[\'"]/m', $source, $m);
            $this->assertNotEmpty($m[1]);
            foreach ($m[1] as $specifier) {
                $this->assertStringStartsWith('./', $specifier, "bare import {$specifier} cannot resolve without an import map");
            }
        }
        $this->assertStringContainsString("from './vendor/stimulus-3.2.2.js'", $boot);
        $this->assertStringContainsString("from './vendor/hotwire-native-bridge-1.2.2.js'", $boot);

        // The attributes the library writes on <html> — bridge.css keys off exactly these.
        $this->assertStringContainsString('document.documentElement.dataset.bridgePlatform', $bridgeLib);
        $this->assertStringContainsString('document.documentElement.dataset.bridgeComponents', $bridgeLib);
        $this->assertStringContainsString('bridge-components: \\[(.*?)\\]', $bridgeLib, 'controllers load only for components listed in the UA');
    }

    public function test_the_attribute_contract_matches_bridge_md(): void
    {
        $boot = File::get(public_path('assets/owner-app/bridge.js'));
        $css = File::get(public_path('assets/owner-app/bridge.css'));
        $doc = File::get(base_path('../docs/dev/owner-app-bridge.md'));

        foreach (['form', 'menu', 'button'] as $component) {
            $this->assertStringContainsString("static component = '{$component}';", $boot);
            $this->assertStringContainsString("application.register('bridge--{$component}'", $boot);
            $this->assertStringContainsString("data-controller=\"bridge--{$component}\"", $doc);
        }
        // Native message events + payload keys (BRIDGE.md is the native teams' contract).
        foreach (["'connect'", "'submitEnabled'", "'submitDisabled'", "'display'", 'submitTitle', 'selectedIndex', 'destructive', 'iosImage', 'androidImage'] as $needle) {
            $this->assertStringContainsString($needle, $boot);
            $this->assertStringContainsString(trim($needle, "'"), $doc);
        }
        foreach (['data-bridge--form-target="submit"', 'data-bridge--menu-target="trigger"', 'data-bridge--menu-target="item"', 'data-bridge-destructive', 'data-bridge-title', 'data-bridge-ios-image', 'data-bridge-android-image', 'data-bridge-hide'] as $attr) {
            $this->assertStringContainsString($attr, $doc);
        }

        $this->assertStringContainsString('html[data-bridge-components~="form"] [data-controller~="bridge--form"] [data-bridge--form-target~="submit"]', $css);
        $this->assertStringContainsString('html[data-bridge-components~="button"] [data-controller~="bridge--button"]', $css);
        $this->assertStringContainsString('[data-bridge-hide~="menu"]', $css);
    }

    // ── S7: Turbo-compatible form responses ──────────────────────────────────

    public function test_turbo_redirects_become_303_and_browser_redirects_stay_302(): void
    {
        $this->post('/__turbo-probe/redirect')->assertStatus(302)->assertRedirect('/__turbo-probe/done');
        $this->withHeaders(self::TURBO)->post('/__turbo-probe/redirect')->assertStatus(303)->assertRedirect('/__turbo-probe/done');

        // Turbo 7 has no request id but always asks for Turbo Streams on a form post.
        $this->flushHeaders();
        $this->withHeaders(['Accept' => self::TURBO['Accept']])->post('/__turbo-probe/redirect')->assertStatus(303);
    }

    public function test_turbo_json_answers_become_a_redirect_back_with_a_toast(): void
    {
        $back = ['Referer' => url('/__turbo-probe/page')];

        $this->withHeaders(self::TURBO + $back)->post('/__turbo-probe/json-ok')
            ->assertStatus(303)->assertRedirect('/__turbo-probe/page')
            ->assertSessionHas('success', 'Imported 3 items.');

        $this->withHeaders(self::TURBO + $back)->post('/__turbo-probe/json-fail')
            ->assertStatus(303)->assertSessionHas('error', 'Invalid CSV file.');

        $this->withHeaders(self::TURBO + $back)->post('/__turbo-probe/json-403')
            ->assertStatus(303)->assertSessionHas('error', 'Unauthorized');

        $this->withHeaders(self::TURBO + $back)->post('/__turbo-probe/json-422', ['name' => 'Pizza', 'password' => 'secret'])
            ->assertStatus(303)->assertRedirect('/__turbo-probe/page')
            ->assertSessionHas('error', 'The given data was invalid.')
            ->assertSessionHasErrors(['name' => 'The name field is required.'])
            ->assertSessionHasInput('name')
            ->assertSessionMissing('_old_input.password');
    }

    public function test_turbo_200_pages_render_in_place_as_422(): void
    {
        $this->post('/__turbo-probe/page')->assertStatus(200);
        $this->withHeaders(self::TURBO)->post('/__turbo-probe/page')->assertStatus(422)->assertSee('Form with errors');
    }

    public function test_non_turbo_calls_downloads_and_visits_are_untouched(): void
    {
        // Browser form post / JS fetch: exactly as before.
        $this->post('/__turbo-probe/json-ok')->assertStatus(200)->assertJsonPath('message', 'Imported 3 items.');
        $this->postJson('/__turbo-probe/json-422')->assertStatus(422)->assertJsonPath('errors.name.0', 'The name field is required.');
        $this->withHeaders(self::TURBO + ['X-Requested-With' => 'XMLHttpRequest'])->post('/__turbo-probe/json-ok')->assertStatus(200);
        $this->flushHeaders();

        // A download answers a Turbo post unchanged (such forms carry data-turbo="false").
        $download = $this->withHeaders(self::TURBO)->post('/__turbo-probe/download')->assertStatus(200);
        $this->assertStringStartsWith('text/csv', (string) $download->headers->get('Content-Type'));

        // Turbo link visits (GET) are not form submissions.
        $this->withHeaders(self::TURBO)->get('/__turbo-probe/page')->assertStatus(200);
    }

    public function test_real_owner_forms_answer_turbo_with_303(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $menu = route('stores.menu', $store->id);

        $empty = fn () => UploadedFile::fake()->createWithContent('menu.csv', '');

        // Browser (no Turbo headers): unchanged.
        $this->actingAs($owner)->post(route('dashboard.layout'), ['cards' => ['trend']])->assertStatus(302);
        $this->actingAs($owner)->post(route('stores.menuImportCsv', $store->id), ['csv_file' => $empty()])
            ->assertStatus(200)->assertJsonPath('success', false);

        // Dashboard "Customize dashboard" form: redirect back, as a 303.
        $this->actingAs($owner)->withHeaders(self::TURBO)->post(route('dashboard.layout'), ['cards' => ['trend']])->assertStatus(303);

        // Menu CSV import: a plain form post answered with JSON — in the app it
        // becomes a redirect back to the menu with the message as a toast.
        $this->actingAs($owner)->withHeaders(self::TURBO + ['Referer' => $menu])
            ->post(route('stores.menuImportCsv', $store->id), ['csv_file' => $empty()])
            ->assertStatus(303)->assertRedirect($menu)->assertSessionHas('error', 'Invalid CSV file.');

        // Validation failure (no file): Laravel's redirect back, as a 303.
        $this->actingAs($owner)->withHeaders(self::TURBO + ['Referer' => $menu])
            ->post(route('stores.menuImportCsv', $store->id), [])
            ->assertStatus(303)->assertRedirect($menu)->assertSessionHasErrors('csv_file');
    }

    // ── S6 helper used by S7 ─────────────────────────────────────────────────

    public function test_historical_redirect_pops_the_modal_in_the_app_only(): void
    {
        $app = request()->duplicate();
        $app->headers->set('User-Agent', self::APP_UA);
        $web = request()->duplicate();
        $web->headers->set('User-Agent', self::WEB_UA);

        $inApp = OwnerApp::historicalRedirect('recede', '/restaurants', $app);
        $this->assertSame(303, $inApp->getStatusCode());
        $this->assertSame(route('owner-app.historical.recede'), $inApp->getTargetUrl());

        $this->assertSame(route('owner-app.historical.refresh'), OwnerApp::historicalRedirect('refresh', '/restaurants', $app)->getTargetUrl());

        $browser = OwnerApp::historicalRedirect('recede', '/restaurants', $web);
        $this->assertSame(302, $browser->getStatusCode());
        $this->assertSame(url('/restaurants'), $browser->getTargetUrl());
    }

    public function test_historical_routes_answer_with_html_turbo_can_follow(): void
    {
        foreach (['recede', 'resume', 'refresh'] as $kind) {
            $response = $this->get("/{$kind}_historical_location")->assertOk();
            $this->assertStringStartsWith('text/html', (string) $response->headers->get('Content-Type'));
            $body = (string) $response->getContent();
            $this->assertNotSame('', $body, 'Turbo proposes the visit only when the followed response has HTML');
            $this->assertSame('', trim(strip_tags($body)), 'a browser sees a blank page');
        }

        // "refresh" reloads the screen underneath: the form's toast survives the hop.
        $this->withSession(['_flash' => ['old' => ['success'], 'new' => []], 'success' => 'Saved.'])
            ->get('/refresh_historical_location')->assertOk();
        $this->assertSame('Saved.', session('success'));
        $this->assertContains('success', (array) session('_flash.old'), 'reflashed: still there for the next request');

        // recede / resume do not reload anything, so the message is not carried over.
        $this->withSession(['_flash' => ['old' => ['success'], 'new' => []], 'success' => 'Saved.'])
            ->get('/recede_historical_location')->assertOk();
        $this->assertNull(session('success'));
    }

    public function test_turbo_detection_ignores_safe_and_ajax_requests(): void
    {
        $store = request()->duplicate([], [], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $store->headers->add(self::TURBO);
        $this->assertTrue(TurboFormResponses::isTurboFormSubmission($store));

        $get = request()->duplicate([], [], [], [], [], ['REQUEST_METHOD' => 'GET']);
        $get->headers->add(self::TURBO);
        $this->assertFalse(TurboFormResponses::isTurboFormSubmission($get));

        $ajax = request()->duplicate([], [], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $ajax->headers->add(self::TURBO + ['X-Requested-With' => 'XMLHttpRequest']);
        $this->assertFalse(TurboFormResponses::isTurboFormSubmission($ajax));

        $plain = request()->duplicate([], [], [], [], [], ['REQUEST_METHOD' => 'POST']);
        $this->assertFalse(TurboFormResponses::isTurboFormSubmission($plain));
    }

    public function test_turbo_compat_keeps_browser_forms_native_and_turns_turbo_forms_on_in_app(): void
    {
        $js = File::get(public_path('assets/owner-ui/turbo-compat.js'));

        $this->assertStringContainsString("var IN_APP = document.documentElement.getAttribute('data-owner-app') === '1';", $js);
        $this->assertStringContainsString("var mode = IN_APP ? 'on' : 'off';", $js);
        $this->assertStringContainsString('if (IN_APP) inAppForms();', $js);
        // form.submit() is routed through Turbo in the app only, with a native fallback.
        $this->assertMatchesRegularExpression('/function inAppForms\(\)[\s\S]*HTMLFormElement\.prototype\.submit = function/', $js);
        $this->assertStringContainsString('return nativeSubmit.call(form);', $js);
    }
}
