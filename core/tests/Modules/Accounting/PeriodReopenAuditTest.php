<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use App\Models\ActivityLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Accounting\Services\Accounting\Gl\ChartOfAccounts;
use Juwri\Modules\Accounting\Services\Accounting\Gl\PeriodLock;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-274: reopening a closed period needs a reason, reopens every later locked
 * month and leaves an audit trail; locking writes its own audit row.
 */
class PeriodReopenAuditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        config(['settings.admin_allow_accounting_gl' => true]);
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
        ChartOfAccounts::ensure($r->id);

        return [$owner, $r];
    }

    private function lockAs(User $owner, int $rid, string $period): void
    {
        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('stores.books.period.lock').'?store_id='.$rid, [
            'period' => $period,
        ])->assertRedirect();
    }

    public function test_unlock_without_reason_is_rejected_and_leaves_lock(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->lockAs($owner, $r->id, '2026-08');

        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('stores.books.period.unlock').'?store_id='.$r->id, [
            'period' => '2026-08',
        ])->assertSessionHasErrors('reason');

        $this->assertSame('2026-08', PeriodLock::latest($r->id));
        $this->assertTrue(PeriodLock::isLocked($r->id, '2026-08-15'));
    }

    public function test_unlock_reopens_later_months_and_writes_audit(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->lockAs($owner, $r->id, '2026-08');
        $this->lockAs($owner, $r->id, '2026-09');

        $this->withoutMiddleware();
        $this->actingAs($owner)->post(route('stores.books.period.unlock').'?store_id='.$r->id, [
            'period' => '2026-08',
            'reason' => 'Late supplier invoice',
        ])->assertRedirect();

        $this->assertNull(PeriodLock::latest($r->id));
        $this->assertFalse(PeriodLock::isLocked($r->id, '2026-09-15'));

        $log = ActivityLog::where('action', 'books.period.reopen')->where('store_id', $r->id)->sole();
        $this->assertSame((int) $r->id, (int) $log->store_id);
        $this->assertSame((int) $owner->id, (int) $log->actor_user_id);
        $this->assertSame('Late supplier invoice', $log->meta['reason']);
        $this->assertSame(['2026-08', '2026-09'], $log->meta['reopened']);
        $this->assertEquals([$owner->id, $owner->id], $log->meta['was_locked_by']);
    }

    public function test_lock_writes_close_audit_row(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        $this->lockAs($owner, $r->id, '2026-08');

        $log = ActivityLog::where('action', 'books.period.close')->where('store_id', $r->id)->sole();
        $this->assertSame((int) $owner->id, (int) $log->actor_user_id);
        $this->assertSame('2026-08', $log->meta['period']);
    }

    public function test_unlock_is_tenant_scoped(): void
    {
        [$owner, $r] = $this->ownerRestaurant();
        [$other, $otherR] = $this->ownerRestaurant();
        $this->lockAs($other, $otherR->id, '2026-08');

        $this->withoutMiddleware();
        $response = $this->actingAs($owner)->post(route('stores.books.period.unlock').'?store_id='.$otherR->id, [
            'period' => '2026-08',
            'reason' => 'Not my store',
        ]);

        $this->assertContains($response->status(), [403, 404]);
        $this->assertSame('2026-08', PeriodLock::latest($otherR->id));
        $this->assertSame(0, ActivityLog::where('action', 'books.period.reopen')->count());
    }
}
