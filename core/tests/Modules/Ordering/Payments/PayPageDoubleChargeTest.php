<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering\Payments;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Ordering\Http\Controllers\User\PaymentController;
use Juwri\Modules\Payments\Models\Transaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * P6-254: the pay page never hands a paid transaction or a closed order to a gateway (a second charge the
 * settlement would refuse to book), and a payment confirmed after the order was cancelled never revives it.
 * Gateways are faked (Http::fake) — nothing leaves the test.
 */
class PayPageDoubleChargeTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Http::preventStrayRequests();
        app()->setLocale('en');

        $settings = new class(['theme_color' => '#111', 'site_title' => 'TestApp', 'meta_description' => 'T', 'social_share_image' => 'default.png', 'userlangsel' => 0, 'include_language_code' => 0, 'site_favicon' => 'favicon.png'])
        {
            public function __construct(private array $data) {}

            public function __get(string $key): mixed
            {
                return $this->data[$key] ?? null;
            }

            public function __set(string $key, mixed $value): void
            {
                $this->data[$key] = $value;
            }

            public function __isset(string $key): bool
            {
                return array_key_exists($key, $this->data);
            }
        };
        view()->share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => $settings, 'languages' => collect(), 'pages' => collect()]);

        $this->withoutMiddleware([
            Installed::class,
            PreventRequestForgery::class,
        ]);
    }

    public function test_pending_transaction_still_shows_the_pay_page(): void
    {
        [, , $tx] = $this->makeOrderTransaction('stripe', 50.0);
        $this->get("/payment/{$tx->id}")->assertOk()->assertSeeHtml('name="payment_method"');
    }

    /** @return array<int, array{0: string}> */
    public static function paidStatuses(): array
    {
        return [[Transaction::STATUS_SUCCESS], [Transaction::STATUS_CONSUMED], ['authorised']];
    }

    #[DataProvider('paidStatuses')]
    public function test_paid_transaction_is_never_handed_to_the_gateway(string $status): void
    {
        [, , $tx] = $this->makeOrderTransaction('stripe', 50.0);
        Transaction::query()->whereKey($tx->id)->update(['status' => $status]);

        $this->get("/payment/{$tx->id}")->assertStatus(409);
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'stripe'])->assertStatus(409);
        Http::assertNothingSent();
    }

    public function test_order_marked_paid_is_refused_even_with_a_pending_transaction(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 50.0);
        $order->forceFill(['is_paid' => 1])->save();

        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'stripe'])->assertStatus(409);
        Http::assertNothingSent();
    }

    /** @return array<int, array{0: string}> */
    public static function closedStatuses(): array
    {
        return [['cancelled'], ['rejected'], ['refunded']];
    }

    #[DataProvider('closedStatuses')]
    public function test_closed_order_answers_410(string $status): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 50.0);
        $order->forceFill(['status' => $status])->save();

        $this->get("/payment/{$tx->id}")->assertStatus(410);
        $this->post("/payment/{$tx->id}/pay", ['payment_method' => 'stripe'])->assertStatus(410);
        Http::assertNothingSent();
    }

    public function test_payment_confirmed_after_cancel_does_not_revive_the_order_and_is_idempotent(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 50.0);
        $order->forceFill(['status' => 'cancelled'])->save();
        $tx->transaction_gatway = 'stripe';

        ob_start();
        PaymentController::paySuccess($tx);
        PaymentController::paySuccess(Transaction::query()->findOrFail($tx->id)); // duplicate webhook delivery
        ob_end_clean();

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame(0, (int) $order->is_paid);
        $this->assertSame('received_late', $order->late_payment_status);
        $this->assertSame(Transaction::STATUS_CONSUMED, Transaction::query()->findOrFail($tx->id)->status);
        $this->assertEqualsWithDelta(50.0, (float) store_options($order->store_id, 'wallet_amount'), 0.001, 'credited once');
    }

    public function test_pending_order_still_settles_normally(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('stripe', 50.0);
        $tx->transaction_gatway = 'stripe';

        ob_start();
        PaymentController::paySuccess($tx);
        ob_end_clean();

        $order->refresh();
        $this->assertSame('pending', $order->status);
        $this->assertSame(1, (int) $order->is_paid);
        $this->assertNull($order->late_payment_status);
    }
}
