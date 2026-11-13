<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront;

use App\Models\StoreOption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Juwri\Core\Models\ExchangeRate;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Core\Services\ExchangeRateService;
use Juwri\Modules\Storefront\Storefront\CommerceRuntime;
use Juwri\Modules\Storefront\Storefront\DisplayRates;
use Tests\TestCase;

/**
 * MC-31 — the unified display-rate resolver: manual mode (byte-identical to the
 * two legacy parsers) plus auto mode (FX feed + manual pins, hidden when stale).
 */
class DisplayRatesTest extends TestCase
{
    use RefreshDatabase;

    private function store(array $opts): int
    {
        $user = User::factory()->create(['status' => 1, 'email_verified_at' => now()]);
        $store = Store::create([
            'user_id' => $user->id, 'slug' => Str::slug('r'.Str::random(6)), 'color' => '#111',
            'title' => 'R', 'sub_title' => 'x', 'description' => 'x', 'main_image' => 'd.png',
            'cover_image' => 'd.png', 'address' => '1', 'phone' => '1',
        ]);
        foreach ($opts as $k => $v) {
            StoreOption::updateStoreOption($store->id, $k, $v);
        }

        return $store->id;
    }

    private function seedRate(string $quote, float $rate, ?string $fetchedAt = null): void
    {
        ExchangeRate::updateOrCreate(
            ['base' => 'USD', 'quote' => $quote, 'provider' => 'frankfurter'],
            ['rate' => $rate, 'fetched_at' => $fetchedAt ?? now()]
        );
        ExchangeRateService::flush();
    }

    public function test_manual_mode_parses_lines_and_lists_codes_base_first(): void
    {
        $id = $this->store([
            'currency_code' => 'USD',
            'currency_rates_manual' => "EUR = 0.9\nOMR = 0.385\nbad line\n",
        ]);

        $dr = DisplayRates::forStore($id);

        $this->assertSame(['EUR' => 0.9, 'OMR' => 0.385], $dr->rates);
        $this->assertSame(['USD', 'EUR', 'OMR'], $dr->codes());
        $this->assertFalse($dr->indicative);
        $this->assertNull($dr->asOf);
    }

    public function test_manual_output_is_byte_identical_to_the_legacy_parser(): void
    {
        $raw = "EUR = 0.9\nGBP=0.78\n  \nOMR = 0.385\n";
        $id = $this->store(['currency_code' => 'USD', 'currency_rates_manual' => $raw]);

        // The legacy CommerceRuntime/ThemeContext body, inlined for comparison.
        $legacy = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || ! str_contains($line, '=')) {
                continue;
            }
            [$c, $r] = array_map('trim', explode('=', $line, 2));
            if ($c !== '' && is_numeric($r) && (float) $r > 0) {
                $legacy[strtoupper($c)] = (float) $r;
            }
        }

        $this->assertSame($legacy, DisplayRates::forStore($id)->rates);
    }

    public function test_no_rates_hides_the_switcher(): void
    {
        $id = $this->store(['currency_code' => 'USD']);
        $this->assertSame([], DisplayRates::forStore($id)->codes());
    }

    public function test_auto_mode_uses_feed_with_manual_pins(): void
    {
        $this->seedRate('EUR', 0.90);
        $this->seedRate('OMR', 0.385);

        $id = $this->store([
            'currency_code' => 'USD',
            'currency_rates_mode' => 'auto',
            'display_currencies' => 'EUR, OMR, KWD',   // KWD has no feed rate
            'currency_rates_manual' => 'EUR = 0.95',    // pin overrides the auto EUR
        ]);

        $dr = DisplayRates::forStore($id);

        $this->assertSame(0.95, $dr->rates['EUR'], 'manual pin wins');
        $this->assertEqualsWithDelta(0.385, $dr->rates['OMR'], 1e-9, 'auto from feed');
        $this->assertArrayNotHasKey('KWD', $dr->rates, 'no feed rate → omitted');
        $this->assertTrue($dr->indicative);
        $this->assertNotNull($dr->asOf);
    }

    public function test_auto_mode_stale_feed_shows_only_pins(): void
    {
        $this->seedRate('EUR', 0.90, now()->subHours(100)->toDateTimeString()); // stale
        $id = $this->store([
            'currency_code' => 'USD',
            'currency_rates_mode' => 'auto',
            'display_currencies' => 'EUR OMR',
            'currency_rates_manual' => 'OMR = 0.4',
        ]);

        $dr = DisplayRates::forStore($id);

        $this->assertSame(['OMR' => 0.4], $dr->rates, 'stale feed → only the pin survives');
        $this->assertTrue($dr->indicative);
        $this->assertNull($dr->asOf);
    }

    public function test_manual_lines_with_a_bad_currency_code_are_ignored(): void
    {
        $id = $this->store(['currency_code' => 'SAR', 'currency_rates_manual' => "EUR = 0.25\nUSDX = 2\n12 = 3\n\$\$\$ = 4\nusd = 0.27"]);

        $this->assertSame(['EUR' => 0.25, 'USD' => 0.27], DisplayRates::forStore($id)->rates);
    }

    public function test_store_base_is_the_store_currency_and_auto_converts_from_it(): void
    {
        $this->seedRate('SAR', 3.75);
        $this->seedRate('EUR', 0.90);
        $id = $this->store(['currency_code' => 'SAR', 'currency_rates_mode' => 'auto', 'display_currencies' => 'EUR USD']);

        $dr = DisplayRates::forStore($id);

        $this->assertSame('SAR', $dr->base);
        $this->assertSame('SAR', $dr->codes()[0]);
        $this->assertEqualsWithDelta(ExchangeRateService::rate('SAR', 'EUR'), $dr->rates['EUR'], 1e-9);
    }

    public function test_storefront_render_paths_pass_the_store_currency_as_base(): void
    {
        $id = $this->store(['currency_code' => 'SAR', 'currency_sign' => 'SAR', 'currency_rates_manual' => 'EUR = 0.25']);
        $store = Store::find($id);
        $opts = (object) store_options($id);
        config(['settings.currency_code' => 'USD']); // a USD platform

        $cfg = app(CommerceRuntime::class)->config(['store' => $store, 'postOptions' => $opts]);
        $this->assertSame('SAR', $cfg['currency']['code']);
    }
}
