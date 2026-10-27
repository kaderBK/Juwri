<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Messaging;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Services\FeatureAccess;
use App\Services\FeatureRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Messaging\Models\PushSubscription;
use Juwri\Modules\Messaging\Services\MobilePush\FcmV1Client;
use Juwri\Modules\Messaging\Services\MobilePush\MobilePushService;
use Juwri\Modules\Messaging\Services\MobilePush\PushCredentials;
use Juwri\Modules\Messaging\Services\StaffPush\FcmPushSender;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * Owner app push: the platform Firebase service account (vault row with
 * store_id NULL) is managed from the admin panel, and owner-app devices
 * always send through it — a store's own BYOK account (meant for its staff /
 * driver apps) can never take over, because the owner app's tokens belong to
 * the platform's Firebase project.
 */
class MobilePushPlatformCredentialTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config([
            'settings.admin_allow_mobile_app_push' => true,
            'services.fcm.server_key' => '',
            'services.fcm.project_id' => '',
        ]);
        FeatureRegistry::clearCache();
        FeatureAccess::flush();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->shareLayoutGlobals([], true);
    }

    private function admin(): User
    {
        return User::factory()->create(['user_type' => 'admin', 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function privateKey(): string
    {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);

        return (string) $pem;
    }

    private function serviceAccount(string $project, ?string $key = null): array
    {
        return [
            'type' => 'service_account',
            'project_id' => $project,
            'client_email' => 'fcm-sender@'.$project.'.iam.gserviceaccount.com',
            'private_key' => $key ?? $this->privateKey(),
        ];
    }

    private function vault(?int $storeId, string $project): void
    {
        $sa = $this->serviceAccount($project);
        Credential::create([
            'store_id' => $storeId, 'feature' => PushCredentials::FEATURE, 'provider' => 'firebase',
            'secrets' => ['project_id' => $sa['project_id'], 'client_email' => $sa['client_email'], 'private_key' => $sa['private_key']],
            'active' => true,
        ]);
    }

    private function fakeFcm(): void
    {
        Http::fake([
            FcmV1Client::OAUTH_URL => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/p/messages/1']),
        ]);
    }

    /** @return list<string> FCM projects that received a message */
    private function projectsSentTo(): array
    {
        return Http::recorded()
            ->map(fn ($pair) => $pair[0]->url())
            ->filter(fn ($url) => str_contains($url, 'fcm.googleapis.com/v1/projects/'))
            ->map(fn ($url) => Str::between($url, '/projects/', '/messages'))
            ->values()->all();
    }

    // ── admin screen ─────────────────────────────────────────────────────────

    public function test_admin_can_save_replace_and_remove_the_platform_account_without_seeing_the_key(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.mobile-push.index'))
            ->assertOk()->assertSee('Not configured');

        $key = $this->privateKey();
        $this->actingAs($admin)->post(route('admin.mobile-push.update'), [
            'service_account' => json_encode($this->serviceAccount('juwri-push-test', $key)),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $row = Credential::whereNull('store_id')->where('feature', PushCredentials::FEATURE)->sole();
        $this->assertSame('juwri-push-test', $row->secrets['project_id']);

        $html = (string) $this->actingAs($admin)->get(route('admin.mobile-push.index'))->assertOk()->getContent();
        $this->assertStringContainsString('juwri-push-test', $html);
        $this->assertStringContainsString('fcm-sender@juwri-push-test.iam.gserviceaccount.com', $html);
        $this->assertStringNotContainsString('PRIVATE KEY', $html);
        $this->assertStringNotContainsString(trim(explode("\n", trim($key))[1]), $html);

        // Replace: still one row, new project.
        $this->actingAs($admin)->post(route('admin.mobile-push.update'), [
            'service_account' => json_encode($this->serviceAccount('other-project')),
        ])->assertRedirect();
        $this->assertSame('other-project', Credential::whereNull('store_id')->where('feature', PushCredentials::FEATURE)->sole()->secrets['project_id']);

        // Garbage is refused without echoing it back, and keeps the stored account.
        $this->actingAs($admin)->post(route('admin.mobile-push.update'), ['service_account' => '{"nope":"secret-garbage"}'])
            ->assertRedirect()->assertSessionHasErrors('service_account');
        $this->assertSame('other-project', PushCredentials::platform()['project_id']);

        $this->actingAs($admin)->delete(route('admin.mobile-push.destroy'))->assertRedirect();
        $this->assertSame(0, Credential::whereNull('store_id')->where('feature', PushCredentials::FEATURE)->count());
        $this->assertSame(PushCredentials::MODE_NONE, PushCredentials::platform()['mode']);
    }

    public function test_a_store_row_is_never_touched_by_the_admin_screen(): void
    {
        $store = $this->store();
        $this->vault((int) $store->id, 'store-byok');

        $this->actingAs($this->admin())->delete(route('admin.mobile-push.destroy'))->assertRedirect();

        $this->assertSame(1, Credential::where('store_id', $store->id)->count());
    }

    public function test_non_admins_cannot_reach_the_screen(): void
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        $this->actingAs($owner)->get(route('admin.mobile-push.index'))->assertRedirect(route('login'));
        $this->actingAs($owner)->post(route('admin.mobile-push.update'), [
            'service_account' => json_encode($this->serviceAccount('evil')),
        ])->assertRedirect(route('login'));
        $this->assertSame(0, Credential::count());
    }

    // ── delivery lanes ───────────────────────────────────────────────────────

    public function test_owner_app_devices_always_use_the_platform_account(): void
    {
        $store = $this->store();
        $this->vault(null, 'juwri-push-test');
        $this->vault((int) $store->id, 'store-byok');
        $this->fakeFcm();

        $ownerDevice = MobilePushService::registerDevice((int) $store->id, 'owner-token', 'fcm', [], null, (int) $store->user_id);

        $this->assertTrue((new FcmPushSender)->send($ownerDevice, ['title' => 'x']));
        $this->assertSame(['juwri-push-test'], $this->projectsSentTo());

        // The owner lane end to end (MobilePushService → StaffPushService → FcmPushSender).
        $this->assertSame(1, MobilePushService::deliver((int) $store->id, MobilePushService::EVENT_ORDER_STATUS, ['title' => 'y']));
        $this->assertSame(['juwri-push-test', 'juwri-push-test'], $this->projectsSentTo());
    }

    public function test_owner_app_devices_never_fall_back_to_a_store_account(): void
    {
        $store = $this->store();
        $this->vault((int) $store->id, 'store-byok');
        $this->fakeFcm();

        $ownerDevice = MobilePushService::registerDevice((int) $store->id, 'owner-token', 'apns', [], null, (int) $store->user_id);

        $this->assertSame(PushCredentials::MODE_NONE, PushCredentials::forDevice($ownerDevice)['mode']);
        $this->assertTrue((new FcmPushSender)->send($ownerDevice, ['title' => 'x']));
        $this->assertSame([], $this->projectsSentTo());
    }

    public function test_staff_app_devices_keep_the_store_byok_first(): void
    {
        $store = $this->store();
        $this->vault(null, 'juwri-push-test');
        $this->vault((int) $store->id, 'store-byok');
        $this->fakeFcm();

        $staffDevice = PushSubscription::storeNative('staff-token', 'fcm', (int) $store->id, 991, null);
        $driverDevice = MobilePushService::registerDevice((int) $store->id, 'driver-token', 'fcm', ['driver_id' => 5], null, null);

        (new FcmPushSender)->send($staffDevice, ['title' => 'x']);
        (new FcmPushSender)->send($driverDevice, ['title' => 'x']);
        $this->assertSame(['store-byok', 'store-byok'], $this->projectsSentTo());

        // …and fall back to the platform account when the store has none.
        Credential::where('store_id', $store->id)->delete();
        (new FcmPushSender)->send($staffDevice, ['title' => 'x']);
        $this->assertSame('juwri-push-test', $this->projectsSentTo()[2]);
    }
}
