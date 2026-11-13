<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\PhoneLine;
use Tests\Support\TestPlans;
use Tests\TestCase;

class PhoneAgentLanguageUiTest extends TestCase
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
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
        $this->withoutMiddleware();
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

    public function test_update_saves_then_clears_language(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $line = PhoneLine::create(['store_id' => $r->id, 'provider' => 'twilio', 'number' => '+15550001001', 'active' => true]);

        $this->actingAs($owner)
            ->post(route('stores.phoneagent.update', $line->id), ['language' => 'ar'])
            ->assertRedirect();
        $this->assertSame('ar', $line->fresh()->language);

        $this->actingAs($owner)
            ->post(route('stores.phoneagent.update', $line->id), ['language' => ''])
            ->assertRedirect();
        $this->assertNull($line->fresh()->language);
    }

    public function test_update_rejects_invalid_language(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $line = PhoneLine::create(['store_id' => $r->id, 'provider' => 'twilio', 'number' => '+15550001002', 'active' => true, 'language' => 'ar']);

        $this->actingAs($owner)
            ->post(route('stores.phoneagent.update', $line->id), ['language' => 'fr'])
            ->assertSessionHasErrors('language');

        $this->assertSame('ar', $line->fresh()->language);
    }

    public function test_update_on_another_owners_line_is_404(): void
    {
        [$ownerA] = $this->ownerRestaurant();
        [, $rB] = $this->ownerRestaurant();
        $foreign = PhoneLine::create(['store_id' => $rB->id, 'provider' => 'twilio', 'number' => '+15550001003', 'active' => true]);

        $this->actingAs($ownerA)
            ->post(route('stores.phoneagent.update', $foreign->id), ['language' => 'ar'])
            ->assertNotFound();

        $this->assertNull($foreign->fresh()->language);
    }

    public function test_save_stores_language(): void
    {
        [$owner, $r] = $this->ownerRestaurant();

        $this->actingAs($owner)->post(route('stores.phoneagent.save').'?store_id='.$r->id, [
            'provider' => 'twilio', 'number' => '+15550001004', 'language' => 'en',
        ])->assertRedirect();

        $this->assertSame('en', PhoneLine::where('store_id', $r->id)->where('number', '+15550001004')->first()->language);
    }

    public function test_index_renders_arabic_language_label_and_rtl(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        Language::updateOrCreate(['code' => 'ar'], ['name' => 'Arabic', 'direction' => 'rtl', 'active' => 1]);
        app()->setLocale('ar');

        $this->actingAs($owner)
            ->get(route('stores.phoneagent.index').'?store_id='.$r->id)
            ->assertOk()
            ->assertSee(trans('lang.call_language', [], 'ar'))
            ->assertSeeHtml('dir="rtl"');
    }
}
