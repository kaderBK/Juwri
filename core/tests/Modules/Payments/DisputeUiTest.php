<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Models\Dispute;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B11-P1: disputes owner list UI.
 */
class DisputeUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_disputes' => true]);
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

    public function test_index_lists_disputes_and_is_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        Dispute::create(['store_id' => $r->id, 'gateway' => 'stripe', 'external_id' => 'dp_mine', 'amount' => 25, 'currency' => 'USD', 'status' => 'needs_response', 'evidence_due_at' => now()->addDays(5), 'opened_at' => now()]);
        Dispute::create(['store_id' => $otherR->id, 'gateway' => 'stripe', 'external_id' => 'dp_theirs', 'amount' => 99, 'currency' => 'USD', 'status' => 'needs_response']);
        $this->withoutMiddleware();

        $res = $this->actingAs($owner)->get(route('stores.disputes.index').'?store_id='.$r->id)->assertOk();
        $res->assertSeeHtml('dp_mine');       // deep-link uses external id
        $res->assertDontSeeHtml('dp_theirs');  // tenant isolation
        $res->assertSeeHtml('disputes');       // nav URL

        // Cross-tenant restaurant id → 403.
        $this->actingAs($owner)->get(route('stores.disputes.index').'?store_id='.$otherR->id)->assertForbidden();
    }
}
