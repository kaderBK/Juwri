<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use App\Models\StaffMember;
use App\Models\StoreOption;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Customers\Models\CustomerAddress;
use Juwri\Modules\Menu\Models\Menu;

/**
 * Platform E2E v2 (V0.5) — the coverage-expansion fixtures the v2 lanes build on,
 * ADDED on top of E2ePlatformSeeder (v1) rather than replacing it. Everything the
 * v1 seeder already makes (19 demo stores under the showcase owner, P1TEST10, the
 * hotel/biolink/directory sub-seeders) stays authoritative; this seeder only adds
 * what v2 needs and v1 lacks:
 *
 *   1. A SECOND owner (tenant-B) with its OWN published store, customer, address
 *      and a paid order — the cross-tenant victims the IDOR journeys (A2/B7/C1)
 *      probe. Distinct email + slug so tenant-A can never reach them legitimately.
 *   2. A staff role matrix on the main showcase store: one active member per role
 *      (manager/cashier/kitchen/waiter), each with the role's DISTINCT default
 *      permission set and a known password — the fixtures the RBAC matrix (B1)
 *      logs in as, role by role.
 *   3. The test-only `stub` payment gateway row (payment_install='1') so the fake
 *      gateway (PaymentMethods\StubController, guarded) is DISPATCHABLE for the
 *      A4 checkout-outcome journey. Seeded ONLY here → never present in prod DBs.
 *
 * Hotel/PMS, biolink and delivery FIXTURES: the schema supports all three
 * (hotel_stays/hotel_bookings, bio_blocks/bio_clicks, delivery_orders all exist)
 * and the v1 sub-seeders already populate baseline data for the showcase owner —
 * so the B6/A6/B8/A7/B9 journeys build on THAT plus tenant-B for isolation. This
 * seeder does not duplicate those; if a table is ever absent it is noted, not
 * fatal (guarded by Schema::hasTable).
 *
 * IDEMPOTENT: every write keys on a natural key (email / slug / store_id+option /
 * store_id+email / track_token / payment_folder) via firstOrCreate /
 * updateOrCreate, so a re-run never duplicates. Prove with the accompanying
 * tests/Feature/E2ePlatformV2SeederTest.php.
 *
 * Run:  php artisan db:seed --class=E2ePlatformV2Seeder --force
 */
class E2ePlatformV2Seeder extends Seeder
{
    /** Tenant-B — the cross-tenant IDOR victim owner (distinct from the showcase owner). */
    public const TENANT_B_EMAIL = 'e2e-tenantb-owner@example.test';

    public const TENANT_B_PASS = 'E2eTenantB-pass';

    public const TENANT_B_SLUG = 'tenantb-demo';

    /** Tenant-B customer + the order whose id/track the IDOR journeys try to reach. */
    public const TENANT_B_CUSTOMER_EMAIL = 'e2e-tenantb-customer@example.test';

    public const TENANT_B_CUSTOMER_PASS = 'E2eTenantBCust123';

    public const TENANT_B_ORDER_TRACK = 'e2eV2tenantBorderTRACKtoken0001';

    /** The main store the staff role matrix attaches to (built by the v1 seeder). */
    public const STAFF_STORE_SLUG = 'crave-demo';

    /** password shared by every seeded staff role (login is email + password). */
    public const STAFF_PASS = 'E2eStaff-pass';

    /** role => [email, pin] — one active member per role, default permissions. */
    private const STAFF_MATRIX = [
        'manager' => ['e2e-staff-manager@example.test', '4101'],
        'cashier' => ['e2e-staff-cashier@example.test', '4102'],
        'kitchen' => ['e2e-staff-kitchen@example.test', '4103'],
        'waiter' => ['e2e-staff-waiter@example.test',  '4104'],
    ];

    public const STUB_GATEWAY_FOLDER = 'stub';

