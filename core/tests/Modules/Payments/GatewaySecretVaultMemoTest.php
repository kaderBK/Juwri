<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Payments;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Payments\Services\GatewaySecretVault;
use Tests\TestCase;

/**
 * WI-M10 — GatewaySecretVault::row() re-queried the `credentials` table on every
 * store_options($id) read, defeating the bulkOptions memo one line above it (~10
 * queries + APP_KEY decrypts per anonymous storefront render). A per-request,
 * app-scoped memo (cleared on every vault write) collapses that to one query.
 */
class GatewaySecretVaultMemoTest extends TestCase
{
    use RefreshDatabase;

    private int $rid;

    protected function setUp(): void
    {
        parent::setUp();
        GatewaySecretVault::forgetMemo();
        StoreOption::flushOptionMemo();

        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $r = Store::create([
            'user_id' => $user->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->rid = $r->id;
    }

    private function credentialQueryCount(): int
    {
        return count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'credentials')));
    }

    public function test_repeated_keyless_store_options_reads_query_credentials_once(): void
    {
        StoreOption::updateStoreOption($this->rid, 'restaurant_stripe_secret_key', 'sk_live_A');
        GatewaySecretVault::forgetMemo();
        StoreOption::flushOptionMemo();

        DB::enableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            store_options($this->rid);
        }

        $this->assertSame(1, $this->credentialQueryCount(), 'five keyless reads must query credentials once');
    }

    public function test_a_store_with_no_vaulted_secrets_also_queries_credentials_once(): void
    {
        // No secret written at all — the common case is a memoised NULL.
        DB::enableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            store_options($this->rid);
        }

        $this->assertSame(1, $this->credentialQueryCount(), 'a memoised NULL must not re-query');
    }

    public function test_repeated_single_key_secret_reads_query_credentials_once(): void
    {
        StoreOption::updateStoreOption($this->rid, 'restaurant_stripe_secret_key', 'sk_live_A');
        GatewaySecretVault::forgetMemo();
        StoreOption::flushOptionMemo();

        DB::enableQueryLog();
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame('sk_live_A', store_options($this->rid, 'restaurant_stripe_secret_key'));
        }

        // P1-07: gateway secrets are read from store_payment_gateways (memoised per store).
        $gatewayQueries = count(array_filter(DB::getQueryLog(), fn ($q) => str_contains($q['query'], 'store_payment_gateways')));
        $this->assertSame(1, $gatewayQueries, 'five single-key reads must query the gateway rows once');
    }

    public function test_a_secret_written_in_the_same_request_is_read_back_fresh(): void
    {
        StoreOption::updateStoreOption($this->rid, 'restaurant_stripe_secret_key', 'sk_live_A');
        $this->assertSame('sk_live_A', store_options($this->rid, 'restaurant_stripe_secret_key'));

        // Write must invalidate the read memo within the same request.
        StoreOption::updateStoreOption($this->rid, 'restaurant_stripe_secret_key', 'sk_live_B');
        $this->assertSame('sk_live_B', store_options($this->rid, 'restaurant_stripe_secret_key'));
    }

    public function test_clearing_a_secret_in_the_same_request_is_visible_immediately(): void
    {
        StoreOption::updateStoreOption($this->rid, 'restaurant_paymob_hmac_secret', 'h1');
        $this->assertSame('h1', GatewaySecretVault::get($this->rid, 'restaurant_paymob_hmac_secret'));

        StoreOption::updateStoreOption($this->rid, 'restaurant_paymob_hmac_secret', '');
        $this->assertNull(GatewaySecretVault::get($this->rid, 'restaurant_paymob_hmac_secret'));
    }
}
