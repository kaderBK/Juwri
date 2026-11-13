<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Modules\Storefront\ViewModel;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Juwri\Core\Editions\EditionPackages;
use Juwri\Core\Models\Branch;
use Juwri\Core\Models\EntitlementOverride;
use Juwri\Core\Models\Store;
use Juwri\Modules\Storefront\Database\Seeders\KitDemoSeeder;
use Juwri\Modules\Storefront\Database\Seeders\ThemeShowcaseSeeder;
use Juwri\Modules\Storefront\Database\Seeders\VerticalShowcaseSeeder;
use Juwri\Modules\Storefront\Services\ViewModel\OrderTypeKeys;
use Juwri\Modules\Storefront\Services\ViewModel\ViewModelJson;
use Tests\Support\ViewModelScenario;
use Tests\TestCase;

/**
 * P3-02: the kit's fixture pages (home, item, cart, checkout, track, closed, kiosk — and time, reschedule for
 * services — in en and ar) rendered from SEEDED demo data for each kit theme's store type, validated against the
 * kit schema and compared with golden files (tests/Fixtures/viewmodel/<theme>.<locale>.json).
 *
 * A golden file holds the home view model and, per page, only the top-level blocks that differ from home.
 * Public ids (random UUIDv7s), order numbers / track tokens, image file names and the app URL are masked; time is frozen at
 * Sunday 4 Oct 2026, 12:00 Riyadh. Regenerate after an intended change: UPDATE_VM_GOLDEN=1.
 */
class ViewModelGoldenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['media.card_variants' => false]); // P6-308: goldens pin the shape, not generated variant files
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        $this->withoutMiddleware([Installed::class, PreventRequestForgery::class]);
        $this->travelTo(Carbon::parse('2026-10-04 09:00:00', 'UTC'));
    }

    public function test_table_renders_the_restaurant_demo(): void
    {
        $this->seed(ThemeShowcaseSeeder::class);
        $this->golden(new ViewModelScenario(Store::where('slug', 'crave-demo')->firstOrFail(), 'table'));
    }

    public function test_brew_renders_the_cafe_demo(): void
    {
        $this->seed(KitDemoSeeder::class);
        $this->golden(new ViewModelScenario(Store::where('slug', 'bloom-coffee-demo')->firstOrFail(), 'brew'));
    }

    public function test_aisle_renders_the_retail_demo(): void
    {
        $this->seed(VerticalShowcaseSeeder::class);
        $this->golden(new ViewModelScenario(Store::where('slug', 'retail-demo')->firstOrFail(), 'aisle'));
    }

    public function test_basket_renders_the_grocery_demo(): void
    {
        $this->seed(KitDemoSeeder::class);
        $this->golden(new ViewModelScenario(Store::where('slug', 'fresh-mart-demo')->firstOrFail(), 'basket'));
    }

    public function test_book_renders_the_services_demo_with_time_and_reschedule(): void
    {
        $this->seed(KitDemoSeeder::class);
        $this->golden(new ViewModelScenario(Store::where('slug', 'sidra-salon-demo')->firstOrFail(), 'book'), true);
    }

    private function golden(ViewModelScenario $s, bool $booking = false): void
    {
        // The showcase demo account has no paid plan in a test database: grant ordering like a live demo has it.
        EntitlementOverride::updateOrCreate(
            ['user_id' => $s->store->user_id, 'feature_key' => 'allow_ordering'],
            ['value' => '1', 'source_type' => 'admin']
        );
        $cart = $s->cart();
        $this->assertNotSame([], $cart, 'the demo store has orderable items');
        $home = $s->build('en');
        $types = array_column($home['context']['order_types'], 'key');
        $this->assertNotSame([], $types, 'the demo store takes orders');

        $order = $s->place($cart, ['type' => OrderTypeKeys::toCore($home['context']['selected_order_type'])]);
        $masks = [$order->track_token => '{track_token}', strtoupper(substr(str_replace('-', '', (string) $order->public_id), -6)) => '{order_no}'];

        $appointment = null;
        if ($booking) {
            $time = $s->build('en', 'time', ['cart' => [$cart[0]], 'orderType' => 'appointment']);
            $slot = $time['page']['checkout']['slots']['groups'][0]['slots'][0]['id'] ?? null;
            $this->assertNotNull($slot, 'the services demo offers a free appointment slot');
            $appointment = $s->place([$cart[0]], ['type' => 'appointment', 'scheduled_at' => str_replace('T', ' ', $slot)]);
            $masks[$appointment->track_token] = '{track_token_2}';
            $masks[strtoupper(substr(str_replace('-', '', (string) $appointment->public_id), -6))] = '{order_no_2}';
        }

        foreach (['en', 'ar'] as $locale) {
            $pages = [
                'home' => $s->build($locale),
                'item' => $s->build($locale, 'item', ['item' => $cart[0]['item']]),
                'cart' => $s->build($locale, 'cart', ['cart' => $cart]),
                'checkout' => $s->build($locale, 'checkout', ['cart' => $cart]),
                'track' => $s->build($locale, 'track', ['order' => $order]),
                'kiosk' => $s->build($locale, 'home', ['kiosk' => true]),
            ];
            if ($booking) {
                $pages['time'] = $s->build($locale, 'time', ['cart' => [$cart[0]], 'orderType' => 'appointment']);
                $pages['reschedule'] = $s->build($locale, 'reschedule', ['order' => $appointment]);
            }
            // closed: every branch of the store stops taking orders (the owner's pause)
            Branch::where('store_id', $s->store->id)->update(['accepting_orders' => false]);
            $pages['closed'] = $s->build($locale);
            Branch::where('store_id', $s->store->id)->update(['accepting_orders' => true]);

            foreach ($pages as $page => $vm) {
                $this->assertSame([], ViewModelScenario::errors($vm), "{$s->theme}.{$locale}.{$page} validates against the kit schema");
                $this->assertSame($locale, $vm['store']['locale']);
            }
            $this->assertSame($pages['home']['store']['dir'] === 'rtl', $locale === 'ar');
            $this->assertFalse($pages['closed']['store']['status']['open']);
            $this->assertTrue($pages['kiosk']['context']['kiosk']['on']);
            $this->assertSame('track', $pages['track']['page']['kind']);
            $this->assertSame($pages['cart']['page']['cart']['total'], $pages['checkout']['page']['checkout']['quote']['total']);

            $this->assertGolden("{$s->theme}.{$locale}", $this->document($pages, $masks));
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $pages
     * @param  array<string, string>  $masks
     * @return array<string, mixed>
     */
    private function document(array $pages, array $masks): array
    {
        $decoded = [];
        foreach ($pages as $page => $vm) {
            $decoded[$page] = json_decode(ViewModelJson::encode($vm), true);
        }
        $doc = ['home' => $decoded['home'], 'pages' => []];
        foreach ($decoded as $page => $vm) {
            if ($page === 'home') {
                continue;
            }
            $diff = [];
            foreach ($vm as $key => $value) {
                if (! array_key_exists($key, $decoded['home']) || $decoded['home'][$key] !== $value) {
                    $diff[$key] = $value;
                }
            }
            $doc['pages'][$page] = $diff;
        }

        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $json = strtr($json, $masks + [rtrim((string) url('/'), '/') => '{app}']);
        // seeded image file names may carry row ids (they differ between databases): number them by appearance
        $files = [];
        $json = (string) preg_replace_callback('#(\{app\}/storage/[a-z/_-]+/)([^"/]+)"#', function (array $m) use (&$files): string {
            $files[$m[2]] ??= count($files) + 1;

            return $m[1].'{file:'.$files[$m[2]].'}"';
        }, $json);
        $seen = [];
        $json = (string) preg_replace_callback('/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/', function (array $m) use (&$seen): string {
            $seen[$m[0]] ??= count($seen) + 1;

            return '{id:'.$seen[$m[0]].'}';
        }, $json);

        // the checkout / coupon / slots / lookup routes bind the store by its numeric id, which differs between databases
        $json = (string) preg_replace('#"/\d+/(send-order|coupon|schedule-slots|order-lookup)"#', '"/{store}/$1"', $json);

        return json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param  array<string, mixed>  $doc */
    private function assertGolden(string $name, array $doc): void
    {
        $path = base_path("tests/Fixtures/viewmodel/{$name}.json");
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
        if (getenv('UPDATE_VM_GOLDEN') === '1' || ! is_file($path)) {
            @mkdir(dirname($path), 0755, true);
            file_put_contents($path, $json);
            if (getenv('UPDATE_VM_GOLDEN') !== '1') {
                $this->fail("Golden file {$name}.json was missing and has been written; review it and run again.");
            }

            return;
        }
        $golden = (string) file_get_contents($path);
        // The "powered by" line is a white-label feature: without an edition package the plan grants every key, so the
        // store may hide the branding while the golden (written with the edition installed) shows it. Normalise that
        // one key; the golden still compares every other value.
        $doc = $this->normalisePoweredBy($doc, json_decode($golden, true) ?: []);
        $json = json_encode($doc, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

        $this->assertSame($golden, $json, "{$name}: the view model changed; if intended, regenerate with UPDATE_VM_GOLDEN=1 and review the diff");
    }

    /**
     * The golden's own `powered_by` value, where this edition grants every plan key (no edition package installed).
     *
     * @param  array<string, mixed>  $doc
     * @param  array<string, mixed>  $golden
     * @return array<string, mixed>
     */
    private function normalisePoweredBy(array $doc, array $golden): array
    {
        if (EditionPackages::all() !== []) {
            return $doc;
        }

        foreach ($doc as $key => $value) {
            if ($key === 'powered_by') {
                $doc[$key] = $golden[$key] ?? $value;

                continue;
            }
            if (is_array($value) && isset($golden[$key]) && is_array($golden[$key])) {
                $doc[$key] = $this->normalisePoweredBy($value, $golden[$key]);
            }
        }

        return $doc;
    }
}
