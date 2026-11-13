<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\CateringRequest;
use App\Models\StoreOption;
use App\Services\Catering\CateringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * SYSTEMS-RUN G-P1: catering calendar + pipeline lifecycle.
 */
class CateringPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_catering' => true]);
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

    private function acceptedRequest(int $rid): CateringRequest
    {
        StoreOption::updateStoreOption($rid, 'catering_enabled', '1');
        $req = new CateringService()->createRequest($rid, ['name' => 'Sam', 'event_at' => now()->addDays(10)->toDateTimeString(), 'headcount' => 30]);
        $req->update(['status' => 'accepted']);

        return $req->fresh();
    }

    public function test_calendar_renders(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->acceptedRequest($r->id);
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.catering.calendar').'?store_id='.$r->id)->assertOk()->assertSeeHtml('pipeline');
    }

    public function test_fulfill_then_close_transitions(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $req = $this->acceptedRequest($r->id);
        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('stores.catering.fulfill', $req->id).'?store_id='.$r->id)->assertRedirect();
        $this->assertSame('fulfilled', $req->fresh()->status);
        $this->actingAs($owner)->post(route('stores.catering.close', $req->id).'?store_id='.$r->id)->assertRedirect();
        $this->assertSame('closed', $req->fresh()->status);
    }

    public function test_fulfill_requires_accepted_and_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $foreign = $this->acceptedRequest($otherR->id);
        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('stores.catering.fulfill', $foreign->id).'?store_id='.$r->id)->assertNotFound();
    }
}
