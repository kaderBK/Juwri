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
use Juwri\Modules\Menu\Models\Menu;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B8-P2: catering owner UI + public request submission.
 */
class CateringUiTest extends TestCase
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
        StoreOption::updateStoreOption($r->id, 'catering_enabled', '1');

        return [$owner, $r];
    }

    public function test_public_submit_creates_request(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();

        $this->post('/'.$r->slug.'/catering', [
            'name' => 'Sam', 'phone' => '123', 'event_at' => now()->addDays(20)->toDateTimeString(), 'headcount' => 30,
            'message' => 'Wedding',
        ])->assertRedirect();

        $this->assertSame(1, CateringRequest::where('store_id', $r->id)->count());
    }

    public function test_owner_index_renders_with_nav(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $res = $this->actingAs($owner)->get(route('stores.catering.index').'?store_id='.$r->id)->assertOk();
        $res->assertSeeHtml('catering');
    }

    public function test_quote_then_accept_creates_order(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        Menu::create(['store_id' => $r->id, 'name' => 'Platter', 'price' => 50, 'active' => 1]);
        $req = new CateringService()->createRequest($r->id, [
            'name' => 'Sam', 'event_at' => now()->addDays(20)->toDateTimeString(), 'headcount' => 30,
        ]);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.catering.quote', $req->id).'?store_id='.$r->id, [
            'quote_total' => '500',
        ])->assertRedirect();
        $this->assertSame('quoted', $req->fresh()->status);

        $this->actingAs($owner)->post(route('stores.catering.accept', $req->id).'?store_id='.$r->id)->assertRedirect();
        $this->assertSame('accepted', $req->fresh()->status);
        $this->assertNotNull($req->fresh()->order_id);
    }

    public function test_decline_and_tenant_scope(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $foreign = new CateringService()->createRequest($otherR->id, [
            'name' => 'X', 'event_at' => now()->addDays(20)->toDateTimeString(), 'headcount' => 5,
        ]);
        $this->withoutMiddleware();

        // Owner cannot decline another tenant's request.
        $this->actingAs($owner)->post(route('stores.catering.decline', $foreign->id).'?store_id='.$r->id)->assertNotFound();
    }

    /** C4: the catering index surfaces the deposit pay link (after accept) + a per-request badge. */
    public function test_catering_index_surfaces_deposit_link_and_badge(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        StoreOption::updateStoreOption($r->id, 'catering_deposit_enabled', '1');
        StoreOption::updateStoreOption($r->id, 'catering_deposit_amount', 75);
        $svc = new CateringService;
        $req = $svc->createRequest($r->id, ['name' => 'Sam', 'event_at' => now()->addDays(20)->toDateTimeString(), 'headcount' => 30]);
        $svc->armDeposit($req); // deposit_status=pending + token
        $this->withoutMiddleware();

        // The request row shows the deposit badge (pending + amount).
        $html = $this->actingAs($owner)
            ->get(route('stores.catering.index').'?store_id='.$r->id)
            ->assertOk()->getContent();
        $this->assertStringContainsString('data-deposit-badge', $html, 'the per-request deposit badge renders');
        $this->assertStringContainsString('75', $html, 'the deposit amount shows');

        // The flashed pay link (set by accept) renders as a copyable field carrying the token.
        $token = $req->fresh()->token;
        $link = route('publicView.catering.deposit', ['slug' => $r->slug, 'token' => $token]);
        $html2 = $this->actingAs($owner)
            ->withSession(['catering_deposit_link' => $link])
            ->get(route('stores.catering.index').'?store_id='.$r->id)
            ->assertOk()->getContent();
        $this->assertStringContainsString('data-catering-deposit-link', $html2, 'the copyable deposit link renders');
        $this->assertStringContainsString($token, $html2, 'the link carries the deposit token');
    }
}
