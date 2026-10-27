<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Models\PhoneLine;
use Tests\Support\TestPlans;
use Tests\TestCase;

class PhoneAgentAdminTest extends TestCase
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

    public function test_save_creates_line_and_credential(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->actingAs($owner)->post(route('stores.phoneagent.save').'?store_id='.$r->id, [
            'provider' => 'twilio', 'number' => '+15551112222', 'greeting' => 'Hi', 'auth_token' => 'tok', 'orders_enabled' => '1',
        ])->assertRedirect();

        $this->assertDatabaseHas('phone_lines', ['store_id' => $r->id, 'number' => '+15551112222', 'provider' => 'twilio']);
        $this->assertSame('tok', Credential::for('twilio', $r->id)['auth_token']);
    }

    public function test_index_renders_with_calls(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        CallLog::create(['store_id' => $r->id, 'call_sid' => 'CA-x', 'provider' => 'twilio', 'from_number' => '+1', 'outcome' => 'order', 'transcript' => []]);
        $this->actingAs($owner)->get(route('stores.phoneagent.index').'?store_id='.$r->id)->assertOk();
    }

    public function test_toggle_is_tenant_scoped(): void
    {
        [$ownerA, $rA] = $this->ownerRestaurant();
        [, $rB] = $this->ownerRestaurant();
        $foreign = PhoneLine::create(['store_id' => $rB->id, 'provider' => 'twilio', 'number' => '+1999', 'active' => true]);
        $this->actingAs($ownerA)->post(route('stores.phoneagent.toggle', $foreign->id))->assertNotFound();
        $this->assertTrue((bool) $foreign->fresh()->active);
    }

    public function test_call_transcript_endpoint_tenant_scoped(): void
    {
        [$ownerA, $rA] = $this->ownerRestaurant();
        [, $rB] = $this->ownerRestaurant();
        $foreign = CallLog::create(['store_id' => $rB->id, 'call_sid' => 'CA-y', 'provider' => 'twilio', 'outcome' => 'order', 'transcript' => [['role' => 'caller', 'text' => 'hi']]]);
        $this->actingAs($ownerA)->getJson(route('stores.phoneagent.call', $foreign->id))->assertNotFound();
    }
}
