<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Support\DemoOwner;

/**
 * J62-1: the fixed shape of the owner hands-on demo (`php artisan juwri:demo-seed`). Slugs and e-mail addresses are the
 * natural keys every demo seeder, the `--fresh` wipe and the checklist agree on. Synthetic data only (J19).
 */
final class DemoOwnerPlan
{
    /** Marker written as a store option on every demo store (`--fresh` wipes only tagged rows). */
    public const TAG_OPTION = 'demo_owner_tag';

    public const TAG_VALUE = 'owner-demo-1';

    /** Where the generated logins are written (relative to storage_path()); git-ignored via storage/app/.gitignore. */
    public const CREDENTIALS_FILE = 'app/demo-credentials.md';

    /** Store slugs of the demo, by vertical. Created by existing seeders unless marked "new". */
    public const STORES = [
        'restaurant' => 'themepack-demo',     // ThemePackDemoSeeder: modifiers, order, customer
        'cafe' => 'bloom-coffee-demo',        // KitDemoSeeder
        'hotel' => 'hotel-demo',              // HotelDemoSeeder: rooms 101/102, checked-in stay
        'retail' => 'retail-demo',            // VerticalShowcaseSeeder
        'pharmacy' => 'pharmacy-otc-demo',    // PharmacyDemoSeeder
        'branches' => 'demo-branches',        // new: multi-branch restaurant, Arabic first
    ];

    /** The one login that owns every demo store (stores are re-homed to it by DemoOwnerSeeder). */
    public const OWNER_EMAIL = 'demo-owner@demo.test';

    public const ADMIN_EMAIL = 'demo-admin@demo.test';

    /** Reseller owner from FusionResellerDemoSeeder (white-label host fusion-a.test). */
    public const RESELLER_EMAIL = 'fusiondemo-a@example.test';

    public const RESELLER_HOST = 'fusion-a.test';

    /** Dashboard staff login (manager role). */
    public const STAFF_EMAIL = 'demo-staff@demo.test';

    /** POS cashier (signs in with a PIN on the till; the PIN is generated at seed time). */
    public const CASHIER_NAME = 'Demo Cashier';

    /** Coupon codes created on the restaurant store: 10% off and a fixed amount. */
    public const COUPON_PERCENT = 'DEMO10';

    public const COUPON_FIXED = 'DEMO5OFF';

    /** @return list<string> every user e-mail the demo creates or re-keys (seeder-made, not the existing seeders' own) */
    public static function userEmails(): array
    {
        return [self::OWNER_EMAIL, self::ADMIN_EMAIL, self::STAFF_EMAIL];
    }
}
