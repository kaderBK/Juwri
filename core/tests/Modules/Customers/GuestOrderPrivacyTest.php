<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Services\Privacy\ShopperPrivacyService;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-209: guest orders (no account, customer_id NULL) are part of a GDPR / PDPL export and erasure once the phone is
 * proven — by the account's verified phone, or by a one-time code in the owner's guest privacy request. Exact phone
 * match only; other stores and other phones untouched; amounts kept (fiscal retention); an audit row written.
 */
class GuestOrderPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+218912345678';

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create(['name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'settings' => ['restaurant_limit' => 999], 'status' => 1]);

        return User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
    }

    private function store(User $owner, string $title): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'shop-'.Str::lower(Str::random(6)), 'color' => '#111', 'title' => $title,
            'sub_title' => 'Sub', 'description' => 'D', 'main_image' => 'default.png', 'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123',
        ]);
    }

    private function guestOrder(Store $store, string $phone, string $name = 'Guest G', string $status = 'completed'): Order
    {
        return Order::create(['store_id' => $store->id, 'type' => 'delivery', 'status' => $status, 'customer_id' => null,
            'customer_name' => $name, 'phone_number' => $phone, 'address' => $name.' Road 9', 'message' => 'no onions', 'tip_amount' => 1.5]);
    }

    private function customer(Store $store, bool $verified): Customer
    {
        $c = Customer::create(['store_id' => $store->id, 'name' => 'Alice', 'email' => Str::random(6).'@ex.co',
            'phone' => self::PHONE, 'password' => Hash::make('secret-pass')]);
        if ($verified) {
            $c->forceFill(['phone_verified_at' => now()])->save();
        }

        return $c->fresh();
    }

    public function test_account_with_verified_phone_exports_and_erases_matching_guest_orders_only(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'A');
        $other = $this->store($owner, 'B');
        $me = $this->customer($store, true);
        $mine = $this->guestOrder($store, '00218 91 234 5678');            // same phone, other format
        $notMine = $this->guestOrder($store, '+218912345679', 'Bob');     // one digit off
        $suffixOnly = $this->guestOrder($store, '912345678', 'Carol');     // local form: never a fuzzy match
        $elsewhere = $this->guestOrder($other, self::PHONE, 'Alice B');   // same phone, another store

        $res = $this->actingAs($me, 'customer')->post(route('customer.privacy.export', $store->id), ['password' => 'secret-pass']);
        $res->assertOk();
        $ids = array_column(json_decode($res->streamedContent(), true)['tables']['orders'], 'id');
        $this->assertSame([(int) $mine->id], $ids);

        $this->actingAs($me, 'customer')->post(route('customer.privacy.delete', $store->id), ['password' => 'secret-pass', 'confirm' => 'DELETE'])
            ->assertOk();

        $row = DB::table('orders')->where('id', $mine->id)->first();
        $this->assertSame('', $row->customer_name);
        $this->assertSame('', $row->phone_number);
        $this->assertSame('', $row->address);
        $this->assertSame(1.5, (float) Order::find($mine->id)->tip_amount, 'amounts are kept for tax retention');
        foreach ([$notMine, $suffixOnly, $elsewhere] as $o) {
            $this->assertNotSame('', (string) DB::table('orders')->where('id', $o->id)->value('phone_number'));
        }
        $log = DB::table('activity_logs')->where('action', 'customer.self_erased')->first();
        $this->assertSame(1, json_decode($log->meta, true)['rows']['guest_orders']);
    }

    public function test_unverified_account_phone_never_reaches_guest_orders(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'A');
        $me = $this->customer($store, false);
        $guest = $this->guestOrder($store, self::PHONE);

        $res = $this->actingAs($me, 'customer')->post(route('customer.privacy.export', $store->id), ['password' => 'secret-pass']);
        $this->assertSame([], json_decode($res->streamedContent(), true)['tables']['orders']);
        $this->actingAs($me, 'customer')->post(route('customer.privacy.delete', $store->id), ['password' => 'secret-pass', 'confirm' => 'DELETE'])->assertOk();
        $this->assertSame(self::PHONE, DB::table('orders')->where('id', $guest->id)->value('phone_number'));
    }

    public function test_open_guest_order_blocks_account_erasure(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'A');
        $me = $this->customer($store, true);
        $this->guestOrder($store, self::PHONE, 'G', 'pending');

        $this->actingAs($me, 'customer')->post(route('customer.privacy.delete', $store->id), ['password' => 'secret-pass', 'confirm' => 'DELETE'])
            ->assertStatus(409);
        $this->assertDatabaseHas('customers', ['id' => $me->id]);
    }

    private function fakeOtp(Store $store): void
    {
        $otp = \Mockery::mock(SmsOtp::class);
        $otp->shouldReceive('request')->withArgs(fn ($phone, OtpScope $scope) => $phone === self::PHONE && $scope->key === 'privacy:'.$store->id)
            ->andReturn([true, 'sent']);
        $otp->shouldReceive('verify')->andReturnUsing(fn ($phone, $code, OtpScope $scope) => $phone === self::PHONE && $code === '123456' && $scope->key === 'privacy:'.$store->id);
        $this->app->instance(SmsOtp::class, $otp);
        $this->withoutMiddleware(); // owner panel gates (plan, 2FA, …) are not under test here
    }

    public function test_owner_guest_request_needs_the_code_then_exports_and_anonymises_with_an_audit_row(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'A');
        $other = $this->store($owner, 'B');
        $guest = $this->guestOrder($store, '+218 91-234-5678');
        $stranger = $this->guestOrder($store, '+218911111111', 'Bob');
        $elsewhere = $this->guestOrder($other, self::PHONE);
        $this->fakeOtp($store);
        $session = ['dashboard_store_id' => $store->id];

        // not proven yet
        $this->actingAs($owner)->withSession($session)->post(route('customers.guestPrivacy.export'))->assertForbidden();
        $this->actingAs($owner)->withSession($session)->post(route('customers.guestPrivacy.erase'), ['confirm' => 'DELETE'])->assertForbidden();

        $this->actingAs($owner)->withSession($session)->post(route('customers.guestPrivacy.code'), ['phone' => self::PHONE])->assertRedirect();
        $this->actingAs($owner)->post(route('customers.guestPrivacy.verify'), ['code' => '000000'])->assertSessionHasErrors('code');
        $this->actingAs($owner)->post(route('customers.guestPrivacy.export'))->assertForbidden();
        $this->actingAs($owner)->post(route('customers.guestPrivacy.verify'), ['code' => '123456'])->assertRedirect(route('customers.guestPrivacy'));

        $res = $this->actingAs($owner)->post(route('customers.guestPrivacy.export'));
        $res->assertOk();
        $json = json_decode($res->streamedContent(), true);
        $this->assertSame('juwri.guest-export.v1', $json['format']);
        $this->assertSame([(int) $guest->id], array_column($json['tables']['orders'], 'id'));
        $this->assertArrayNotHasKey('track_token', $json['tables']['orders'][0]);

        $this->actingAs($owner)->post(route('customers.guestPrivacy.erase'), ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        $this->actingAs($owner)->post(route('customers.guestPrivacy.erase'), ['confirm' => 'DELETE'])->assertRedirect();

        $this->assertSame('', DB::table('orders')->where('id', $guest->id)->value('phone_number'));
        $this->assertSame('', DB::table('orders')->where('id', $guest->id)->value('customer_name'));
        $this->assertSame(1.5, (float) Order::find($guest->id)->tip_amount);
        $this->assertSame('+218911111111', DB::table('orders')->where('id', $stranger->id)->value('phone_number'));
        $this->assertSame(self::PHONE, DB::table('orders')->where('id', $elsewhere->id)->value('phone_number'));

        $audit = DB::table('activity_logs')->where('store_id', $store->id)->where('action', 'customer.guest_erased')->first();
        $this->assertNotNull($audit);
        $this->assertSame((int) $owner->id, (int) $audit->actor_user_id);
        $this->assertStringNotContainsString('912345678', (string) $audit->meta, 'no phone in the audit row');
        $this->assertSame(1, json_decode($audit->meta, true)['rows']['guest_orders']);
        $this->assertTrue(DB::table('activity_logs')->where('store_id', $store->id)->where('action', 'customer.guest_export')->exists());

        // the proof is spent with the erasure
        $this->actingAs($owner)->post(route('customers.guestPrivacy.export'))->assertForbidden();
    }

    public function test_owner_guest_erase_refuses_while_an_order_is_open(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner, 'A');
        $open = $this->guestOrder($store, self::PHONE, 'G', 'pending');
        $this->fakeOtp($store);

        $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])->post(route('customers.guestPrivacy.code'), ['phone' => self::PHONE]);
        $this->actingAs($owner)->post(route('customers.guestPrivacy.verify'), ['code' => '123456']);
        $this->actingAs($owner)->post(route('customers.guestPrivacy.erase'), ['confirm' => 'DELETE'])->assertSessionHas('error');
        $this->assertSame(self::PHONE, DB::table('orders')->where('id', $open->id)->value('phone_number'));
    }

    public function test_owner_page_renders_before_and_after_verification(): void
    {
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->shareLayoutGlobals();
        $owner = $this->owner();
        $store = $this->store($owner, 'A');
        $this->guestOrder($store, self::PHONE);
        $this->fakeOtp($store);

        $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])->get(route('customers.guestPrivacy'))
            ->assertOk()->assertSee('Send code')->assertDontSee('Download data (JSON)');
        $this->actingAs($owner)->post(route('customers.guestPrivacy.code'), ['phone' => self::PHONE]);
        $this->actingAs($owner)->post(route('customers.guestPrivacy.verify'), ['code' => '123456']);
        $this->actingAs($owner)->get(route('customers.guestPrivacy'))
            ->assertOk()->assertSee(self::PHONE)->assertSee('Download data (JSON)')->assertSee('Anonymise guest orders');
        $this->actingAs($owner)->get(route('customers.index'))->assertOk()->assertSee(route('customers.guestPrivacy'));
    }

    public function test_phone_normalisation_is_exact(): void
    {
        $this->assertSame('+218912345678', ShopperPrivacyService::normalisePhone('00218 91-234-5678'));
        $this->assertSame('+218912345678', ShopperPrivacyService::normalisePhone('+218 (91) 234 5678'));
        $this->assertSame('912345678', ShopperPrivacyService::normalisePhone('91 234 5678'));
    }
}
