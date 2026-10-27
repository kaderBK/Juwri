<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Juwri\Modules\Storefront\Database\Seeders;

use App\Models\StaffMember;
use App\Models\StoreOption;
use App\Services\PosService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Contracts\Billing\PlanCatalog;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\ModuleSeeders;
use Juwri\Modules\Pos\Models\PosTerminal;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoCredentials;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoOwnerPlan;

/**
 * J62-2: the ACCOUNTS half of the owner hands-on demo (`php artisan juwri:demo-seed`).
 *
 * Creates the four logins the owner clicks through with — the store owner, the
 * platform admin, the reseller owner and the restaurant staff — plus the POS
 * cashier, its till and an open drawer, and records every login (URL + secret)
 * with {@see DemoCredentials} so the run can write them to a local file.
 *
 * IDEMPOTENT: every row keys on its own natural key (e-mail / slug / name) via
 * updateOrCreate, so running the seeder twice never duplicates a row. A missing
 * store or a missing reseller fixture is SKIPPED with a warning, never a crash:
 * this seeder runs in the middle of a demo-seed chain whose earlier steps may not
 * have produced every fixture (the reseller rows only exist in an edition that
 * ships the reseller module).
 *
 * The passwords and the PIN come from DemoCredentials (generated once per run);
 * no secret is ever echoed, printed or logged here.
 */
class DemoAccountsSeeder extends Seeder
{
    /**
     * The throwaway plan the demo owner rides on where plans exist (Billing, ee).
     * In Community the owner runs on the edition plan (every feature on), so no
     * plan row is written there at all.
     */
    private const PLAN_NAME = 'ZZ Owner Demo Plan (seeder)';

    /**
     * plans.settings (raw array — the `object` cast encodes it). Entitlements::has()
     * compares `(string) $value === '1'`, so every feature flag MUST be the STRING
     * '1'; boolean true / 'yes' / 'on' all silently fail the gate. The limits are
     * plain numbers (999 = the unlimited sentinel Entitlements::normalize() strips).
     *
     * Shape mirrors Billing's DemoProPlanSeeder (the proven blob) plus the demo's
     * extra verticals: hotel, kds, loyalty, reservations and biolink.
     */
    private const PLAN_SETTINGS = [
        'restaurant_limit' => 20,
        'category_limit' => 999,
        'menu_limit' => 999,
        'scan_limit' => 999,
        'allow_ordering' => '1',
        'table_qr' => '1',
        'hide_branding' => '1',
        'advertisements' => '0',
        'custom_features' => ['13' => '1', '14' => '1', '15' => '1', '16' => '1', '17' => '1', '10' => '1', '12' => '1', '11' => '1'],
        'allow_advanced' => '1',
        'allow_pro' => '1',
        'allow_on_free' => '0',
        'allow_on_try' => '0',
        'allow_on_main' => '0',
        'allow_viewonly' => '0',
        'allow_custom' => '0',
        'allow_onlybio' => '0',
        'pos_enabled' => '1',
        'storefront_api' => '1',
        'ai_chatbot' => '1',
        'agent_api' => '1',
        'theme_customizer' => '1',
        'hotel' => '1',
        'kds' => '1',
        'loyalty' => '1',
        'reservations' => '1',
        'biolink' => '1',
    ];

    /** The POS cashier signs in with a PIN; the e-mail is its natural key. */
    private const CASHIER_EMAIL = 'demo-cashier@demo.test';

    /** The name of the demo till (a named POS device of the restaurant store). */
    private const TILL_NAME = 'Demo Till';

    /** The drawer's opening float, in major units of the store currency (SAR). */
    private const CASHIER_FLOAT = 200.00;

    public function run(): void
    {
        $credentials = app(DemoCredentials::class);

        $this->seedOwner($credentials);
        $this->seedAdmin($credentials);
        $this->seedReseller($credentials);
        $this->seedStaff($credentials);
    }

