<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Agents;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Agents\Models\CallLog;
use Juwri\Modules\Agents\Services\Telephony\VoiceCostLedger;
use Tests\Support\TestPlans;
use Tests\TestCase;

class PhoneAgentMinutesCardTest extends TestCase
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

    private function recordMinutes(Store $r, int $seconds): void
    {
        $log = CallLog::create([
            'store_id' => $r->id, 'call_sid' => 'CA-'.Str::upper(Str::random(10)), 'provider' => 'twilio',
            'from_number' => '+15550009999', 'outcome' => 'order', 'transcript' => [], 'duration_seconds' => $seconds,
        ]);
        VoiceCostLedger::recordCall($log);
    }

    public function test_minutes_card_shows_used_included_and_overage_in_english(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->recordMinutes($r, 601 * 60);

        $usage = VoiceCostLedger::monthUsage((int) $r->id);

        $this->actingAs($owner)
            ->get(route('stores.phoneagent.index').'?store_id='.$r->id)
            ->assertOk()
            ->assertSee('Minutes used this month')
            ->assertSee((string) $usage['minutes'])
            ->assertSee((string) $usage['included'])
            ->assertSee(number_format($usage['overage_charge'], 2));
    }

    public function test_minutes_card_shows_arabic_label_and_same_numbers(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->recordMinutes($r, 601 * 60);

        app()->setLocale('ar');
        $label = (require lang_path('ar/lang.php'))['minutes_used_this_month'];
        $this->assertNotSame('Minutes used this month', $label);

        $usage = VoiceCostLedger::monthUsage((int) $r->id);

        $this->actingAs($owner)
            ->get(route('stores.phoneagent.index').'?store_id='.$r->id)
            ->assertOk()
            ->assertSee($label)
            ->assertSee((string) $usage['minutes'])
            ->assertSee((string) $usage['included'])
            ->assertSee(number_format($usage['overage_charge'], 2));
    }

    public function test_minutes_card_shows_zero_usage_for_a_store_with_no_calls(): void
    {
        [$owner, $r] = $this->ownerRestaurant();

        $usage = VoiceCostLedger::monthUsage((int) $r->id);

        $this->actingAs($owner)
            ->get(route('stores.phoneagent.index').'?store_id='.$r->id)
            ->assertOk()
            ->assertSee((string) $usage['minutes'])
            ->assertSee((string) $usage['included']);
    }
}