    public function run(): void
    {
        $tenantB = $this->tenantBOwner();
        $store = $this->tenantBStore($tenantB);
        $this->tenantBVictims($store);

        $this->staffMatrix();
        $this->stubGateway();

        // Chain the lane fixture seeders so a single db:seed --class=E2ePlatformV2Seeder
        // provisions EVERYTHING the v2 journeys need — otherwise the reproducibility
        // gate (WF.1) fails on missing fixtures (attacker customer, EUR rate, dispatch
        // order, hotel stay). Guarded by class_exists so a partial checkout still runs.
        foreach ([
            E2eLaneAFixturesSeeder::class,
            E2eDispatchDemoSeeder::class,
            E2ePosModifierDemoSeeder::class,
            E2eKdsStationDemoSeeder::class,
            E2eDarkFeatureEnableSeeder::class,
            // P1-13 13e: the hotel stay fixture is the Hotel module's — looked up in the seeder registry (a module's
            // seeders are not one of its public parts).
            ModuleSeeders::find('HotelDemoSeeder'),
        ] as $seeder) {
            if ($seeder !== null && class_exists($seeder)) {
                $this->call($seeder);
            }
        }

        $this->noteFeatureFixtures();

        $this->command?->info(sprintf(
            'E2ePlatformV2Seeder: tenant-B owner #%d store "%s" (#%d) + victim order %s; staff matrix on %s; stub gateway installed.',
            $tenantB->id, self::TENANT_B_SLUG, $store->id, self::TENANT_B_ORDER_TRACK, self::STAFF_STORE_SLUG,
        ));
    }

    /* ============================ TENANT-B ============================ */

    private function tenantBOwner(): User
    {
        return User::firstOrCreate(
            ['email' => self::TENANT_B_EMAIL],
            [
                'username' => 'e2etenantb',
                'name' => 'E2E Tenant B Owner',
                'password' => Hash::make(self::TENANT_B_PASS),
                'user_type' => 'user',
                'group_id' => 2,
                'status' => 1,
                'country_code' => 'SA',
                'country' => 'Saudi Arabia',
                'email_verified_at' => now(),
            ]
        );
    }

    /** A minimal but published crave store under tenant-B (its own tenant island). */
    private function tenantBStore(User $owner): Store
    {
        $store = Store::updateOrCreate(
            ['slug' => self::TENANT_B_SLUG],
            [
                'user_id' => $owner->id,
                'title' => 'Tenant B Bistro',
                'sub_title' => 'IDOR isolation target',
                'description' => 'A second-tenant store used only to prove cross-tenant isolation.',
                'color' => '#0f766e',
                'address' => 'Olaya St, Riyadh',
                'phone' => '966500000200',
                'catalog_visible' => 1,
                'is_verified' => true,
            ]
        );
        $rid = $store->id;

        foreach ([
            'store_template' => 'crave',
            'restaurant_delivery_order' => '1',
            'restaurant_takeaway_order' => '1',
            'restaurant_on_table_order' => '1',
            'allow_coupons' => '1',
            'customer_auth_mode' => 'optional',
            'menu_languages' => json_encode(['ar', 'en']),
            'default_language' => 'ar',
        ] as $name => $value) {
            StoreOption::updateStoreOption($rid, $name, $value);
        }

        Branch::updateOrCreate(
            ['store_id' => $rid, 'name' => 'Main Branch'],
            [
                'address' => 'Tenant B Bistro, Riyadh', 'phone' => '966500000210',
                'latitude' => 24.6900, 'longitude' => 46.6850, 'timezone' => 'Asia/Riyadh',
                'active' => true, 'accepting_orders' => true, 'position' => 0,
                'hours' => $this->hours247(),
            ]
        );

        $cat = MenuCategory::updateOrCreate(
            ['store_id' => $rid, 'name' => 'Mains'],
            ['position' => 0, 'active' => '1']
        );
        foreach ([['Tenant B Burger', 40], ['Tenant B Salad', 30]] as $i => [$name, $price]) {
            Menu::updateOrCreate(
                ['store_id' => $rid, 'name' => $name],
                [
                    'category_id' => $cat->id,
                    'description' => $name.' — tenant B item.',
                    'price' => $price,
                    'is_halal' => true,
                    'active' => '1',
                    'position' => $i,
                ]
            );
        }

        return $store;
    }