    /**
     * 1. The owner: one login that owns every demo store. It rides a throwaway
     *    plan where plans exist, so the hotel, table QR, ordering and POS
     *    entitlements are all on (in Community the edition plan grants them).
     */
    private function seedOwner(DemoCredentials $credentials): void
    {
        // P1-13: plans are Billing (ee) — the plan is written by Billing's DemoThrowawayPlanSeeder when it is
        // present; in Community the owner runs on the edition plan (every feature on), so there is no plan to write.
        if ($planSeeder = ModuleSeeders::find('DemoThrowawayPlanSeeder')) {
            $this->call($planSeeder, true, [
                'name' => self::PLAN_NAME,
                'description' => 'Throwaway plan created by DemoAccountsSeeder — do not sell.',
                'settings' => self::PLAN_SETTINGS,
            ]);
        }

        // users.group_id is the legacy-but-fully-supported link (User::plan() falls back to Plan::find(group_id)
        // when there is no live subscription) and is stored as a string.
        $planId = app(PlanCatalog::class)->plans()->firstWhere('name', self::PLAN_NAME)?->getKey();

        $password = $credentials->password('owner');

        User::updateOrCreate(
            ['email' => DemoOwnerPlan::OWNER_EMAIL],
            [
                'username' => 'demoowner',
                'name' => 'Demo Owner',
                'password' => Hash::make($password),
                'user_type' => 'user',
                'group_id' => $planId !== null ? (string) $planId : 'free',
                'status' => 1,
                'country_code' => 'SA',
                'country' => 'Saudi Arabia',
                'email_verified_at' => now(),
            ]
        );

        $credentials->record(
            'Owner dashboard',
            route('dashboard'),
            DemoOwnerPlan::OWNER_EMAIL,
            $password,
        );
    }

    /**
     * 2. The platform admin, modelled on E2eAdminSeeder (user_type 'admin' is
     *    User::isAdmin()) but WITHOUT its E2E env requirement — the demo needs
     *    the admin panel to be reachable in a local demo database.
     */
    private function seedAdmin(DemoCredentials $credentials): void
    {
        $password = $credentials->password('admin');

        User::updateOrCreate(
            ['email' => DemoOwnerPlan::ADMIN_EMAIL],
            [
                'username' => 'demoadmin',
                'name' => 'Demo Platform Admin',
                'password' => Hash::make($password),
                'user_type' => 'admin',   // Juwri\Core\Models\User::isAdmin()
                'group_id' => '1',
                'status' => 1,
                'email_verified_at' => now(),
            ]
        );

        $credentials->record(
            'Platform admin',
            route('admin.dashboard'),
            DemoOwnerPlan::ADMIN_EMAIL,
            $password,
        );
    }

    /**
     * 3. The reseller owner of the white-label host. Its rows come from the
     *    reseller module's own demo seeder, which only exists where that module
     *    is installed — so a missing owner is a SKIP with a warning, not a crash.
     */
    private function seedReseller(DemoCredentials $credentials): void
    {
        $owner = User::where('email', DemoOwnerPlan::RESELLER_EMAIL)->first();

        if (! $owner) {
            $this->command?->warn(
                'DemoAccountsSeeder: '.DemoOwnerPlan::RESELLER_EMAIL.' is missing — the reseller demo rows are not '
                .'there (no reseller module?). The reseller login is skipped.'
            );

            return;
        }

        $password = $credentials->password('reseller');

        User::updateOrCreate(
            ['email' => DemoOwnerPlan::RESELLER_EMAIL],
            [
                'password' => Hash::make($password),
                'status' => 1,
                'email_verified_at' => now(),
            ]
        );

        $host = 'https://'.DemoOwnerPlan::RESELLER_HOST;

        $credentials->record('Reseller panel', $host.'/reseller', DemoOwnerPlan::RESELLER_EMAIL, $password);
        $credentials->record('Reseller storefront', $host.'/fusion-demo-a1', DemoOwnerPlan::RESELLER_EMAIL, $password);
    }

