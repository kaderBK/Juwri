<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Integrations;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Integrations\Jobs\SendMetaCapiEventJob;
use Tests\TestCase;

class MetaPixelTest extends TestCase
{
    use RefreshDatabase;

    private function storeFixture(array $options = []): Store
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png',
            'cover_image' => 'default.png', 'address' => '1', 'phone' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($options as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store;
    }

    public function test_capi_noop_when_pixel_not_configured(): void
    {
        Http::fake();
        $store = $this->storeFixture();

        new SendMetaCapiEventJob($store->id, 'Purchase', 'order_1', 20.0, 'USD')->handle();

        Http::assertNothingSent();
    }

    public function test_capi_sends_deduped_event_when_configured(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['events_received' => 1], 200)]);
        $store = $this->storeFixture(['meta_pixel_id' => '111222333', 'meta_capi_token' => 'EAA-token']);

        new SendMetaCapiEventJob($store->id, 'Purchase', 'order_42', 25.5, 'AED')->handle();

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($request->url(), '111222333/events')
                && $body['data'][0]['event_id'] === 'order_42'
                && $body['data'][0]['event_name'] === 'Purchase'
                && $body['data'][0]['custom_data']['currency'] === 'AED'
                && (float) $body['data'][0]['custom_data']['value'] === 25.5;
        });
    }
}
