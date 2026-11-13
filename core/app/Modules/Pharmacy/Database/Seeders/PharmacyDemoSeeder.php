<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Pharmacy\Database\Seeders;

use App\Models\MenuCategory;
use App\Models\StoreOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Pharmacy\Models\PharmacyLicence;

/**
 * rev/multi-vertical Lane F4 — a demo OTC (over-the-counter) pharmacy store,
 * DEV ONLY, for design-QA/e2e (DECISIONS A11). OTC-ONLY (D3): every item
 * below is a plain retail product (pain relief, vitamins, first-aid) with an
 * age-gate flag and/or a batch/expiry pair — nothing here is, or resembles, a
 * prescription/Rx SKU. See PharmacyOtcGuardrailTest (F4) for the automated
 * check that keeps it that way.
 *
 * Never touches demo account #10008 ([theme-features-audit-pack]) — this is a
 * brand-new throwaway plan/owner/store, keyed on its own natural keys via
 * updateOrCreate/firstOrCreate so re-running is a no-op (idempotent, mirrors
 * HotelDemoSeeder). NOT registered in DatabaseSeeder's default chain — run it
 * explicitly:
 *
 *   php artisan db:seed --class=PharmacyDemoSeeder
 */
class PharmacyDemoSeeder extends Seeder
{
    private const PLAN_NAME = 'ZZ Pharmacy OTC Demo Throwaway Plan (seeder)';

    private const SLUG = 'pharmacy-otc-demo';

    private const OWNER_EMAIL = 'pharmacy-demo-owner@demo.test';

    private const OWNER_PASS = 'password';

