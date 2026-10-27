<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;
use Juwri\Modules\Messaging\Services\MobilePush\PushCredentials;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T9 — device registration (both platforms, both guards), the owner
 * settings surface, and the tenant/gate boundaries around them.
 */
class MobilePushDevicesTest extends TestCase
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

        config(['settings.admin_allow_mobile_app_push' => true]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        // The '*' composer that shares the layout scaffolding is gated behind
        // env('APP_INSTALLED'), which is deliberately false in phpunit.xml.
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R '.Str::random(4), 'sub_title' => 't', 'description' => 't',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function staff(Store $r): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $r->user_id, 'store_id' => $r->id,
            'name' => 'S'.Str::random(4), 'email' => Str::random(6).'@t.test',
            'password' => bcrypt('x'), 'role' => 'manager', 'active' => 1,
        ]);
    }

    // ── registration ─────────────────────────────────────────────────────────

    /** Both platforms register, and `platform` records the OS — the token is FCM either way. */
    public function test_both_platforms_register(): void
    {
        $r = $this->storeFixture();

        $android = MobilePushService::registerDevice((int) $r->id, 'tok-android', 'fcm', ['device_name' => 'Pixel 8']);
        $ios = MobilePushService::registerDevice((int) $r->id, 'tok-ios', 'apns', ['device_name' => 'iPhone 15']);

        $this->assertSame('fcm', $android->platform);
        $this->assertSame('apns', $ios->platform);
        $this->assertSame('tok-android', $android->endpoint);
        $this->assertSame('Pixel 8', $android->device_name);
        $this->assertNotNull($ios->last_seen_at);
        $this->assertCount(2, MobilePushService::devices((int) $r->id));
    }

    /** Re-registering the same token refreshes the row rather than duplicating it. */
    public function test_re_registration_is_idempotent_and_refreshes_the_heartbeat(): void
    {
        $r = $this->storeFixture();

        $first = MobilePushService::registerDevice((int) $r->id, 'tok', 'fcm', ['app_version' => '1.0.0']);
        $first->forceFill(['last_seen_at' => now()->subDays(3)])->save();

        $second = MobilePushService::registerDevice((int) $r->id, 'tok', 'fcm', ['app_version' => '1.1.0']);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, PushSubscription::where('endpoint', 'tok')->count());
        $this->assertSame('1.1.0', $second->app_version);
        $this->assertTrue($second->last_seen_at->greaterThan(now()->subMinute()));
    }

    /**
     * A device pruned by one transient failure must come back when it next
     * checks in — otherwise a single hiccup mutes a phone forever.
     */
    public function test_re_registering_revives_a_pruned_device(): void
    {
        $r = $this->storeFixture();
        $sub = MobilePushService::registerDevice((int) $r->id, 'tok', 'fcm');
        $sub->forceFill(['failed_at' => now()])->save();

        $this->assertNull(MobilePushService::registerDevice((int) $r->id, 'tok', 'fcm')->failed_at);
    }

    public function test_registration_refuses_when_the_feature_is_killed(): void
    {
        $r = $this->storeFixture();

        config(['settings.admin_allow_mobile_app_push' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $this->expectException(ValidationException::class);
        MobilePushService::registerDevice((int) $r->id, 'tok', 'fcm');
    }

    /** The owner-app endpoint carries no `feature:` middleware — the gate is in the body. */
    public function test_the_owner_register_endpoint_is_gated_in_the_controller(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        $this->actingAs($owner)
            ->postJson(route('stores.mobilepush.register', ['store_id' => $r->id]), [
                'token' => 'tok-owner', 'platform' => 'fcm', 'device_name' => 'Owner iPhone',
            ])->assertOk()->assertJson(['success' => true]);

        $sub = PushSubscription::where('endpoint', 'tok-owner')->firstOrFail();
        $this->assertSame((int) $owner->id, (int) $sub->user_id);
        $this->assertNull($sub->staff_id);

        config(['settings.admin_allow_mobile_app_push' => false]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();

        $this->actingAs($owner)
            ->postJson(route('stores.mobilepush.register', ['store_id' => $r->id]), [
                'token' => 'tok-owner-2', 'platform' => 'fcm',
            ])->assertStatus(403)->assertJson(['error' => 'feature_disabled']);
    }

    /** The staff-guard endpoint scopes to the staff member's own restaurant. */
    public function test_the_staff_register_endpoint_scopes_to_the_staff_restaurant(): void
    {
        $r = $this->storeFixture();
        $staff = $this->staff($r);

        $this->actingAs($staff, 'staff')
            ->postJson(route('staffapp.registerDevice'), [
                'token' => 'tok-staff', 'platform' => 'apns', 'device_name' => 'Till iPad',
            ])->assertOk();

        $sub = PushSubscription::where('endpoint', 'tok-staff')->firstOrFail();
        $this->assertSame((int) $r->id, (int) $sub->store_id);
        $this->assertSame((int) $staff->id, (int) $sub->staff_id);
        $this->assertSame('Till iPad', $sub->device_name);
    }

    public function test_an_owner_cannot_register_a_device_against_another_store(): void
    {
        $mine = $this->storeFixture();
        $theirs = $this->storeFixture();

        $this->actingAs(User::find($mine->user_id))
            ->postJson(route('stores.mobilepush.register', ['store_id' => $theirs->id]), [
                'token' => 'tok', 'platform' => 'fcm',
            ])->assertStatus(403);

        $this->assertSame(0, PushSubscription::count());
    }

    // ── device list / revoke ─────────────────────────────────────────────────

    public function test_the_device_list_only_shows_native_devices(): void
    {
        $r = $this->storeFixture();
        MobilePushService::registerDevice((int) $r->id, 'native', 'fcm');
        PushSubscription::store(['endpoint' => 'https://web/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']], (int) $r->id);

        $devices = MobilePushService::devices((int) $r->id);
        $this->assertCount(1, $devices);
        $this->assertSame('native', $devices->first()->endpoint);
    }

    public function test_revoke_removes_the_device_and_is_tenant_scoped(): void
    {
        $mine = $this->storeFixture();
        $theirs = $this->storeFixture();
        $sub = MobilePushService::registerDevice((int) $theirs->id, 'tok', 'fcm');

        // Another store's owner cannot revoke it, by id or by route.
        $this->assertFalse(MobilePushService::revokeDevice((int) $mine->id, (int) $sub->id));
        $this->actingAs(User::find($mine->user_id))
            ->delete(route('stores.mobilepush.devices.revoke', ['id' => $sub->id, 'store_id' => $mine->id]))
            ->assertRedirect();
        $this->assertSame(1, PushSubscription::count());

        // Its own owner can.
        $this->actingAs(User::find($theirs->user_id))
            ->delete(route('stores.mobilepush.devices.revoke', ['id' => $sub->id, 'store_id' => $theirs->id]))
            ->assertRedirect();
        $this->assertSame(0, PushSubscription::count());
    }

    /** Revoking must never touch the staff PWA's web-push subscriptions. */
    public function test_revoke_cannot_delete_a_web_subscription(): void
    {
        $r = $this->storeFixture();
        $web = PushSubscription::store(['endpoint' => 'https://web/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']], (int) $r->id);

        $this->assertFalse(MobilePushService::revokeDevice((int) $r->id, (int) $web->id));
        $this->assertSame(1, PushSubscription::count());
    }

    // ── settings screen ──────────────────────────────────────────────────────

    public function test_the_settings_page_renders_with_an_empty_state(): void
    {
        $r = $this->storeFixture();

        $this->actingAs(User::find($r->user_id))
            ->get(route('stores.mobilepush.index', ['store_id' => $r->id]))->assertOk()->assertSeeHtml('Mobile App &amp; Push')->assertSeeHtml('No app devices yet.')->assertSeeHtml('Firebase not set up');
    }

    public function test_the_settings_page_lists_devices_and_never_prints_a_secret(): void
    {
        $r = $this->storeFixture();
        MobilePushService::registerDevice((int) $r->id, 'super-secret-token', 'apns', [
            'device_name' => 'Chef iPhone', 'app_version' => '1.2.3',
        ]);
        Credential::create([
            'store_id' => $r->id, 'feature' => PushCredentials::FEATURE, 'provider' => 'firebase',
            'secrets' => PushCredentials::parseServiceAccount(json_encode([
                'project_id' => 'proj-x', 'client_email' => 'e@x',
                'private_key' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n",
            ])),
            'active' => true,
        ]);

        $res = $this->actingAs(User::find($r->user_id))
            ->get(route('stores.mobilepush.index', ['store_id' => $r->id]))->assertOk()->assertSeeHtml('Chef iPhone')->assertSeeHtml('1.2.3')->assertSeeHtml('Firebase connected')->assertSeeHtml('proj-x');

        // Neither the device token nor any key material may reach the page.
        $html = $res->getContent();
        $this->assertStringNotContainsString('super-secret-token', $html);
        $this->assertStringNotContainsString('PRIVATE KEY', $html);
        $this->assertStringNotContainsString('e@x', $html);
    }

    public function test_owner_can_save_and_clear_firebase_credentials(): void
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);

        // Garbage in is refused without echoing it back.
        $this->actingAs($owner)
            ->post(route('stores.mobilepush.credentials', ['store_id' => $r->id]), ['service_account' => 'nope'])
            ->assertSessionHasErrors('service_account');
        $this->assertSame(0, Credential::where('feature', PushCredentials::FEATURE)->count());

        $json = json_encode([
            'type' => 'service_account', 'project_id' => 'my-proj', 'client_email' => 'fcm@my-proj.iam',
            'private_key' => "-----BEGIN PRIVATE KEY-----\nAAAA\n-----END PRIVATE KEY-----\n",
        ]);

        $this->actingAs($owner)
            ->post(route('stores.mobilepush.credentials', ['store_id' => $r->id]), ['service_account' => $json])
            ->assertRedirect();

        $this->assertTrue(PushCredentials::configured((int) $r->id));
        $this->assertSame('my-proj', PushCredentials::forStore((int) $r->id)['project_id']);

        $this->actingAs($owner)
            ->delete(route('stores.mobilepush.credentials.clear', ['store_id' => $r->id]))
            ->assertRedirect();

        $this->assertFalse(PushCredentials::configured((int) $r->id));
    }

    public function test_owner_can_save_per_event_toggles(): void
    {
        $r = $this->storeFixture();

        // Default: everything on.
        $this->assertSame(
            array_fill_keys(MobilePushService::EVENTS, true),
            MobilePushService::toggles((int) $r->id)
        );

        $this->actingAs(User::find($r->user_id))
            ->post(route('stores.mobilepush.settings', ['store_id' => $r->id]), [
                'events' => [MobilePushService::EVENT_NEW_ORDER],
            ])->assertRedirect();

        $toggles = MobilePushService::toggles((int) $r->id);
        $this->assertTrue($toggles[MobilePushService::EVENT_NEW_ORDER]);
        $this->assertFalse($toggles[MobilePushService::EVENT_ORDER_STATUS]);
        $this->assertSame('0', (string) StoreOption::getStoreOption($r->id, 'mobile_push_order_status'));
    }

    public function test_the_test_push_reports_when_no_device_is_registered(): void
    {
        $r = $this->storeFixture();

        $this->actingAs(User::find($r->user_id))
            ->post(route('stores.mobilepush.test', ['store_id' => $r->id]))
            ->assertRedirect();

        $this->assertSame(0, MobilePushService::sendTest((int) $r->id));

        MobilePushService::registerDevice((int) $r->id, 'tok', 'fcm');
        // No Firebase credentials → the sender no-ops but still reports delivery,
        // so the owner learns the device is reachable rather than seeing a lie.
        $this->assertSame(1, MobilePushService::sendTest((int) $r->id));
    }
}
