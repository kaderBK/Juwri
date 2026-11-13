<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Hotel;

use Juwri\Modules\Payments\Models\Transaction;
use App\Models\Upgrade;
use Juwri\Core\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Hourly / daily housekeeping crons (scheduled in Console\Kernel).
 *
 * Hourly: abandoned checkouts (transactions with no status, older than 3 hours)
 * are purged; an upgrade whose expiry timestamp has passed drops its user back
 * to the free group and is deleted. Daily: the sitemap caches are dropped so
 * the next request rebuilds them.
 */
class HousekeepingCronTest extends TestCase
{
    use RefreshDatabase;

    private const HOURLY = 'cron:hourly';

    private const DAILY = 'cron:daily';

    private function transaction(User $user, ?string $status, int $hoursAgo): Transaction
    {
        $tx = Transaction::create([
            'product_name' => 'Plan', 'product_id' => 1, 'user_id' => $user->id,
            'status' => $status, 'amount' => 10, 'currency_code' => 'USD',
        ]);
        $tx->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();

        return $tx;
    }

    public function test_hourly_purges_only_stale_statusless_transactions(): void
    {
        $user = User::factory()->create();
        $stale = $this->transaction($user, null, 4);
        $fresh = $this->transaction($user, null, 1);
        $paid = $this->transaction($user, 'success', 10);

        $this->artisan(self::HOURLY)->assertExitCode(0);

        $this->assertModelMissing($stale);
        $this->assertModelExists($fresh);
        $this->assertModelExists($paid);
    }

    public function test_hourly_expires_lapsed_upgrades_to_the_free_group(): void
    {
        $this->requiresEditionPackage('behaviour of an edition package (plans, billing, reseller or marketplace)');
        $lapsed = User::factory()->create(['group_id' => 'pro']);
        $active = User::factory()->create(['group_id' => 'pro']);
        $gone = Upgrade::create(['user_id' => $lapsed->id, 'upgrade_expires' => now()->subMinute()->timestamp]);
        $kept = Upgrade::create(['user_id' => $active->id, 'upgrade_expires' => now()->addDay()->timestamp]);

        $this->artisan(self::HOURLY)->assertExitCode(0);

        $this->assertSame('free', (string) $lapsed->fresh()->group_id);
        $this->assertSame('pro', (string) $active->fresh()->group_id);
        $this->assertModelMissing($gone);
        $this->assertModelExists($kept);
    }

    public function test_daily_drops_the_section_sitemap_caches(): void
    {
        foreach (['marketing', 'blog', 'restaurants'] as $section) {
            Cache::put('sitemap.'.$section, '<xml/>', 3600);
        }

        $this->artisan(self::DAILY)->assertExitCode(0);

        foreach (['marketing', 'blog', 'restaurants'] as $section) {
            $this->assertFalse(Cache::has('sitemap.'.$section), "sitemap.{$section} not refreshed");
        }
    }
}
