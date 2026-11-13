<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Http\Middleware\Installed;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * T5 — in-page notifications on the public track page.
 *
 * On a genuine status CHANGE the shared poller detects, the diner gets a
 * title flash + navigator.vibrate (always) and — behind an explicit opt-in
 * gesture — a Notification API notification. A sound toggle is OFF by default.
 * All 17 themes include the shared partial after the 4-include block; all
 * inline JS is nonce'd; no PII (the JSON is status-only). DOM/announcements
 * via textContent.
 */
class TrackNotifyTest extends TestCase
{
    use RefreshDatabase;

    private const THEMES = KeptTemplates::MODERN;

    private const PARTIAL = 'views/store_templates/_partials/track-notify.blade.php';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Cache::flush();

        $settings = new class(['theme_color' => '#111', 'site_title' => 'MB', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            ThrottleRequests::class,
            Installed::class,
        ]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10,
            'annual_price' => 100, 'lifetime_price' => 1000, 'status' => 1,
            'settings' => ['restaurant_limit' => 999, 'allow_ordering' => '1'],
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'Pizza', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    public function test_all_17_themes_include_the_shared_notify_partial(): void
    {
        foreach (self::THEMES as $theme) {
            $src = file_get_contents(resource_path("views/store_templates/{$theme}/track.blade.php"));
            $this->assertStringContainsString(
                "@include('store_templates._partials.track-notify')",
                $src,
                "[$theme] missing the T5 notify include"
            );
        }
    }

    public function test_partial_wires_title_flash_vibrate_and_gated_notification(): void
    {
        $p = file_get_contents(resource_path(self::PARTIAL));

        // Always-on channels.
        $this->assertStringContainsString('navigator.vibrate', $p);
        $this->assertStringContainsString('document.title', $p);

        // Notification API strictly behind a gesture + a permission gate.
        $this->assertStringContainsString('Notification.requestPermission', $p);
        $this->assertStringContainsString("Notification.permission !== 'granted'", $p);
        $this->assertStringContainsString('id="mbNotifyBtn"', $p);

        // Delta detector rides the shared poller.
        $this->assertStringContainsString('window.mbTrackPoll(', $p);

        // Nonce on the inline script (CSP).
        $this->assertStringContainsString('nonce="{{ csp_nonce() }}"', $p);

        // Announcements/DOM via textContent, never innerHTML with server strings.
        $this->assertStringContainsString('.textContent', $p);
        $this->assertStringNotContainsString('innerHTML', $p);

        // a11y live region.
        $this->assertStringContainsString('aria-live="polite"', $p);
    }

    public function test_sound_toggle_is_off_by_default_and_namespaced_per_token(): void
    {
        $p = file_get_contents(resource_path(self::PARTIAL));

        // Default OFF: state comes from localStorage === '1', not a truthy default.
        $this->assertStringContainsString("lsGet(KEY + ':sound') === '1'", $p);
        // Per-track-token localStorage namespacing.
        $this->assertStringContainsString("'mbnotify:'", $p);
        // Sound uses an EXISTING global audio asset (no new binary).
        $this->assertStringContainsString('assets/global/audio/', $p);
        $this->assertFileExists(public_path('assets/global/audio/notification_message-notification-alert-7-331719.mp3'));
    }

    public function test_track_page_renders_the_notify_controls(): void
    {
        $r = $this->storeFixture();
        $order = Order::create([
            'store_id' => $r->id, 'status' => 'preparing', 'type' => 'takeaway',
            'customer_name' => 'John', 'track_token' => Str::random(32),
        ]);

        $res = $this->get(route('store.orderTrack', $order->track_token));
        $res->assertOk();
        $res->assertSeeHtml('id="mbNotifyBtn"');
        $res->assertSeeHtml('id="mbSoundToggle"');
        $res->assertSeeHtml('navigator.vibrate');
        // Seeded with the current status so the first poll is not a false change.
        $res->assertSeeHtml('var last   = "preparing"');
    }
}
