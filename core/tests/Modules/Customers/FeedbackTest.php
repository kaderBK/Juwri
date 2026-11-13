<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Customers;

use App\Models\Customer;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Customers\Models\CustomerFeedback;
use Juwri\Modules\Customers\Services\Crm\GuestTimeline;
use Juwri\Modules\Customers\Services\FeedbackService;
use Tests\Support\TestPlans;
use Tests\Support\UniquePhone;
use Tests\TestCase;

/**
 * SYSTEMS-RUN S-P1: customer feedback — request idempotency, token, funnel, CRM tie.
 */
class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        config(['settings.admin_allow_support_desk' => true]);
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $settings = new class(['theme_color' => '#111', 'site_title' => 'T', 'meta_description' => 'T', 'social_share_image' => 'd.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'f.png'])
        {
            public function __construct(private array $d) {}

            public function __get($k): mixed
            {
                return $this->d[$k] ?? null;
            }

            public function __set($k, $v): void
            {
                $this->d[$k] = $v;
            }

            public function __isset($k): bool
            {
                return array_key_exists($k, $this->d);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect()]);
    }

    private function scenario(): array
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 'T', 'monthly_price' => 1, 'annual_price' => 1,
            'lifetime_price' => 1, 'status' => 1, 'settings' => json_encode(['restaurant_limit' => 999]),
        ]);
        $owner = User::factory()->create(['group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $customer = Customer::create(['name' => 'C', 'store_id' => $r->id, 'phone' => UniquePhone::next('5')]);
        $order = Order::create(['store_id' => $r->id, 'type' => 'delivery', 'status' => 'completed', 'customer_id' => $customer->id]);

        return [$owner, $r, $customer, $order];
    }

    public function test_request_is_one_per_order(): void
    {
        [$owner, $r, $customer, $order] = $this->scenario();
        $svc = new FeedbackService;
        $a = $svc->requestForOrder($order);
        $b = $svc->requestForOrder($order);
        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, CustomerFeedback::where('order_id', $order->id)->count());
    }

    public function test_survey_page_and_low_rating_stays_private(): void
    {
        [$owner, $r, $customer, $order] = $this->scenario();
        $fb = new FeedbackService()->requestForOrder($order);

        $this->get(route('feedback.survey', $fb->token))->assertOk()->assertSeeHtml('How was your order');
        $this->post(route('feedback.submit', $fb->token), ['rating' => 2, 'comment' => 'Cold'])->assertOk()->assertSeeHtml('Thank you');
        $this->assertSame('responded', $fb->fresh()->status);

        // Already completed → 410.
        $this->post(route('feedback.submit', $fb->token), ['rating' => 5])->assertStatus(410);
    }

    public function test_high_rating_redirects_to_review_link(): void
    {
        [$owner, $r, $customer, $order] = $this->scenario();
        StoreOption::updateStoreOption($r->id, 'google_review_url', 'https://g.page/review');
        $fb = new FeedbackService()->requestForOrder($order);
        $this->post(route('feedback.submit', $fb->token), ['rating' => 5])->assertRedirect('https://g.page/review');
    }

    public function test_feedback_appears_in_guest_timeline(): void
    {
        [$owner, $r, $customer, $order] = $this->scenario();
        $fb = new FeedbackService()->requestForOrder($order);
        new FeedbackService()->submit($fb, 4, 'Good');
        $events = new GuestTimeline()->for($customer);
        $this->assertTrue(collect($events)->contains(fn ($e) => $e['type'] === 'feedback'));
    }

    public function test_owner_inbox_scoped(): void
    {
        [$owner, $r, $customer, $order] = $this->scenario();
        [$other, $otherR] = $this->scenario();
        $this->withoutMiddleware();
        $this->actingAs($owner)->get(route('stores.feedback.index').'?store_id='.$r->id)->assertOk();
        $this->actingAs($owner)->get(route('stores.feedback.index').'?store_id='.$otherR->id)->assertForbidden();
    }
}
