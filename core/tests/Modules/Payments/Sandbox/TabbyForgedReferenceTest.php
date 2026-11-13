<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments\Sandbox;

use App\Http\Middleware\Installed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Juwri\Modules\Payments\Models\Transaction;
use Tests\Support\GatewayTestHelpers;
use Tests\TestCase;

/**
 * p5p int: a Tabby webhook / return carrying a non-numeric reference (or id) never reaches the database as a
 * transaction id (PostgreSQL refused the bigint cast and aborted the whole request transaction), and a webhook
 * "signed" with an empty secret never pays — on every driver.
 */
class TabbyForgedReferenceTest extends TestCase
{
    use GatewayTestHelpers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class]);
    }

    public function test_non_numeric_reference_and_empty_secret_header_never_pay_or_error(): void
    {
        [, $order, $tx] = $this->makeOrderTransaction('tabby', 10.0, ['secret_key' => 'sk_test_abc', 'merchant_code' => 'M'], 'SAR');
        $tx->payment_id = 'ref_1';
        $tx->save();
        config(['payments.sandbox.enabled' => false]);
        Http::fake(['*' => Http::response(['id' => 'ref_1', 'status' => 'CLOSED', 'order' => ['reference_id' => (string) $tx->id], 'amount' => '10.00', 'currency' => 'SAR'])]);

        foreach (['ref_1', '1e3', (string) $tx->id.'x', ' '.$tx->id] as $ref) {
            $this->postJson('/webhook/tabby', ['id' => 'ref_1', 'order' => ['reference_id' => $ref]], ['X-Tabby-Auth' => ''])
                ->assertStatus(401);
            $this->assertLessThan(500, $this->get('/ipn/tabby?id='.urlencode($ref))->status(), "return with id {$ref}");
        }
        // Forged with the real reference but an empty / HMAC('') auth header.
        $raw = (string) json_encode(['id' => 'ref_1', 'order' => ['reference_id' => (string) $tx->id]]);
        foreach (['', hash_hmac('sha256', $raw, '')] as $header) {
            $this->call('POST', '/webhook/tabby', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_TABBY_AUTH' => $header], $raw)
                ->assertStatus(401);
        }

        $this->assertSame(0, (int) $order->fresh()->is_paid);
        $this->assertNotSame(Transaction::STATUS_CONSUMED, Transaction::find($tx->id)?->status);
    }
}
