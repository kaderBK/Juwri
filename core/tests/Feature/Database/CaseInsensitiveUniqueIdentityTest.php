<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature\Database;

use App\Models\Customer;
use App\Models\StaffMember;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Juwri\Core\Auth\CaseDuplicateFinder;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/** P6-98: case-insensitive UNIQUE email/username, the duplicate report, the fail-safe migration and the provider. */
class CaseInsensitiveUniqueIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'app/Core/Database/Migrations/2026_10_13_200000_make_case_insensitive_identity_indexes_unique.php';

    private function functional(): bool
    {
        return in_array(DB::connection()->getDriverName(), ['pgsql', 'sqlite'], true);
    }

    private function migration(): object
    {
        return require base_path(self::MIGRATION);
    }

    /** Drop the P6-98 uniques (pre-migration state) and create two users whose emails differ only in case. */
    private function legacyTwins(): array
    {
        if (! $this->functional()) {
            $this->markTestSkipped('MySQL ci collation: the existing unique index already rejects case twins');
        }
        $this->migration()->down();
        $a = User::factory()->create();
        $b = User::factory()->create();
        DB::table('users')->where('id', $a->id)->update(['email' => 'Twin@Example.com']);
        DB::table('users')->where('id', $b->id)->update(['email' => 'twin@example.com']);

        return [$a, $b];
    }

    private function store(): Store
    {
        $owner = User::factory()->create();

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => 'S',
            'sub_title' => 't', 'description' => 'd', 'main_image' => 'd.png', 'cover_image' => 'd.png',
            'address' => '1 St', 'phone' => '555-0100', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_unique_enforced_case_insensitively_on_every_driver(): void
    {
        $store = $this->store();
        $u = User::factory()->create(['email' => 'one@example.com', 'username' => 'alice']);
        $cases = [
            fn () => DB::table('users')->where('id', User::factory()->create()->id)->update(['email' => 'ONE@example.com']),
            fn () => DB::table('users')->where('id', User::factory()->create()->id)->update(['username' => 'Alice']),
            function () use ($store) {
                foreach (['Staff@S.test', 'staff@s.test'] as $email) {
                    StaffMember::create(['owner_id' => $store->user_id, 'store_id' => $store->id, 'name' => 'S', 'email' => $email,
                        'password' => bcrypt('x'), 'role' => 'cashier', 'active' => 1]);
                }
            },
            function () use ($store) {
                foreach (['Cust@C.test', 'cust@c.test'] as $email) {
                    Customer::create(['store_id' => $store->id, 'name' => 'C', 'phone' => '5'.Str::random(8), 'email' => $email]);
                }
            },
        ];
        foreach ($cases as $i => $case) {
            try {
                DB::transaction($case);
                $this->fail("case {$i}: case variant was accepted");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // same customer email in another store stays allowed (scope is per store)
        Customer::create(['store_id' => $store->id, 'name' => 'C', 'phone' => '5'.Str::random(8), 'email' => 'shared@c.test']);
        Customer::create(['store_id' => $this->store()->id, 'name' => 'C', 'phone' => '5'.Str::random(8), 'email' => 'Shared@C.test']);
        $this->assertSame('one@example.com', $u->fresh()->email);
        if ($this->functional()) {
            $this->assertTrue(Schema::hasIndex('users', 'users_lower_email_unique'));
            $this->assertFalse(Schema::hasIndex('users', 'users_lower_email_index'));
        }
    }

    public function test_finder_and_command_report_case_twins_without_values(): void
    {
        $this->artisan('accounts:find-case-duplicates')->expectsOutputToContain('No case-only duplicates')->assertExitCode(0);

        [$a, $b] = $this->legacyTwins();
        $groups = (new CaseDuplicateFinder)->find();
        $this->assertCount(1, $groups);
        $this->assertSame(['users', 'email'], [$groups[0]['table'], $groups[0]['column']]);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($groups[0]['rows'], 'id'));
        $this->assertStringNotContainsStringIgnoringCase('twin@', (string) json_encode($groups));

        $this->artisan('accounts:find-case-duplicates')->expectsOutputToContain('differ only in letter case')->assertExitCode(1);
    }

    public function test_migration_refuses_with_duplicates_and_changes_nothing(): void
    {
        $this->legacyTwins();
        try {
            $this->migration()->up();
            $this->fail('migration ran despite case duplicates');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('accounts:find-case-duplicates', $e->getMessage());
            $this->assertStringContainsString('users.email', $e->getMessage());
        }
        $this->assertFalse(Schema::hasIndex('users', 'users_lower_email_unique'));
        $this->assertTrue(Schema::hasIndex('users', 'users_lower_email_index'));
    }

    public function test_migration_succeeds_once_duplicates_are_resolved(): void
    {
        [, $b] = $this->legacyTwins();
        DB::table('users')->where('id', $b->id)->update(['email' => 'twin2@example.com']);
        $this->migration()->up();
        $this->assertTrue(Schema::hasIndex('users', 'users_lower_email_unique'));
        $this->expectException(QueryException::class);
        DB::table('users')->where('id', $b->id)->update(['email' => 'TWIN@example.com']);
    }

    public function test_provider_prefers_exact_case_and_refuses_ambiguous_twins(): void
    {
        [$a, $b] = $this->legacyTwins();
        $provider = Auth::createUserProvider('users');

        $this->assertSame($a->id, $provider->retrieveByCredentials(['email' => 'Twin@Example.com'])->getAuthIdentifier());
        $this->assertSame($b->id, $provider->retrieveByCredentials(['email' => ' twin@example.com '])->getAuthIdentifier());

        try {
            $provider->retrieveByCredentials(['email' => 'TWIN@EXAMPLE.COM', 'password' => 'x']);
            $this->fail('ambiguous twins resolved arbitrarily');
        } catch (ValidationException $e) {
            $this->assertSame(__('auth.case_duplicate'), $e->errors()['email'][0]);
        }
    }

    public function test_provider_single_match_is_case_insensitive(): void
    {
        $u = User::factory()->create(['email' => 'solo@example.com']);
        $this->assertSame($u->id, Auth::createUserProvider('users')->retrieveByCredentials(['email' => 'SOLO@example.com'])->getAuthIdentifier());
    }
}
