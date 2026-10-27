<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Ordering;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Tests\TestCase;

/** P6-226: visible labels + autocomplete on the contact form; totals use the store currency's decimals. */
class OrderHubCheckoutViewTest extends TestCase
{
    use RefreshDatabase;

    private function html(string $locale, string $currency): string
    {
        app()->setLocale($locale);
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111', 'title' => 'R',
            'sub_title' => 'x', 'description' => 'x', 'main_image' => 'default.png', 'cover_image' => 'default.png',
            'address' => '1 St', 'phone' => '123',
        ]);
        StoreOption::updateStoreOption($store->id, 'currency_code', $currency);
        $cart = [[
            'store_id' => $store->id, 'title' => 'R', 'total' => 12.345,
            'items' => [['item_id' => 1, 'name' => 'Dish', 'image' => null, 'note' => null, 'unit_price' => 12.345, 'quantity' => 1, 'line_total' => 12.345]],
        ]];

        return view('order-hub.checkout', ['cart' => $cart])->render();
    }

    public function test_contact_fields_have_labels_autocomplete_and_tel_type(): void
    {
        foreach (['en', 'ar'] as $locale) {
            $html = $this->html($locale, 'USD');
            $this->assertStringContainsString('<label for="hubName"', $html, $locale);
            $this->assertStringContainsString('<label for="hubPhone"', $html, $locale);
            $this->assertStringContainsString('autocomplete="name"', $html);
            $this->assertStringContainsString('type="tel"', $html);
            $this->assertStringContainsString('autocomplete="tel"', $html);
            $this->assertMatchesRegularExpression('/<label[^>]*for="ntText"/', $html);
        }
    }

    public function test_kwd_total_shows_three_decimals(): void
    {
        $html = $this->html('en', 'KWD');
        $this->assertStringContainsString('12.345', $html);
    }
}
