<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\StaffMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Juwri\Core\Auth\CaseInsensitiveUserProvider;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Money\Currencies;
use Tests\TestCase;

/**
 * P6-40 (internal PostgreSQL audit, items O1, O2): sign-in, reset lookup and uniqueness treat emails/usernames/coupon
 * codes case-insensitively on every driver; money SQL (Currencies::sqlMajor) returns the exact decimal on every driver.
 */
class PgCaseAndMoneyPortabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_emails_are_stored_lowercased_on_every_auth_model(): void
    {
        $user = User::factory()->create(['email' => '  Mixed.Case@Example.TEST ']);
        $this->assertSame('mixed.case@example.test', $user->fresh()->email);
        $this->assertSame('a@b.test', (new Customer(['email' => 'A@B.test']))->email);
        $staff = new StaffMember;
        $staff->email = 'Staff@X.Test';
        $this->assertSame('staff@x.test', $staff->email);
    }

    public function test_sign_in_and_reset_lookup_ignore_email_and_username_case(): void
    {
        User::factory()->create(['email' => 'owner@example.test', 'username' => 'ownerone', 'password' => bcrypt('secret-pass')]);
        // A legacy row stored before normalisation (mixed case in the column) still signs in.
        $legacy = User::factory()->create(['password' => bcrypt('secret-pass')]);
        DB::table('users')->where('id', $legacy->id)->update(['email' => 'Legacy@Example.TEST', 'username' => 'LegacyUser']);

        $this->assertInstanceOf(CaseInsensitiveUserProvider::class, Auth::guard('web')->getProvider());
        $this->assertTrue(Auth::guard('web')->validate(['email' => 'OWNER@Example.test', 'password' => 'secret-pass']));
        $this->assertTrue(Auth::guard('web')->validate(['username' => 'OwnerOne', 'password' => 'secret-pass']));
        $this->assertTrue(Auth::guard('web')->validate(['email' => 'legacy@example.test', 'password' => 'secret-pass']));
        $this->assertTrue(Auth::guard('web')->validate(['username' => 'legacyuser', 'password' => 'secret-pass']));
        $this->assertFalse(Auth::guard('web')->validate(['email' => 'OWNER@Example.test', 'password' => 'wrong']));
        // The password broker finds the account by the same provider.
        $this->assertSame($legacy->id, Auth::createUserProvider('users')->retrieveByCredentials(['email' => 'LEGACY@example.test'])?->getAuthIdentifier());
        $this->assertInstanceOf(CaseInsensitiveUserProvider::class, Auth::createUserProvider('customers'));
        $this->assertInstanceOf(CaseInsensitiveUserProvider::class, Auth::createUserProvider('staff'));
    }

    public function test_unique_email_rule_rejects_a_case_variant_on_every_driver(): void
    {
        $existing = User::factory()->create(['email' => 'taken@example.test']);
        DB::table('users')->where('id', $existing->id)->update(['email' => 'Taken@Example.test']);

        $this->assertTrue(Validator::make(['email' => 'TAKEN@example.TEST'], ['email' => 'unique:users,email'])->fails());
        $this->assertTrue(Validator::make(['email' => 'taken@example.test'], ['email' => 'exists:users,email'])->passes());
        $this->assertTrue(Validator::make(['email' => 'TAKEN@example.test'], ['email' => 'unique:users,email,'.$existing->id])->passes());
        $this->assertTrue(Validator::make(['email' => 'free@example.test'], ['email' => 'unique:users,email'])->passes());
    }

    public function test_store_slug_lookup_finds_a_lowercase_slug_from_a_mixed_case_url(): void
    {
        $store = Store::factory()->create();
        $this->assertSame($store->id, Store::query()->idOrSlug(strtoupper($store->slug))->value('id'));
    }

    public function test_integer_division_truncates_on_pgsql_which_is_why_money_sql_divides_by_a_decimal(): void
    {
        $intDiv = (float) DB::selectOne('SELECT 1999 / 100 AS v')->v;
        $this->assertSame(DB::getDriverName() === 'pgsql' || DB::getDriverName() === 'sqlite' ? 19.0 : 19.99, $intDiv);

        $this->assertSame('19.99', $this->dec(DB::selectOne('SELECT '.Currencies::sqlMajor('1999', null).' AS v')->v));
    }

    public function test_money_sum_over_mixed_exponents_equals_the_php_sum_on_every_driver(): void
    {
        // 1999 minor units each: USD 19.99 + KWD 1.999 + JPY 1999 = 2020.989.
        $sql = 'SELECT SUM('.Currencies::sqlMajor('1999', 'c.code').') AS v FROM currencies c WHERE c.code IN (?, ?, ?)';
        $sum = DB::selectOne($sql, ['USD', 'KWD', 'JPY'])->v;

        $php = array_sum(array_map(fn ($c) => 1999 / 10 ** Currencies::exponent($c), ['USD', 'KWD', 'JPY']));
        $this->assertSame(number_format($php, 3, '.', ''), $this->dec($sum, 3));
        $this->assertSame('2020.989', $this->dec($sum, 3));
    }

    private function dec(mixed $v, int $scale = 2): string
    {
        return number_format(round((float) $v, $scale), $scale, '.', '');
    }
}
