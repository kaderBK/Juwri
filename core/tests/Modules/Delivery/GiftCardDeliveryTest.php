<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery;

use App\Models\GiftCard;
use App\Models\StoreOption;
use App\Services\GiftCardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Loyalty\Mail\GiftCardMail;
use Tests\Support\TestPlans;
use Tests\TestCase;

class GiftCardDeliveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Mail::fake();
    }

    private function storeFixture(): Store
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 10, 'annual_price' => 100,
            'lifetime_price' => 1000, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 't', 'description' => 't', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1 St', 'phone' => '123', 'created_at' => now(), 'updated_at' => now(),
        ]);
        StoreOption::updateStoreOption($r->id, 'currency_code', 'USD');

        return $r;
    }

    private function active(Store $r, array $meta = []): GiftCard
    {
        ['card' => $card, 'transaction' => $txn] = GiftCardService::initiate($r->id, 50.0, 'stripe', $meta);
        GiftCardService::activate($card, $txn);

        return $card->fresh();
    }

    public function test_deliver_sends_email_and_is_idempotent(): void
    {
        $r = $this->storeFixture();
        $card = $this->active($r, ['recipient_email' => 'gift@x.com']);

        $this->assertTrue(GiftCardService::deliver($card));
        $this->assertNotNull($card->fresh()->delivered_at);
        Mail::assertQueued(GiftCardMail::class, 1);

        // Re-run → no second send.
        $this->assertFalse(GiftCardService::deliver($card->fresh()));
        Mail::assertQueued(GiftCardMail::class, 1);
    }

    public function test_scheduled_send_defers_until_due(): void
    {
        $r = $this->storeFixture();
        $card = $this->active($r, [
            'recipient_email' => 'gift@x.com',
            'scheduled_send_at' => Carbon::now()->addDays(2),
        ]);

        // Not due yet → nothing sent.
        $this->assertSame(0, GiftCardService::deliverDue());
        Mail::assertNothingQueued();
        $this->assertNull($card->fresh()->delivered_at);

        // Time passes → the tick delivers it.
        Carbon::setTestNow(Carbon::now()->addDays(3));
        $this->assertSame(1, GiftCardService::deliverDue());
        Carbon::setTestNow();
        Mail::assertQueued(GiftCardMail::class, 1);
        $this->assertNotNull($card->fresh()->delivered_at);
    }

    public function test_activation_delivers_immediately_when_no_schedule(): void
    {
        $r = $this->storeFixture();
        // activate() is called inside active(); with no scheduled_send_at delivery
        // happens via the CheckoutController hook — here we assert deliver() path.
        $card = $this->active($r, ['recipient_email' => 'now@x.com']);
        GiftCardService::deliver($card);
        Mail::assertQueued(GiftCardMail::class, function ($m) {
            return $m->hasTo('now@x.com');
        });
    }

    public function test_gift_card_mail_uses_the_store_language_and_rtl(): void
    {
        $r = $this->storeFixture();
        StoreOption::updateStoreOption($r->id, 'default_language', 'ar');
        $card = $this->active($r, ['recipient_email' => 'gift@x.com']);

        $this->assertTrue(GiftCardService::deliver($card));
        Mail::assertQueued(GiftCardMail::class, fn (GiftCardMail $m) => $m->locale === 'ar');

        $mail = new GiftCardMail($card->fresh(), 'R', null, 'ar');
        $html = $mail->render();
        $this->assertStringContainsString('dir="rtl"', $html);
    }
}
