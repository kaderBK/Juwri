<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Coupon;
use App\Models\Customer;
use App\Models\StoreOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Juwri\Core\Models\Store;

/**
 * Platform E2E v2 — Lane-A depth fixtures.
 *
 * Wave-0's E2ePlatformV2Seeder deliberately leaves the deep per-feature fixtures
 * to each lane (see the LEDGER heartbeat: "each lane adds its own depth"). This
 * seeder adds the store-config the Lane-A coverage journeys (A1/A9 tax +
 * multi-currency) need on top of the showcase baseline, WITHOUT touching any
 * app-wide setting:
 *
 *   • crave-demo (the A-lane order fixture, owner 26, 104 items, P1TEST10 coupon)
 *     gets a real 15% percentage tax + a second display currency (EUR @ 0.90
 *     manual) so the tax-pre-discount invariant and the currency switcher are
 *     actually exercisable. The base showcase leaves tax at 0, which makes the
 *     "tax computed on the PRE-discount subtotal" assertion vacuous.
 *
 * Idempotent: every write is an updateOrCreate on (store_id, option_name), so
 * re-running is a no-op. Store-scoped only — no config() / global settings are
 * touched, honouring the "no app-wide setting changes" rule.
 *
 * Run once per Lane-A DB AFTER E2ePlatformSeeder + E2ePlatformV2Seeder:
 *   php artisan db:seed --class=E2eLaneAFixturesSeeder --force
 */
class E2eLaneAFixturesSeeder extends Seeder
{
    /**
     * The A-lane order fixture stores — one per storefront ENGINE family so A1
     * proves en/LTR checkout on three genuinely different cart engines:
     *   crave = the "cv" SPA, aurora = the bottom-sheet engine, feast = the
     *   form-in-drawer engine. Tax makes the tax-pre-discount invariant real on
     *   all three; the second display currency is only needed on crave (A9).
     */
    private const TAX_STORES = ['crave-demo', 'aurora-demo', 'feast-demo'];

    private const CURRENCY_STORE = 'crave-demo';

    /** crave-demo is the attacker's home store. */
    private const ORDER_STORE_SLUG_ATTACKER = 'crave-demo';

    /**
     * A2 — the ATTACKER customer: a legitimate signed-in customer of crave-demo
     * (tenant-A). The IDOR journey logs in as this customer and proves it cannot
     * reach tenant-B's victim order/address (E2ePlatformV2Seeder's TENANT_B_*).
     */
    public const ATTACKER_CUSTOMER_EMAIL = 'e2e-a-attacker@example.test';

    public const ATTACKER_CUSTOMER_PASS = 'E2eAttackerA123';

    /**
     * A5 — a SINGLE-USE coupon on crave-demo's owner. The concurrency journey
     * fires N parallel orders all applying it and asserts exactly one claims it
     * (used → 1, one order gets coupon_id) — the atomic-claim invariant. `used`
     * is force-reset to 0 on every run so the race is repeatable.
     */
    public const ONCE_COUPON_CODE = 'E2EONCE1';

    public function run(): void
    {
        $taxOpts = [
            // A1/A9 — a real percentage tax so grand = subtotal − discount + tax is
            // non-trivial and the tax base (PRE-discount subtotal) is observable.
            'restaurant_taxx_charge' => '15',
            'restaurant_tax_type' => 'percentage',
            'restaurant_tax_label' => 'VAT',
            // Make sure the coupon box is offered (A1 applies P1TEST10).
            'allow_coupons' => '1',
            // A10 — enable the customer reservation + waitlist surfaces (store-
            // scoped options; waitlist is already on across the demo baseline).
            'enable_reservations' => '1',
            'enable_waitlist' => '1',
        ];

        $done = [];
        foreach (self::TAX_STORES as $slug) {
            $store = Store::query()->where('slug', $slug)->first();
            if (! $store) {
                $this->command?->warn("E2eLaneAFixturesSeeder: {$slug} not found — run E2ePlatformSeeder first.");

                continue;
            }
            $opts = $taxOpts;
            if ($slug === self::CURRENCY_STORE) {
                // A9 — a second display currency with a fixed manual rate so the
                // storefront currency switcher and per-currency totals are testable.
                $opts['display_currencies'] = 'EUR';
                $opts['currency_rates_mode'] = 'manual';
                $opts['currency_rates_manual'] = 'EUR = 0.90';
            }
            foreach ($opts as $name => $value) {
                StoreOption::updateStoreOption($store->id, $name, $value);
            }
            $done[] = "{$slug}(#{$store->id})";
        }

        // A2 — attacker customer on crave-demo (tenant-A).
        $craveId = null;
        $crave = Store::query()->where('slug', self::ORDER_STORE_SLUG_ATTACKER)->first();
        if ($crave) {
            $craveId = $crave->id;
            Customer::firstOrCreate(
                ['email' => self::ATTACKER_CUSTOMER_EMAIL],
                [
                    'store_id' => $crave->id,
                    'name' => 'E2E Attacker A',
                    'phone' => '966500000111',
                    'password' => Hash::make(self::ATTACKER_CUSTOMER_PASS),
                ]
            );
        }

        // A5 — single-use coupon on crave-demo's owner (reset used=0 so the race
        // is repeatable). Owner-scoped like P1TEST10, not branch-locked.
        if ($crave) {
            $coupon = Coupon::firstOrCreate(
                ['user_id' => $crave->user_id, 'code' => self::ONCE_COUPON_CODE],
                ['percentage' => 10, 'coupon_type' => 'percentage', 'branch_id' => null, 'applies_to' => 'all', 'limit' => 1, 'used' => 0, 'expiry_at' => null]
            );
            $coupon->forceFill([
                'percentage' => 10, 'coupon_type' => 'percentage', 'branch_id' => null,
                'applies_to' => 'all', 'limit' => 1, 'used' => 0, 'expiry_at' => null, 'per_customer_limit' => null,
            ])->save();
        }

        $this->command?->info(
            'E2eLaneAFixturesSeeder: 15% VAT on '.implode(', ', $done).'; EUR@0.90 on '.self::CURRENCY_STORE
            .'; attacker customer '.self::ATTACKER_CUSTOMER_EMAIL.($craveId ? " on #{$craveId}" : ' (store missing)')
            .'; single-use coupon '.self::ONCE_COUPON_CODE.'.'
        );
    }
}
