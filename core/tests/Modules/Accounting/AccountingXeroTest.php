<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Accounting;

use App\Http\Middleware\Installed;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Credential;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\SsrfGuard;
use Juwri\Modules\Accounting\Jobs\SyncInvoiceJob;
use Juwri\Modules\Accounting\Services\Accounting\AccountingTokenService;
use Juwri\Modules\Menu\Models\Menu;
use Tests\TestCase;

/**
 * GAPS-RUN-2 B3-P1: Xero driver + token rotation.
 */
class AccountingXeroTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SsrfGuard::$resolver = static fn (string $h): array => ['93.184.216.34']; // P6-68: test hosts resolve public
        $this->withoutMiddleware(Installed::class);
    }

    private function storeFixture(): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);

        return Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function connectXero(Store $store): Credential
    {
        return Credential::create([
            'store_id' => $store->id, 'feature' => 'accounting', 'provider' => 'xero', 'active' => true,
            'secrets' => [
                'provider' => 'xero', 'access_token' => 'tok', 'tenant_id' => 'TENANT-9',
                'base_url' => 'https://xero.example/api.xro/2.0',
            ],
        ]);
    }

    public function test_xero_sync_posts_accrec_with_tenant_header(): void
    {
        Http::fake(['xero.example/*' => Http::response(['Invoices' => [['InvoiceID' => 'XR-1']]], 200)]);
        $store = $this->storeFixture();
        $this->connectXero($store);
        $menu = Menu::create(['store_id' => $store->id, 'name' => 'Tea', 'price' => 3, 'active' => 1]);
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $menu->id, 'quantity' => 2]);

        new SyncInvoiceJob($order->id)->handle();
        new SyncInvoiceJob($order->id)->handle(); // idempotent replay

        Http::assertSentCount(1);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/Invoices')
                && $request->hasHeader('Xero-tenant-id', 'TENANT-9')
                && $request['Type'] === 'ACCREC';
        });
        $this->assertDatabaseHas('external_invoices', ['order_id' => $order->id, 'provider' => 'xero', 'external_id' => 'XR-1']);
    }

    public function test_token_refresh_rotates_and_persists(): void
    {
        Http::fake(['auth.xero.example/*' => Http::response([
            'access_token' => 'NEW_ACCESS', 'refresh_token' => 'NEW_REFRESH', 'expires_in' => 1800,
        ], 200)]);
        $store = $this->storeFixture();
        $cred = Credential::create([
            'store_id' => $store->id, 'feature' => 'accounting', 'provider' => 'xero', 'active' => true,
            'secrets' => [
                'provider' => 'xero', 'access_token' => 'OLD_ACCESS', 'refresh_token' => 'OLD_REFRESH',
                'token_url' => 'https://auth.xero.example/token', 'client_id' => 'cid', 'client_secret' => 'sec',
                'base_url' => 'https://xero.example/api.xro/2.0',
            ],
        ]);

        $ok = new AccountingTokenService()->refresh($cred);

        $this->assertTrue($ok);
        $fresh = (array) $cred->fresh()->secrets;
        $this->assertSame('NEW_ACCESS', $fresh['access_token']);
        $this->assertSame('NEW_REFRESH', $fresh['refresh_token']); // old refresh token replaced
        $this->assertArrayHasKey('token_expires_at', $fresh);
    }

    public function test_refresh_noop_without_token_url(): void
    {
        $store = $this->storeFixture();
        $cred = $this->connectXero($store); // no token_url/refresh_token
        $this->assertFalse(new AccountingTokenService()->refresh($cred));
    }

    public function test_quickbooks_path_unchanged(): void
    {
        Http::fake(['qb.example/*' => Http::response(['id' => 'INV-1'], 200)]);
        $store = $this->storeFixture();
        Credential::create([
            'store_id' => $store->id, 'feature' => 'accounting', 'provider' => 'quickbooks', 'active' => true,
            'secrets' => ['provider' => 'quickbooks', 'access_token' => 'tok', 'base_url' => 'https://qb.example/api'],
        ]);
        $order = Order::create(['store_id' => $store->id, 'status' => 'new', 'type' => 'takeaway']);

        new SyncInvoiceJob($order->id)->handle();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'qb.example/api/invoices'));
        $this->assertDatabaseHas('external_invoices', ['order_id' => $order->id, 'provider' => 'quickbooks', 'external_id' => 'INV-1']);
    }
}
