<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Loyalty;

use App\Mails\CampaignMail;
use App\Models\Coupon;
use App\Models\Customer;
use App\Models\StoreOption;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Services\BirthdayOfferService;
use Juwri\Modules\Messaging\Contracts\Sms;
use Tests\TestCase;

class BirthdayOffersTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $today;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        // Fixed reference "today" so the run is deterministic.
        $this->today = Carbon::create(2026, 7, 1, 9, 0, 0);
    }

    private function storeFixture(array $options = ['birthday_offers_enabled' => '1']): array
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'Cafe', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($options as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return [$user, $store];
    }

    private function customer(Store $store, array $attrs): Customer
    {
        return Customer::create(array_merge([
            'store_id' => $store->id, 'name' => 'C', 'source' => 'test',
        ], $attrs));
    }

    /**
     * A configurable fake Sms binding: returns $result (or throws $throw) on send.
     */
    private function fakeSms(?bool $result = true, ?\Throwable $throw = null): object
    {
        return new class($result, $throw) implements Sms
        {
            /** @var list<array{to: string, feature: string}> */
            public array $sent = [];

            public function __construct(private ?bool $result, private ?\Throwable $throw) {}

            public function send(string $to, string $message, ?int $storeId = null, string $feature = 'general', bool $marketing = false): bool
            {
                $this->sent[] = ['to' => $to, 'feature' => $feature];
                if ($this->throw !== null) {
                    throw $this->throw;
                }

                return (bool) $this->result;
            }

            public function normalizePhone(string $phone): string
            {
                return $phone;
            }
        };
    }

    public function test_sends_birthday_coupon_and_marks_year(): void
    {
        [$owner, $store] = $this->storeFixture(['birthday_offers_enabled' => '1', 'birthday_reward_percentage' => '15', 'birthday_validity_days' => '7']);
        $cust = $this->customer($store, [
            'email' => 'c@example.com', 'marketing_consent' => true,
            'birthday' => '1990-07-01', // same month-day as $today
        ]);

        $res = app(BirthdayOfferService::class)->run($this->today);

        $this->assertSame(1, $res['offers']);
        $coupon = Coupon::where('user_id', $owner->id)->first();
        $this->assertNotNull($coupon);
        $this->assertSame(15, (int) $coupon->percentage);
        $this->assertStringStartsWith('BDAY', $coupon->code);
        $this->assertSame('2026-07-08', $coupon->expiry_at->format('Y-m-d')); // today + 7
        $this->assertSame(2026, (int) $cust->fresh()->last_birthday_offer_sent_year);
        Mail::assertQueued(CampaignMail::class); // CampaignMail implements ShouldQueue
    }

    public function test_is_idempotent_across_reruns(): void
    {
        [, $store] = $this->storeFixture();
        $this->customer($store, ['email' => 'c@example.com', 'marketing_consent' => true, 'birthday' => '1985-07-01']);

        $first = app(BirthdayOfferService::class)->run($this->today);
        $second = app(BirthdayOfferService::class)->run($this->today);

        $this->assertSame(1, $first['offers']);
        $this->assertSame(0, $second['offers']); // already sent this year
        $this->assertSame(1, Coupon::count());
    }

    public function test_skips_customers_without_consent(): void
    {
        [, $store] = $this->storeFixture();
        $this->customer($store, ['email' => 'c@example.com', 'marketing_consent' => false, 'sms_consent' => false, 'birthday' => '1990-07-01']);

        $res = app(BirthdayOfferService::class)->run($this->today);
        $this->assertSame(0, $res['offers']);
        $this->assertSame(0, Coupon::count());
    }

    public function test_skips_when_birthday_is_not_today(): void
    {
        [, $store] = $this->storeFixture();
        $this->customer($store, ['email' => 'c@example.com', 'marketing_consent' => true, 'birthday' => '1990-12-25']);

        $res = app(BirthdayOfferService::class)->run($this->today);
        $this->assertSame(0, $res['offers']);
    }

    public function test_disabled_restaurant_is_skipped(): void
    {
        [, $store] = $this->storeFixture(['birthday_offers_enabled' => '0']);
        $this->customer($store, ['email' => 'c@example.com', 'marketing_consent' => true, 'birthday' => '1990-07-01']);

        $res = app(BirthdayOfferService::class)->run($this->today);
        $this->assertSame(0, $res['offers']);
        $this->assertSame(0, Coupon::count());
    }

    public function test_sms_channel_sends_and_logs(): void
    {
        [, $store] = $this->storeFixture(['birthday_offers_enabled' => '1', 'birthday_channel' => 'sms']);
        $this->customer($store, ['phone' => '+16505551234', 'sms_consent' => true, 'birthday' => '1990-07-01']);

        $res = app(BirthdayOfferService::class)->run($this->today);

        $this->assertSame(1, $res['offers']);
        $this->assertDatabaseHas('sms_logs', ['to' => '+16505551234', 'feature' => 'birthday']);
    }

    public function test_mail_outage_deletes_coupon_and_next_day_retries(): void
    {
        [$owner, $store] = $this->storeFixture(['birthday_offers_enabled' => '1', 'birthday_channel' => 'email']);
        $cust = $this->customer($store, [
            'email' => 'c@example.com', 'marketing_consent' => true, 'birthday' => '1990-07-01',
        ]);

        $backup = Mail::getFacadeRoot();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));

        $res = app(BirthdayOfferService::class)->run($this->today);
        $this->assertSame(0, $res['offers']);
        $this->assertSame(0, Coupon::where('user_id', $owner->id)->count());
        $this->assertNull($cust->fresh()->last_birthday_offer_sent_year);

        // Transport recovers: the next day (still in the grace window) delivers + stamps.
        Mail::swap($backup);
        Mail::fake();
        $res = app(BirthdayOfferService::class)->run($this->today->copy()->addDay());
        $this->assertSame(1, $res['offers']);
        $this->assertSame(1, Coupon::where('user_id', $owner->id)->count());
        $this->assertSame(2026, (int) $cust->fresh()->last_birthday_offer_sent_year);
        Mail::assertQueued(CampaignMail::class, 1);
    }

    public function test_email_only_channel_without_email_is_not_stamped(): void
    {
        [, $store] = $this->storeFixture(['birthday_offers_enabled' => '1', 'birthday_channel' => 'email']);
        $cust = $this->customer($store, [
            'marketing_consent' => true, 'sms_consent' => false, 'birthday' => '1990-07-01',
        ]);

        $res = app(BirthdayOfferService::class)->run($this->today);

        $this->assertSame(0, $res['offers']);
        $this->assertSame(0, Coupon::count());
        $this->assertNull($cust->fresh()->last_birthday_offer_sent_year);
    }

    public function test_sms_exception_is_not_stamped(): void
    {
        [, $store] = $this->storeFixture(['birthday_offers_enabled' => '1', 'birthday_channel' => 'sms']);
        $cust = $this->customer($store, [
            'phone' => '+16505551234', 'sms_consent' => true, 'birthday' => '1990-07-01',
        ]);
        $this->app->instance(Sms::class, $this->fakeSms(throw: new \RuntimeException('sms down')));

        $res = app(BirthdayOfferService::class)->run($this->today);

        $this->assertSame(0, $res['offers']);
        $this->assertSame(0, Coupon::count());
        $this->assertNull($cust->fresh()->last_birthday_offer_sent_year);
    }

    public function test_grace_window_covers_two_days_but_not_three(): void
    {
        [, $store] = $this->storeFixture();
        $twoDaysAgo = $this->customer($store, ['email' => 'a@example.com', 'marketing_consent' => true, 'birthday' => '1990-06-29']);
        $threeDaysAgo = $this->customer($store, ['email' => 'b@example.com', 'marketing_consent' => true, 'birthday' => '1990-06-28']);

        $res = app(BirthdayOfferService::class)->run($this->today);

        $this->assertSame(1, $res['offers']);
        $this->assertSame(1, Coupon::count());
        $this->assertSame(2026, (int) $twoDaysAgo->fresh()->last_birthday_offer_sent_year);
        $this->assertNull($threeDaysAgo->fresh()->last_birthday_offer_sent_year);
    }

    public function test_feb_29_birthday_windows(): void
    {
        // Non-leap year: 29 Feb birthdays go out on 28 Feb.
        [, $p1] = $this->storeFixture();
        $c1 = $this->customer($p1, ['email' => 'a@example.com', 'marketing_consent' => true, 'birthday' => '2000-02-29']);
        $this->assertSame(1, app(BirthdayOfferService::class)->run(Carbon::create(2027, 2, 28, 9))['offers']);
        $this->assertSame(2027, (int) $c1->fresh()->last_birthday_offer_sent_year);
        StoreOption::updateStoreOption($p1->id, 'birthday_offers_enabled', '0');

        // Leap year: matches the real 29 Feb.
        [, $p2] = $this->storeFixture();
        $c2 = $this->customer($p2, ['email' => 'b@example.com', 'marketing_consent' => true, 'birthday' => '2000-02-29']);
        $this->assertSame(1, app(BirthdayOfferService::class)->run(Carbon::create(2028, 2, 29, 9))['offers']);
        $this->assertSame(2028, (int) $c2->fresh()->last_birthday_offer_sent_year);
        StoreOption::updateStoreOption($p2->id, 'birthday_offers_enabled', '0');

        // Leap year: NOT sent early on 28 Feb.
        [, $p3] = $this->storeFixture();
        $c3 = $this->customer($p3, ['email' => 'c@example.com', 'marketing_consent' => true, 'birthday' => '2000-02-29']);
        $this->assertSame(0, app(BirthdayOfferService::class)->run(Carbon::create(2028, 2, 28, 9))['offers']);
        $this->assertNull($c3->fresh()->last_birthday_offer_sent_year);
    }

    public function test_stamped_customer_is_not_resent_later_in_window(): void
    {
        [, $store] = $this->storeFixture();
        $cust = $this->customer($store, ['email' => 'c@example.com', 'marketing_consent' => true, 'birthday' => '1990-07-01']);

        $this->assertSame(1, app(BirthdayOfferService::class)->run($this->today)['offers']);
        // Two days later the birthday is still inside the grace window, but the year
        // stamp dedupes it.
        $this->assertSame(0, app(BirthdayOfferService::class)->run($this->today->copy()->addDays(2))['offers']);
        $this->assertSame(1, Coupon::count());
        $this->assertSame(2026, (int) $cust->fresh()->last_birthday_offer_sent_year);
    }
}