    /**
     * 4. The restaurant team: a dashboard manager, a POS cashier with a PIN, the
     *    till and an open drawer — so the owner can start selling at once.
     */
    private function seedStaff(DemoCredentials $credentials): void
    {
        $store = Store::where('slug', DemoOwnerPlan::STORES['restaurant'])->first();

        if (! $store) {
            $this->command?->warn(
                'DemoAccountsSeeder: the restaurant store ('.DemoOwnerPlan::STORES['restaurant'].') is missing — '
                .'the staff and POS fixtures are skipped.'
            );

            return;
        }

        $rid = (int) $store->id;
        $ownerId = (int) $store->user_id;
        $branchId = $this->defaultBranchId($rid);

        // The till's money is in SAR — the drawer float is 200.00 SAR.
        StoreOption::updateStoreOption($rid, 'currency_code', 'SAR');

        // 4a. The dashboard staff member (manager role, the role's default grants).
        $password = $credentials->password('staff');

        StaffMember::updateOrCreate(
            ['email' => DemoOwnerPlan::STAFF_EMAIL],
            [
                'store_id' => $rid,
                'owner_id' => $ownerId,
                'branch_id' => $branchId,
                'default_branch_id' => $branchId,
                'name' => 'Demo Staff Manager',
                'password' => Hash::make($password),
                'role' => 'manager',
                'permissions' => $this->permissionMap('manager'),
                'active' => true,
            ]
        );

        $credentials->record(
            'Staff dashboard',
            route('staff.login'),
            DemoOwnerPlan::STAFF_EMAIL,
            $password,
        );

        // 4b. The POS cashier. Its credential is the PIN; the password column is
        //     NOT NULL, so it carries an unusable random value nobody is told.
        $pin = $credentials->pin('cashier');

        $cashier = StaffMember::updateOrCreate(
            ['email' => self::CASHIER_EMAIL],
            [
                'store_id' => $rid,
                'owner_id' => $ownerId,
                'branch_id' => $branchId,
                'default_branch_id' => $branchId,
                'name' => DemoOwnerPlan::CASHIER_NAME,
                'password' => Hash::make(Str::random(40)),
                'role' => 'cashier',
                'permissions' => $this->permissionMap('cashier'),
                'active' => true,
            ]
        );

        // The PIN goes in through the model's own API: a bcrypt pin_hash plus the
        // offline verifier source. The plaintext pin column is never written.
        $cashier->forceFill(['pin_hash' => Hash::make($pin), 'pin' => null]);
        $cashier->rememberOfflinePin($pin);
        $cashier->save();

        // 4c. The till and an open drawer with the demo float. PosService::openShift
        //     refuses a second open shift, so an already-open drawer is kept.
        PosTerminal::updateOrCreate(
            ['store_id' => $rid, 'name' => self::TILL_NAME],
            ['branch_id' => $branchId],
        );

        if (! PosService::currentShift($rid, $branchId)) {
            PosService::openShift($store, $cashier, $branchId, self::CASHIER_FLOAT);
        }

        $credentials->record(
            'POS register',
            route('pos.index'),
            self::CASHIER_EMAIL,
            $pin,
            'pin',
        );
    }

    /** The restaurant's default (first-created) branch — where the POS drawer sits. */
    private function defaultBranchId(int $storeId): ?int
    {
        $id = Branch::where('store_id', $storeId)->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * A role's permissions as an explicit allow-map, so the row's grants are
     * self-describing and independent of future default changes.
     *
     * @return array<string, bool>
     */
    private function permissionMap(string $role): array
    {
        $map = [];

        foreach (StaffMember::ROLE_PERMISSIONS[$role] ?? [] as $permission) {
            $map[$permission] = true;
        }

        return $map;
    }
}
