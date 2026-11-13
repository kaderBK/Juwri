<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\PosShift;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Pos\Models\PosTerminal;
use Juwri\Modules\Storefront\Database\Seeders\DemoAccountsSeeder;
use Juwri\Modules\Storefront\Database\Seeders\ThemePackDemoSeeder;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoCredentials;
use Juwri\Modules\Storefront\Support\DemoOwner\DemoOwnerPlan;
use Tests\TestCase;

/**
 * J62-2: the demo accounts seeder — one account per demo e-mail, a working
 * password for each, a PIN the POS actually accepts, and no row duplicated when
 * it runs twice. The secrets come from a DemoCredentials instance bound into
 * the container, so the test reads back exactly what the seeder used.
 */
class DemoAccountsSeederTest extends TestCase
{
    use RefreshDatabase;

    private DemoCredentials $credentials;

    protected function setUp(): void
    {
        parent::setUp();

        // A fresh credential bag for this test. The seeder resolves it from the
        // container, so the test can read back the very secrets it generated.
        $this->credentials = new DemoCredentials;
        $this->app->instance(DemoCredentials::class, $this->credentials);

        // The demo restaurant (its branches are where the POS drawer sits).
        $this->seed(ThemePackDemoSeeder::class);
    }

    public function test_it_creates_exactly_one_account_per_demo_email_with_a_working_password(): void
    {
        $this->seed(DemoAccountsSeeder::class);

        foreach ([DemoOwnerPlan::OWNER_EMAIL, DemoOwnerPlan::ADMIN_EMAIL] as $email) {
            $this->assertSame(1, User::where('email', $email)->count(), 'one user row for '.$email);
        }

        $this->assertSame(1, StaffMember::where('email', DemoOwnerPlan::STAFF_EMAIL)->count(), 'one staff row for the dashboard manager');

        $owner = User::where('email', DemoOwnerPlan::OWNER_EMAIL)->firstOrFail();
        $this->assertSame('user', (string) $owner->user_type);
        $this->assertSame(1, (int) $owner->status);
        $this->assertSame('SA', (string) $owner->country_code);
        $this->assertNotNull($owner->email_verified_at, 'the demo owner is a verified e-mail');
        $this->assertTrue(
            Hash::check($this->credentials->password('owner'), (string) $owner->password),
            'the recorded owner password signs the owner in'
        );

        $admin = User::where('email', DemoOwnerPlan::ADMIN_EMAIL)->firstOrFail();
        $this->assertSame('admin', (string) $admin->user_type, 'User::isAdmin() reads user_type');
        $this->assertTrue(
            Hash::check($this->credentials->password('admin'), (string) $admin->password),
            'the recorded admin password signs the admin in'
        );
    }

    public function test_the_staff_manager_and_the_pos_cashier_are_created_on_the_demo_restaurant(): void
    {
        $this->seed(DemoAccountsSeeder::class);

        $store = Store::where('slug', DemoOwnerPlan::STORES['restaurant'])->firstOrFail();

        $staff = StaffMember::where('email', DemoOwnerPlan::STAFF_EMAIL)->firstOrFail();
        $this->assertSame((int) $store->id, (int) $staff->store_id);
        $this->assertSame('manager', (string) $staff->role);
        $this->assertTrue((bool) $staff->active);
        $this->assertTrue(
            Hash::check($this->credentials->password('staff'), (string) $staff->password),
            'the recorded staff password signs the staff member in'
        );

        $cashier = StaffMember::where('name', DemoOwnerPlan::CASHIER_NAME)->firstOrFail();
        $this->assertSame((int) $store->id, (int) $cashier->store_id);
        $this->assertSame('cashier', (string) $cashier->role);
        $this->assertTrue((bool) $cashier->active);
    }

    public function test_the_cashier_pin_authenticates_through_the_models_own_api(): void
    {
        $this->seed(DemoAccountsSeeder::class);

        $store = Store::where('slug', DemoOwnerPlan::STORES['restaurant'])->firstOrFail();
        $cashier = StaffMember::where('name', DemoOwnerPlan::CASHIER_NAME)->firstOrFail();

        $this->assertNotEmpty((string) $cashier->pin_hash, 'the PIN is stored hashed');
        $this->assertNull($cashier->pin, 'the plaintext pin column is never written');
        $this->assertNotNull($cashier->pin_offline_kdf, 'the offline PIN verifier source is written too');

        $match = StaffMember::authenticateByPin((int) $store->id, $this->credentials->pin('cashier'));

        $this->assertNotNull($match, 'the recorded PIN authenticates the cashier');
        $this->assertSame((int) $cashier->id, (int) $match->id);
    }

    public function test_the_till_and_an_open_drawer_with_the_demo_float_are_ready(): void
    {
        $this->seed(DemoAccountsSeeder::class);

        $store = Store::where('slug', DemoOwnerPlan::STORES['restaurant'])->firstOrFail();

        $this->assertSame(1, PosTerminal::where('store_id', $store->id)->count(), 'one till on the demo restaurant');

        $shift = PosShift::where('store_id', $store->id)->where('status', 'open')->firstOrFail();
        $this->assertSame(20000, (int) $shift->opening_float_minor, 'the drawer opens with a 200.00 SAR float');
    }

    public function test_running_it_twice_duplicates_nothing(): void
    {
        $this->seed(DemoAccountsSeeder::class);
        $before = $this->rowCounts();

        $this->seed(DemoAccountsSeeder::class);

        $this->assertSame($before, $this->rowCounts(), 'the second run adds no row');
        $this->assertSame(
            1,
            User::where('email', DemoOwnerPlan::OWNER_EMAIL)->count(),
            'still one owner after a second run'
        );
        $this->assertTrue(
            Hash::check(
                $this->credentials->password('owner'),
                (string) User::where('email', DemoOwnerPlan::OWNER_EMAIL)->firstOrFail()->password
            ),
            'the owner password still signs in after a second run'
        );
    }

    public function test_no_secret_reaches_the_command_output(): void
    {
        $exit = Artisan::call('db:seed', ['--class' => DemoAccountsSeeder::class, '--force' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $exit, $output);

        foreach ([
            $this->credentials->password('owner'),
            $this->credentials->password('admin'),
            $this->credentials->password('staff'),
            $this->credentials->pin('cashier'),
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $output, 'a secret leaked into the command output');
        }
    }

    /**
     * The row counts the idempotency assertion compares.
     *
     * @return array<string, int>
     */
    private function rowCounts(): array
    {
        $store = Store::where('slug', DemoOwnerPlan::STORES['restaurant'])->firstOrFail();

        return [
            'users' => User::count(),
            'staff' => StaffMember::where('store_id', $store->id)->count(),
            'terminals' => PosTerminal::where('store_id', $store->id)->count(),
            'shifts' => PosShift::where('store_id', $store->id)->count(),
        ];
    }
}