    public function run(): void
    {
        // P1-13: plans are Billing (ee) — written by Billing's DemoThrowawayPlanSeeder when it is present; in Community
        // the owner runs on the edition plan.
        if ($planSeeder = ModuleSeeders::find('DemoThrowawayPlanSeeder')) {
            $this->call($planSeeder, true, [
                'name' => self::PLAN_NAME,
                'description' => 'Throwaway plan created by PharmacyDemoSeeder — do not sell.',
                'settings' => [
                    'restaurant_limit' => 5,
                    'menu_limit' => 999,
                    'category_limit' => 999,
                    'allow_ordering' => '1',
                ],
            ]);
        }
        $planId = app(PlanCatalog::class)->plans()->firstWhere('name', self::PLAN_NAME)?->getKey();

        $owner = User::updateOrCreate(
            ['email' => self::OWNER_EMAIL],
            [
                'username' => 'pharmacydemo',
                'name' => 'Pharmacy Demo Owner',
                'password' => Hash::make(self::OWNER_PASS),
                'group_id' => $planId !== null ? (string) $planId : 'free',
                'status' => 1,
                'email_verified_at' => now(),
            ]
        );

        // F0-6: non-restaurant creation is gated behind the `multi_vertical`
        // entitlement — grant it directly to this demo owner (mirrors how
        // VerticalEntitlementGateTest/VerticalOnboardingPickerTest grant it),
        // since this seeder writes the store directly rather than going
        // through StoreController::store()'s HTTP subject-gate.
        EntitlementOverride::updateOrCreate(
            ['user_id' => $owner->id, 'feature_key' => 'multi_vertical'],
            ['value' => '1', 'source_type' => 'admin']
        );

        $store = Store::updateOrCreate(
            ['slug' => self::SLUG],
            [
                'user_id' => $owner->id,
                'store_type' => 'pharmacy',
                'color' => '#0f766e',
                'title' => 'Wellness Corner Pharmacy (OTC Demo)',
                'sub_title' => 'Over-the-counter essentials',
                'description' => 'Demo OTC pharmacy store — over-the-counter products only, no prescriptions.',
                'main_image' => 'default.png',
                'cover_image' => 'default.png',
                'address' => '1 Demo Street',
                'phone' => '5550001234',
                'created_at' => now(),
                'updated_at' => now(),
            ]
        );

        StoreOption::updateStoreOption($store->id, 'store_template', 'shelf');

        $painRelief = MenuCategory::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Pain Relief'],
            ['position' => 1, 'active' => 1]
        );
        $vitamins = MenuCategory::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Vitamins & Supplements'],
            ['position' => 2, 'active' => 1]
        );
        $firstAid = MenuCategory::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'First Aid'],
            ['position' => 3, 'active' => 1]
        );
        $coughCold = MenuCategory::firstOrCreate(
            ['store_id' => $store->id, 'name' => 'Cough & Cold'],
            ['position' => 4, 'active' => 1]
        );

        // Plain OTC products only. `age_restricted` / `batch_no` / `expiry_date`
        // are the ENTIRE pharmacy-specific data model this pack adds — no
        // prescription/Rx/pharmacist/controlled-substance field anywhere.
        $items = [
            ['cat' => $painRelief->id, 'name' => 'Pain Relief Tablets 500mg (20ct)', 'price' => 4.50, 'age_restricted' => false, 'batch_no' => 'PR-2026-01', 'expiry_date' => now()->addYear()->toDateString()],
            ['cat' => $painRelief->id, 'name' => 'Cold-Pressed Menthol Rub', 'price' => 6.00, 'age_restricted' => false, 'batch_no' => 'PR-2026-02', 'expiry_date' => now()->addMonths(9)->toDateString()],
            ['cat' => $vitamins->id, 'name' => 'Vitamin C 1000mg (60ct)', 'price' => 9.99, 'age_restricted' => false, 'batch_no' => 'VT-2026-01', 'expiry_date' => now()->addYears(2)->toDateString()],
            ['cat' => $vitamins->id, 'name' => 'Daily Multivitamin (30ct)', 'price' => 12.50, 'age_restricted' => false, 'batch_no' => 'VT-2026-02', 'expiry_date' => now()->addDays(20)->toDateString()], // expiring soon
            ['cat' => $firstAid->id, 'name' => 'Adhesive Bandages (Assorted, 40ct)', 'price' => 3.25, 'age_restricted' => false, 'batch_no' => null, 'expiry_date' => null],
            ['cat' => $firstAid->id, 'name' => 'Antiseptic Wipes (30ct)', 'price' => 4.00, 'age_restricted' => false, 'batch_no' => 'FA-2025-11', 'expiry_date' => now()->subDays(5)->toDateString()], // already expired
            ['cat' => $coughCold->id, 'name' => 'Cough Syrup (Adult, 100ml)', 'price' => 7.50, 'age_restricted' => true, 'batch_no' => 'CC-2026-01', 'expiry_date' => now()->addMonths(14)->toDateString()],
            ['cat' => $coughCold->id, 'name' => 'Sinus Relief Capsules (16ct)', 'price' => 8.25, 'age_restricted' => true, 'batch_no' => 'CC-2026-02', 'expiry_date' => now()->addYear()->toDateString()],
        ];

        foreach ($items as $position => $item) {
            Menu::updateOrCreate(
                ['store_id' => $store->id, 'category_id' => $item['cat'], 'name' => $item['name']],
                [
                    'price' => $item['price'],
                    'active' => 1,
                    'position' => $position,
                    'age_restricted' => $item['age_restricted'],
                    'batch_no' => $item['batch_no'],
                    'expiry_date' => $item['expiry_date'],
                ]
            );
        }

        $this->approvedDemoLicence($store);
    }

    /**
     * A synthetic, already-approved licence (P1-23 gate: a pharmacy is shown only with an approved, unexpired
     * licence in an enabled country). Demo data only; the country still has to be enabled by
     * PHARMACY_ENABLED_COUNTRIES, so nothing becomes public on an install that has not enabled it.
     */
    private function approvedDemoLicence(Store $store): void
    {
        $licence = PharmacyLicence::where('store_id', $store->id)->where('licence_number', 'DEMO-OTC-0001')->first()
            ?? new PharmacyLicence(['store_id' => $store->id, 'licence_number' => 'DEMO-OTC-0001']);
        $licence->fill([
            'country' => 'SA',
            'issuing_authority' => 'Demo Health Authority (synthetic)',
            'expires_on' => now()->addYear()->toDateString(),
            'responsible_person_name' => 'Demo Pharmacist',
            'document_path' => 'pharmacy-licences/demo-otc-0001.pdf',
        ]);
        $licence->forceFill(['status' => PharmacyLicence::APPROVED, 'reviewed_at' => now()])->save();
    }
}
