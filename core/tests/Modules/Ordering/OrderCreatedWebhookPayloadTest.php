<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\Order;
use App\Support\OrderCreatedWebhookPayload;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Support\LegacyStoreKeys;
use Tests\TestCase;

/**
 * F0-10 (integrations pack): the order.created payload is canonical and every live
 * dispatcher goes through the one builder, so a Zapier "New Order" trigger sampled
 * on any source (storefront, API, POS, HungerStation) sees the same keys.
 */
class OrderCreatedWebhookPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function order(): Order
    {
        $owner = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'T', 'description' => 'T', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // NB: `total` is a computed accessor (Order::getTotalAttribute), not a column.
        return Order::create([
            'store_id' => $store->id, 'type' => 'delivery', 'status' => 'new',
            'track_token' => Str::random(24), 'created_at' => now(),
        ]);
    }

    public function test_build_always_carries_the_canonical_keys(): void
    {
        $payload = OrderCreatedWebhookPayload::build($this->order(), 'api');

        foreach (OrderCreatedWebhookPayload::CANONICAL_KEYS as $key) {
            $this->assertArrayHasKey($key, $payload, "order.created must always carry '{$key}'");
        }
        $this->assertSame('api', $payload['source']);
        $this->assertSame('delivery', $payload['type']);
    }

    /** The builder and the F0-5 sample fixture must agree, or Zapier samples drift. */
    public function test_canonical_keys_match_the_sample_fixture(): void
    {
        $fixture = json_decode((string) file_get_contents(
            base_path('resources/fixtures/webhook-samples/order.created.json')
        ), true);

        // P1-04: the delivered envelope mirrors store_id under the legacy key (DeliverWebhookJob).
        $this->assertSame(
            array_keys(LegacyStoreKeys::mirror(array_fill_keys(OrderCreatedWebhookPayload::CANONICAL_KEYS, 1))),
            array_keys($fixture['data']),
            'order.created.json data keys must equal the canonical builder keys'
        );
    }

    public function test_extra_adds_source_specific_keys_without_losing_canonical(): void
    {
        $payload = OrderCreatedWebhookPayload::build($this->order(), 'storefront', ['table_number' => 7]);

        $this->assertSame('storefront', $payload['source']);
        $this->assertSame(7, $payload['table_number']);
        foreach (OrderCreatedWebhookPayload::CANONICAL_KEYS as $key) {
            $this->assertArrayHasKey($key, $payload);
        }
    }

    /**
     * Pin every LIVE order.created dispatcher to the builder — a hand-rolled inline
     * array here is exactly the drift F0-10 removed. If a new producer is added, add
     * it to this list and route it through the builder.
     */
    public function test_every_live_dispatcher_uses_the_canonical_builder(): void
    {
        $files = [
            'app/Services/OrderApiService.php',
            'app/Http/Controllers/User/StoreController.php',
            'app/Services/PosService.php',
            'app/Modules/Channels/Services/HungerStation/HungerStationOrderService.php',
        ];

        foreach ($files as $rel) {
            $src = (string) file_get_contents(base_path($rel));
            $this->assertStringContainsString(
                'OrderCreatedWebhookPayload::build(',
                $src,
                "{$rel} dispatches order.created but does not use the canonical builder"
            );
            // And it no longer hand-rolls an inline order.created array.
            $this->assertStringNotContainsString(
                "'order.created', [",
                $src,
                "{$rel} still hand-rolls an inline order.created payload — route it through the builder"
            );
        }
    }
}
