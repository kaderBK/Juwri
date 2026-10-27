<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Channels;

use App\Services\Aggregators\Jahez\JahezClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Channels\Models\AggregatorConnection;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\FakeJahezClient;
use Juwri\Modules\Channels\Services\Aggregators\Jahez\JahezClientFactory;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\FakeKeetaClient;
use Juwri\Modules\Channels\Services\Aggregators\Keeta\KeetaClientFactory;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * A6 — aggregator factories must fail loud (log + `unavailable` status) when the owner
 * has flipped driver=real and saved BYOK creds but the real client class is not shipped,
 * instead of silently returning the Fake and reporting a phantom success. The owner hub
 * surfaces a "Driver not available" badge and refuses the menu push in that state.
 */
class AggregatorDriverStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
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
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_config_blocks_resolve(): void
    {
        // Added by A6 — previously null, making driver=real unreachable.
        $this->assertNotNull(config('services.jahez.driver'));
        $this->assertNotNull(config('services.keeta.driver'));
        $this->assertSame('fake', config('services.jahez.driver'));
        $this->assertSame('fake', config('services.keeta.driver'));
    }

    public function test_default_fake_driver_returns_fake_client_and_status(): void
    {
        $r = $this->storeFixture();

        $this->assertSame('fake', JahezClientFactory::driverStatus($r->id));
        $this->assertInstanceOf(FakeJahezClient::class, JahezClientFactory::make($r->id));
        $this->assertSame('fake', KeetaClientFactory::driverStatus($r->id));
        $this->assertInstanceOf(FakeKeetaClient::class, KeetaClientFactory::make($r->id));
    }

    public function test_real_driver_with_creds_but_missing_client_is_unavailable_and_logs(): void
    {
        $r = $this->storeFixture();
        config()->set('services.jahez.driver', 'real');
        Credential::create([
            'store_id' => $r->id, 'feature' => 'jahez', 'provider' => 'jahez',
            'secrets' => ['api_key' => 'k', 'secret' => 's'], 'active' => true,
        ]);

        // The real client class is intentionally not shipped yet (A7).
        $this->assertFalse(class_exists(JahezClient::class));
        $this->assertSame('unavailable', JahezClientFactory::driverStatus($r->id));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn ($msg, $ctx = []) => str_contains($msg, 'driver=real but real client missing')
                && ($ctx['provider'] ?? null) === 'jahez');

        // make() must still return the Fake (never fatal) but log the fail-loud line.
        $this->assertInstanceOf(FakeJahezClient::class, JahezClientFactory::make($r->id));
    }

    public function test_real_driver_without_creds_stays_fake(): void
    {
        $r = $this->storeFixture();
        config()->set('services.keeta.driver', 'real');

        // driver=real but no BYOK creds → still fake (owner has not connected).
        $this->assertSame('fake', KeetaClientFactory::driverStatus($r->id));
    }

    public function test_hub_shows_unavailable_badge_when_driver_real_but_client_missing(): void
    {
        $this->withoutMiddleware();
        [$owner, $r] = $this->connectedJahezStore();
        config()->set('services.jahez.driver', 'real');

        $this->actingAs($owner)
            ->get(route('stores.aggregators.index').'?store_id='.$r->id)
            ->assertOk()
            ->assertSee('Driver not available');
    }

    public function test_menu_push_is_refused_when_driver_unavailable(): void
    {
        $this->withoutMiddleware();
        [$owner, $r, $conn] = $this->connectedJahezStore();
        config()->set('services.jahez.driver', 'real');

        $this->actingAs($owner)
            ->post(route('stores.aggregators.menuPush', $conn->id).'?store_id='.$r->id)
            ->assertRedirect();

        // A failed sync row is recorded and NO phantom "ok" success.
        $this->assertDatabaseHas('aggregator_sync_logs', [
            'store_id' => $r->id, 'provider' => 'jahez', 'status' => 'failed',
        ]);
        $this->assertDatabaseMissing('aggregator_sync_logs', [
            'store_id' => $r->id, 'provider' => 'jahez', 'status' => 'ok',
        ]);
    }

    /** @return array{0: User, 1: Store, 2: AggregatorConnection} */
    private function connectedJahezStore(): array
    {
        $r = $this->storeFixture();
        $owner = User::find($r->user_id);
        $conn = AggregatorConnection::create([
            'provider' => 'jahez', 'store_id' => $r->id, 'external_store_id' => 'JZ-A6', 'status' => 'active',
        ]);
        Credential::create([
            'store_id' => $r->id, 'feature' => 'jahez', 'provider' => 'jahez',
            'secrets' => ['api_key' => 'k', 'secret' => 's'], 'active' => true,
        ]);

        return [$owner, $r, $conn];
    }
}
