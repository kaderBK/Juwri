<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\AppStore;

use App\Http\Middleware\Installed;
use App\Models\App;
use App\Models\AppInstallation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\User;
use Tests\TestCase;

/** Owner 10-29: apps looked free — the 2-day trial was never shown. */
class AppTrialWordingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    public function test_has_trial_only_for_paid_recurring_apps_with_trial_days(): void
    {
        $this->assertTrue((new App(['billing_type' => 'recurring', 'price' => 2.99, 'trial_days' => 2]))->hasTrial());
        $this->assertFalse((new App(['billing_type' => 'recurring', 'price' => 2.99, 'trial_days' => 0]))->hasTrial());
        $this->assertFalse((new App(['billing_type' => 'recurring', 'price' => 0, 'trial_days' => 2]))->hasTrial());
        $this->assertFalse((new App(['billing_type' => 'one_time', 'price' => 2.99, 'trial_days' => 2]))->hasTrial());
    }

    public function test_trial_strings_exist_in_every_locale(): void
    {
        $en = require base_path('lang/en/lang.php');
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $l) {
            $tr = require base_path("lang/$l/lang.php");
            foreach ([
                'days_day_free_trial',
                'free_for_days_days_then_price',
                'installed_free_trial_until_date',
                'your_wallet_is_charged_when_the_trial_ends_uninstall_before_then_and_you_pay_nothing',
            ] as $k) {
                $this->assertArrayHasKey($k, $tr, "$l $k");
                $this->assertNotSame($en[$k], $tr[$k], "$l $k identical to en");
                if (str_contains($en[$k], ':')) {
                    $this->assertStringContainsString(':', $tr[$k], "$l $k lost placeholder");
                }
            }
        }
    }

    public function test_browse_cards_and_detail_page_show_trial_only_when_eligible(): void
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $trialApp = App::create([
            'name' => 'Trial Pro',
            'slug' => 'trial-pro',
            'scopes' => ['menu:read'],
            'status' => 'approved',
            'category' => 'marketing',
            'tagline' => 'Automated campaigns',
            'billing_type' => 'recurring',
            'price' => 3.99,
            'billing_period_days' => 30,
            'trial_days' => 2,
        ]);

        $this->actingAs($user)->withSession(['locale' => 'en'])
            ->get(route('user.store.apps'))
            ->assertOk()
            ->assertSee('2-day free trial');

        $this->actingAs($user)->withSession(['locale' => 'en'])
            ->get(route('user.store.apps.show', $trialApp->slug))
            ->assertOk()
            ->assertViewHas('trialEligible', true)
            ->assertSee('Free for 2 days, then')
            ->assertSee('Your wallet is charged when the trial ends. Uninstall before then and you pay nothing.');

        // Prior installation (even uninstalled) disqualifies from a second trial.
        AppInstallation::create([
            'app_id' => $trialApp->id,
            'user_id' => $user->id,
            'granted_scopes' => ['menu:read'],
            'status' => 'uninstalled',
            'installed_at' => now()->subDays(10),
            'uninstalled_at' => now()->subDays(5),
        ]);

        $this->actingAs($user)->withSession(['locale' => 'en'])
            ->get(route('user.store.apps.show', $trialApp->slug))
            ->assertOk()
            ->assertViewHas('trialEligible', false)
            ->assertDontSee('Free for 2 days, then')
            ->assertSee('Paid app — charges apply per its pricing once installed.');
    }
}
