<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hr;

use App\Http\Middleware\Installed;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Hr\Models\StaffShift;
use Juwri\Modules\Hr\Services\Rota\RotaService;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B7-P2: rota owner UI + staff PWA "my shifts".
 */
class RotaUiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_staff_rota' => true]);
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

    private function staff(User $owner, Store $r): StaffMember
    {
        return StaffMember::create([
            'owner_id' => $owner->id, 'store_id' => $r->id, 'name' => 'Sam', 'role' => 'waiter',
            'email' => 'staff'.Str::random(5).'@ex.com', 'password' => Hash::make('secret'), 'active' => 1,
        ]);
    }

    public function test_owner_page_renders_with_nav(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->withoutMiddleware();
        $res = $this->actingAs($owner)->get(route('stores.rota.index').'?store_id='.$r->id)->assertOk();
        $res->assertSee(___('Add shift'));
        $res->assertSeeHtml('rota'); // nav URL
    }

    public function test_store_creates_shift_and_rejects_cross_tenant_staff(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $mine = $this->staff($owner, $r);
        $foreign = $this->staff($other, $otherR);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.rota.store').'?store_id='.$r->id, [
            'staff_id' => $mine->id, 'starts_at' => '2026-07-13 09:00', 'ends_at' => '2026-07-13 17:00',
        ])->assertRedirect();
        $this->assertDatabaseHas('staff_shifts', ['store_id' => $r->id, 'staff_id' => $mine->id]);

        // Foreign staff on my restaurant → 404.
        $this->actingAs($owner)->post(route('stores.rota.store').'?store_id='.$r->id, [
            'staff_id' => $foreign->id, 'starts_at' => '2026-07-14 09:00', 'ends_at' => '2026-07-14 17:00',
        ])->assertNotFound();
    }

    public function test_publish_marks_week_shifts(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $mine = $this->staff($owner, $r);
        new RotaService()->createShift(['store_id' => $r->id, 'staff_id' => $mine->id, 'starts_at' => '2026-07-13 09:00', 'ends_at' => '2026-07-13 17:00']);
        $this->withoutMiddleware();

        $this->actingAs($owner)->post(route('stores.rota.publish').'?store_id='.$r->id, ['week' => '2026-07-13'])->assertRedirect();
        $this->assertTrue(StaffShift::where('store_id', $r->id)->first()->published);
    }

    public function test_payroll_page_and_csv_render(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->staff($owner, $r);
        $this->withoutMiddleware();

        $this->actingAs($owner)->get(route('stores.rota.payroll').'?store_id='.$r->id)
            ->assertOk()->assertSee('Scheduled');

        $res = $this->actingAs($owner)->get(route('stores.rota.payroll.csv').'?store_id='.$r->id.'&from=2026-07-01&to=2026-07-31')->assertOk();
        $this->assertStringContainsString('text/csv', $res->headers->get('content-type'));
    }

    public function test_staff_sees_only_published_shifts(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $staff = $this->staff($owner, $r);
        $svc = new RotaService;
        $svc->createShift(['store_id' => $r->id, 'staff_id' => $staff->id, 'starts_at' => now()->addDay()->format('Y-m-d 09:00'), 'ends_at' => now()->addDay()->format('Y-m-d 17:00'), 'published' => true]);
        $svc->createShift(['store_id' => $r->id, 'staff_id' => $staff->id, 'starts_at' => now()->addDays(2)->format('Y-m-d 09:00'), 'ends_at' => now()->addDays(2)->format('Y-m-d 17:00'), 'published' => false]);
        $this->withoutMiddleware(Installed::class);

        $res = $this->actingAs($staff, 'staff')->getJson('/staff-app/shifts')->assertOk();
        $res->assertJsonCount(1, 'shifts');
    }
}
