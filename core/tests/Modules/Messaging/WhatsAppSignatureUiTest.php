<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Models\WhatsAppRoute;
use Tests\TestCase;

/**
 * C1 UI wiring: the two operator-facing controls must actually render, and the
 * store-dashboard signature badge must reflect real state (secret present,
 * number routed) rather than being a dead control. The superadmin toggle must
 * change what the page shows.
 */
class WhatsAppSignatureUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        // APP_INSTALLED=false disables the real global $settings composer, so the
        // admin/owner layouts must be given one. Magic object => missing keys read
        // as null instead of erroring (mirrors AdminOrdersTest's scaffold).
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

        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
            'pages' => collect(),
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
        return User::factory()->create([
            'group_id' => '1', 'status' => 1, 'email_verified_at' => now(), 'user_type' => 'admin',
        ]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(8)), 'color' => '#111',
            'title' => 'WA Sig UI', 'sub_title' => 't', 'description' => 't',
            'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '1',
        ]);
    }

    public function test_store_dashboard_shows_green_when_secret_and_route_exist(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->store($owner);
        Credential::create([
            'store_id' => $store->id, 'feature' => 'whatsapp', 'provider' => 'twilio',
            'secrets' => ['sid' => 'AC1', 'auth_token' => 'tok', 'from_number' => '+15550000000'], 'active' => true,
        ]);
        WhatsAppRoute::create(['provider' => 'twilio', 'sender_id' => '+15550000000', 'store_id' => $store->id]);

        $this->actingAs($owner)
            ->get(route('stores.whatsappSettings', $store->id))->assertOk()->assertSeeHtml('signature-verified with your key');
    }

    public function test_store_dashboard_shows_red_when_number_not_registered(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->store($owner);
        // Secret saved but no route row yet -> "not registered" red line.
        Credential::create([
            'store_id' => $store->id, 'feature' => 'whatsapp', 'provider' => 'twilio',
            'secrets' => ['sid' => 'AC1', 'auth_token' => 'tok'], 'active' => true,
        ]);

        $this->actingAs($owner)
            ->get(route('stores.whatsappSettings', $store->id))->assertOk()->assertSeeHtml('sending number is not registered yet');
    }

    public function test_store_dashboard_stays_quiet_when_nothing_configured(): void
    {
        $owner = User::factory()->create(['status' => 1]);
        $store = $this->store($owner);

        $this->actingAs($owner)
            ->get(route('stores.whatsappSettings', $store->id))->assertOk()->assertDontSeeHtml('signature-verified with your key')->assertDontSeeHtml('No signing secret saved');
    }

    public function test_superadmin_toggle_and_health_readout_render(): void
    {
        $admin = $this->makeAdmin();

        // Toggle ON (default): no red alert, health list present.
        $this->actingAs($admin)
            ->get(route('admin.whatsapp.settings'))->assertOk()->assertSeeHtml('Verify webhook signatures')->assertSeeHtml('Inbound webhook health')->assertDontSeeHtml('Signature verification is OFF');
    }

    public function test_superadmin_toggle_off_shows_the_danger_alert(): void
    {
        $admin = $this->makeAdmin();
        config(['settings.whatsapp_verify_signatures' => '0']);

        $this->actingAs($admin)
            ->get(route('admin.whatsapp.settings'))->assertOk()->assertSeeHtml('Signature verification is OFF');
    }
}
