<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Mail\CustomerContactChange;
use Juwri\Modules\Messaging\Contracts\Sms;
use Juwri\Modules\Messaging\Contracts\SmsOtp;
use Juwri\Modules\Messaging\Data\OtpScope;
use Tests\TestCase;

/** P6-304: a profile phone / e-mail change only takes effect once proven, and the old address is told. */
class CustomerContactChangeTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{0: string, 1: string}> */
    private array $otpSent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        Mail::fake();

        $sent = &$this->otpSent;
        $this->app->instance(SmsOtp::class, new class($sent) implements SmsOtp
        {
            /** @param list<array{0: string, 1: string}> $sent */
            public function __construct(private array &$sent) {}

            public function request(string $phone, OtpScope $scope): array
            {
                $this->sent[] = [$phone, '123456'];

                return [true, 'sent'];
            }

            public function verify(string $phone, string $code, OtpScope $scope): bool
            {
                foreach ($this->sent as [$p, $c]) {
                    if ($p === $phone && $c === $code) {
                        return true;
                    }
                }

                return false;
            }
        });
    }

    private function store(): Store
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $owner->id, 'slug' => 'shop-'.Str::lower(Str::random(6)),
            'color' => '#111', 'title' => 'Shop', 'sub_title' => 'Sub', 'description' => 'D',
            'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function customer(Store $store, array $attrs = []): Customer
    {
        $c = Customer::create($attrs + ['store_id' => $store->id, 'name' => 'Sam', 'email' => 'sam@ex.co', 'phone' => '+966500000001']);
        $c->forceFill(['phone_verified_at' => now(), 'email_verified_at' => now()])->save();

        return $c;
    }

    public function test_a_new_phone_waits_for_its_code_and_the_old_email_is_told(): void
    {
        $store = $this->store();
        $c = $this->customer($store);

        $this->actingAs($c, 'customer')->postJson(route('customer.profile.update', $store->id), ['name' => 'Sam', 'phone' => '+966 500 000 999'])
            ->assertOk()->assertJsonPath('pending.phone', true);

        $fresh = $c->fresh();
        $this->assertSame('+966500000001', $fresh->phone, 'the live phone must not change before the code');
        $this->assertNotNull($fresh->phone_verified_at);
        $this->assertNotEmpty($fresh->pending_phone);
        Mail::assertQueued(CustomerContactChange::class, fn ($m) => $m->kind === 'notice_old' && $m->hasTo('sam@ex.co'));

        $this->actingAs($c, 'customer')->postJson(route('customer.profile.phone.verify', $store->id), ['code' => '000000'])->assertStatus(422);
        $this->assertSame('+966500000001', $c->fresh()->phone);

        $this->actingAs($c, 'customer')->postJson(route('customer.profile.phone.verify', $store->id), ['code' => '123456'])->assertOk();
        $this->assertSame($fresh->pending_phone, $c->fresh()->phone);
        $this->assertNull($c->fresh()->pending_phone);
    }

    public function test_a_phone_used_by_another_customer_is_refused_whatever_the_format(): void
    {
        $store = $this->store();
        $other = $this->customer($store, ['email' => 'o@ex.co', 'phone' => '+966500000777']);
        $c = $this->customer($store, ['email' => 'c@ex.co', 'phone' => '+966500000001']);
        $normalised = app(Sms::class)->normalizePhone('+966 500 000 777');
        $other->forceFill(['phone' => $normalised])->save();

        $this->actingAs($c, 'customer')->postJson(route('customer.profile.update', $store->id), ['name' => 'C', 'phone' => '+966 500 000 777'])
            ->assertStatus(422);
        $this->assertSame([], $this->otpSent);
    }

    public function test_a_new_email_waits_for_the_signed_link(): void
    {
        $store = $this->store();
        $c = $this->customer($store);

        $this->actingAs($c, 'customer')->postJson(route('customer.profile.update', $store->id), ['name' => 'Sam', 'email' => 'New@Ex.co'])
            ->assertOk()->assertJsonPath('pending.email', true);
        $this->assertSame('sam@ex.co', $c->fresh()->email);
        Mail::assertQueued(CustomerContactChange::class, fn ($m) => $m->kind === 'confirm_email' && $m->hasTo('new@ex.co'));
        Mail::assertQueued(CustomerContactChange::class, fn ($m) => $m->kind === 'notice_old' && $m->hasTo('sam@ex.co'));

        // A link for a different address does nothing.
        $bad = URL::temporarySignedRoute('customer.profile.email.verify', now()->addHour(), ['store' => $store->id, 'customer' => $c->id, 'h' => hash('sha256', 'evil@ex.co')]);
        $this->get($bad)->assertRedirect();
        $this->assertSame('sam@ex.co', $c->fresh()->email);

        $good = URL::temporarySignedRoute('customer.profile.email.verify', now()->addHour(), ['store' => $store->id, 'customer' => $c->id, 'h' => hash('sha256', 'new@ex.co')]);
        $this->get($good)->assertRedirect();
        $this->assertSame('new@ex.co', $c->fresh()->email);
        $this->assertNull($c->fresh()->pending_email);

        // Used once: replaying it changes nothing more.
        $c->fresh()->forceFill(['email' => 'later@ex.co'])->save();
        $this->get($good)->assertRedirect();
        $this->assertSame('later@ex.co', $c->fresh()->email);
    }

    public function test_birthday_must_be_past_and_can_be_cleared(): void
    {
        $store = $this->store();
        $c = $this->customer($store, ['birthday' => '1990-01-01']);

        $this->actingAs($c, 'customer')->postJson(route('customer.profile.update', $store->id), ['name' => 'Sam', 'birthday' => now()->addDay()->toDateString()])
            ->assertStatus(422);
        $this->actingAs($c, 'customer')->postJson(route('customer.profile.update', $store->id), ['name' => 'Sam', 'birthday' => ''])
            ->assertOk();
        $this->assertNull($c->fresh()->birthday);
    }

    public function test_unchanged_contact_details_keep_their_verified_flags(): void
    {
        $store = $this->store();
        $c = $this->customer($store);

        $this->actingAs($c, 'customer')->postJson(route('customer.profile.update', $store->id), ['name' => 'Sam B', 'email' => 'SAM@ex.co', 'phone' => '+966500000001'])
            ->assertOk()->assertJsonPath('pending', []);
        $this->assertNotNull($c->fresh()->phone_verified_at);
        Mail::assertNothingQueued();
    }
}
