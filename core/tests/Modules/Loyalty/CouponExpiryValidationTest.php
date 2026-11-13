<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Coupon;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * BL-036 C7: a store owner's coupon expiry must be a real, non-past calendar date. The loose `date` rule let a
 * typo like year 12026 or 30 February through (read as some other date, possibly already expired). Editing may
 * re-send the stored expiry unchanged, so an expired coupon can still be edited. One plain translated message.
 */
class CouponExpiryValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function owner(?string $tz = null): User
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($tz !== null) {
            StoreOption::updateStoreOption($store->id, 'timezone', $tz);
        }

        return $owner;
    }

    /** @return array<string,mixed> */
    private function form(string $code, ?string $expiry): array
    {
        return ['code' => $code, 'coupon_type' => 'percentage', 'percentage' => 20, 'limit' => 10, 'expiry_at' => $expiry];
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function invalidExpiries(): array
    {
        return [
            'five-digit year' => ['12026-03-12', 'Enter a valid expiry date.'],
            'impossible day' => ['2031-02-30', 'Enter a valid expiry date.'],
            'not a date' => ['tomorrow-ish', 'Enter a valid expiry date.'],
            'other format' => ['12/03/2031', 'Enter a valid expiry date.'],
            'yesterday' => ['2026-10-26', 'The expiry date cannot be in the past.'],
        ];
    }

    #[DataProvider('invalidExpiries')]
    public function test_create_rejects_impossible_or_past_expiry_with_one_plain_message(string $expiry, string $message): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $owner = $this->owner();

        $this->actingAs($owner)->from(route('stores.coupons.create'))
            ->post(route('stores.coupons.store'), $this->form('BADDATE', $expiry))
            ->assertRedirect(route('stores.coupons.create'))
            ->assertSessionHasErrors(['expiry_at' => $message]);

        $this->assertSame([$message], session('errors')->get('expiry_at'), 'exactly one error, never the raw format');
        $this->assertFalse(Coupon::where('code', 'BADDATE')->exists());
    }

    public function test_create_accepts_today_a_future_date_and_no_expiry(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $owner = $this->owner();

        foreach (['TODAY1' => '2026-10-27', 'LATER1' => '2027-10-27', 'NEVER1' => null] as $code => $expiry) {
            $this->actingAs($owner)->post(route('stores.coupons.store'), $this->form($code, $expiry))
                ->assertSessionHasNoErrors()
                ->assertRedirect(route('stores.coupons.index'));
        }

        $this->assertSame('2026-10-27', Coupon::where('code', 'TODAY1')->first()->expiry_at->toDateString());
        $this->assertSame('2027-10-27', Coupon::where('code', 'LATER1')->first()->expiry_at->toDateString());
        $this->assertNull(Coupon::where('code', 'NEVER1')->first()->expiry_at);
    }

    public function test_today_is_read_in_the_store_timezone(): void
    {
        // 22:00 UTC on 26 Oct is already 27 Oct in Riyadh (UTC+3): 26 Oct is past there.
        Carbon::setTestNow(Carbon::parse('2026-10-26 22:00:00', 'UTC'));
        $owner = $this->owner('Asia/Riyadh');

        $this->actingAs($owner)->post(route('stores.coupons.store'), $this->form('UTCDAY', '2026-10-26'))
            ->assertSessionHasErrors(['expiry_at' => 'The expiry date cannot be in the past.']);
        $this->actingAs($owner)->post(route('stores.coupons.store'), $this->form('LOCALDAY', '2026-10-27'))
            ->assertSessionHasNoErrors();
    }

    public function test_edit_keeps_an_unchanged_past_expiry_but_rejects_a_new_past_one(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $owner = $this->owner();
        $coupon = Coupon::create([
            'code' => 'OLDONE', 'percentage' => 10, 'limit' => 5, 'used' => 0, 'coupon_type' => 'percentage',
            'user_id' => $owner->id, 'issuer_scope' => 'owner', 'applies_to' => 'food_order',
            'expiry_at' => Carbon::parse('2026-09-27 23:59:59', 'UTC'),
        ]);

        // Changing the discount of an already expired coupon must not force a new expiry date.
        $this->actingAs($owner)->put(route('stores.coupons.update', $coupon), ['percentage' => 15] + $this->form('OLDONE', '2026-09-27'))
            ->assertSessionHasNoErrors();
        $this->assertSame(15.0, (float) $coupon->fresh()->percentage);
        $this->assertSame('2026-09-27', $coupon->fresh()->expiry_at->toDateString());

        // Moving it to a different past date is still refused, and nothing changes.
        $this->actingAs($owner)->from(route('stores.coupons.edit', $coupon))
            ->put(route('stores.coupons.update', $coupon), $this->form('OLDONE', '2026-10-20'))
            ->assertSessionHasErrors(['expiry_at' => 'The expiry date cannot be in the past.']);
        $this->assertSame('2026-09-27', $coupon->fresh()->expiry_at->toDateString());

        // An impossible date is refused on edit too.
        $this->actingAs($owner)->put(route('stores.coupons.update', $coupon), $this->form('OLDONE', '12026-03-12'))
            ->assertSessionHasErrors(['expiry_at' => 'Enter a valid expiry date.']);
    }

    /** Review fix 8: re-sending the stored date keeps the stored moment exactly (no re-stamp to end of day). */
    public function test_edit_with_the_unchanged_date_keeps_the_stored_moment(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $owner = $this->owner('Asia/Riyadh');
        $coupon = Coupon::create([
            'code' => 'KEEPME', 'percentage' => 10, 'limit' => 5, 'used' => 0, 'coupon_type' => 'percentage',
            'user_id' => $owner->id, 'issuer_scope' => 'owner', 'applies_to' => 'food_order',
            'expiry_at' => Carbon::parse('2026-11-30 10:15:00', 'UTC'), // 13:15 in Riyadh: not an end-of-day stamp
        ]);

        $this->actingAs($owner)->put(route('stores.coupons.update', $coupon), ['percentage' => 12] + $this->form('KEEPME', '2026-11-30'))
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-11-30 10:15:00', $coupon->fresh()->expiry_at->format('Y-m-d H:i:s'));

        $this->actingAs($owner)->put(route('stores.coupons.update', $coupon), $this->form('KEEPME', '2026-12-01'))
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-12-01 20:59:59', $coupon->fresh()->expiry_at->format('Y-m-d H:i:s'), 'a changed date is the end of that store day');
    }

    public function test_the_error_is_translated(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-27 12:00:00', 'UTC'));
        $owner = $this->owner();
        app()->setLocale('ar');

        $this->actingAs($owner)->post(route('stores.coupons.store'), $this->form('ARDATE', '2026-01-01'))
            ->assertSessionHasErrors(['expiry_at' => 'لا يمكن أن يكون تاريخ الانتهاء في الماضي.']);
        $this->actingAs($owner)->post(route('stores.coupons.store'), $this->form('ARDATE', '12026-03-12'))
            ->assertSessionHasErrors(['expiry_at' => 'أدخل تاريخ انتهاء صالحًا.']);
    }
}
