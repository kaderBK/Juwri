<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Models\MenuCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Models\AggregatorSyncLog;
use Juwri\Modules\Channels\Services\Aggregators\India\IndiaAggregatorSync;
use Juwri\Modules\Channels\Services\Aggregators\Swiggy\FakeSwiggyClient;
use Juwri\Modules\Channels\Services\Aggregators\Swiggy\SwiggyClientInterface;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\FakeZomatoClient;
use Juwri\Modules\Channels\Services\Aggregators\Zomato\ZomatoClientInterface;
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * PARITY-RUN T7 — the owner-facing half: the Zomato/Swiggy connection pages.
 *
 * The assertions that matter most here are the SECRET ones. A connection page is
 * the classic place a platform leaks a tenant's partner credentials back into
 * HTML, so this file pins that the page renders the controls, stores what the
 * owner typed, and never echoes it.
 */
class IndiaAggregatorUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';

        // APP_INSTALLED=false in phpunit.xml means the app-wide $settings view
        // composer never runs under test — share the same shape it would.
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => $settings,
            'languages' => collect(),
        ]);

        $this->withoutMiddleware();
        FakeZomatoClient::reset();
        FakeSwiggyClient::reset();
        $this->app->bind(ZomatoClientInterface::class, fn () => new FakeZomatoClient);
        $this->app->bind(SwiggyClientInterface::class, fn () => new FakeSwiggyClient);
    }

    /** @return array{0: User, 1: Store} */
    private function owner(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'aggregator_hub' => '1']),
        ]);
        $user = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$user, $store];
    }

    public function test_page_renders_an_empty_state_before_the_channel_is_connected(): void
    {
        [$user, $store] = $this->owner();

        $res = $this->actingAs($user)->get(route('stores.zomato.index', $store->id));

        $res->assertOk();
        $res->assertSeeHtml('Zomato');
        $res->assertSeeHtml('Not connected yet');
        // The webhook URL is shown before connecting, because a partner manager
        // usually asks for it during onboarding.
        $res->assertSeeHtml(route('webhook.zomato.inbound'));
        $res->assertSeeHtml('X-Zomato-Signature');
    }

    public function test_connecting_stores_the_mapping_and_the_encrypted_secrets(): void
    {
        [$user, $store] = $this->owner();

        $this->actingAs($user)->post(route('stores.zomato.connect', $store->id), [
            'external_store_id' => 'ZOM-42',
            'webhook_secret' => 'super-secret-hook',
            'api_key' => 'the-api-key',
            'api_secret' => 'the-api-secret',
            'prep_minutes' => 25,
            'auto_accept' => '1',
        ])->assertRedirect();

        $conn = AggregatorConnection::where('store_id', $store->id)->where('provider', 'zomato')->first();
        $this->assertNotNull($conn);
        $this->assertSame('ZOM-42', $conn->external_store_id);
        $this->assertSame(25, (int) $conn->settings['prep_minutes']);
        $this->assertTrue((bool) $conn->auto_accept);

        $secrets = Credential::for('zomato', (int) $store->id);
        $this->assertSame('super-secret-hook', $secrets['webhook_secret']);
        $this->assertSame('the-api-key', $secrets['api_key']);

        // Stored ENCRYPTED: the raw row must not contain the plaintext.
        $raw = DB::table('credentials')
            ->where('store_id', $store->id)->where('feature', 'zomato')->value('secrets');
        $this->assertStringNotContainsString('super-secret-hook', (string) $raw);
    }

    public function test_the_page_never_echoes_a_stored_secret(): void
    {
        [$user, $store] = $this->owner();
        $this->actingAs($user)->post(route('stores.zomato.connect', $store->id), [
            'external_store_id' => 'ZOM-42',
            'webhook_secret' => 'super-secret-hook',
            'api_key' => 'the-api-key',
            'api_secret' => 'the-api-secret',
        ])->assertRedirect();

        $res = $this->actingAs($user)->get(route('stores.zomato.index', $store->id));

        $res->assertOk();
        $res->assertDontSeeHtml('super-secret-hook');
        $res->assertDontSeeHtml('the-api-key');
        $res->assertDontSeeHtml('the-api-secret');
        // A masked placeholder proves something IS stored, without revealing it.
        $res->assertSeeHtml('••••');
        $res->assertDontSeeHtml('Not connected yet');
    }

    public function test_an_empty_secret_field_keeps_the_stored_value(): void
    {
        [$user, $store] = $this->owner();
        $this->actingAs($user)->post(route('stores.zomato.connect', $store->id), [
            'external_store_id' => 'ZOM-42', 'webhook_secret' => 'keep-me',
        ])->assertRedirect();

        // Re-saving only the outlet id must not wipe the secret — the field can
        // never be pre-filled, so an empty submit means "unchanged".
        $this->actingAs($user)->post(route('stores.zomato.connect', $store->id), [
            'external_store_id' => 'ZOM-99',
        ])->assertRedirect();

        $this->assertSame('keep-me', Credential::for('zomato', (int) $store->id)['webhook_secret']);
        $this->assertSame('ZOM-99', AggregatorConnection::where('store_id', $store->id)->value('external_store_id'));
    }

    public function test_an_outlet_id_already_claimed_by_another_store_is_refused(): void
    {
        [$userA, $postA] = $this->owner();
        [$userB, $postB] = $this->owner();

        $this->actingAs($userA)->post(route('stores.zomato.connect', $postA->id), [
            'external_store_id' => 'SHARED-1', 'webhook_secret' => 'a',
        ])->assertRedirect();

        $this->actingAs($userB)->post(route('stores.zomato.connect', $postB->id), [
            'external_store_id' => 'SHARED-1', 'webhook_secret' => 'b',
        ])->assertRedirect();

        $this->assertSame(1, AggregatorConnection::where('external_store_id', 'SHARED-1')->count());
        $this->assertSame(
            (int) $postA->id,
            (int) AggregatorConnection::where('external_store_id', 'SHARED-1')->value('store_id')
        );
    }

    public function test_test_connection_records_a_sync_log_row(): void
    {
        [$user, $store] = $this->owner();
        $this->actingAs($user)->post(route('stores.swiggy.connect', $store->id), [
            'external_store_id' => 'SWG-7', 'webhook_secret' => 's',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('stores.swiggy.test', $store->id))->assertRedirect();

        $log = AggregatorSyncLog::where('store_id', $store->id)->where('provider', 'swiggy')->first();
        $this->assertNotNull($log);
        $this->assertSame('ok', $log->status);
    }

    public function test_menu_push_publishes_and_logs(): void
    {
        [$user, $store] = $this->owner();
        $cat = MenuCategory::create(['store_id' => $store->id, 'name' => 'Mains', 'active' => '1', 'position' => 1]);
        Menu::create(['store_id' => $store->id, 'category_id' => $cat->id, 'name' => 'Thali', 'price' => 250, 'active' => '1', 'position' => 1]);

        $this->actingAs($user)->post(route('stores.zomato.connect', $store->id), [
            'external_store_id' => 'ZOM-42', 'webhook_secret' => 'x',
        ])->assertRedirect();

        $this->actingAs($user)->post(route('stores.zomato.menuPush', $store->id))->assertRedirect();

        $this->assertNotNull(FakeZomatoClient::$lastMenu);
        $this->assertCount(1, FakeZomatoClient::$lastMenu['items']);
        $conn = AggregatorConnection::where('store_id', $store->id)->where('provider', 'zomato')->first();
        $this->assertSame('synced', $conn->menu_sync_state);
        $this->assertNotNull($conn->last_synced_at);
        $this->assertSame('ok', AggregatorSyncLog::where('provider', 'zomato')->latest('id')->first()->status);
    }

    public function test_a_failed_menu_push_is_reported_not_swallowed(): void
    {
        [$user, $store] = $this->owner();
        Menu::create(['store_id' => $store->id, 'name' => 'Thali', 'price' => 250, 'active' => '1']);

        $this->actingAs($user)->post(route('stores.zomato.connect', $store->id), [
            'external_store_id' => 'ZOM-42', 'webhook_secret' => 'x',
        ])->assertRedirect();

        FakeZomatoClient::$failNext = 'partner rejected the payload';
        $this->actingAs($user)->post(route('stores.zomato.menuPush', $store->id))->assertRedirect();

        $conn = AggregatorConnection::where('store_id', $store->id)->where('provider', 'zomato')->first();
        $this->assertSame('failed', $conn->menu_sync_state);
        $this->assertStringContainsString('partner rejected', (string) $conn->sync_error);

        $log = AggregatorSyncLog::where('provider', 'zomato')->latest('id')->first();
        $this->assertSame('failed', $log->status);

        // …and the owner sees it on the page.
        $this->actingAs($user)->get(route('stores.zomato.index', $store->id))->assertOk()->assertSeeHtml('partner rejected the payload');
    }

    public function test_outlet_toggle_and_availability_sync_and_pause(): void
    {
        [$user, $store] = $this->owner();
        Menu::create(['store_id' => $store->id, 'name' => 'Thali', 'price' => 250, 'active' => '1']);

        $this->actingAs($user)->post(route('stores.swiggy.connect', $store->id), [
            'external_store_id' => 'SWG-7', 'webhook_secret' => 's',
        ])->assertRedirect();

        $conn = fn () => AggregatorConnection::where('store_id', $store->id)->where('provider', 'swiggy')->first();

        $this->actingAs($user)->post(route('stores.swiggy.outlet', $store->id))->assertRedirect();
        $this->assertFalse(IndiaAggregatorSync::isOutletOnline($conn()));
        $this->assertFalse(FakeSwiggyClient::lastCall('setOutletOnline')['online']);

        $this->actingAs($user)->post(route('stores.swiggy.outlet', $store->id))->assertRedirect();
        $this->assertTrue(IndiaAggregatorSync::isOutletOnline($conn()));

        $this->actingAs($user)->post(route('stores.swiggy.availability', $store->id))->assertRedirect();
        $this->assertContains('setItemAvailability', FakeSwiggyClient::ops());

        $this->actingAs($user)->post(route('stores.swiggy.pause', $store->id))->assertRedirect();
        $this->assertTrue($conn()->isPaused());
    }

    public function test_disconnect_removes_the_mapping_and_the_secrets(): void
    {
        [$user, $store] = $this->owner();
        $this->actingAs($user)->post(route('stores.zomato.connect', $store->id), [
            'external_store_id' => 'ZOM-42', 'webhook_secret' => 'x',
        ])->assertRedirect();

        $this->actingAs($user)->delete(route('stores.zomato.disconnect', $store->id))->assertRedirect();

        $this->assertSame(0, AggregatorConnection::where('store_id', $store->id)->count());
        $this->assertNull(Credential::for('zomato', (int) $store->id));
    }

    public function test_another_owners_store_is_not_found(): void
    {
        [$userA] = $this->owner();
        [$userB, $postB] = $this->owner();

        $this->actingAs($userA)->get(route('stores.zomato.index', $postB->id))->assertNotFound();
        $this->actingAs($userA)->post(route('stores.zomato.connect', $postB->id), [
            'external_store_id' => 'ZOM-HIJACK', 'webhook_secret' => 'x',
        ])->assertNotFound();
        $this->assertSame(0, AggregatorConnection::where('external_store_id', 'ZOM-HIJACK')->count());
    }

    public function test_the_hub_links_to_the_dedicated_channel_page(): void
    {
        [$user, $store] = $this->owner();
        AggregatorConnection::create([
            'provider' => 'zomato', 'store_id' => $store->id,
            'external_store_id' => 'ZOM-42', 'status' => 'active',
        ]);

        $this->actingAs($user)
            ->get(route('stores.aggregators.index', ['store_id' => $store->id]))->assertOk()->assertSeeHtml(route('stores.zomato.index', $store->id));
    }
}
