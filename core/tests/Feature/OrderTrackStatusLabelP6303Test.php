<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Storefront\Services\Api\StorefrontOrderView;
use Juwri\Modules\Storefront\Services\ViewModel\MoneyFormat;
use Juwri\Modules\Storefront\Services\ViewModel\OrderPresenter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestPlans;
use Tests\TestCase;

/**
 * P6-303: the track-page status label is derived from the same publicStatus() key the
 * JSON API exposes, so the two surfaces never drift. unpaid / on_delivery / refunded /
 * cancelled used to fall through to the generic 'Order placed' default; call_bill the
 * same. All six are pinned here, against the presenter's label AND publicStatus().
 *
 * The progress steps for a cancelled or refunded order must also be hidden — the
 * terminal label is the whole story.
 */
class OrderTrackStatusLabelP6303Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([
            Installed::class,
            Locale::class,
            PreventRequestForgery::class,
        ]);
        View::share([
            'activeTheme' => active_theme(),
            'activeThemeAssets' => active_theme(true),
            'settings' => (object) [],
            'languages' => collect(),
            'admin_languages' => collect(),
        ]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999, 'menu_limit' => 999, 'allow_ordering' => '1']),
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)), 'email' => Str::lower(Str::random(8)).'@owner.test',
        ]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R '.Str::random(4), 'sub_title' => 't', 'description' => 't',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'store_type' => 'restaurant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function presenter(Store $store): OrderPresenter
    {
        return new OrderPresenter($store, new MoneyFormat($store), store_tz((int) $store->id));
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function makeOrder(Store $store, string $status, array $extra = []): Order
    {
        return Order::create(array_merge([
            'store_id' => $store->id,
            'type' => 'takeaway',
            'status' => $status,
            'track_token' => Str::lower(Str::random(32)),
            'created_at' => now(),
        ], $extra));
    }

    /** Reflective accessor — statusLabel() is private. */
    private function statusLabel(OrderPresenter $presenter, Order $order): string
    {
        $m = new \ReflectionMethod(OrderPresenter::class, 'statusLabel');
        $m->setAccessible(true);

        return (string) $m->invoke($presenter, $order);
    }

    /** Reflective accessor — steps() is private. */
    private function steps(OrderPresenter $presenter, Order $order): array
    {
        $m = new \ReflectionMethod(OrderPresenter::class, 'steps');
        $m->setAccessible(true);

        return $m->invoke($presenter, $order);
    }

    /**
     * The full matrix the prompt names. Each row is (raw DB status, extra cols, expected
     * presenter label, expected publicStatus key).
     */
    public static function statusCases(): array
    {
        return [
            'unpaid' => ['unpaid', [], 'Payment not completed', 'pending_payment'],
            'call_bill' => ['call_bill', [], 'Ready', 'ready'],
            'on_delivery' => ['on_delivery', [], 'Out for delivery', 'on_the_way'],
            'refunded' => ['refunded', [], 'Refunded', 'refunded'],
            'new' => ['new', [], 'Order placed', 'placed'],
            'cancelled' => ['cancelled', [], 'Cancelled', 'cancelled'],
        ];
    }

    /**
     * Every case the prompt calls out: the presenter's track-page status_label equals
     * the expected English label AND matches the same key publicStatus() gives the API.
     *
     * @dataProvider statusCases
     */
    #[DataProvider('statusCases')]
    public function test_presenter_label_and_public_status_match_per_case(
        string $rawStatus,
        array $extra,
        string $expectedLabel,
        string $expectedPublicKey,
    ): void {
        $store = $this->store($this->owner());
        $order = $this->makeOrder($store, $rawStatus, $extra);
        $presenter = $this->presenter($store);

        $this->assertSame($expectedLabel, $this->statusLabel($presenter, $order));
        $this->assertSame($expectedPublicKey, StorefrontOrderView::publicStatus($order));
    }

    /** The progress steps are hidden when the order is cancelled or refunded. */
    public function test_steps_are_hidden_for_cancelled_and_refunded_orders(): void
    {
        $store = $this->store($this->owner());
        $presenter = $this->presenter($store);

        $this->assertSame([], $this->steps($presenter, $this->makeOrder($store, 'cancelled')));
        $this->assertSame([], $this->steps($presenter, $this->makeOrder($store, 'refunded')));
    }

    /** A live order still gets its progress steps — the hide is purely terminal. */
    public function test_steps_still_present_for_live_orders(): void
    {
        $store = $this->store($this->owner());
        $presenter = $this->presenter($store);

        $new = $this->makeOrder($store, 'new');
        $this->assertNotSame([], $this->steps($presenter, $new));

        $unpaid = $this->makeOrder($store, 'unpaid');
        $this->assertNotSame([], $this->steps($presenter, $unpaid));
    }

    /** The new pending_payment key is registered in en and ar so the label is not the raw English. */
    public function test_pending_payment_label_is_translated_in_en_and_ar(): void
    {
        $en = trans('lang.payment_not_completed', [], 'en');
        $ar = trans('lang.payment_not_completed', [], 'ar');

        $this->assertSame('Payment not completed', $en);
        $this->assertNotSame('lang.payment_not_completed', $ar, 'ar is missing lang.payment_not_completed');
        $this->assertNotSame($en, $ar, 'ar lang.payment_not_completed is still English');
    }
}
