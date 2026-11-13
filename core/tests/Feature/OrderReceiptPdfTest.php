<?php
// SPDX-License-Identifier: AGPL-3.0-only
// Copyright (c) JUWRI LTD

namespace Tests\Feature;

use App\Http\Middleware\Installed;
use App\Http\Middleware\PreventRequestForgery;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Juwri\Core\Http\Middleware\Locale;
use Juwri\Core\Models\Language;
use Juwri\Core\Models\Store;
use Juwri\Core\Models\User;
use Juwri\Modules\Menu\Models\Menu;
use Juwri\Modules\Ordering\Services\OrderFiscalContext;
use Juwri\Modules\Ordering\Services\OrderReceiptPdf;
use Smalot\PdfParser\Parser;
use Tests\Support\TestPlans;
use Tests\TestCase;

/** P6-172: receipt PDF in pure PHP (TCPDF); amounts are read from the order, never recomputed. */
class OrderReceiptPdfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        putenv('THEME_NAME=juwri');
        $_ENV['THEME_NAME'] = 'juwri';
        $_SERVER['THEME_NAME'] = 'juwri';
        Language::firstOrCreate(['code' => 'en'], ['name' => 'English', 'direction' => 'ltr', 'active' => 1]);
        $this->withoutMiddleware([Installed::class, Locale::class, PreventRequestForgery::class]);
        View::share(['activeTheme' => active_theme(), 'activeThemeAssets' => active_theme(true), 'settings' => (object) [], 'languages' => collect(), 'admin_languages' => collect()]);
    }

    private function owner(): User
    {
        $plan = TestPlans::create([
            'name' => 'P'.Str::random(5), 'description' => 't', 'monthly_price' => 1,
            'annual_price' => 1, 'lifetime_price' => 1, 'status' => 1,
            'settings' => json_encode(['restaurant_limit' => 999]),
        ]);

        return User::factory()->create([
            'group_id' => (string) $plan->id, 'status' => 1, 'email_verified_at' => now(),
            'username' => 'owner'.Str::lower(Str::random(6)), 'email' => Str::lower(Str::random(8)).'@owner.test',
        ]);
    }

    private function store(User $owner): Store
    {
        return Store::create([
            'user_id' => $owner->id, 'slug' => 'r-'.Str::lower(Str::random(6)), 'color' => '#111',
            'title' => 'R '.Str::random(4), 'sub_title' => 't', 'description' => 't',
            'main_image' => 'd.png', 'cover_image' => 'd.png', 'address' => '1 St', 'phone' => '1',
            'store_type' => 'restaurant', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(Store $store): Order
    {
        return Order::create([
            'store_id' => $store->id, 'type' => 'takeaway', 'status' => 'new',
            'track_token' => Str::lower(Str::random(32)), 'created_at' => now(),
        ]);
    }

    public function test_pdf_is_served_for_the_owner_and_totals_match_the_order(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $order = $this->order($store);

        $res = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('stores.orders.receiptPdf', $order->id));

        $res->assertOk();
        $this->assertStringContainsString('application/pdf', (string) $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $res->getContent());

        $money = $order->computeMoney();
        [$html] = app(OrderReceiptPdf::class)->html($store, $order);
        $dec = money_decimals($order->currency_code ?? 'USD');
        $this->assertStringContainsString(number_format((float) $money['grand'], $dec, '.', ','), $html);
        $this->assertStringContainsString(number_format((float) $money['tax'], $dec, '.', ','), $html);
    }

    public function test_another_owner_cannot_read_the_pdf(): void
    {
        $order = $this->order($this->store($this->owner()));
        $other = $this->owner();
        $this->store($other);

        $this->actingAs($other)->get(route('stores.orders.receiptPdf', $order->id))->assertNotFound();
    }

    public function test_no_chrome_or_shell_is_used(): void
    {
        $src = (string) file_get_contents(app_path('Modules/Ordering/Services/OrderReceiptPdf.php'));
        foreach (['proc_open', 'shell_exec', 'exec(', 'puppeteer', 'passthru'] as $bad) {
            $this->assertStringNotContainsString($bad, strtolower($src));
        }
    }

    public function test_the_text_of_the_rendered_pdf_equals_the_order_numbers(): void
    {
        $owner = $this->owner();
        $store = $this->store($owner);
        $order = $this->order($store);
        $m1 = Menu::create(['store_id' => $store->id, 'name' => 'Falafel', 'price' => 10, 'active' => '1']);
        $m2 = Menu::create(['store_id' => $store->id, 'name' => 'Tea', 'price' => 7, 'active' => '1']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m1->id, 'quantity' => 2, 'unit_price' => 12.5]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $m2->id, 'quantity' => 3, 'unit_price' => 7.25]);
        $order = $order->fresh();

        $res = $this->actingAs($owner)->withSession(['dashboard_store_id' => $store->id])
            ->get(route('stores.orders.receiptPdf', $order->id));
        $res->assertOk();
        $text = (new Parser)->parseContent((string) $res->getContent())->getText();
        $text = preg_replace('/\s+/u', ' ', $text);

        $ctx = OrderFiscalContext::for($store, $order);
        $dec = money_decimals($ctx->currency);
        $fmt = fn (float $v) => number_format($v, $dec, '.', ',');
        $this->assertStringContainsString($fmt($ctx->total), $text);
        $this->assertStringContainsString($fmt($ctx->taxAmount), $text);
        $this->assertNotEmpty($ctx->lines);
        foreach ($ctx->lines as $l) {
            $this->assertStringContainsString($l->name, $text);
            $this->assertStringContainsString($fmt($l->amount ?? $l->unitPrice * $l->quantity), $text);
        }
    }

    public function test_every_receipt_label_is_translated_in_all_locales(): void
    {
        $keys = ['receipt', 'vat_number', 'cr_number', 'item', 'qty', 'unit_price', 'vat_rate', 'vat_amount', 'amount', 'discount', 'vat', 'total'];
        // Words that are genuinely the same as English in these locales.
        $same = ['es' => ['total'], 'fr' => ['total'], 'pt' => ['total', 'item']];
        foreach (['ar', 'de', 'es', 'fr', 'hi', 'pt', 'ru', 'tr', 'ur', 'zh'] as $loc) {
            foreach ($keys as $k) {
                $en = trans('lang.'.$k, [], 'en');
                $tr = trans('lang.'.$k, [], $loc);
                $this->assertNotSame('lang.'.$k, $tr, "$loc is missing lang.$k");
                if (! in_array($k, $same[$loc] ?? [], true)) {
                    $this->assertNotSame($en, $tr, "$loc lang.$k is still English");
                }
            }
        }
    }
}
