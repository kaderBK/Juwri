<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Tests\Support\TestPlans;
use Tests\TestCase;

class PhoneAgentSettingsTest extends TestCase
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

    public function test_update_saves_settings(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $line = PhoneLine::create(['store_id' => $r->id, 'provider' => 'twilio', 'number' => '+15550001111', 'active' => true]);

        $this->actingAs($owner)->post(route('stores.phoneagent.update', $line->id), [
            'greeting' => 'Hello there',
            'active_hours' => '09:00-23:00',
            'handoff_number' => '+15559998888',
            'orders_enabled' => '1',
            'monthly_minute_cap' => '500',
            'recording_enabled' => '1',
            'recording_region' => 'ksa',
            'link_pay_only' => '1',
        ])->assertRedirect();

        $line->refresh();
        $this->assertSame('Hello there', $line->greeting);
        $this->assertSame('09:00-23:00', $line->active_hours);
        $this->assertSame('+15559998888', $line->handoff_number);
        $this->assertTrue((bool) $line->orders_enabled);
        $this->assertSame(500, (int) $line->monthly_minute_cap);
        $this->assertTrue((bool) $line->recording_enabled);
        $this->assertSame('ksa', $line->recording_region);
        $this->assertTrue((bool) $line->link_pay_only);
    }

    public function test_update_unchecked_booleans_set_false(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $line = PhoneLine::create([
            'store_id' => $r->id, 'provider' => 'twilio', 'number' => '+15550002222', 'active' => true,
            'orders_enabled' => true, 'recording_enabled' => true, 'recording_region' => 'eu', 'link_pay_only' => true,
        ]);

        $this->actingAs($owner)->post(route('stores.phoneagent.update', $line->id), [
            'greeting' => 'Hi',
        ])->assertRedirect();

        $line->refresh();
        $this->assertTrue((bool) $line->active);
        $this->assertFalse((bool) $line->orders_enabled);
        $this->assertFalse((bool) $line->recording_enabled);
        $this->assertFalse((bool) $line->link_pay_only);
    }

    public function test_update_on_another_owners_line_is_404(): void
    {
        [$ownerA] = $this->ownerRestaurant();
        [, $rB] = $this->ownerRestaurant();
        $foreign = PhoneLine::create(['store_id' => $rB->id, 'provider' => 'twilio', 'number' => '+15550003333', 'active' => true]);

        $this->actingAs($ownerA)->post(route('stores.phoneagent.update', $foreign->id), [
            'greeting' => 'Nope',
        ])->assertNotFound();

        $this->assertNull($foreign->fresh()->greeting);
    }

    public function test_recording_without_region_fails_validation(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $line = PhoneLine::create(['store_id' => $r->id, 'provider' => 'twilio', 'number' => '+15550004444', 'active' => true]);

        $this->actingAs($owner)
            ->post(route('stores.phoneagent.update', $line->id), ['recording_enabled' => '1'])
            ->assertSessionHasErrors('recording_region');

        $this->assertFalse((bool) $line->fresh()->recording_enabled);
    }

    public function test_calls_index_lists_only_own_calls(): void
    {
        [$ownerA, $rA] = $this->ownerRestaurant();
        [, $rB] = $this->ownerRestaurant();
        CallLog::create(['store_id' => $rA->id, 'call_sid' => 'CA-own', 'provider' => 'twilio', 'from_number' => '+1111', 'outcome' => 'order', 'transcript' => []]);
        CallLog::create(['store_id' => $rB->id, 'call_sid' => 'CA-foreign', 'provider' => 'twilio', 'from_number' => '+2222', 'outcome' => 'order', 'transcript' => []]);

        $this->actingAs($ownerA)
            ->get(route('stores.phoneagent.calls.index').'?store_id='.$rA->id)
            ->assertOk()
            ->assertSee('+1111')
            ->assertDontSee('+2222');
    }

    public function test_calls_show_of_foreign_call_is_404(): void
    {
        [$ownerA] = $this->ownerRestaurant();
        [, $rB] = $this->ownerRestaurant();
        $foreign = CallLog::create(['store_id' => $rB->id, 'call_sid' => 'CA-x', 'provider' => 'twilio', 'from_number' => '+1', 'outcome' => 'order', 'transcript' => []]);

        $this->actingAs($ownerA)->get(route('stores.phoneagent.calls.show', $foreign->id))->assertNotFound();
    }

    public function test_show_renders_transcript_text(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $call = CallLog::create([
            'store_id' => $r->id, 'call_sid' => 'CA-t', 'provider' => 'twilio', 'from_number' => '+15550005555',
            'outcome' => 'order', 'duration_seconds' => 42,
            'transcript' => [
                ['role' => 'caller', 'text' => 'I would like a large pizza'],
                ['role' => 'agent', 'text' => 'Sure, anything else?'],
                ['role' => 'system', 'text' => 'handoff:secret-marker'],
            ],
        ]);

        $this->actingAs($owner)
            ->get(route('stores.phoneagent.calls.show', $call->id).'?store_id='.$r->id)
            ->assertOk()
            ->assertSee('I would like a large pizza')
            ->assertSee('Sure, anything else?')
            ->assertDontSee('secret-marker');
    }
}
