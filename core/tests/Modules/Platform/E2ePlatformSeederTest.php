<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Platform;

use App\Models\Coupon;
use App\Models\MenuCategory;
use App\Models\StoreTable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Settings\StoreSettings;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Storefront\Database\Seeders\E2ePlatformSeeder;
use Juwri\Modules\Storefront\Services\Theme\KeptTemplates;
use Tests\TestCase;

/**
 * Platform E2E (W0.3): E2ePlatformSeeder rolls up the platform demo seeders and
 * adds the display-only store (pageview; flipbook was removed in WI-03), one shared P1TEST10
 * coupon, and a demoable baseline on all 18 demo stores — idempotently.
 *
 * Runs the seeder TWICE and asserts nothing duplicates: exactly 18 demo stores
 * and exactly one P1TEST10 coupon survive the second run.
 */
class E2ePlatformSeederTest extends TestCase
{
    use RefreshDatabase;

    /** The exact 18 demo-store slugs the seeder governs (17 modern + 1 display-only). */
    private function expectedDemoSlugs(): array
    {
        $slugs = array_map(
            static fn (string $t): string => $t.'-demo',
            [...KeptTemplates::MODERN, 'pageview']
        );
        sort($slugs);

        return $slugs;
    }

    public function test_seeds_the_platform_idempotently(): void
    {
        $expected = $this->expectedDemoSlugs();
        $this->assertCount(18, $expected, 'guard: 17 modern + pageview = 18');

        // First run.
        $this->seed(E2ePlatformSeeder::class);

        $owner = User::where('email', 'showcase-demo@example.com')->first();
        $this->assertNotNull($owner, 'showcase owner exists');
        $ownerId = (int) $owner->id;

        // Snapshot store ids after the FIRST run so the second run can be proven
        // to update-in-place rather than create anew.
        $idsAfterFirst = Store::whereIn('slug', $expected)
            ->orderBy('slug')->pluck('id', 'slug')->all();

        // Second run — must not duplicate anything.
        $this->seed(E2ePlatformSeeder::class);

        /* ── Idempotency: exactly 18 demo stores, same rows, under the owner ── */
        $this->assertSame(
            18,
            Store::whereIn('slug', $expected)->count(),
            'exactly 18 demo-store ROWS exist (a duplicate would push this to 19+)'
        );
        $this->assertSame(
            18,
            Store::whereIn('slug', $expected)->where('user_id', $ownerId)->count(),
            'all 18 demo stores belong to the showcase owner'
        );

        $actualSlugs = Store::whereIn('slug', $expected)
            ->orderBy('slug')->pluck('slug')->all();
        $this->assertSame($expected, $actualSlugs, 'all 18 expected slugs present, none missing');

        // Same primary keys after the second run → updated in place, not recreated.
        $idsAfterSecond = Store::whereIn('slug', $expected)
            ->orderBy('slug')->pluck('id', 'slug')->all();
        $this->assertSame($idsAfterFirst, $idsAfterSecond, 'second run reused every store row');

        /* ── The display-only store is real, published, item-bearing ── */
        foreach (['pageview' => 'pageview-demo'] as $template => $slug) {
            $store = Store::where('slug', $slug)->first();
            $this->assertNotNull($store, "$slug exists");
            $rid = $store->id;

            $this->assertSame(1, (int) $store->catalog_visible, "$slug is published");
            $this->assertSame(
                $template,
                StoreSettings::legacy((int) $rid, 'store_template'),
                "$slug template is $template"
            );
            $this->assertSame(
                'optional',
                StoreSettings::legacy((int) $rid, 'customer_auth_mode'),
                "$slug allows guest checkout"
            );
            $this->assertSame(2, MenuCategory::where('store_id', $rid)->count(), "$slug has 2 categories");
            $this->assertSame(12, Menu::where('store_id', $rid)->count(), "$slug has 2×6 = 12 items (no duplication)");
            $this->assertTrue(
                Menu::where('store_id', $rid)->whereNotNull('image')->where('image', '!=', '')->exists(),
                "$slug items carry an image"
            );
        }

        /* ── P1TEST10: exactly one, owner-scoped, a live 10% percentage coupon ── */
        $coupons = Coupon::where('code', 'P1TEST10')->get();
        $this->assertCount(1, $coupons, 'exactly ONE P1TEST10 coupon (owner-scoped, not one-per-store-per-run)');
        $coupon = $coupons->first();
        $this->assertSame($ownerId, (int) $coupon->user_id, 'P1TEST10 is scoped to the showcase owner');
        $this->assertSame('percentage', $coupon->coupon_type, 'P1TEST10 is a percentage coupon');
        $this->assertSame(10, (int) $coupon->percentage, 'P1TEST10 is 10% off');
        $this->assertNull($coupon->branch_id, 'P1TEST10 is not branch-locked (would fail the storefront pre-flight)');

        /* ── Demoable baseline on every one of the 18 stores ── */
        foreach ($expected as $slug) {
            $rid = (int) Store::where('slug', $slug)->value('id');

            // Delivery + pickup + dine-in enabled.
            foreach ([
                'restaurant_delivery_order',
                'restaurant_takeaway_order',
                'restaurant_on_table_order',
                'allow_coupons',
            ] as $flag) {
                $this->assertSame(
                    '1',
                    StoreSettings::legacy((int) $rid, $flag),
                    "$slug: $flag is enabled"
                );
            }

            // Arabic present in the language list.
            $langs = json_decode(
                (string) StoreSettings::legacy((int) $rid, 'menu_languages'),
                true
            ) ?: [];
            $this->assertContains('ar', $langs, "$slug offers Arabic");

            // At least one table row.
            $this->assertGreaterThan(0, StoreTable::where('store_id', $rid)->count(), "$slug has a table");

            // Business hours open 24/7 → the branch reads as open right now.
            $branch = Branch::where('store_id', $rid)->first();
            $this->assertNotNull($branch, "$slug has a branch");
            $this->assertTrue($branch->isOpenNow(), "$slug branch is open 24/7");
        }
    }

    /**
     * A second, tighter guard on the coupon-duplication failure mode: even across
     * two runs the coupon ledger holds exactly one P1TEST10 for the owner.
     */
    public function test_p1test10_coupon_is_not_duplicated_across_runs(): void
    {
        $this->seed(E2ePlatformSeeder::class);
        $this->seed(E2ePlatformSeeder::class);

        $owner = User::where('email', 'showcase-demo@example.com')->firstOrFail();

        $this->assertSame(
            1,
            Coupon::where('user_id', $owner->id)->where('code', 'P1TEST10')->count(),
            'P1TEST10 stays a single owner-scoped row after two seed runs'
        );
    }
}