    /** Tenant-B customer + address + a paid order: the concrete IDOR victims. */
    private function tenantBVictims(Store $store): void
    {
        $customer = Customer::firstOrCreate(
            ['email' => self::TENANT_B_CUSTOMER_EMAIL],
            [
                'store_id' => $store->id,
                'name' => 'Tenant B Customer',
                'phone' => '966500000220',
                'password' => Hash::make(self::TENANT_B_CUSTOMER_PASS),
            ]
        );

        CustomerAddress::firstOrCreate(
            ['customer_id' => $customer->id, 'label' => 'Home'],
            [
                'line' => '12 Olaya St',
                'city' => 'Riyadh',
                'country' => 'SA',
                'lat' => 24.6900,
                'lng' => 46.6850,
                'is_default' => true,
            ]
        );

        // A paid order with a KNOWN track_token — the resource the IDOR journeys
        // try (and must fail) to read from tenant-A's session.
        Order::updateOrCreate(
            ['track_token' => self::TENANT_B_ORDER_TRACK],
            [
                'store_id' => $store->id,
                'customer_id' => $customer->id,
                'customer_name' => 'Tenant B Customer',
                'phone_number' => '966500000220',
                'type' => 'delivery',
                'status' => 'pending',
                'is_paid' => 1,
                'payment_gateway' => 'stub',
            ]
        );
    }

    /* ============================ STAFF MATRIX ============================ */

    /**
     * One active staff member per role on the main showcase store, each carrying
     * that role's DEFAULT permission set (StaffMember::ROLE_PERMISSIONS) so a
     * B1 journey can prove default-deny-then-widen without per-member overrides.
     */
    private function staffMatrix(): void
    {
        $store = Store::where('slug', self::STAFF_STORE_SLUG)->first();
        if (! $store) {
            $this->command?->warn('E2ePlatformV2Seeder: '.self::STAFF_STORE_SLUG.' missing — staff matrix skipped (run E2ePlatformSeeder first).');

            return;
        }

        foreach (self::STAFF_MATRIX as $role => [$email, $pin]) {
            $perms = StaffMember::ROLE_PERMISSIONS[$role] ?? [];
            // Store permissions as an explicit allow-map so the row's grants are
            // self-describing and independent of future default changes.
            $permMap = [];
            foreach ($perms as $p) {
                $permMap[$p] = true;
            }

            StaffMember::updateOrCreate(
                ['store_id' => $store->id, 'email' => $email],
                [
                    'owner_id' => $store->user_id,
                    'name' => 'E2E '.ucfirst($role),
                    'password' => Hash::make(self::STAFF_PASS),
                    'pin_hash' => Hash::make($pin),
                    'role' => $role,
                    'permissions' => $permMap,
                    'active' => true,
                ]
            );
        }
    }

    /* ============================ STUB GATEWAY ============================ */

    /**
     * The test-only fake gateway catalog row. Installed (payment_install='1') so
     * the storefront can offer `payment_method=stub`, dispatched to the guarded
     * PaymentMethods\StubController. Present ONLY in E2E databases (this seeder is
     * never run against prod), and the controller is itself env-guarded.
     */
    private function stubGateway(): void
    {
        $this->call(E2eStubGatewaySeeder::class);
    }

    /* ============================ NOTES ============================ */

    /** Emit which feature schemas are present so lanes know what to rely on. */
    private function noteFeatureFixtures(): void
    {
        $have = [];
        $missing = [];
        foreach ([
            'hotel/PMS' => 'hotel_stays',
            'biolink' => 'bio_blocks',
            'delivery' => 'delivery_orders',
        ] as $feature => $table) {
            if (Schema::hasTable($table)) {
                $have[] = $feature;
            } else {
                $missing[] = $feature;
            }
        }
        if ($have) {
            $this->command?->info('E2ePlatformV2Seeder: feature schema present ('.implode(', ', $have).') — v1 sub-seeders + tenant-B provide baseline; deep per-feature fixtures live in each lane.');
        }
        if ($missing) {
            $this->command?->warn('E2ePlatformV2Seeder: feature schema MISSING ('.implode(', ', $missing).') — those lanes must guard/defer.');
        }
    }

    /* ============================ HELPERS ============================ */

    /** 24/7 branch hours map keyed by Branch::DAYS (mon…sun). */
    private function hours247(): array
    {
        $days = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $map = [];
        foreach ($days as $d) {
            $map[$d] = ['open' => '00:00', 'close' => '23:59', 'closed' => false];
        }

        return $map;
    }
}
