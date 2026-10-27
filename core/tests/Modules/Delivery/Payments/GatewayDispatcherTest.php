<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Delivery\Payments;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Jobs\CreateNotificationJob;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaddleController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\PaymentController;
use Juwri\Modules\Payments\Http\Controllers\User\PaymentMethods\WireTransferController;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\Support\MorWebhookFixtures;
use Tests\TestCase;

/**
 * WI-06c — the ipn/webhook dispatcher (PaymentMethods\PaymentController), the
 * wire-transfer hand-off and Paddle's return/webhook answers.
 */
class GatewayDispatcherTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->shareLayoutGlobals();
        config(['settings.paddle_webhook_secret' => 'pdl_test_secret']);
    }

    public function test_unknown_gateway_folder_is_404_on_both_entry_points(): void
    {
        $this->post('ipn/no_such_gateway')->assertNotFound();
        $this->get('ipn/no_such_gateway')->assertNotFound();
        $this->post('webhook/no_such_gateway')->assertNotFound();
    }

    public function test_known_folder_reaches_its_gateway_controller(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        PaymentGateway::create(['payment_folder' => 'paddle', 'payment_title' => 'Paddle', 'payment_install' => '1']);

        // Paddle's return ignores the request: flash + back to the subscription page.
        $this->get('ipn/paddle')->assertRedirect(route('subscription'));
        $this->assertNotEmpty(session('app_alert_message'));
        $this->assertSame('success', session('app_alert_type'));
    }

    public function test_a_gateway_row_without_install_flag_still_receives_callbacks(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        PaymentGateway::create(['payment_folder' => 'paddle', 'payment_title' => 'Paddle', 'payment_install' => '0']);

        $this->get('ipn/paddle')->assertRedirect(route('subscription'));
    }

    public function test_paddle_webhook_answers(): void
    {
        PaymentGateway::create(['payment_folder' => 'paddle', 'payment_title' => 'Paddle', 'payment_install' => '1']);

        $this->postJson('webhook/paddle', ['event_type' => 'transaction.completed'])
            ->assertStatus(400)->assertExactJson(['message' => 'Invalid signature verification.', 'status' => 400]);

        $completed = ['event_type' => 'transaction.completed', 'data' => ['id' => 'txn_1']];
        ['body' => $body, 'headers' => $headers] = MorWebhookFixtures::paddleSigned($completed, 'pdl_test_secret');
        $this->call('POST', 'webhook/paddle', [], [], [], $this->serverHeaders($headers), $body)
            ->assertStatus(400)->assertExactJson(['status' => 400]);

        ['body' => $body, 'headers' => $headers] = MorWebhookFixtures::paddleSigned(['event_type' => 'subscription.created', 'data' => ['id' => 'sub_1']], 'pdl_test_secret');
        $this->call('POST', 'webhook/paddle', [], [], [], $this->serverHeaders($headers), $body)
            ->assertOk()->assertExactJson(['message' => 'successful', 'status' => 200]);
    }

    public function test_gateway_without_the_entry_point_is_404_not_500(): void
    {
        // Wire transfer is offline: no return, no webhook (was a 500 "undefined method").
        PaymentGateway::create(['payment_folder' => 'wire_transfer', 'payment_title' => 'Bank', 'payment_install' => '1']);
        $this->post('webhook/wire_transfer')->assertNotFound();
        $this->get('ipn/wire_transfer')->assertNotFound();

        // A row whose folder has no controller at all (was a 500 "class not found").
        PaymentGateway::create(['payment_folder' => 'retired_gateway', 'payment_title' => 'Old', 'payment_install' => '1']);
        $this->post('webhook/retired_gateway')->assertNotFound();
    }

    public function test_folder_to_controller_mapping(): void
    {
        $ns = 'Juwri\\Modules\\Payments\\Http\\Controllers\\User\\PaymentMethods\\';
        $this->assertSame($ns.'TwoCheckoutController', PaymentController::controllerFor('two_checkout'));
        $this->assertSame($ns.'LemonSqueezyController', PaymentController::controllerFor('lemon_squeezy'));
        $this->assertSame($ns.'StripeController', PaymentController::controllerFor('stripe'));
        $this->assertSame($ns.'WireTransferController', PaymentController::controllerFor('wire_transfer'));
    }

    public function test_paddle_ipn_called_directly_redirects_to_subscription(): void
    {
        $this->requiresEditionPackage('an edition package route or view');
        $response = TestResponse::fromBaseResponse(PaddleController::ipn(request()));

        $response->assertRedirect(route('subscription'));
    }

    public function test_wire_transfer_marks_the_transaction_pending_notifies_admin_and_shows_bank_details(): void
    {
        Bus::fake([CreateNotificationJob::class]);
        [$user, $tx] = $this->makePlanTransaction(49.0, 'SAR', 'membership');
        $tx->forceFill(['transaction_gatway' => 'stripe', 'transaction_description' => 'Plan payment'])->save();
        $this->actingAs($user);
        request()->setUserResolver(fn () => $user);

        $response = TestResponse::fromBaseResponse(WireTransferController::pay($tx));

        $response->assertOk();
        $this->assertSame('owner.billing.gateways.wire-transfer', $response->original->name());
        $this->assertSame($tx->id, $response->original->getData()['transaction']->id);
        // Reference rows: transaction id, description, username (WI-13b).
        $html = $response->original->render();
        $this->assertStringContainsString('<dd>#'.$tx->id.'</dd>', $html);
        $this->assertStringContainsString('<dd>Plan payment</dd>', $html);
        $this->assertStringContainsString('<dd>'.e($user->username).'</dd>', $html);
        $fresh = Transaction::find($tx->id);
        $this->assertSame('wire_transfer', $fresh->transaction_gatway);
        $this->assertSame(Transaction::STATUS_PENDING, $fresh->status);
        Bus::assertDispatched(CreateNotificationJob::class);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function serverHeaders(array $headers): array
    {
        $server = ['CONTENT_TYPE' => 'application/json'];
        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return $server;
    }
}
