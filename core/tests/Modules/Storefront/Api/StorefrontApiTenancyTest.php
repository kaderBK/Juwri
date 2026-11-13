<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\Api;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Customer;
use App\Models\MenuCategory;
use App\Models\Order;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\Store;
use Juwri\Modules\Customers\Models\CustomerToken;
use Juwri\Modules\Messaging\Data\OtpScope;
use Juwri\Modules\Messaging\Services\SmsOtpService;
use Juwri\Modules\Storefront\Models\StorefrontApiIdempotency;
use Tests\Support\StorefrontApi\ApiContract;
use Tests\Support\StorefrontApi\ApiFixtures;
use Tests\TestCase;

/**
 * P3-06b tenancy (README §2.5): the store comes from the token only. Store A's token never reads or writes store
 * B's data — items, orders, branches, customers, idempotency keys — and the answers do not reveal that B's rows
 * exist (404 / a warning, never B's data).
 */
class StorefrontApiTenancyTest extends TestCase
{
    use RefreshDatabase;

    private ApiFixtures $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        app()->setLocale('en');
        $this->travelTo(Carbon::parse('2026-10-02 18:40:00', 'UTC'));
        $this->fx = new ApiFixtures;
    }

    /** @return array{0: Store, 1: Store} [A = Saffron House, B = another store with its own item] */
    private function stores(): array
    {
        $a = $this->fx->saffron();
        $b = $this->fx->store($this->fx->owner(), 'other-'.Str::lower(Str::random(5)), 'Other Grill', 'restaurant', [
            'restaurant_takeaway_order' => true, 'restaurant_delivery_order' => true,
        ]);
        $cat = MenuCategory::create(['store_id' => $b->id, 'name' => 'B', 'active' => 1]);
        $this->fx->item($b, $cat, '019fffff-0000-7000-8000-00000000b001', 'B only dish', 33);
        $this->fx->token($b, 'sfx_b_read', ['menu:read']);
        $this->fx->token($b, 'sfx_b_write', ['order:write'], ['https://b.example']);

        return [$a, $b];
    }

    private function read(string $path, string $token = 'sfx_demo_saffron_read_0001')
    {
        return $this->getJson($path, ['X-Storefront-Token' => $token]);
    }

    public function test_a_token_never_reads_another_stores_item_order_or_branch(): void
    {
        [, $b] = $this->stores();

        $item = $this->read('/api/v1/storefront/items/019fffff-0000-7000-8000-00000000b001')->assertNotFound();
        ApiContract::assertResponse('getItem', 'GET', '/api/v1/storefront/items/019fffff-0000-7000-8000-00000000b001', $item);
        $this->assertStringNotContainsString('B only dish', (string) $item->getContent());

        $catalog = $this->read('/api/v1/storefront/catalog')->assertOk();
        $this->assertStringNotContainsString('B only dish', (string) $catalog->getContent());
        $this->assertStringNotContainsString('019fffff-0000-7000-8000-00000000b001', (string) $catalog->getContent());

        $bOrder = $this->fx->order($b, 'trk_b_order_0000000000000000001');
        $this->read('/api/v1/storefront/orders/'.$bOrder->track_token)->assertNotFound();
        $this->read('/api/v1/storefront/orders/'.$bOrder->track_token, 'sfx_b_read')->assertOk();

        $branch = Branch::query()->where('store_id', $b->id)->first() ?? Branch::create(['store_id' => $b->id, 'name' => 'B main', 'active' => true]);
        $this->read('/api/v1/storefront/store?branch='.$branch->public_id)->assertNotFound()->assertJsonPath('code', 'not_found');
    }

    public function test_a_cart_with_another_stores_item_is_never_priced_or_placed_here(): void
    {
        $this->stores();
        $foreign = ['line_id' => 'x', 'item_id' => '019fffff-0000-7000-8000-00000000b001', 'qty' => 1];

        // quote: only the foreign line → 422 out_of_stock naming the line; with an own line → a warning, B unpriced
        $only = $this->postJson('/api/v1/storefront/quote', ['order_type' => 'takeaway', 'lines' => [$foreign]], ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001'])
            ->assertStatus(422)->assertJsonPath('code', 'out_of_stock')->assertJsonPath('details.line_ids', ['x']);
        ApiContract::juwriRules('quoteCart', $only);
        $mixed = $this->postJson('/api/v1/storefront/quote', ['order_type' => 'takeaway', 'lines' => [
            ['line_id' => 'own', 'item_id' => ApiFixtures::TAWOOK, 'qty' => 1], $foreign,
        ]], ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001'])->assertOk();
        $this->assertSame([['code' => 'item_unavailable', 'line_id' => 'x']], array_map(fn ($w) => ['code' => $w['code'], 'line_id' => $w['line_id']], $mixed->json('warnings')));
        $this->assertSame(4200, $mixed->json('quote.lines.0.line_total.minor'));
        $this->assertCount(1, $mixed->json('quote.lines'));

        // order: the foreign line refuses the whole submit, nothing written in either store
        $this->postJson('/api/v1/storefront/orders', [
            'order_type' => 'takeaway', 'lines' => [['line_id' => 'own', 'item_id' => ApiFixtures::TAWOOK, 'qty' => 1], $foreign],
            'quote_hash' => (string) $mixed->json('quote_hash'), 'payment' => ['mode' => 'pay_later'],
        ], ['X-Storefront-Token' => 'sfx_demo_saffron_write_0001', 'Origin' => 'https://saffron-house.example', 'Idempotency-Key' => (string) Str::uuid7()])
            ->assertStatus(422)->assertJsonPath('code', 'out_of_stock')->assertJsonPath('details.line_ids', ['x']);
        $this->assertSame(0, Order::query()->count());
    }

    public function test_option_ids_of_another_item_or_store_are_not_accepted(): void
    {
        $this->stores();
        // the grill's option on the tawook line: not this item's → unorderable line
        $r = $this->postJson('/api/v1/storefront/quote', ['order_type' => 'takeaway', 'lines' => [
            ['line_id' => 'a', 'item_id' => ApiFixtures::TAWOOK, 'qty' => 1, 'option_ids' => [ApiFixtures::OPT_GARLIC]],
        ]], ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001']);
        $r->assertStatus(422)->assertJsonPath('code', 'out_of_stock');
    }

    public function test_the_same_idempotency_key_in_two_stores_is_two_keys(): void
    {
        $this->stores();
        $key = (string) Str::uuid7();
        foreach ([['sfx_demo_saffron_write_0001', 'https://saffron-house.example', ApiFixtures::TAWOOK], ['sfx_b_write', 'https://b.example', '019fffff-0000-7000-8000-00000000b001']] as [$token, $origin, $item]) {
            $readToken = $token === 'sfx_b_write' ? 'sfx_b_read' : 'sfx_demo_saffron_read_0001';
            $body = ['order_type' => 'takeaway', 'lines' => [['line_id' => 'l', 'item_id' => $item, 'qty' => 1]]];
            $hash = $this->postJson('/api/v1/storefront/quote', $body, ['X-Storefront-Token' => $readToken])->assertOk()->json('quote_hash');
            $this->postJson('/api/v1/storefront/orders', $body + ['quote_hash' => $hash, 'payment' => ['mode' => 'pay_later']], [
                'X-Storefront-Token' => $token, 'Origin' => $origin, 'Idempotency-Key' => $key,
            ])->assertCreated()->assertJsonPath('replayed', false);
        }
        $this->assertSame(2, Order::query()->where('client_uuid', $key)->count(), 'one order per store under the same key');
    }

    public function test_a_customer_token_of_another_store_is_refused(): void
    {
        [, $b] = $this->stores();
        $customer = Customer::query()->create(['store_id' => $b->id, 'name' => 'B shopper', 'phone' => '+966500000009']);
        $raw = CustomerToken::issue($customer, 'test');

        $this->getJson('/api/v1/storefront/store', ['X-Storefront-Token' => 'sfx_demo_saffron_read_0001', 'Authorization' => 'Bearer '.$raw])
            ->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
        $this->getJson('/api/v1/storefront/store', ['X-Storefront-Token' => 'sfx_b_read', 'Authorization' => 'Bearer '.$raw])->assertOk();
    }

    public function test_otp_codes_and_idempotency_rows_are_per_store(): void
    {
        [$a, $b] = $this->stores();
        $this->fx->token($a, 'sfx_a_otp', ['order:write'], ['https://saffron-house.example']);
        $key = (string) Str::uuid7();
        $this->postJson('/api/v1/storefront/customer/otp/request', ['phone' => '+966551234567'], [
            'X-Storefront-Token' => 'sfx_a_otp', 'Origin' => 'https://saffron-house.example', 'Idempotency-Key' => $key,
        ])->assertStatus(202);
        $this->postJson('/api/v1/storefront/customer/otp/request', ['phone' => '+966551234567'], [
            'X-Storefront-Token' => 'sfx_b_write', 'Origin' => 'https://b.example', 'Idempotency-Key' => $key,
        ])->assertStatus(202)->assertHeaderMissing('Idempotent-Replayed');
        $this->assertSame([(int) $a->id, (int) $b->id], StorefrontApiIdempotency::query()->orderBy('store_id')->pluck('store_id')->map(fn ($v) => (int) $v)->all());

        // a code issued for A never verifies at B
        $otp = app(SmsOtpService::class);
        Cache::put($otp->key(OtpScope::store((int) $a->id), '+966551234567'), ['hash' => Hash::make('111111'), 'attempts' => 0], 600);
        $this->postJson('/api/v1/storefront/customer/otp/verify', ['phone' => '+966551234567', 'code' => '111111'], [
            'X-Storefront-Token' => 'sfx_b_write', 'Origin' => 'https://b.example', 'Idempotency-Key' => (string) Str::uuid7(),
        ])->assertStatus(422)->assertJsonPath('code', 'otp_invalid');
        $this->assertSame(0, Customer::query()->where('store_id', $b->id)->where('phone', '+966551234567')->count());
    }
}
