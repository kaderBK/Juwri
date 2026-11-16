<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Models\Coupon;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\Tenancy\TenantContext;
use Juwri\Modules\Loyalty\Models\CouponBatch;
use Juwri\Modules\Loyalty\Services\CouponBulk;
use Tests\TestCase;

/**
 * Coupons C1: shared bulk engine — batches, code generation, scoped filters, bulk actions.
 *
 * Store owners are real users with a store (and so an account, AccountProvisioner). Reseller ids are plain issuer
 * keys here: resellers are an edition module (Community has none), and coupons only carry the id.
 */
class CouponBulkTest extends TestCase
{
    use RefreshDatabase;

    private const RESELLER_A = 1;

    private const RESELLER_B = 2;

    private CouponBulk $bulk;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bulk = new CouponBulk;
    }

    protected function tearDown(): void
    {
        TenantContext::current()->clear();
        parent::tearDown();
    }

    /**
     * A store owner with their own account (the store creates it).
     *
     * @return array{0: int, 1: int} [user id, account id]
     */
    private function owner(): array
    {
        $store = Store::factory()->create();
        $user = User::query()->findOrFail($store->user_id);
        $this->assertNotNull($user->account_id);

        return [(int) $user->id, (int) $user->account_id];
    }

    /**
     * Run $fn as the owner-facing tenant context of one account (as the dashboard middleware does).
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    private function asAccount(int $accountId, Closure $fn): mixed
    {
        TenantContext::current()->actAs($accountId);
        try {
            return $fn();
        } finally {
            TenantContext::current()->clear();
        }
    }

    /** @return array<string, mixed> */
    private function settings(array $extra = []): array
    {
        return $extra + ['coupon_type' => 'percentage', 'percentage' => 20, 'limit' => 3, 'expiry_at' => now()->addMonth()];
    }

    private function make(string $code, array $attrs = []): Coupon
    {
        $batchId = $attrs['batch_id'] ?? null;
        unset($attrs['batch_id']);
        $c = new Coupon;
        $c->fill($attrs + ['code' => $code, 'percentage' => 10, 'limit' => 5, 'used' => 0,
            'coupon_type' => 'percentage', 'issuer_scope' => 'platform', 'applies_to' => 'plan_checkout']);
        if ($batchId !== null) {
            $c->setAttribute('batch_id', $batchId);
        }
        $c->save();

        return $c;
    }

    /** @return list<string> */
    private function codes(array $scope, array $filters = []): array
    {
        return $this->bulk->query($scope, $filters)->pluck('code')->sort()->values()->all();
    }

    public function test_generates_1000_unique_codes_with_prefix_alphabet_and_length(): void
    {
        $batch = $this->bulk->generate(['scope' => 'platform'], $this->settings(), 1000, 'spring', 8, 'Spring promo');

        $this->assertSame(1000, $batch->count);
        $codes = Coupon::where('batch_id', $batch->id)->pluck('code');
        $this->assertCount(1000, $codes);
        $this->assertCount(1000, $codes->unique());
        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^SPRING-[ABCDEFGHJKMNPQRSTUVWXYZ23456789]{8}$/', $code);
        }
        $this->assertSame('SPRING', $batch->prefix);
        $this->assertSame('Spring promo', $batch->name);
        // Written through the model: the per-issuer key is set on every row.
        $this->assertSame(0, Coupon::where('batch_id', $batch->id)->where(fn ($q) => $q->whereNull('issuer_key')->orWhere('issuer_key', '!=', 'p'))->count());
    }

    public function test_no_prefix_and_invalid_arguments(): void
    {
        $b = $this->bulk->generate(['scope' => 'platform'], $this->settings(), 5, null, 10);
        foreach (Coupon::where('batch_id', $b->id)->pluck('code') as $code) {
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{10}$/', $code);
            $this->assertDoesNotMatchRegularExpression('/[01OIL]/', $code);
        }
        foreach ([[0, 'A', 8], [1001, 'A', 8], [5, 'bad prefix', 8], [5, 'ABCDEFGHIJKLM', 8], [5, 'A', 5], [5, 'A', 13]] as [$n, $p, $l]) {
            try {
                $this->bulk->generate(['scope' => 'platform'], $this->settings(), $n, $p, $l);
                $this->fail("expected failure for $n/$p/$l");
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        foreach ([['scope' => 'reseller'], ['scope' => 'store'], ['scope' => 'store', 'user_id' => 0], ['scope' => 'nope'], []] as $scope) {
            try {
                $this->bulk->generate($scope, $this->settings(), 1);
                $this->fail('expected failure for scope '.json_encode($scope));
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(1, CouponBatch::count());

        // The longest bulk code (12 prefix + dash + 12) fits the widened column on every driver.
        $long = $this->bulk->generate(['scope' => 'platform'], $this->settings(), 1, 'ABCDEFGHJKMN', 12);
        $this->assertSame(25, strlen((string) Coupon::where('batch_id', $long->id)->value('code')));
    }

    public function test_collision_with_existing_code_of_the_same_issuer_is_retried(): void
    {
        $this->make('pre-aaaaaaaa'); // codes are unique per issuer case-insensitively
        $seq = ['AAAAAAAA', 'AAAAAAAA', 'BBBBBBBB', 'CCCCCCCC', 'DDDDDDDD'];
        $bulk = new CouponBulk(function (int $len) use (&$seq) {
            return array_shift($seq) ?? strtoupper(substr(md5((string) microtime(true).random_int(0, 99999)), 0, $len));
        });
        $batch = $bulk->generate(['scope' => 'platform'], $this->settings(), 3, 'pre');

        $codes = Coupon::where('batch_id', $batch->id)->pluck('code')->sort()->values()->all();
        $this->assertSame(['PRE-BBBBBBBB', 'PRE-CCCCCCCC', 'PRE-DDDDDDDD'], $codes);
    }

    public function test_settings_are_copied_and_scope_forced(): void
    {
        [$otherOwner] = $this->owner();
        $batch = $this->bulk->generate(['scope' => 'reseller', 'reseller_id' => self::RESELLER_A], $this->settings([
            'user_id' => $otherOwner, 'issuer_scope' => 'platform', 'reseller_id' => self::RESELLER_B, 'applies_to' => 'food_order', 'evil' => 1,
        ]), 4, 'RS');

        $this->assertSame('reseller', $batch->issuer_scope);
        $this->assertSame(self::RESELLER_A, $batch->reseller_id);
        $this->assertNull($batch->user_id);
        $this->assertNull($batch->account_id); // a reseller batch belongs to no store account
        $coupons = Coupon::where('batch_id', $batch->id)->get();
        $this->assertCount(4, $coupons);
        foreach ($coupons as $c) {
            $this->assertSame('reseller', $c->issuer_scope);
            $this->assertSame(self::RESELLER_A, (int) $c->reseller_id);
            $this->assertNull($c->user_id);
            $this->assertSame('plan_checkout', $c->applies_to);
            $this->assertSame(20, $c->percentage);
            $this->assertSame(3, (int) $c->limit);
            $this->assertSame(0, (int) $c->used);
            $this->assertSame('r:'.self::RESELLER_A, $c->issuer_key);
        }
        $this->assertSame(20, $batch->settings['percentage']);
        $this->assertArrayNotHasKey('evil', $batch->settings);
        $this->assertArrayNotHasKey('user_id', $batch->settings);
    }

    public function test_store_scope(): void
    {
        [$userId, $accountId] = $this->owner();
        $b = $this->bulk->generate(['scope' => 'store', 'user_id' => $userId], $this->settings(['per_customer_limit' => 1, 'coupon_type' => 'fixed', 'percentage' => 4.5]), 2);
        $this->assertSame($accountId, $b->account_id); // tenant key filled from the owner's account
        $c = Coupon::where('batch_id', $b->id)->first();
        $this->assertSame('owner', $c->issuer_scope);
        $this->assertSame($userId, (int) $c->user_id);
        $this->assertSame($accountId, (int) $c->account_id);
        $this->assertSame('food_order', $c->applies_to);
        $this->assertSame(1, (int) $c->per_customer_limit);
        $this->assertSame('u:'.$userId, $c->issuer_key);
        $this->assertNull($c->percentage_rate);
        $this->assertNotNull($c->amount_minor);
        $this->assertEquals(4.5, $c->percentage);
    }

    public function test_scope_isolation_and_filters_cannot_widen(): void
    {
        [$ownerA] = $this->owner();
        [$ownerB] = $this->owner();
        [$ownerR] = $this->owner();
        $this->make('PLAT1');
        $this->make('RA1', ['issuer_scope' => 'reseller', 'reseller_id' => self::RESELLER_A]);
        $this->make('RB1', ['issuer_scope' => 'reseller', 'reseller_id' => self::RESELLER_B]);
        $this->make('SA1', ['issuer_scope' => 'owner', 'user_id' => $ownerA, 'applies_to' => 'food_order']);
        $this->make('SB1', ['issuer_scope' => 'owner', 'user_id' => $ownerB, 'applies_to' => 'food_order']);
        // An owner coupon of a merchant under reseller A is the owner's, never the reseller's.
        $this->make('SR1', ['issuer_scope' => 'owner', 'user_id' => $ownerR, 'reseller_id' => self::RESELLER_A, 'applies_to' => 'food_order']);
        $resellerA = ['scope' => 'reseller', 'reseller_id' => self::RESELLER_A];

        $this->assertSame(['PLAT1'], $this->codes(['scope' => 'platform']));
        $this->assertSame(['RA1'], $this->codes($resellerA));
        $this->assertSame(['RB1'], $this->codes(['scope' => 'reseller', 'reseller_id' => self::RESELLER_B]));
        $this->assertSame(['SA1'], $this->codes(['scope' => 'store', 'user_id' => $ownerA]));
        $this->assertSame(['SB1'], $this->codes(['scope' => 'store', 'user_id' => $ownerB]));
        $this->assertSame(['SR1'], $this->codes(['scope' => 'store', 'user_id' => $ownerR]));

        // Hostile filters (search wildcard, other scope's batch, every status) never widen.
        foreach ([['search' => '%'], ['search' => 'R'], ['search' => "' OR 1=1 --"], ['type' => 'percentage'], ['created_from' => '2000-01-01']] as $f) {
            $this->assertNotContains('RB1', $this->codes($resellerA, $f));
            $this->assertNotContains('PLAT1', $this->codes($resellerA, $f));
            $this->assertNotContains('SR1', $this->codes($resellerA, $f));
        }
        foreach (CouponBulk::STATUSES as $s) {
            $this->assertNotContains('SB1', $this->codes(['scope' => 'store', 'user_id' => $ownerA], ['status' => $s]));
        }
        $b = $this->bulk->generate(['scope' => 'reseller', 'reseller_id' => self::RESELLER_B], $this->settings(), 2);
        $this->assertSame([], $this->codes($resellerA, ['batch_id' => $b->id]));
    }

    public function test_cross_tenant_store_owners_cannot_see_or_change_each_others_coupons(): void
    {
        [$userA, $accountA] = $this->owner();
        [$userB, $accountB] = $this->owner();
        $this->assertNotSame($accountA, $accountB);
        $ownerA = ['scope' => 'store', 'user_id' => $userA];
        $ownerB = ['scope' => 'store', 'user_id' => $userB];

        // Each owner generates inside their own tenant context, as the dashboard does.
        $batchA = $this->asAccount($accountA, fn () => $this->bulk->generate($ownerA, $this->settings(), 3, 'AAA', 8, 'A promo'));
        $batchB = $this->asAccount($accountB, fn () => $this->bulk->generate($ownerB, $this->settings(), 3, 'BBB', 8, 'B promo'));
        $this->assertSame($accountA, $batchA->account_id);
        $this->assertSame($accountB, $batchB->account_id);
        $this->assertSame(3, Coupon::where('batch_id', $batchA->id)->where('account_id', $accountA)->count());
        $this->assertSame(3, Coupon::where('batch_id', $batchB->id)->where('account_id', $accountB)->count());
        $usedB = Coupon::where('batch_id', $batchB->id)->first();
        $usedB->forceFill(['used' => 1])->save();

        // Same code for two owners is allowed and stays separate (per-issuer uniqueness, no leak).
        $this->make('SHARED', ['issuer_scope' => 'owner', 'user_id' => $userA, 'applies_to' => 'food_order']);
        $this->make('SHARED', ['issuer_scope' => 'owner', 'user_id' => $userB, 'applies_to' => 'food_order']);

        $this->asAccount($accountA, function () use ($ownerA, $ownerB, $batchA, $batchB): void {
            // Batches: owner A sees only their own — by issuer filter and by the tenant scope.
            $this->assertSame([$batchA->id], $this->bulk->batches($ownerA)->pluck('id')->all());
            $this->assertSame([], $this->bulk->batches($ownerB)->pluck('id')->all());
            $this->assertNull($this->bulk->batches($ownerA)->find($batchB->id));
            $this->assertNull(CouponBatch::query()->find($batchB->id));
            $this->assertSame([], $this->bulk->batches(['scope' => 'platform'])->pluck('id')->all());

            // Even a caller passing owner B's scope inside A's context reads and changes nothing of B.
            $this->assertSame(0, $this->bulk->query($ownerB)->count());
            $this->assertSame(0, $this->bulk->expireNow($this->bulk->query($ownerB)));
            $this->assertSame([0, 0], $this->bulk->setLimit($this->bulk->query($ownerB), 1));
            $this->assertSame([0, 0], $this->bulk->deleteUnused($this->bulk->query($ownerB)));

            // Owner A addressing owner B's batch gets nothing; every bulk action stays inside A.
            $this->assertSame([], $this->codes($ownerA, ['batch_id' => $batchB->id]));
            $this->assertSame(0, $this->bulk->expireNow($this->bulk->query($ownerA, ['batch_id' => $batchB->id])));
            $this->assertSame([0, 0], $this->bulk->setLimit($this->bulk->query($ownerA, ['batch_id' => $batchB->id]), 1));
            $this->assertSame([0, 0], $this->bulk->deleteUnused($this->bulk->query($ownerA, ['batch_id' => $batchB->id])));

            // CSV of owner A never contains owner B's codes.
            $h = fopen('php://memory', 'w+');
            $this->bulk->writeCsv($h, $this->bulk->query($ownerA));
            rewind($h);
            $csv = (string) stream_get_contents($h);
            $this->assertStringContainsString('AAA-', $csv);
            $this->assertStringNotContainsString('BBB-', $csv);

            $this->assertSame(4, $this->bulk->expireNow($this->bulk->query($ownerA)));
            [$deleted, $kept] = $this->bulk->deleteUnused($this->bulk->query($ownerA));
            $this->assertSame([4, 0], [$deleted, $kept]);
        });

        // Outside any tenant context: B's rows are untouched, A's are gone.
        $bRows = Coupon::where('user_id', $userB)->get();
        $this->assertCount(4, $bRows);
        foreach ($bRows as $c) {
            $this->assertTrue($c->expiry_at === null || $c->expiry_at->isFuture());
            $this->assertNotSame(1, (int) $c->limit); // setLimit(1) never reached B (batch limit 3, hand-made 5)
        }
        $this->assertSame(0, Coupon::where('user_id', $userA)->count());

        // A code of another owner never counts as taken for generation.
        $seq = ['ZZZZZZZZ'];
        $this->make('X-ZZZZZZZZ', ['issuer_scope' => 'owner', 'user_id' => $userB, 'applies_to' => 'food_order']);
        $bulk = new CouponBulk(fn (int $len) => array_shift($seq) ?? str_repeat('Q', $len));
        $b = $this->asAccount($accountA, fn () => $bulk->generate($ownerA, $this->settings(), 1, 'X'));
        $this->assertSame(['X-ZZZZZZZZ'], Coupon::where('batch_id', $b->id)->pluck('code')->all());
        $this->assertSame(2, Coupon::where('code', 'X-ZZZZZZZZ')->count());
    }

    public function test_status_filters(): void
    {
        $this->make('NEVER');
        $this->make('PARTLY', ['used' => 2, 'limit' => 5]);
        $this->make('PARTLYUNL', ['used' => 2, 'limit' => 0]);
        $this->make('USEDUP', ['used' => 5, 'limit' => 5]);
        $this->make('EXPIRED', ['expiry_at' => now()->subDay()]);
        $this->make('FUTURE', ['expiry_at' => now()->addDay()]);

        $f = fn (string $s) => $this->codes(['scope' => 'platform'], ['status' => $s]);
        $this->assertSame(['EXPIRED', 'FUTURE', 'NEVER'], $f('never_used'));
        $this->assertSame(['PARTLY', 'PARTLYUNL'], $f('partly_used'));
        $this->assertSame(['USEDUP'], $f('used_up'));
        $this->assertSame(['EXPIRED'], $f('expired'));
        $this->assertSame(['FUTURE', 'NEVER', 'PARTLY', 'PARTLYUNL'], $f('active'));

        $this->expectException(InvalidArgumentException::class);
        $f('bogus');
    }

    public function test_other_filters_and_escaped_search(): void
    {
        $b = $this->bulk->generate(['scope' => 'platform'], $this->settings(['coupon_type' => 'fixed']), 3, 'AB');
        $this->make('AB_LIT', ['applies_interval' => 'monthly']);
        $this->make('ABXLIT');
        $this->make('100%OFF');
        $q = fn (array $f) => $this->codes(['scope' => 'platform'], $f);

        $this->assertSame(['AB_LIT'], $q(['search' => 'ab_']));
        $this->assertSame(['100%OFF'], $q(['search' => '100%']));
        $this->assertSame([], $q(['search' => '%']));
        $this->assertCount(3, $q(['batch_id' => $b->id]));
        $this->assertCount(3, $q(['type' => 'fixed']));
        $this->assertSame(['AB_LIT'], $q(['applies_interval' => 'monthly']));
        $this->assertCount(6, $q(['created_from' => now()->toDateString(), 'created_to' => now()->toDateString()]));
        $this->assertSame([], $q(['created_to' => now()->subDays(2)->toDateString()]));
        $this->assertCount(3, $q(['search' => 'ab-']));
    }

    public function test_delete_unused_keeps_used(): void
    {
        $a = $this->make('UNUSED1');
        $this->make('UNUSED2');
        $this->make('USED1', ['used' => 1]);
        $other = $this->make('OTHERRES', ['issuer_scope' => 'reseller', 'reseller_id' => self::RESELLER_A]);

        [$deleted, $kept] = $this->bulk->deleteUnused($this->bulk->query(['scope' => 'platform']));

        $this->assertSame([2, 1], [$deleted, $kept]);
        $this->assertNull(Coupon::find($a->id));
        $this->assertNotNull(Coupon::where('code', 'USED1')->first());
        $this->assertNotNull(Coupon::find($other->id));
    }

    public function test_expire_extend_and_set_limit(): void
    {
        $open = $this->make('OPEN', ['expiry_at' => now()->addDays(5)]);
        $never = $this->make('NOEXP', ['expiry_at' => null]);
        $used = $this->make('USED', ['used' => 4, 'limit' => 9, 'expiry_at' => now()->addDays(5)]);
        $done = $this->make('DONE', ['expiry_at' => now()->subDay()]);
        $foreign = $this->make('FOREIGN', ['issuer_scope' => 'reseller', 'reseller_id' => self::RESELLER_B, 'expiry_at' => now()->addDays(5)]);
        $q = fn () => $this->bulk->query(['scope' => 'platform']);

        // limit: used=4 cannot drop to 2
        [$updated, $skipped] = $this->bulk->setLimit($q(), 2);
        $this->assertSame([3, 1], [$updated, $skipped]);
        $this->assertSame(2, (int) $open->fresh()->limit);
        $this->assertSame(9, (int) $used->fresh()->limit);
        $this->bulk->setLimit($q(), 0);
        $this->assertSame(0, (int) $used->fresh()->limit);
        $this->assertSame(5, (int) $foreign->fresh()->limit);

        // extend: only coupons with an earlier expiry move; never-expiring stay null
        $to = now()->addDays(30);
        $this->assertSame(3, $this->bulk->extendExpiry($q(), $to));
        $this->assertTrue($open->fresh()->expiry_at->isSameDay($to));
        $this->assertTrue($done->fresh()->expiry_at->isFuture());
        $this->assertNull($never->fresh()->expiry_at);
        $this->assertFalse($foreign->fresh()->expiry_at->isSameDay($to));

        // expire now: everything not yet expired (incl. never-expiring)
        $this->assertSame(4, $this->bulk->expireNow($q()));
        foreach ([$open, $never, $used, $done] as $c) {
            $this->assertTrue($c->fresh()->expiry_at->isPast());
        }
        $this->assertTrue($foreign->fresh()->expiry_at->isFuture());
        $this->assertSame(0, $this->bulk->expireNow($q()));

        $this->expectException(InvalidArgumentException::class);
        $this->bulk->extendExpiry($q(), now()->subDay());
    }

    public function test_csv_contents(): void
    {
        $b = CouponBatch::create(['name' => '=cmd', 'issuer_scope' => 'platform', 'count' => 1]);
        $this->make('CSV1', ['used' => 2, 'limit' => 5, 'batch_id' => $b->id, 'expiry_at' => Carbon::parse('2031-05-06 07:08:09')]);
        $this->make('CSV2', ['used' => 5, 'limit' => 5, 'expiry_at' => null, 'coupon_type' => 'fixed', 'percentage' => 12]);
        $this->make('CSVX', ['issuer_scope' => 'reseller', 'reseller_id' => self::RESELLER_A]);

        $h = fopen('php://memory', 'w+');
        $this->bulk->writeCsv($h, $this->bulk->query(['scope' => 'platform'])->orderBy('code'));
        rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, null, ',', '"', '')) !== false) {
            $rows[] = $r;
        }

        $this->assertSame(['code', 'batch', 'type', 'amount', 'used', 'limit', 'remaining', 'expiry_at', 'status'], $rows[0]);
        $this->assertCount(3, $rows);
        $this->assertSame(['CSV1', "'=cmd", 'percentage', '10', '2', '5', '3', '2031-05-06 07:08:09', 'partly_used'], $rows[1]);
        $this->assertSame(['CSV2', '', 'fixed', '12', '5', '5', '0', '', 'used_up'], $rows[2]);

        $resp = $this->bulk->exportCsv($this->bulk->query(['scope' => 'platform']), 'x.csv');
        ob_start();
        $resp->sendContent();
        $body = (string) ob_get_clean();
        $this->assertStringContainsString('CSV1', $body);
        $this->assertStringNotContainsString('CSVX', $body);
    }
}
