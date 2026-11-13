<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Pos;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Pos\Support\KioskPin;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B9-P3: kiosk owner config + source label.
 */
class KioskConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_kiosk' => true]);
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
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
    }

    private function ownerRestaurant(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$owner, $r];
    }

    public function test_config_renders_and_saves(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->get(route('stores.kiosk.index').'?store_id='.$r->id)->assertOk();
        $res->assertSee(___('Open on the tablet'));
        $res->assertSeeHtml('kiosk-settings'); // nav URL

        $this->actingAs($owner)->post(route('stores.kiosk.update').'?store_id='.$r->id, [
            'kiosk_enabled' => '1', 'kiosk_idle_seconds' => '90', 'kiosk_exit_pin' => '804613',
        ])->assertRedirect();

        $this->assertSame('1', (string) store_options($r->id, 'kiosk_enabled'));
        $this->assertSame('90', (string) store_options($r->id, 'kiosk_idle_seconds'));
        $stored = (string) store_options($r->id, 'kiosk_exit_pin');
        $this->assertNotSame('804613', $stored, 'P6-88: kiosk PIN is stored hashed');
        $this->assertTrue(Hash::check('804613', $stored));
    }

    public function test_pin_form_never_echoes_and_blank_keeps_clear_removes(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $url = route('stores.kiosk.update').'?store_id='.$r->id;

        $this->actingAs($owner)->post($url, ['kiosk_exit_pin' => '864209', 'kiosk_setup_pin' => '135792'])->assertRedirect();
        $page = $this->actingAs($owner)->get(route('stores.kiosk.index').'?store_id='.$r->id)->assertOk();
        $page->assertDontSee((string) store_options($r->id, 'kiosk_exit_pin'), false);
        $page->assertSee('kiosk_exit_pin_clear', false);

        $this->actingAs($owner)->post($url, ['kiosk_exit_pin' => '', 'kiosk_idle_seconds' => '60'])->assertRedirect();
        $this->assertTrue(KioskPin::verify($r->id, 'kiosk_exit_pin', '864209'), 'blank keeps');

        $this->actingAs($owner)->post($url, ['kiosk_setup_pin_clear' => '1'])->assertRedirect();
        $this->assertSame('', (string) store_options($r->id, 'kiosk_setup_pin'));
        $this->assertTrue(KioskPin::isSet($r->id, 'kiosk_exit_pin'));
    }

    /** P6-242: new PINs need 6+ digits and must not be trivial. */
    public function test_short_or_trivial_pins_are_rejected(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $url = route('stores.kiosk.update').'?store_id='.$r->id;

        foreach (['1', '1234', '12345', 'abcdef', '111111', '123456', '654321', '121212', '123123', '890123'] as $bad) {
            $this->actingAs($owner)->post($url, ['kiosk_exit_pin' => $bad])->assertSessionHasErrors('kiosk_exit_pin');
            $this->actingAs($owner)->post($url, ['kiosk_setup_pin' => $bad])->assertSessionHasErrors('kiosk_setup_pin');
        }
        $this->assertFalse(KioskPin::isSet($r->id, 'kiosk_exit_pin'), 'nothing saved');

        $this->flushSession(); // the rejected posts above flashed errors
        $this->actingAs($owner)->post($url, ['kiosk_exit_pin' => '804613'])->assertSessionHasNoErrors();
        $this->assertTrue(KioskPin::verify($r->id, 'kiosk_exit_pin', '804613'));
        $this->assertFalse(KioskPin::isWeak($r->id, 'kiosk_exit_pin'));
    }

    /** P6-242: a PIN stored before the rules is flagged on the settings page until re-set. */
    public function test_existing_short_pin_is_flagged_on_the_settings_page(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        StoreOption::updateStoreOption($r->id, 'kiosk_exit_pin', Hash::make('12')); // legacy, pre-rules
        $index = route('stores.kiosk.index').'?store_id='.$r->id;

        $this->actingAs($owner)->get($index)->assertOk()->assertSee('data-pin-weak="kiosk_exit_pin"', false)
            ->assertDontSee('data-pin-weak="kiosk_setup_pin"', false);
        $this->assertTrue(KioskPin::verify($r->id, 'kiosk_exit_pin', '12'), 'old PIN still works');
        $this->assertTrue(KioskPin::isWeak($r->id, 'kiosk_exit_pin'), 'still weak after use');

        $this->actingAs($owner)->post(route('stores.kiosk.update').'?store_id='.$r->id, ['kiosk_exit_pin' => '804613'])->assertRedirect();
        $this->actingAs($owner)->get($index)->assertOk()->assertDontSee('data-pin-weak="kiosk_exit_pin"', false);
    }

    public function test_config_is_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.kiosk.index').'?store_id='.$otherR->id)->assertForbidden();
    }

    public function test_order_source_label_covers_kiosk(): void
    {
        $this->assertSame('Kiosk', order_source_label('kiosk'));
        $this->assertSame('Zzz', order_source_label('zzz')); // unknown → ucfirst fallback
    }
}
