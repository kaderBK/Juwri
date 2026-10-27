<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Sandbox;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Juwri\Core\Models\Store;
use Juwri\Modules\Payments\Models\PaymentGateway;
use Juwri\Modules\Payments\Models\Transaction;
use Juwri\Modules\Payments\Services\Gateways\Settlement;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * Phase 4 item 18: the sandbox matrix base — each provider's documented sandbox answers over a faked HTTP client (no
 * network, invented test keys: never a real secret). Routes: "METHOD url-fragment" => answer (array body, or
 * ['__status' => code, 'body' => …], or a callable(ClientRequest)). Later fake() calls replace the routes.
 */
abstract class SandboxTestCase extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $routes = [];

    private bool $faked = false;

    abstract protected function gateway(): string;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
        $this->setUpGateway();
        config(['payments.sandbox.enabled' => true, 'settings.currency_code' => 'SAR']);
    }

    /** The gateway's row (payment_gateways), when the migrations did not ship it. */
    protected function setUpGateway(): void
    {
        if (! PaymentGateway::query()->where('payment_folder', $this->gateway())->exists()) {
            PaymentGateway::create(['payment_folder' => $this->gateway(), 'payment_title' => ucfirst($this->gateway()), 'payment_install' => '1']);
        }
    }

    /** @param array<string, mixed> $routes */
    protected function fake(array $routes): void
    {
        $this->routes = $routes;
        if ($this->faked) {
            return;
        }
        $this->faked = true;
        Http::fake(function (ClientRequest $request) {
            foreach ($this->routes as $route => $answer) {
                [$method, $fragment] = explode(' ', $route, 2);
                if ($request->method() !== $method || ! str_contains($request->url(), $fragment)) {
                    continue;
                }
                if (is_callable($answer)) {
                    $answer = $answer($request);
                }

                return is_array($answer) && array_key_exists('__status', $answer)
                    ? Http::response($answer['body'], $answer['__status'])
                    : Http::response($answer);
            }

            return Http::response(['message' => 'unexpected '.$request->method().' '.$request->url()], 500);
        });
    }

    /**
     * A pending store order of 100.00 SAR paid on this gateway with the store's own (test) keys.
     *
     * @param  array<string, string>  $creds
     * @return array{0: Store, 1: Order, 2: Transaction}
     */
    protected function order(array $creds, float $amount = 100.0): array
    {
        $this->requiresEditionPackage('order settlement (edition package)');
        [$store, $order, $tx] = $this->makeOrderTransaction($this->gateway(), $amount, $creds, 'SAR');
        StoreOption::updateStoreOption($store->id, 'currency_code', 'SAR');

        return [$store, $order, $tx];
    }

    /** POST a raw body to webhook/{gateway} with server headers (HTTP_…). */
    protected function webhook(string $body, array $server = []): TestResponse
    {
        return $this->call('POST', route('webhook', $this->gateway()), [], [], [], array_merge(['CONTENT_TYPE' => 'application/json'], $server), $body);
    }

    protected function assertCreditedOnce(Transaction $tx): void
    {
        $this->assertSame(1, DB::table('outbox_messages')->where('dedupe_key', Settlement::CREDIT.':'.$tx->id)->count(), 'credited exactly once');
    }

    protected function receipts(): int
    {
        return DB::table('webhook_receipts')->where('provider', $this->gateway())->where('status', 'processed')->count();
    }
}
